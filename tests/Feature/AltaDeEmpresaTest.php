<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El alta de empresas del panel maestro.
 *
 * El 8-sep-2026 dar de alta una empresa devolvía un 500 en el modal, sin
 * mensaje: `companies.slug` tiene índice único y el controlador lo escribía con
 * `Str::slug($nombre)` a pelo, así que dos nombres que slugifican igual
 * —"Fibra Sur" y "FIBRA SUR"— reventaban la inserción. El slug no se lee en
 * ningún sitio del proyecto, sólo se escribe, de modo que el choque no protegía
 * nada: sólo tumbaba el alta.
 *
 * Además el alta no era atómica. Como el nombre y el email quedaban ocupados
 * por el intento fallido, el reintento chocaba contra su propio primer intento,
 * y la empresa a medias (sin administrador, o con el rol sin permisos) sólo se
 * podía arreglar entrando a la base de datos.
 */
class AltaDeEmpresaTest extends TestCase
{
    use RefreshDatabase;

    /** El caso normal: empresa, rol admin con todos los permisos y su usuario. */
    public function test_da_de_alta_la_empresa_con_su_administrador(): void
    {
        $this->alta(['name' => 'Fibra Óptica del Sur'])
            ->assertRedirect(route('master.index'))
            ->assertSessionHas('success');

        $empresa = Company::where('email', 'contacto@sur.test')->firstOrFail();

        $this->assertSame('fibra-optica-del-sur', $empresa->slug);
        $this->assertTrue($empresa->active);

        $admin = $empresa->users()->where('email', 'admin@sur.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);

        setPermissionsTeamId($empresa->id);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    /** Dos nombres que slugifican igual se distinguen con sufijo, no con un 500. */
    public function test_un_nombre_que_slugifica_igual_que_otro_no_tumba_el_alta(): void
    {
        Company::create([
            'name' => 'Fibra Sur', 'slug' => 'fibra-sur',
            'email' => 'vieja@sur.test', 'active' => true,
        ]);

        $this->alta(['name' => 'FIBRA SUR'])->assertRedirect(route('master.index'));

        $this->assertSame('fibra-sur-2', Company::where('email', 'contacto@sur.test')->value('slug'));
    }

    /** Un nombre sin ni un carácter slugificable tampoco puede dejar el slug vacío. */
    public function test_un_nombre_sin_caracteres_latinos_recibe_un_slug_propio(): void
    {
        Company::create([
            'name' => '株式会社', 'slug' => 'empresa',
            'email' => 'vieja@jp.test', 'active' => true,
        ]);

        $this->alta(['name' => '有限会社'])->assertRedirect(route('master.index'));

        $this->assertSame('empresa-2', Company::where('email', 'contacto@sur.test')->value('slug'));
    }

    /**
     * Y si el alta falla a mitad, no deja empresa a medias.
     *
     * El fallo se provoca en la creación del usuario, que es el último paso:
     * cualquier cosa que reviente ahí —el índice único de `users.email`, el
     * enum de `role`, un observer— dejaba antes la empresa creada con su rol
     * de admin y sin nadie que pudiera entrar, y con el nombre y el email ya
     * ocupados de cara al reintento.
     */
    public function test_si_el_administrador_no_se_puede_crear_no_queda_empresa_a_medias(): void
    {
        $master = $this->master();
        $empresasAntes = Company::count();

        Event::listen('eloquent.creating: '.User::class, function () {
            throw new \RuntimeException('el usuario no se pudo crear');
        });

        try {
            $this->withoutExceptionHandling()
                ->actingAs($master)
                ->post(route('master.companies.store'), [
                    'name' => 'Fibra Norte',
                    'email' => 'contacto@norte.test',
                    'admin_name' => 'Admin',
                    'admin_email' => 'admin@norte.test',
                    'password' => 'secreto123',
                ]);
            $this->fail('El alta debía fallar al crear el usuario.');
        } catch (\RuntimeException $e) {
            // Es el fallo provocado; lo que se mide es el estado que deja.
        }

        $this->assertSame($empresasAntes, Company::count(), 'El alta fallida no debe dejar la empresa creada.');
        $this->assertDatabaseMissing('companies', ['email' => 'contacto@norte.test']);
    }

    private function alta(array $campos = [])
    {
        return $this->actingAs($this->master())->post(route('master.companies.store'), array_merge([
            'name' => 'Fibra Sur',
            'email' => 'contacto@sur.test',
            'admin_name' => 'Admin del Sur',
            'admin_email' => 'admin@sur.test',
            'password' => 'secreto123',
        ], $campos));
    }

    private function master(): User
    {
        $company = Company::create(['name' => 'Integra', 'slug' => 'integra', 'active' => true]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Master',
            'email' => 'master@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        // authorizeMaster() pregunta por el rol de spatie, no por la columna.
        setPermissionsTeamId($company->id);
        $user->assignRole(Role::firstOrCreate([
            'name' => 'master',
            'company_id' => $company->id,
            'guard_name' => 'web',
        ]));

        return $user->fresh();
    }
}
