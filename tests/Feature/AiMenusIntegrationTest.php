<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppAi;
use App\Jobs\ProcessWhatsAppMenu;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppAiClient;
use App\Services\WhatsAppMenuService;
use App\Support\DefaultAiMenusIntegration;
use App\Support\MenuActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiMenusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Instance $instance;
    private WhatsAppConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'access_token' => 'token-meta',
            'active' => true,
        ]);

        $this->conversation = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Katherine',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        // El mensaje del cliente que abre la ventana de 24 h. Sin él,
        // isWindowOpen() es false y ningún job del bot llega a enviar nada.
        WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id,
            'wamid' => 'wamid.IN1',
            'type' => 'text',
            'content' => 'hola',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        config(['services.ai_menus.webhook_url' => 'https://n8n.example.test/webhook/whatsapp-menu-ia']);
    }

    private function integration(): CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $this->company->id)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->firstOrFail();
    }

    /** El interruptor: lo único que la empresa decide sobre la IA. */
    private function turnAiOn(): CompanyIntegration
    {
        $integration = $this->integration();
        $integration->update(['enabled' => true]);

        return $integration->fresh();
    }

    private function inbound(string $text): array
    {
        return ['content' => $text, 'metadata' => []];
    }

    // ------------------------------------------------------------------
    // Registro automático
    // ------------------------------------------------------------------

    public function test_una_empresa_nueva_nace_con_la_tarjeta_de_ia(): void
    {
        // El observer la creó al hacer Company::create en setUp.
        $this->assertDatabaseHas('company_integrations', [
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_AI_MENUS,
        ]);
    }

    public function test_nace_apagada(): void
    {
        $i = $this->integration();

        $this->assertFalse($i->enabled);
        $this->assertFalse($i->aiReady());
    }

    public function test_sembrarla_dos_veces_no_duplica_nada(): void
    {
        DefaultAiMenusIntegration::createFor($this->company);
        DefaultAiMenusIntegration::createFor($this->company);

        $this->assertSame(1, CompanyIntegration::where('company_id', $this->company->id)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->count());
    }

    public function test_no_le_apaga_la_ia_a_quien_ya_la_encendio(): void
    {
        $this->turnAiOn();

        $this->assertNull(DefaultAiMenusIntegration::createFor($this->company));
        $this->assertTrue($this->integration()->enabled);
    }

    // ------------------------------------------------------------------
    // Cuándo se hace cargo
    // ------------------------------------------------------------------

    public function test_apagada_no_atiende_nada(): void
    {
        Queue::fake();

        $handled = app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation, $this->inbound('no me funciona el internet'), 'wamid.1'
        );

        $this->assertFalse($handled);
        Queue::assertNothingPushed();
    }

    public function test_encendida_atiende_el_mensaje_que_ningun_menu_reconoce(): void
    {
        Queue::fake();
        $this->turnAiOn();

        $handled = app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation, $this->inbound('no me funciona el internet desde ayer'), 'wamid.1'
        );

        $this->assertTrue($handled);
        Queue::assertPushed(ProcessWhatsAppAi::class, fn ($job) => $job->message === 'no me funciona el internet desde ayer'
            && $job->isFlowAnswer === false);
    }

    public function test_sin_url_del_flujo_no_atiende(): void
    {
        Queue::fake();
        $this->turnAiOn();
        config(['services.ai_menus.webhook_url' => null]);

        $this->assertFalse(app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation, $this->inbound('hola que tal'), 'wamid.1'
        ));
        Queue::assertNothingPushed();
    }

    public function test_no_atiende_si_un_agente_ya_tiene_el_chat(): void
    {
        Queue::fake();
        $this->turnAiOn();

        $agent = User::create([
            'company_id' => $this->company->id,
            'name' => 'Asesora',
            'email' => 'asesora@fibra.test',
            'password' => 'secret',
            'active' => true,
        ]);
        $this->conversation->update(['assigned_to' => $agent->id]);

        $this->assertFalse(app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation->fresh(), $this->inbound('hola'), 'wamid.1'
        ));
        Queue::assertNothingPushed();
    }

    public function test_un_audio_o_una_imagen_no_van_a_la_ia(): void
    {
        Queue::fake();
        $this->turnAiOn();

        // Sin texto no hay nada que el modelo pueda entender: mejor que lo
        // atienda la respuesta automática o una persona.
        $this->assertFalse(app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation, ['content' => '', 'metadata' => []], 'wamid.1'
        ));
        Queue::assertNothingPushed();
    }

    public function test_la_respuesta_a_una_pregunta_de_la_ia_vuelve_a_la_ia(): void
    {
        Queue::fake();
        $this->turnAiOn();

        WhatsAppBotFlow::open(
            $this->conversation->id, null, WhatsAppBotFlow::ACTION_AI,
            WhatsAppBotFlow::STEP_IDENTIFICATION, ['action' => WhatsAppBotFlow::ACTION_AI]
        );

        $this->assertTrue(app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation, $this->inbound('1094123456'), 'wamid.2'
        ));

        Queue::assertPushed(ProcessWhatsAppAi::class, fn ($job) => $job->isFlowAnswer === true
            && $job->message === '1094123456');
        // No debe irse por el camino del menú, que no sabría retomarlo.
        Queue::assertNotPushed(ProcessWhatsAppMenu::class);
    }

    public function test_la_respuesta_a_una_pregunta_del_menu_sigue_yendo_al_menu(): void
    {
        Queue::fake();
        $this->turnAiOn();

        WhatsAppBotFlow::open(
            $this->conversation->id, null, 'reportar_falla',
            WhatsAppBotFlow::STEP_REPORT, ['action' => 'reportar_falla']
        );

        $this->assertTrue(app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation, $this->inbound('sin internet desde anoche'), 'wamid.2'
        ));

        Queue::assertPushed(ProcessWhatsAppMenu::class);
        Queue::assertNotPushed(ProcessWhatsAppAi::class);
    }

    // ------------------------------------------------------------------
    // Traducción de la respuesta del flujo
    // ------------------------------------------------------------------

    private function ask(array $respuesta, int $status = 200): ?array
    {
        Http::fake(['n8n.example.test/*' => Http::response($respuesta, $status)]);
        $this->turnAiOn();

        return app(WhatsAppAiClient::class)->ask($this->instance, $this->conversation, 'cuanto debo');
    }

    public function test_una_respuesta_cerrada_se_traduce_a_reply(): void
    {
        $d = $this->ask(['handled' => true, 'text' => 'Debes $89.500', 'step' => null, 'handoff' => false]);

        $this->assertInstanceOf(MenuActionResult::class, $d['result']);
        $this->assertSame('Debes $89.500', $d['result']->text);
        $this->assertFalse($d['result']->keepsWaiting());
        $this->assertFalse($d['result']->handoff);
    }

    public function test_una_pregunta_se_traduce_a_ask_y_queda_marcada_como_de_la_ia(): void
    {
        $d = $this->ask([
            'handled' => true,
            'text' => 'Dime tu documento',
            'step' => 'awaiting_identification',
            'context' => ['attempts' => 1],
            'handoff' => false,
        ]);

        $this->assertTrue($d['result']->keepsWaiting());
        $this->assertSame('awaiting_identification', $d['result']->step);
        // Sin esta marca, la respuesta del cliente acabaría en el servicio de
        // acciones del menú, que contestaría con silencio.
        $this->assertSame(WhatsAppBotFlow::ACTION_AI, $d['result']->context['action']);
    }

    public function test_una_derivacion_se_traduce_a_escalate_con_su_nota(): void
    {
        $d = $this->ask([
            'handled' => true,
            'text' => 'Te paso con un asesor',
            'handoff' => true,
            'nota_asesor' => 'La IA derivó: quiere cambiar de plan',
        ]);

        $this->assertTrue($d['result']->handoff);
        $this->assertStringContainsString('cambiar de plan', $d['note']);
    }

    public function test_handled_false_no_produce_decision(): void
    {
        $this->assertNull($this->ask(['handled' => false, 'meta' => ['motivo' => ['sin turnos']]]));
    }

    public function test_un_paso_desconocido_se_degrada_a_respuesta_cerrada(): void
    {
        // El flujo lo puede editar alguien sin tocar este código: un paso que
        // no exista dejaría la conversación esperando algo que nadie retoma.
        $d = $this->ask(['handled' => true, 'text' => 'Listo', 'step' => 'awaiting_lo_que_sea']);

        $this->assertFalse($d['result']->keepsWaiting());
    }

    public function test_sin_texto_y_sin_handoff_no_produce_decision(): void
    {
        $this->assertNull($this->ask(['handled' => true, 'text' => '   ', 'handoff' => false]));
    }

    public function test_sin_texto_pero_con_handoff_deriva_igual(): void
    {
        // El chat tiene que llegar a una persona aunque no haya nada que decir.
        $d = $this->ask(['handled' => true, 'text' => '', 'handoff' => true]);

        $this->assertTrue($d['result']->handoff);
    }

    public function test_si_el_flujo_falla_no_produce_decision(): void
    {
        $this->assertNull($this->ask(['message' => 'boom'], 500));
    }

    public function test_si_el_flujo_no_responde_no_produce_decision(): void
    {
        Http::fake(['n8n.example.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->turnAiOn();

        $this->assertNull(app(WhatsAppAiClient::class)->ask($this->instance, $this->conversation, 'cuanto debo'));
    }

    // ------------------------------------------------------------------
    // Lo que se le manda al flujo
    // ------------------------------------------------------------------

    public function test_el_payload_lleva_el_contexto_pero_ningun_ajuste(): void
    {
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => false])]);
        $this->turnAiOn();

        app(WhatsAppAiClient::class)->ask($this->instance, $this->conversation, 'cuanto debo');

        Http::assertSent(function ($request) {
            $b = $request->data();

            return $b['ia'] === ['habilitada' => true]
                // El modelo, el servidor y los permisos son de la plataforma y
                // viven en el flujo: mandarlos desde aquí sería mantener los
                // mismos valores en dos sitios.
                && ! array_key_exists('ollama', $b)
                && $b['mensaje'] === 'cuanto debo'
                && $b['conversacion']['id'] === $this->conversation->id
                && $b['empresa']['id'] === $this->company->id;
        });
    }

    public function test_el_turno_cuenta_las_respuestas_previas_de_la_ia(): void
    {
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => false])]);
        $this->turnAiOn();

        foreach ([1, 2] as $n) {
            WhatsAppMessage::create([
                'conversation_id' => $this->conversation->id,
                'type' => 'text',
                'content' => 'respuesta ' . $n,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => ['action_type' => WhatsAppBotFlow::ACTION_AI],
            ]);
        }

        app(WhatsAppAiClient::class)->ask($this->instance, $this->conversation, 'y ahora?');

        Http::assertSent(fn ($request) => $request->data()['conversacion']['turno'] === 3);
    }

    // ------------------------------------------------------------------
    // Ejecución
    // ------------------------------------------------------------------

    public function test_el_job_de_ia_delega_la_ejecucion_en_el_job_del_menu(): void
    {
        Queue::fake();
        Http::fake(['n8n.example.test/*' => Http::response([
            'handled' => true, 'text' => 'Debes $89.500', 'handoff' => false,
        ])]);
        $this->turnAiOn();

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'cuanto debo'))
            ->handle(app(WhatsAppAiClient::class));

        // El envío no lo hace la IA: lo hace el único job que habla con Meta.
        Queue::assertPushed(ProcessWhatsAppMenu::class, fn ($job) => $job->aiResult !== null
            && $job->aiResult->text === 'Debes $89.500');
    }

    public function test_el_job_de_ia_no_hace_nada_si_un_agente_tomo_el_chat(): void
    {
        Queue::fake();
        Http::fake();
        $this->turnAiOn();

        $agent = User::create([
            'company_id' => $this->company->id,
            'name' => 'Asesora',
            'email' => 'asesora@fibra.test',
            'password' => 'secret',
            'active' => true,
        ]);
        $this->conversation->update(['assigned_to' => $agent->id]);

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'cuanto debo'))
            ->handle(app(WhatsAppAiClient::class));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_una_pregunta_caducada_no_se_retoma(): void
    {
        Queue::fake();
        Http::fake();
        $this->turnAiOn();

        // El cliente contesta cuando la pregunta ya expiró: retomarla ahora
        // sería revivir una conversación que él ya dio por perdida.
        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, '1094123456', true))
            ->handle(app(WhatsAppAiClient::class));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
