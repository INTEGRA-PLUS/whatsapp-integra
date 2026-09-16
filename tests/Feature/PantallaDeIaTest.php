<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El candado comercial de la IA que responde sola.
 *
 * Delante del freno de siempre —el secreto que escribe el equipo— hay ahora una
 * pregunta distinta: si la empresa contrató el complemento. Son dos cosas que
 * parecen una, «¿lo pagó?» y «¿está seguro?», y juntarlas haría que el día que
 * alguien contrate el complemento la IA se encendiera sola, sin que nadie del
 * equipo se entere de que hay un modelo nuevo hablando con clientes reales.
 *
 * Lo que se protege aquí es que el candado esté en el servidor y no en la
 * pantalla: quien quiera saltárselo no va a usar el navegador.
 */
class PantallaDeIaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ai_activation.secret' => 'abre-sesamo']);

        $this->company = $this->empresa('Fibra Sin IA', 'fibra-sin-ia', $this->sinIa());
        $this->user = $this->admin($this->company, 'admin@fibra.test');
    }

    /**
     * El que más caro sale: un admin con todos los permisos de su empresa
     * enciende la IA sin haberla contratado.
     *
     * 402 y no 403 para que la pantalla pueda decir «contrátalo» en vez de «no
     * tienes acceso», que manda al admin a pelearse con sus roles por algo que
     * no es de roles.
     */
    public function test_sin_el_complemento_no_se_puede_configurar(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/settings/ai-flow', ['chat_enabled' => true])
            ->assertStatus(402);
    }

    /**
     * El que se olvida: el secreto correcto NO se salta el plan.
     *
     * Es el camino natural del error —«si tiene el secreto, por algo será»— y
     * deja encendida a una empresa que no lo ha contratado.
     */
    public function test_el_secreto_correcto_no_abre_lo_que_no_se_contrato(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/settings/ai-flow/unlock', ['secret' => 'abre-sesamo'])
            ->assertStatus(402);

        $this->assertNull($this->company->fresh()->ai_flow_unlocked_at);
    }

    /** Y la pantalla lo sabe, para enseñar lo que hace la función en vez de un error. */
    public function test_la_pantalla_dice_que_no_lo_tiene_contratado(): void
    {
        $this->actingAs($this->user)
            ->get('/ia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FlujoIa/Index')
                ->where('tiene_ia', false));
    }

    /**
     * Con el complemento contratado y sin el secreto: la pantalla enseña el
     * formulario del secreto, no la configuración.
     *
     * Contratar no es encender. El freno del equipo sigue estando.
     */
    public function test_con_el_complemento_pero_sin_el_secreto_no_hay_configuracion(): void
    {
        $empresa = $this->empresa('Fibra Con IA', 'fibra-con-ia', $this->conIa());
        $admin = $this->admin($empresa, 'admin@conia.test');

        $this->actingAs($admin)
            ->get('/ia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tiene_ia', true));

        $this->actingAs($admin)
            ->getJson('/api/settings/ai-flow')
            ->assertOk()
            ->assertJsonPath('unlocked', false);

        // Y sin desbloquear no se configura, aunque el plan esté contratado.
        $this->actingAs($admin)
            ->putJson('/api/settings/ai-flow', ['chat_enabled' => true])
            ->assertStatus(403);
    }

    /**
     * El complemento barato no enciende el flujo caro.
     *
     * `tieneIa()` es cierto con el complemento Esencial —semáforo y resumen,
     * que cuestan céntimos— pero ése no trae el chat con IA, que cuesta trece
     * veces un análisis de semáforo. Un candado que sólo preguntara «¿tiene
     * IA?» dejaría encender desde esta misma pantalla lo que no se ha
     * contratado, y la diferencia no la vería nadie hasta la factura del modelo.
     */
    public function test_el_complemento_barato_no_abre_el_chat_con_ia(): void
    {
        $barato = $this->soloIaBarata();

        if ($barato === null) {
            $this->markTestSkipped('El catálogo no tiene un complemento con IA que no traiga el chat.');
        }

        $empresa = $this->empresa('Fibra Barata', 'fibra-barata', $barato);
        $admin = $this->admin($empresa, 'admin@barata.test');
        $empresa->forceFill([
            'ai_flow_unlocked_at' => now(),
            'ai_flow_unlocked_by' => $admin->id,
        ])->save();

        // Entra en la pantalla: tiene IA contratada.
        $this->actingAs($admin)
            ->get('/ia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tiene_ia', true));

        // Pero no puede encender el flujo que no incluye su complemento.
        $this->actingAs($admin)
            ->putJson('/api/settings/ai-flow', ['chat_enabled' => true])
            ->assertStatus(402);
    }

    /**
     * El aislamiento de siempre: desbloquear una empresa no desbloquea otra.
     *
     * `whatsapp_conversations` y compañía no tienen `company_id` y aquí sí lo
     * hay, pero el desbloqueo se escribe sobre `$user->company` — si alguna vez
     * se resolviera por un id que venga del request, esto se pone rojo.
     */
    public function test_desbloquear_una_empresa_no_desbloquea_otra(): void
    {
        $mia = $this->empresa('Mía', 'mia', $this->conIa());
        $otra = $this->empresa('Otra', 'otra', $this->conIa());
        $admin = $this->admin($mia, 'admin@mia.test');

        $this->actingAs($admin)
            ->postJson('/api/settings/ai-flow/unlock', ['secret' => 'abre-sesamo'])
            ->assertOk();

        $this->assertNotNull($mia->fresh()->ai_flow_unlocked_at);
        $this->assertNull($otra->fresh()->ai_flow_unlocked_at, 'La otra empresa no se toca.');
    }

    /**
     * El enlace viejo sigue llevando a alguna parte.
     *
     * `/settings?tab=flujo-ia` está escrito en manuales y en WhatsApps del
     * equipo. Sin esto, quien lo abre acaba en la pestaña de Perfil sin
     * entender qué pasó, que es la peor forma de enterarse de que algo se movió.
     */
    public function test_el_enlace_viejo_lleva_a_la_pantalla_nueva(): void
    {
        $this->actingAs($this->user)
            ->get('/settings?tab=flujo-ia')
            ->assertRedirect(route('ia.index'));
    }

    /** Y la configuración sin ese parámetro sigue abriéndose como siempre. */
    public function test_la_configuracion_sigue_funcionando(): void
    {
        $this->actingAs($this->user)
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Index'));
    }

    /**
     * Una empresa con el complemento que se le pida, sin escribir su nombre.
     *
     * El catálogo se está reescribiendo —los planes de hoy no son los de la
     * semana pasada— así que los complementos se buscan por lo que hacen y no
     * por cómo se llaman: `conIa()` y `sinIa()` preguntan al propio
     * `PlanDeLaEmpresa`. Un test que nombra un plan se pone rojo el día que
     * alguien lo renombra, y no porque nada se haya roto.
     */
    private function empresa(string $nombre, string $slug, ?string $complemento = null): Company
    {
        return Company::create(array_filter([
            'name' => $nombre,
            'slug' => $slug,
            'active' => true,
            'ia' => $complemento,
        ], fn ($v) => $v !== null));
    }

    /** El complemento que abre los flujos de chat y menús: el caro. */
    private function conIa(): string
    {
        foreach (array_keys(config('planes.ia', [])) as $slug) {
            $empresa = new Company(['ia' => $slug]);

            if (PlanDeLaEmpresa::de($empresa)->permiteFlujoIa('ai_chat')) {
                return $slug;
            }
        }

        $this->fail('Ningún complemento del catálogo abre el chat con IA.');
    }

    /** Uno que trae IA pero NO el chat, si el catálogo lo tiene. */
    private function soloIaBarata(): ?string
    {
        foreach (array_keys(config('planes.ia', [])) as $slug) {
            $plan = PlanDeLaEmpresa::de(new Company(['ia' => $slug]));

            if ($plan->tieneIa() && ! $plan->permiteFlujoIa('ai_chat')) {
                return $slug;
            }
        }

        return null;
    }

    /** Y uno que no trae IA ninguna. */
    private function sinIa(): string
    {
        foreach (array_keys(config('planes.ia', [])) as $slug) {
            if (! PlanDeLaEmpresa::de(new Company(['ia' => $slug]))->tieneIa()) {
                return $slug;
            }
        }

        $this->fail('El catálogo no tiene ningún complemento sin IA.');
    }

    private function admin(Company $company, string $email): User
    {
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => $email,
            'password' => 'secret',
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);

        $role = Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web',
        ]);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'whatsapp_menus.update', 'guard_name' => 'web',
        ]));
        $user->assignRole($role);

        return $user->fresh();
    }
}
