<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppMenu;
use App\Support\ModoDeAtencion;
use App\Support\OrdenDeLaConversacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cómo quiere atender la empresa, en una sola decisión.
 *
 * Antes no se elegía: se deducía de cuatro sitios —los dos interruptores de IA,
 * las palabras clave de cada menú y el tipo `welcome`— y ninguno se llamaba
 * «cómo quiero atender». Nadie podía pedir atención manual sin saber que eso
 * significaba apagar dos cosas y vaciar un campo de otra pantalla.
 *
 * Lo que más se cuida aquí es **qué NO se toca**: los submenús. Un submenú no
 * salta solo —lo abre una opción de otro menú— así que apagarlo rompería el menú
 * que lo abre sin cambiar nada de lo que recibe el cliente.
 */
class ModoDeAtencionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true,
            'plan' => 'basico', 'ia' => 'completa',
        ]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'access_token' => 'token',
            'active' => true,
        ]);

        config([
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'n8n_llave',
        ]);
    }

    // ─── En qué modo está ────────────────────────────────────────────────────

    /** Una empresa sin nada encendido atiende a mano, que es lo que parece. */
    public function test_sin_nada_encendido_el_modo_es_manual(): void
    {
        $this->assertSame(ModoDeAtencion::MANUAL, ModoDeAtencion::actual($this->company));
    }

    /** @test */
    public function con_un_menu_que_salta_el_modo_es_menu(): void
    {
        $this->menu('Menú principal', trigger: 'hola, menu');

        $this->assertSame(ModoDeAtencion::MENU, ModoDeAtencion::actual($this->company));
    }

    /** @test */
    public function con_ia_y_sin_menus_que_salten_el_modo_es_ia(): void
    {
        $this->encenderIa();

        $this->assertSame(ModoDeAtencion::IA, ModoDeAtencion::actual($this->company));
    }

    /**
     * Con menú Y con IA el modo sigue siendo «Con menú».
     *
     * Antes había un cuarto modo, «Menú + IA», para justo este caso. Se quitó
     * porque no era un modo: era el estado normal de «Con menú» cuando la
     * empresa tiene la IA encendida, y como tarjeta aparte obligaba a elegir
     * entre dos que sólo se diferenciaban en algo invisible en ambas.
     *
     * Manda el menú, que es lo que el cliente ve primero.
     *
     * @test
     */
    public function con_las_dos_cosas_manda_el_menu(): void
    {
        $this->menu('Menú principal', trigger: 'hola');
        $this->encenderIa();

        $this->assertSame(ModoDeAtencion::MENU, ModoDeAtencion::actual($this->company));
    }

    // ─── Aplicar un modo ─────────────────────────────────────────────────────

    /** @test */
    public function pasar_a_manual_apaga_los_menus_que_saltan_y_la_ia(): void
    {
        $principal = $this->menu('Menú principal', trigger: 'hola');
        $this->encenderIa();

        ModoDeAtencion::aplicar($this->company, ModoDeAtencion::MANUAL);

        $this->assertFalse((bool) $principal->refresh()->active);
        $this->assertSame(ModoDeAtencion::MANUAL, ModoDeAtencion::actual($this->company));
    }

    /**
     * Y NO toca los submenús.
     *
     * Un submenú no salta solo: lo abre una opción de otro menú. Apagarlo
     * rompería el menú que lo abre sin cambiar nada de lo que recibe el cliente
     * al escribir, que es lo único que este modo decide.
     *
     * @test
     */
    public function pasar_a_manual_no_apaga_los_submenus(): void
    {
        $this->menu('Menú principal', trigger: 'hola');
        $submenu = $this->menu('Crédito', trigger: '');

        ModoDeAtencion::aplicar($this->company, ModoDeAtencion::MANUAL);

        $this->assertTrue((bool) $submenu->refresh()->active, 'El submenú sigue encendido.');
    }

    /** @test */
    public function pasar_a_menu_enciende_los_menus_que_saltan(): void
    {
        $principal = $this->menu('Menú principal', trigger: 'hola', activo: false);

        ModoDeAtencion::aplicar($this->company, ModoDeAtencion::MENU);

        $this->assertTrue((bool) $principal->refresh()->active);
        $this->assertSame(ModoDeAtencion::MENU, ModoDeAtencion::actual($this->company));
    }

    /**
     * Y «Con menú» NO toca la IA, ni para encenderla ni para apagarla.
     *
     * Es lo que permite que haya tres modos y no cuatro: la IA la manda un solo
     * interruptor, en «IA que responde», y el menú convive con ella encendida o
     * apagada. Si este modo la apagara, elegirlo le quitaría la IA a quien la
     * paga sin habérselo pedido.
     *
     * @test
     */
    public function pasar_a_menu_no_apaga_la_ia_que_ya_estaba(): void
    {
        $this->menu('Menú principal', trigger: 'hola', activo: false);
        $this->encenderIa();

        ModoDeAtencion::aplicar($this->company, ModoDeAtencion::MENU);

        $this->assertTrue(
            OrdenDeLaConversacion::de($this->company->id)['ia_chat'],
            'La IA sigue encendida: el modo del menú no es quién para apagarla.'
        );
    }

    /** Pasar a IA apaga los menús que saltaban, para que no se adelanten. */
    public function test_pasar_a_ia_deja_de_disparar_los_menus(): void
    {
        $principal = $this->menu('Menú principal', trigger: 'hola');

        ModoDeAtencion::aplicar($this->company, ModoDeAtencion::IA);

        $this->assertFalse((bool) $principal->refresh()->active);
        $this->assertSame(ModoDeAtencion::IA, ModoDeAtencion::actual($this->company));
    }

    // ─── Lo que se enseña antes de aplicar ───────────────────────────────────

    /**
     * Se puede saber qué va a cambiar **con nombres**, antes de cambiarlo.
     *
     * «Se apagarán tres menús» no es información; «se apagará Menú principal»
     * sí. Un botón que apaga cosas sin decir cuáles es un botón que nadie pulsa
     * dos veces.
     *
     * @test
     */
    public function dice_que_menus_va_a_tocar_antes_de_tocarlos(): void
    {
        $this->menu('Menú principal', trigger: 'hola');
        $this->menu('Crédito', trigger: '');

        $cambios = ModoDeAtencion::loQueCambiaria($this->company, ModoDeAtencion::MANUAL);

        $this->assertSame(['Menú principal'], $cambios['menus_a_apagar']);
        $this->assertSame([], $cambios['menus_a_encender']);

        // Y no se aplicó nada al preguntarlo.
        $this->assertSame(ModoDeAtencion::MENU, ModoDeAtencion::actual($this->company));
    }

    /**
     * Sin plan para la IA, el modo que la necesita viene bloqueado y con motivo.
     *
     * Aplicarlo dejaría al admin con un modo elegido que no está en marcha, que
     * es peor que decirle que no puede.
     *
     * @test
     */
    public function sin_plan_para_la_ia_el_modo_viene_bloqueado(): void
    {
        $this->company->update(['ia' => 'ninguno']);

        $cambios = ModoDeAtencion::loQueCambiaria($this->company, ModoDeAtencion::IA);

        $this->assertNotNull($cambios['bloqueado']);
        $this->assertStringContainsString('plan', $cambios['bloqueado']);

        ModoDeAtencion::aplicar($this->company, ModoDeAtencion::IA);

        $this->assertSame(ModoDeAtencion::MANUAL, ModoDeAtencion::actual($this->company), 'No se aplicó nada.');
    }

    // ─── Desde la pantalla ───────────────────────────────────────────────────

    /** @test */
    public function se_consulta_y_se_cambia_desde_la_pantalla(): void
    {
        $this->comoAdmin();
        $principal = $this->menu('Menú principal', trigger: 'hola');

        $this->getJson('/api/atencion/modo')
            ->assertOk()
            ->assertJsonPath('modo', ModoDeAtencion::MENU);

        $this->postJson('/api/atencion/modo', ['modo' => ModoDeAtencion::MANUAL])
            ->assertOk()
            ->assertJsonPath('modo', ModoDeAtencion::MANUAL);

        $this->assertFalse((bool) $principal->refresh()->active);
    }

    /** Un modo inventado no pasa la validación. */
    public function test_un_modo_desconocido_se_rechaza(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/atencion/modo', ['modo' => 'telepatia'])->assertStatus(422);
    }

    /**
     * Quien puede mirar los menús pero no configurarlos, tampoco cambia el modo.
     *
     * Es el permiso correcto y no uno nuevo: elegir si el bot responde es de la
     * misma familia que configurar lo que responde, y partirlos en dos dejaría
     * a alguien pudiendo apagarle la atención automática a la empresa sin poder
     * volver a encenderla.
     */
    public function test_hace_falta_el_permiso_de_menus(): void
    {
        // El admin primero, y a propósito: `User::booted()` le da el rol
        // `admin` con TODOS los permisos al primer usuario de cada empresa. Si
        // el mirón se creara primero, sería él quien acabara siendo admin y
        // este test pasaría por el motivo equivocado.
        $this->comoAdmin();

        $miron = User::create([
            'company_id' => $this->company->id,
            'name' => 'Mirón', 'email' => 'miron@fibra.test',
            'password' => 'secret', 'active' => true,
        ]);

        setPermissionsTeamId($this->company->id);

        // Un rol propio, y no `agent`: `CompanyObserver` siembra los roles de
        // cada empresa nueva ya con sus permisos, así que reutilizar el nombre
        // le habría concedido justo el permiso que este test quiere negar.
        $rol = Role::firstOrCreate([
            'name' => 'solo_mirar', 'company_id' => $this->company->id, 'guard_name' => 'web',
        ]);
        $rol->givePermissionTo(Permission::firstOrCreate([
            'name' => 'whatsapp_menus.view', 'guard_name' => 'web',
        ]));
        $miron->assignRole($rol);

        $this->actingAs($miron)
            ->postJson('/api/atencion/modo', ['modo' => ModoDeAtencion::MANUAL])
            ->assertForbidden();
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function menu(string $nombre, string $trigger, bool $activo = true): WhatsAppMenu
    {
        return WhatsAppMenu::create([
            'company_id' => $this->company->id,
            'instance_id' => $this->instance->id,
            'name' => $nombre,
            'body_text' => '¿En qué te ayudo?',
            'trigger_text' => $trigger,
            'type' => 'button',
            'active' => $activo,
        ]);
    }

    private function encenderIa(): void
    {
        CompanyIntegration::updateOrCreate(
            ['company_id' => $this->company->id, 'key' => CompanyIntegration::KEY_AI_CHAT],
            ['enabled' => true]
        );
    }

    private function comoAdmin(): User
    {
        $user = User::create([
            'company_id' => $this->company->id,
            'name' => 'Admin', 'email' => 'admin@fibra.test',
            'password' => 'secret', 'active' => true,
        ]);

        setPermissionsTeamId($this->company->id);

        $rol = Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web',
        ]);
        $rol->givePermissionTo(Permission::firstOrCreate([
            'name' => 'whatsapp_menus.update', 'guard_name' => 'web',
        ]));
        $user->assignRole($rol);

        $this->actingAs($user);

        return $user;
    }
}
