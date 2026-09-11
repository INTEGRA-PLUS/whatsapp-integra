<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El marketplace: catálogo, instalación y ajustes.
 *
 * Lo que se prueba es el contorno del módulo: que instalar y encender sean dos
 * cosas distintas, que los ajustes los limpie la extensión antes de guardarse, y
 * —sobre todo— que ninguna empresa pueda tocar ni referenciar lo de otra, que es
 * la única forma de fallo que no se arregla con un despliegue.
 */
class ExtensionMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true]);
        $this->admin = $this->userFor($this->company, 'admin@fibra.test', [
            'extensions.view', 'extensions.create', 'extensions.update', 'extensions.delete',
        ]);
    }

    /**
     * Un usuario con exactamente los permisos que se le pasen.
     *
     * El PRIMER usuario de una empresa hereda todos los permisos de la
     * plataforma (User::booted), así que se le antepone un dueño de paja: sin
     * eso, cualquier prueba de "esto exige permiso" sería un falso verde. Y el
     * rol no se llama 'admin' por lo mismo — ese rol lo tiene sincronizado con
     * todos los permisos el dueño.
     *
     * @param  list<string>  $permissions
     */
    private function userFor(Company $company, string $email, array $permissions): User
    {
        if (User::where('company_id', $company->id)->doesntExist()) {
            User::create([
                'company_id' => $company->id,
                'name' => 'Dueño',
                'email' => "dueno-{$company->id}@test",
                'password' => 'secret',
                'active' => true,
                'role' => 'admin',
            ]);
        }

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Usuario',
            'email' => $email,
            'password' => 'secret',
            'active' => true,
            'role' => 'agent',
        ]);

        setPermissionsTeamId($company->id);

        $role = Role::firstOrCreate([
            'name' => 'operacion', 'company_id' => $company->id, 'guard_name' => 'web',
        ]);

        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user->assignRole($role);

        return $user;
    }

    public function test_el_catalogo_lista_las_extensiones_del_codigo(): void
    {
        $this->actingAs($this->admin)
            ->get('/extensiones')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Extensions/Index')
                ->has('extensions', 3)
                ->where('extensions.0.installed', false));
    }

    public function test_instalar_crea_la_fila_con_los_ajustes_de_fabrica(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/extensions/follow_up/install')
            ->assertOk()
            ->assertJsonPath('installed', true)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('settings.minutes', 30);

        $fila = CompanyExtension::where('company_id', $this->company->id)->where('slug', 'follow_up')->first();
        $this->assertNotNull($fila);
        $this->assertSame($this->admin->id, $fila->installed_by);
    }

    /**
     * Un doble clic en "Instalar" no puede acabar en un 500 contra el índice
     * único, y sobre todo no puede reiniciar los ajustes de quien ya la tenía.
     */
    public function test_instalar_dos_veces_no_duplica_ni_reinicia(): void
    {
        $this->actingAs($this->admin)->postJson('/api/extensions/follow_up/install')->assertOk();
        $this->actingAs($this->admin)
            ->putJson('/api/extensions/follow_up/settings', ['settings' => ['minutes' => 90]])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/api/extensions/follow_up/install')
            ->assertOk()
            ->assertJsonPath('settings.minutes', 90);

        $this->assertSame(1, CompanyExtension::where('company_id', $this->company->id)->count());
    }

    public function test_apagar_conserva_los_ajustes_y_desinstalar_los_borra(): void
    {
        $this->actingAs($this->admin)->postJson('/api/extensions/follow_up/install')->assertOk();
        $this->actingAs($this->admin)
            ->putJson('/api/extensions/follow_up/settings', ['settings' => ['minutes' => 120]])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/api/extensions/follow_up/toggle', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('settings.minutes', 120);

        $this->actingAs($this->admin)
            ->deleteJson('/api/extensions/follow_up')
            ->assertOk()
            ->assertJsonPath('installed', false);

        $this->assertSame(0, CompanyExtension::where('company_id', $this->company->id)->count());
    }

    /**
     * Los ajustes no se guardan como llegan: la extensión los recorta a su rango
     * y descarta lo que no reconoce. Sin esto, un `minutes` de 0 dejaría el
     * comando avisando de todas las conversaciones cada cinco minutos.
     */
    public function test_los_ajustes_pasan_por_el_saneado_de_la_extension(): void
    {
        $this->actingAs($this->admin)->postJson('/api/extensions/follow_up/install');

        $this->actingAs($this->admin)
            ->putJson('/api/extensions/follow_up/settings', [
                'settings' => [
                    'minutes' => 0,
                    'notify' => 'a-quien-sea',
                    'repeat_minutes' => 999999,
                    'campo_inventado' => 'x',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.minutes', 5)
            ->assertJsonPath('settings.notify', 'both')
            ->assertJsonPath('settings.repeat_minutes', 10080)
            ->assertJsonMissingPath('settings.campo_inventado');
    }

    /** Una etiqueta de otra empresa no se guarda, aunque se mande el id a mano. */
    public function test_no_se_puede_referenciar_una_etiqueta_de_otra_empresa(): void
    {
        $otra = Company::create(['name' => 'Fibra Norte', 'slug' => 'fibra-norte', 'active' => true]);
        $ajena = Tag::create(['company_id' => $otra->id, 'name' => 'Ajena', 'color' => '#fff']);
        $propia = Tag::create(['company_id' => $this->company->id, 'name' => 'Propia', 'color' => '#000']);

        $this->actingAs($this->admin)->postJson('/api/extensions/follow_up/install');

        $this->actingAs($this->admin)
            ->putJson('/api/extensions/follow_up/settings', ['settings' => ['tag_id' => $ajena->id]])
            ->assertOk()
            ->assertJsonPath('settings.tag_id', null);

        $this->actingAs($this->admin)
            ->putJson('/api/extensions/follow_up/settings', ['settings' => ['tag_id' => $propia->id]])
            ->assertOk()
            ->assertJsonPath('settings.tag_id', $propia->id);
    }

    /**
     * La instalación de una empresa es invisible para la otra, aunque las dos
     * miren el mismo slug del mismo catálogo.
     */
    public function test_cada_empresa_ve_solo_sus_instalaciones(): void
    {
        $otra = Company::create(['name' => 'Fibra Norte', 'slug' => 'fibra-norte', 'active' => true]);
        $vecino = $this->userFor($otra, 'admin@norte.test', ['extensions.view', 'extensions.delete']);

        $this->actingAs($this->admin)->postJson('/api/extensions/follow_up/install')->assertOk();

        $this->actingAs($vecino)
            ->get('/extensiones')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('extensions.0.installed', false));

        // Y borrar con el mismo slug desde la otra empresa no toca la fila ajena.
        $this->actingAs($vecino)->deleteJson('/api/extensions/follow_up')->assertOk();

        $this->assertSame(1, CompanyExtension::where('company_id', $this->company->id)->count());
    }

    public function test_configurar_exige_el_permiso(): void
    {
        $mirón = $this->userFor(
            Company::create(['name' => 'Fibra Este', 'slug' => 'fibra-este', 'active' => true]),
            'miron@este.test',
            ['extensions.view']
        );

        $this->actingAs($mirón)->get('/extensiones')->assertOk();
        $this->actingAs($mirón)->postJson('/api/extensions/follow_up/install')->assertForbidden();
        $this->actingAs($mirón)->putJson('/api/extensions/follow_up/settings', ['settings' => []])->assertForbidden();
    }

    public function test_un_slug_que_no_existe_es_un_404_y_no_un_500(): void
    {
        $this->actingAs($this->admin)->get('/extensiones/no_existe')->assertNotFound();
        $this->actingAs($this->admin)->postJson('/api/extensions/no_existe/install')->assertNotFound();
    }

    /** Configurar algo que no se ha instalado no crea la fila por la puerta de atrás. */
    public function test_no_se_pueden_guardar_ajustes_sin_instalar(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/extensions/follow_up/settings', ['settings' => ['minutes' => 60]])
            ->assertNotFound();

        $this->assertSame(0, CompanyExtension::where('company_id', $this->company->id)->count());
    }

    /**
     * El detalle entrega el esquema con las opciones ya resueltas y acotadas a
     * la empresa: es la lista que va a viajar al navegador.
     */
    public function test_el_detalle_resuelve_las_opciones_de_la_empresa(): void
    {
        $otra = Company::create(['name' => 'Fibra Norte', 'slug' => 'fibra-norte', 'active' => true]);
        Tag::create(['company_id' => $otra->id, 'name' => 'Ajena', 'color' => '#fff']);
        $propia = Tag::create(['company_id' => $this->company->id, 'name' => 'Propia', 'color' => '#000']);

        $this->actingAs($this->admin)
            ->get('/extensiones/follow_up')
            ->assertOk()
            ->assertInertia(function ($page) use ($propia) {
                $schema = collect($page->toArray()['props']['extension']['schema']);
                $campo = $schema->firstWhere('key', 'tag_id');

                $this->assertSame(
                    [['value' => (string) $propia->id, 'label' => 'Propia']],
                    $campo['options']
                );
            });
    }
}
