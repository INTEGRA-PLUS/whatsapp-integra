<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\AgentAssignmentService;
use App\Support\TraspasoAUnAsesor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A quién le llega el chat cuando la IA se rinde.
 *
 * Antes iba siempre al asesor menos cargado, escrito a fuego. Funciona para un
 * equipo de cinco que hacen lo mismo, y no para una empresa donde soporte
 * técnico y cartera son dos mundos: ahí la factura acaba en manos del que
 * instala antenas sólo porque tenía un hueco.
 *
 * Lo que más importa de todo esto es el último bloque: **qué pasa cuando la
 * configuración se rompe**. Un asesor que se da de baja no puede convertirse en
 * un cliente esperando a nadie.
 */
class TraspasoAUnAsesorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private AgentAssignmentService $reparto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true,
            // Con el complemento de IA contratado: la pantalla responde 402 a
            // quien no lo tiene, y lo que se prueba aquí es el traspaso.
            'plan' => 'basico', 'ia' => 'completa',
        ]);

        $this->reparto = new AgentAssignmentService;
    }

    /** Quien no ha tocado nada sigue como antes: el menos cargado. */
    public function test_por_defecto_va_al_menos_cargado(): void
    {
        $ocupado = $this->agente('Ocupado');
        $libre = $this->agente('Libre');

        $this->conversacionesAbiertas($ocupado, 3);

        $this->assertSame(
            TraspasoAUnAsesor::MENOS_CARGADO,
            TraspasoAUnAsesor::de($this->company)['estrategia']
        );
        $this->assertSame($libre->id, TraspasoAUnAsesor::asesorPara($this->company, $this->reparto)?->id);
    }

    /** @test */
    public function puede_ir_siempre_a_una_persona_concreta(): void
    {
        $this->agente('Cualquiera');
        $elegida = $this->agente('La elegida');

        // Aunque tenga más carga que el resto: lo eligió una persona.
        $this->conversacionesAbiertas($elegida, 9);

        TraspasoAUnAsesor::guardar($this->company, [
            'estrategia' => TraspasoAUnAsesor::FIJO,
            'usuario_id' => $elegida->id,
        ]);

        $this->assertSame($elegida->id, TraspasoAUnAsesor::asesorPara($this->company, $this->reparto)?->id);
    }

    /** @test */
    public function puede_repartir_dentro_de_un_equipo(): void
    {
        $soporte1 = $this->agente('Soporte uno');
        $soporte2 = $this->agente('Soporte dos');
        $cartera = $this->agente('Cartera');

        // El de fuera del equipo está libre y aun así no le toca.
        $this->conversacionesAbiertas($soporte1, 4);
        $this->conversacionesAbiertas($soporte2, 1);

        TraspasoAUnAsesor::guardar($this->company, [
            'estrategia' => TraspasoAUnAsesor::EQUIPO,
            'equipo' => [$soporte1->id, $soporte2->id],
        ]);

        $elegido = TraspasoAUnAsesor::asesorPara($this->company, $this->reparto);

        $this->assertSame($soporte2->id, $elegido?->id, 'Dentro del equipo, el menos cargado.');
        $this->assertNotSame($cartera->id, $elegido?->id);
    }

    /** @test */
    public function puede_no_asignar_a_nadie(): void
    {
        $this->agente('Alguien');

        TraspasoAUnAsesor::guardar($this->company, ['estrategia' => TraspasoAUnAsesor::BANDEJA]);

        $this->assertNull(TraspasoAUnAsesor::asesorPara($this->company, $this->reparto));
    }

    // ─── Cuando la configuración se rompe ────────────────────────────────────

    /**
     * Si la persona elegida se da de baja, el chat le llega a otro.
     *
     * La alternativa es el silencio, que es justo lo que este mecanismo existe
     * para evitar: un cliente esperando no se entera de que había un error de
     * configuración.
     *
     * @test
     */
    public function si_el_asesor_fijo_esta_inactivo_el_chat_no_se_queda_sin_nadie(): void
    {
        $elegida = $this->agente('Se dio de baja');
        $otro = $this->agente('Sigue aquí');

        TraspasoAUnAsesor::guardar($this->company, [
            'estrategia' => TraspasoAUnAsesor::FIJO,
            'usuario_id' => $elegida->id,
        ]);

        $elegida->update(['active' => false]);

        $this->assertSame($otro->id, TraspasoAUnAsesor::asesorPara($this->company, $this->reparto)?->id);
    }

    /** Y si el equipo entero queda inactivo, lo mismo. */
    public function test_un_equipo_sin_nadie_disponible_cae_al_menos_cargado(): void
    {
        $delEquipo = $this->agente('Del equipo');
        $fuera = $this->agente('De fuera');

        TraspasoAUnAsesor::guardar($this->company, [
            'estrategia' => TraspasoAUnAsesor::EQUIPO,
            'equipo' => [$delEquipo->id],
        ]);

        $delEquipo->update(['active' => false]);

        $this->assertSame($fuera->id, TraspasoAUnAsesor::asesorPara($this->company, $this->reparto)?->id);
    }

    /**
     * Pero «bandeja» no cae a ningún sitio.
     *
     * Ahí no hay error que salvar: es una decisión. Hay empresas donde nadie
     * quiere chats asignados y todos miran la misma lista.
     *
     * @test
     */
    public function bandeja_no_cae_a_nadie_aunque_haya_asesores(): void
    {
        $this->agente('Disponible');

        TraspasoAUnAsesor::guardar($this->company, ['estrategia' => TraspasoAUnAsesor::BANDEJA]);

        $this->assertNull(TraspasoAUnAsesor::asesorPara($this->company, $this->reparto));
    }

    /** Un asesor de otra empresa no puede ser el destino, ni elegido a mano. */
    public function test_no_se_puede_asignar_a_alguien_de_otra_empresa(): void
    {
        $propio = $this->agente('Propio');

        $otra = Company::create([
            'name' => 'Otra ISP', 'slug' => 'otra-isp', 'active' => true, 'plan' => 'basico',
        ]);
        $ajeno = $this->agente('Ajeno', $otra);

        TraspasoAUnAsesor::guardar($this->company, [
            'estrategia' => TraspasoAUnAsesor::FIJO,
            'usuario_id' => $ajeno->id,
        ]);

        $this->assertSame($propio->id, TraspasoAUnAsesor::asesorPara($this->company, $this->reparto)?->id);
    }

    /** Guardar una estrategia no deja escrita la configuración de la anterior. */
    public function test_cambiar_de_estrategia_limpia_lo_que_ya_no_aplica(): void
    {
        $agente = $this->agente('Alguien');

        TraspasoAUnAsesor::guardar($this->company, [
            'estrategia' => TraspasoAUnAsesor::EQUIPO,
            'equipo' => [$agente->id],
        ]);

        TraspasoAUnAsesor::guardar($this->company, [
            'estrategia' => TraspasoAUnAsesor::FIJO,
            'usuario_id' => $agente->id,
        ]);

        // Si el equipo siguiera guardado, la pantalla enseñaría al volver una
        // selección que no se está aplicando.
        $this->assertSame([], TraspasoAUnAsesor::de($this->company->refresh())['equipo']);
    }

    /** Y no pisa lo que haya al lado en `settings`, que es un cajón compartido. */
    public function test_guardar_no_borra_los_demas_ajustes(): void
    {
        $this->company->update(['settings' => ['erp_instance_id' => 7]]);

        TraspasoAUnAsesor::guardar($this->company, ['estrategia' => TraspasoAUnAsesor::BANDEJA]);

        $this->assertSame(7, $this->company->refresh()->settings['erp_instance_id']);
    }

    // ─── Desde la pantalla ───────────────────────────────────────────────────

    /** Se guarda desde «IA que responde», con su permiso de siempre. */
    public function test_se_guarda_desde_la_pantalla(): void
    {
        $agente = $this->agente('Soporte');
        $this->comoAdmin();

        $this->putJson('/api/settings/ai-flow', [
            'traspaso' => ['estrategia' => 'fijo', 'usuario_id' => $agente->id],
        ])->assertOk();

        $guardado = TraspasoAUnAsesor::de($this->company->refresh());

        $this->assertSame('fijo', $guardado['estrategia']);
        $this->assertSame($agente->id, $guardado['usuario_id']);
    }

    /**
     * Y no se puede poner de destino a alguien de otra empresa.
     *
     * Aquí el aislamiento es manual: sin la comprobación, el desplegable se
     * puede falsear desde el navegador y los chats de una empresa acabarían en
     * la bandeja de un asesor de otra.
     *
     * @test
     */
    public function no_se_puede_guardar_un_asesor_de_otra_empresa(): void
    {
        $this->comoAdmin();

        $otra = Company::create([
            'name' => 'Otra ISP', 'slug' => 'otra-isp-2', 'active' => true, 'plan' => 'basico',
        ]);
        $ajeno = $this->agente('Ajeno', $otra);

        $this->putJson('/api/settings/ai-flow', [
            'traspaso' => ['estrategia' => 'fijo', 'usuario_id' => $ajeno->id],
        ])->assertStatus(422);
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function comoAdmin(): User
    {
        $admin = $this->agente('Admin de la pantalla');

        setPermissionsTeamId($this->company->id);

        $rol = Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web',
        ]);
        $rol->givePermissionTo(Permission::firstOrCreate([
            'name' => 'whatsapp_menus.update', 'guard_name' => 'web',
        ]));
        $admin->assignRole($rol);

        // El apartado va detrás de un secreto del equipo; esto es lo que
        // comprueba `Company::aiFlowUnlocked()`.
        config(['services.ai_activation.secret' => 'abre-sesamo']);
        $this->company->update(['ai_flow_unlocked_at' => now()]);

        $this->actingAs($admin);

        return $admin;
    }

    private function agente(string $nombre, ?Company $company = null): User
    {
        $company ??= $this->company;

        $user = User::create([
            'company_id' => $company->id,
            'name' => $nombre,
            'email' => Str::slug($nombre).'@fibra.test',
            'password' => 'secret',
            'active' => true,
        ]);

        // Los roles de Spatie van por equipos, y el equipo es la empresa: sin
        // fijarlo, `hasRole()` no encuentra nada y el reparto se queda vacío.
        setPermissionsTeamId($company->id);

        $role = Role::firstOrCreate([
            'name' => 'agent', 'company_id' => $company->id, 'guard_name' => 'web',
        ]);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'conversations.view', 'guard_name' => 'web',
        ]));
        $user->assignRole($role);

        return $user;
    }

    private function conversacionesAbiertas(User $agente, int $cuantas): void
    {
        $instancia = Instance::firstOrCreate(
            ['company_id' => $agente->company_id, 'phone_number_id' => '1177962515404155'],
            [
                'uuid' => (string) Str::uuid(), 'name' => 'Línea',
                'waba_id' => '1022301494026392', 'type' => 'meta',
                'access_token' => 'token', 'active' => true,
            ]
        );

        foreach (range(1, $cuantas) as $i) {
            WhatsAppConversation::create([
                'instance_id' => $instancia->id,
                'wa_id' => '5730078520'.$agente->id.$i,
                'phone_number' => '5730078520'.$agente->id.$i,
                'status' => 'open',
                'assigned_to' => $agente->id,
                'last_message_at' => now(),
            ]);
        }
    }
}
