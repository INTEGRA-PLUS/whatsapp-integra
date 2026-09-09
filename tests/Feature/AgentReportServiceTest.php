<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\AgentReportService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El informe de agentes: cuánto responde cada uno y cuánto tarda.
 *
 * El servicio recorre los mensajes conversación a conversación para emparejar
 * cada pregunta del cliente con la respuesta que la cerró. Estas pruebas fijan
 * ese cálculo —y el aislamiento entre empresas, que aquí no lo da ningún scope
 * global sino el `join` con las instancias de la empresa.
 */
class AgentReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private AgentReportService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servicio = app(AgentReportService::class);
    }

    public function test_cuenta_entrantes_salientes_y_el_tiempo_de_respuesta(): void
    {
        [$company, $instance] = $this->empresaConLinea();
        $agente = $this->agente($company, 'Ana');

        $conv = $this->conversacion($instance);

        // El cliente pregunta y Ana contesta dos minutos después.
        $this->mensaje($conv, 'inbound', null, now()->subMinutes(10));
        $this->mensaje($conv, 'outbound', $agente->id, now()->subMinutes(8));

        $informe = $this->servicio->build($company->id, now()->subDay(), now()->addDay());

        $this->assertSame(1, $informe['totals']['inbound']);
        $this->assertSame(1, $informe['totals']['outbound']);
        $this->assertSame(120, $informe['totals']['avg_response_seconds']);
        $this->assertSame(1, $informe['totals']['response_count']);

        $fila = $informe['agents'][0];
        $this->assertSame('Ana', $fila['name']);
        $this->assertSame(1, $fila['messages_sent']);
        $this->assertSame(1, $fila['conversations_handled']);
        $this->assertSame(120, $fila['avg_response_seconds']);
    }

    /**
     * Varias preguntas seguidas y una sola respuesta cuentan como un tiempo de
     * respuesta, medido desde la primera: es cuando el cliente empezó a esperar.
     */
    public function test_una_rafaga_del_cliente_se_mide_desde_el_primer_mensaje(): void
    {
        [$company, $instance] = $this->empresaConLinea();
        $agente = $this->agente($company, 'Ana');
        $conv = $this->conversacion($instance);

        $this->mensaje($conv, 'inbound', null, now()->subMinutes(10));
        $this->mensaje($conv, 'inbound', null, now()->subMinutes(9));
        $this->mensaje($conv, 'inbound', null, now()->subMinutes(8));
        $this->mensaje($conv, 'outbound', $agente->id, now()->subMinutes(5));

        $informe = $this->servicio->build($company->id, now()->subDay(), now()->addDay());

        $this->assertSame(3, $informe['totals']['inbound']);
        $this->assertSame(1, $informe['totals']['response_count']);
        $this->assertSame(300, $informe['totals']['avg_response_seconds']);
    }

    public function test_una_conversacion_abierta_sin_responder_se_le_carga_a_su_agente(): void
    {
        [$company, $instance] = $this->empresaConLinea();
        $agente = $this->agente($company, 'Ana');

        $asignada = $this->conversacion($instance, ['assigned_to' => $agente->id]);
        $this->mensaje($asignada, 'outbound', $agente->id, now()->subMinutes(20));
        $this->mensaje($asignada, 'inbound', null, now()->subMinutes(2));

        $huerfana = $this->conversacion($instance);
        $this->mensaje($huerfana, 'inbound', null, now()->subMinutes(3));

        $informe = $this->servicio->build($company->id, now()->subDay(), now()->addDay());

        $this->assertSame(1, $informe['totals']['unanswered_unassigned']);
        $this->assertSame(2, $informe['totals']['open_conversations']);

        $ana = collect($informe['agents'])->firstWhere('name', 'Ana');
        $this->assertSame(1, $ana['unanswered_count']);
    }

    /**
     * El aislamiento entre empresas no lo da ningún scope global: lo da el
     * `join` con las instancias. Si alguien lo quita, esta prueba lo dice.
     */
    public function test_el_informe_no_ve_los_mensajes_de_otra_empresa(): void
    {
        [$company, $instance] = $this->empresaConLinea();
        $agente = $this->agente($company, 'Ana');

        [$otra, $suLinea] = $this->empresaConLinea();
        $suAgente = $this->agente($otra, 'Ajena');

        $mio = $this->conversacion($instance);
        $this->mensaje($mio, 'inbound', null, now()->subMinutes(10));
        $this->mensaje($mio, 'outbound', $agente->id, now()->subMinutes(9));

        $ajeno = $this->conversacion($suLinea);
        $this->mensaje($ajeno, 'inbound', null, now()->subMinutes(10));
        $this->mensaje($ajeno, 'outbound', $suAgente->id, now()->subMinutes(9));

        $informe = $this->servicio->build($company->id, now()->subDay(), now()->addDay());

        $this->assertSame(1, $informe['totals']['inbound'], 'Se colaron mensajes de otra empresa en el informe.');
        $this->assertSame(1, $informe['totals']['outbound']);
        $this->assertCount(1, $informe['agents']);
        $this->assertSame('Ana', $informe['agents'][0]['name']);
    }

    public function test_las_notas_internas_no_cuentan_como_respuesta(): void
    {
        [$company, $instance] = $this->empresaConLinea();
        $agente = $this->agente($company, 'Ana');
        $conv = $this->conversacion($instance);

        $this->mensaje($conv, 'inbound', null, now()->subMinutes(10));
        $this->mensaje($conv, 'outbound', $agente->id, now()->subMinutes(9), true);

        $informe = $this->servicio->build($company->id, now()->subDay(), now()->addDay());

        $this->assertSame(0, $informe['totals']['outbound'], 'Una nota interna se contó como mensaje al cliente.');
        $this->assertSame(0, $informe['totals']['response_count']);
    }

    public function test_el_detalle_de_un_agente_cuadra_con_el_resumen(): void
    {
        [$company, $instance] = $this->empresaConLinea();
        $ana = $this->agente($company, 'Ana');
        $beto = $this->agente($company, 'Beto');

        $conv = $this->conversacion($instance);
        $this->mensaje($conv, 'inbound', null, now()->subMinutes(10));
        $this->mensaje($conv, 'outbound', $ana->id, now()->subMinutes(8));

        $otra = $this->conversacion($instance);
        $this->mensaje($otra, 'inbound', null, now()->subMinutes(6));
        $this->mensaje($otra, 'outbound', $beto->id, now()->subMinutes(5));

        $detalle = $this->servicio->buildForAgent($company->id, $ana->id, now()->subDay(), now()->addDay());

        $this->assertSame(1, $detalle['totals']['messages_sent']);
        $this->assertSame(1, $detalle['totals']['conversations_handled']);
        $this->assertSame(120, $detalle['totals']['avg_response_seconds']);
        $this->assertCount(1, $detalle['by_conversation']);
        $this->assertSame($conv->id, $detalle['by_conversation'][0]['conversation_id']);
    }

    public function test_una_empresa_sin_lineas_devuelve_el_informe_vacio(): void
    {
        $company = Company::create([
            'name' => 'Sin líneas',
            'slug' => 'sin-'.Str::random(6),
            'active' => true,
        ]);

        $informe = $this->servicio->build($company->id, now()->subDay(), now()->addDay());

        $this->assertSame(0, $informe['totals']['inbound']);
        $this->assertSame([], $informe['agents']);
    }

    /** @return array{0: Company, 1: Instance} */
    private function empresaConLinea(): array
    {
        $company = Company::create([
            'name' => 'Cooperativa',
            'slug' => 'coop-'.Str::random(8),
            'active' => true,
        ]);

        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '117796'.Str::random(8),
            'waba_id' => 'waba-'.Str::random(4),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        return [$company, $instance];
    }

    private function agente(Company $company, string $nombre): User
    {
        return User::create([
            'name' => $nombre,
            'email' => Str::random(10).'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'active' => true,
        ]);
    }

    private function conversacion(Instance $instance, array $extra = []): WhatsAppConversation
    {
        $numero = '5730'.random_int(10000000, 99999999);

        return WhatsAppConversation::create(array_merge([
            'instance_id' => $instance->id,
            'wa_id' => $numero,
            'phone_number' => $numero,
            'name' => 'Socio',
            'status' => 'open',
            'last_message_at' => now(),
        ], $extra));
    }

    private function mensaje(
        WhatsAppConversation $conv,
        string $direccion,
        ?int $agenteId,
        CarbonInterface $cuando,
        bool $interno = false
    ): WhatsAppMessage {
        return WhatsAppMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.Str::random(10),
            'type' => 'text',
            'content' => 'texto',
            'direction' => $direccion,
            'is_internal' => $interno,
            'status' => 'delivered',
            'sent_by' => $agenteId,
            'sent_at' => $cuando,
        ]);
    }
}
