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
use App\Models\WebhookEndpoint;
use App\Services\WhatsAppAiClient;
use App\Services\WhatsAppMenuService;
use App\Support\DefaultAiMenusIntegration;
use App\Support\AiDecision;
use App\Support\MenuActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        // El chat IA es otro proceso y recoge lo que ésta deja pasar. Aquí se
        // prueba la IA de menús sola, así que se apaga: si no, los casos de
        // "apagada no atiende nada" verían actuar al otro y no a ésta.
        config(['services.ai_chat.webhook_url' => null, 'services.ai_chat.api_key' => null]);
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

    private function ask(array $respuesta, int $status = 200): ?AiDecision
    {
        Http::fake(['n8n.example.test/*' => Http::response($respuesta, $status)]);
        $this->turnAiOn();

        return app(WhatsAppAiClient::class)->ask($this->instance, $this->conversation, 'cuanto debo');
    }

    public function test_una_respuesta_cerrada_se_traduce_a_reply(): void
    {
        $d = $this->ask(['handled' => true, 'text' => 'Debes $89.500', 'step' => null, 'handoff' => false]);

        $this->assertInstanceOf(MenuActionResult::class, $d->result);
        $this->assertSame('Debes $89.500', $d->result->text);
        $this->assertFalse($d->result->keepsWaiting());
        $this->assertFalse($d->result->handoff);
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

        $this->assertTrue($d->result->keepsWaiting());
        $this->assertSame('awaiting_identification', $d->result->step);
        // Sin esta marca, la respuesta del cliente acabaría en el servicio de
        // acciones del menú, que contestaría con silencio.
        $this->assertSame(WhatsAppBotFlow::ACTION_AI, $d->result->context['action']);
    }

    public function test_una_derivacion_se_traduce_a_escalate_con_su_nota(): void
    {
        $d = $this->ask([
            'handled' => true,
            'text' => 'Te paso con un asesor',
            'handoff' => true,
            'nota_asesor' => 'La IA derivó: quiere cambiar de plan',
        ]);

        $this->assertTrue($d->result->handoff);
        $this->assertStringContainsString('cambiar de plan', $d->note);
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

        $this->assertFalse($d->result->keepsWaiting());
    }

    public function test_sin_texto_y_sin_handoff_no_produce_decision(): void
    {
        $this->assertNull($this->ask(['handled' => true, 'text' => '   ', 'handoff' => false]));
    }

    public function test_sin_texto_pero_con_handoff_deriva_igual(): void
    {
        // El chat tiene que llegar a una persona aunque no haya nada que decir.
        $d = $this->ask(['handled' => true, 'text' => '', 'handoff' => true]);

        $this->assertTrue($d->result->handoff);
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

    public function test_el_payload_lleva_el_contexto_pero_ningun_ajuste_tecnico(): void
    {
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => false])]);
        $this->turnAiOn();

        app(WhatsAppAiClient::class)->ask($this->instance, $this->conversation, 'cuanto debo');

        Http::assertSent(function ($request) {
            $b = $request->data();

            return $b['ia']['habilitada'] === true
                // El modelo y el servidor de Ollama son de la plataforma y viven
                // en el flujo: mandarlos desde aquí sería mantener los mismos
                // valores en dos sitios. Los permisos NO, esos son de la empresa.
                && ! array_key_exists('ollama', $b)
                && ! array_key_exists('modelo', $b['ia'])
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
        Queue::assertPushed(ProcessWhatsAppMenu::class, fn ($job) => $job->ai !== null
            && $job->ai->result->text === 'Debes $89.500');
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

    // ------------------------------------------------------------------
    // El presupuesto de la cola
    //
    // Sin nada de esto declarado mandaba el `--timeout=90` del worker, la mitad
    // de lo que el propio flujo se permite tardar: el worker mataba el job a
    // mitad de inferencia y la cola lo liberaba para otro. Hasta tres
    // inferencias por mensaje y, en la carrera, dos respuestas al cliente.
    // ------------------------------------------------------------------

    public function test_el_job_de_ia_se_da_mas_tiempo_que_el_modelo(): void
    {
        $job = new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'hola');

        $this->assertGreaterThan(
            (int) config('services.ai_menus.timeout'),
            $job->timeout,
            'El job tiene que sobrevivir a la espera completa del modelo.'
        );
    }

    public function test_el_job_de_ia_no_reintenta(): void
    {
        // El flujo crea radicados y pide cobros: un segundo intento no repite
        // una lectura, repite esos efectos.
        $this->assertSame(1, (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'hola'))->tries);
    }

    public function test_ningun_job_dura_mas_que_el_retry_after_de_la_cola(): void
    {
        // La regla que estaba rota: por debajo de esto la cola le entrega a un
        // segundo worker un job que el primero sigue ejecutando.
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $jobs = [
            new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'hola'),
            new ProcessWhatsAppMenu($this->instance->id, $this->conversation->id, null, null, ''),
            new \App\Jobs\DeliverWhatsAppMessage(1),
            new \App\Jobs\SendCampaignMessage(1),
            new \App\Jobs\DeliverWebhook(1, 'x', []),
        ];

        foreach ($jobs as $job) {
            $this->assertLessThan(
                $retryAfter,
                $job->timeout,
                class_basename($job) . ' dura más que el retry_after de la cola: se ejecutaría dos veces.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Un cliente, una inferencia
    // ------------------------------------------------------------------

    public function test_se_encola_con_retardo_para_que_el_cliente_termine_de_escribir(): void
    {
        Queue::fake();
        $this->turnAiOn();

        app(WhatsAppMenuService::class)->handleInbound(
            $this->instance, $this->conversation, $this->inbound('hola'), 'wamid.1'
        );

        Queue::assertPushed(ProcessWhatsAppAi::class, fn ($job) => $job->delay !== null);
    }

    public function test_no_se_lanza_una_segunda_inferencia_mientras_hay_una_en_curso(): void
    {
        Queue::fake();
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => true, 'text' => 'Listo'])]);
        $this->turnAiOn();

        // El candado que tendría el job que ya está preguntándole al modelo.
        $enCurso = Cache::lock('whatsapp-ai:conversation:' . $this->conversation->id, 300);
        $this->assertTrue($enCurso->get());

        try {
            (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'y desde ayer'))
                ->handle(app(WhatsAppAiClient::class));
        } finally {
            $enCurso->release();
        }

        // Ni una segunda inferencia ni una segunda respuesta al cliente.
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_los_mensajes_seguidos_del_cliente_se_juntan_en_una_sola_pregunta(): void
    {
        Queue::fake();
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => false])]);
        $this->turnAiOn();

        // "hola" ya está en setUp; el cliente sigue escribiendo.
        foreach (['no tengo internet', 'desde ayer'] as $texto) {
            WhatsAppMessage::create([
                'conversation_id' => $this->conversation->id,
                'type' => 'text',
                'content' => $texto,
                'direction' => 'inbound',
                'status' => 'delivered',
                'sent_at' => now(),
            ]);
        }

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'hola'))
            ->handle(app(WhatsAppAiClient::class));

        Http::assertSent(function ($request) {
            $mensaje = $request->data()['mensaje'];

            // Con sólo "hola", la IA contestaría un saludo e ignoraría la avería.
            return str_contains($mensaje, 'hola')
                && str_contains($mensaje, 'no tengo internet')
                && str_contains($mensaje, 'desde ayer');
        });
    }

    public function test_lo_que_ya_se_contesto_no_vuelve_a_la_pregunta(): void
    {
        Queue::fake();
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => false])]);
        $this->turnAiOn();

        WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id,
            'type' => 'text',
            'content' => 'Hola, ¿en qué te ayudo?',
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id,
            'type' => 'text',
            'content' => 'no tengo internet',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'no tengo internet'))
            ->handle(app(WhatsAppAiClient::class));

        Http::assertSent(fn ($request) => $request->data()['mensaje'] === 'no tengo internet');
    }

    public function test_si_el_cliente_escribe_durante_la_inferencia_se_reencola(): void
    {
        Queue::fake();
        $this->turnAiOn();

        // El flujo tarda, y mientras tanto llega otro mensaje. Sin el relevo se
        // quedaría sin contestar: el candado lo descartó.
        Http::fake(['n8n.example.test/*' => function () {
            WhatsAppMessage::create([
                'conversation_id' => $this->conversation->id,
                'type' => 'text',
                'content' => 'y la tele tampoco',
                'direction' => 'inbound',
                'status' => 'delivered',
                'sent_at' => now(),
            ]);

            return Http::response(['handled' => true, 'text' => 'Reviso tu internet']);
        }]);

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'no tengo internet'))
            ->handle(app(WhatsAppAiClient::class));

        Queue::assertPushed(ProcessWhatsAppAi::class, fn ($job) => $job->message === 'y la tele tampoco'
            && $job->afterMessageId !== null);
    }

    // ------------------------------------------------------------------
    // El evento de negocio
    // ------------------------------------------------------------------

    public function test_no_se_contesta_dos_veces_el_mismo_mensaje(): void
    {
        Queue::fake();
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => true, 'text' => 'Lo reviso'])]);
        $this->turnAiOn();

        // El relevo y el job del webhook pueden apuntar al mismo mensaje. Si el
        // primero ya contestó, el segundo no tiene nada que preguntar.
        WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id,
            'type' => 'text',
            'content' => 'Lo reviso',
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'hola'))
            ->handle(app(WhatsAppAiClient::class));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_el_evento_del_flujo_llega_a_la_decision(): void
    {
        $d = $this->ask([
            'handled' => true,
            'text' => 'Aquí tienes tu enlace de pago',
            'evento' => 'payment.requested',
            'datos_evento' => ['total_por_pagar' => 89500],
        ]);

        $this->assertSame('payment.requested', $d->event);
        $this->assertSame(89500, $d->eventData['total_por_pagar']);
    }

    public function test_un_evento_desconocido_se_descarta(): void
    {
        // Al otro lado hay un flujo que alguien puede editar: un evento libre
        // dispararía webhooks que la empresa no configuró.
        $d = $this->ask([
            'handled' => true,
            'text' => 'Listo',
            'evento' => 'algo.inventado',
            'datos_evento' => ['x' => 1],
        ]);

        $this->assertNull($d->event);
        $this->assertSame([], $d->eventData);
    }

    public function test_la_ia_avisa_a_los_sistemas_de_la_empresa_igual_que_el_menu(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        WebhookEndpoint::create([
            'company_id' => $this->company->id,
            'name' => 'ERP',
            'url' => 'https://erp.example.test/hook',
            'events' => ['payment.requested'],
            'active' => true,
        ]);

        $this->runAiJob(new AiDecision(
            MenuActionResult::reply('Aquí tienes tu enlace de pago'),
            null,
            ['intencion' => 'pagar'],
            'payment.requested',
            ['total_por_pagar' => 89500],
            ['id' => 4412, 'identificacion' => '1094123456', 'nombre' => 'Katherine Pérez']
        ));

        // Sin esto, el mismo cliente generaba cobro por el menú y no por la IA.
        Queue::assertPushed(\App\Jobs\DeliverWebhook::class, fn ($job) => $job->event === 'payment.requested'
            && $job->body['data']['total_por_pagar'] === 89500
            && $job->body['data']['cliente']['identificacion'] === '1094123456');
    }

    public function test_sin_evento_no_se_avisa_a_nadie(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        WebhookEndpoint::create([
            'company_id' => $this->company->id,
            'name' => 'ERP',
            'url' => 'https://erp.example.test/hook',
            'events' => ['payment.requested', 'invoice.queried'],
            'active' => true,
        ]);

        $this->runAiJob(new AiDecision(MenuActionResult::reply('Con gusto 🙌')));

        Queue::assertNotPushed(\App\Jobs\DeliverWebhook::class);
    }

    // ------------------------------------------------------------------
    // Lo que la IA averiguó no se tira
    // ------------------------------------------------------------------

    public function test_la_ia_recuerda_a_quien_identifico(): void
    {
        Queue::fake();
        Http::fake(['n8n.example.test/*' => Http::response([
            'handled' => true,
            'text' => 'Debes $89.500',
            'meta' => ['cliente' => ['id' => 4412, 'identificacion' => '1094123456', 'nombre' => 'Katherine Pérez']],
        ])]);
        $this->turnAiOn();

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'cuanto debo'))
            ->handle(app(WhatsAppAiClient::class));

        // Es el mismo sitio donde lo guarda el menú: así el siguiente mensaje
        // —lo atienda quien lo atienda— no le vuelve a pedir la cédula.
        $integra = $this->conversation->fresh()->metadata['integra'];
        $this->assertSame('1094123456', $integra['identificacion']);
        $this->assertSame(4412, $integra['cliente_id']);
        $this->assertSame('Katherine Pérez', $integra['nombre']);
    }

    public function test_si_la_ia_no_identifico_a_nadie_no_pisa_lo_que_ya_sabiamos(): void
    {
        Queue::fake();
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => true, 'text' => 'Con gusto'])]);
        $this->turnAiOn();

        $this->conversation->update(['metadata' => ['integra' => [
            'cliente_id' => 99, 'identificacion' => '111', 'nombre' => 'Ya conocido',
        ]]]);

        (new ProcessWhatsAppAi($this->instance->id, $this->conversation->id, 'gracias'))
            ->handle(app(WhatsAppAiClient::class));

        $this->assertSame('111', $this->conversation->fresh()->metadata['integra']['identificacion']);
    }

    public function test_la_burbuja_guarda_por_que_la_ia_contesto_eso(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        $this->runAiJob(new AiDecision(
            MenuActionResult::reply('Debes $89.500'),
            null,
            [
                'intencion' => 'consultar_facturas',
                'confianza' => 0.92,
                'modelo' => 'gpt-oss:120b-cloud',
                'turno' => 2,
                'uso' => ['planificador' => ['ms' => 4200]],
            ]
        ));

        $burbuja = WhatsAppMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->firstOrFail();

        // En el log esto se perdía: no había forma de auditar después.
        $this->assertSame(WhatsAppBotFlow::ACTION_AI, $burbuja->metadata['action_type']);
        $this->assertSame('consultar_facturas', $burbuja->metadata['ia']['intencion']);
        $this->assertSame(0.92, $burbuja->metadata['ia']['confianza']);
        $this->assertSame('gpt-oss:120b-cloud', $burbuja->metadata['ia']['modelo']);
        $this->assertSame(4200, $burbuja->metadata['ia']['ms']);
    }

    /** Ejecuta el job que envía, con la decisión que la IA ya tomó. */
    private function runAiJob(AiDecision $decision): void
    {
        $job = new ProcessWhatsAppMenu(
            $this->instance->id, $this->conversation->id, null, null, '', null, $decision
        );

        app()->call([$job, 'handle']);
    }

    // ------------------------------------------------------------------
    // Permisos por empresa
    // ------------------------------------------------------------------

    public function test_nace_solo_con_lectura(): void
    {
        // Encender el interruptor no puede ser lo mismo que autorizar radicados
        // y cobros: hasta ahora los permisos vivían en el flujo, iguales para
        // toda la plataforma, y encenderlo concedía las tres cosas de golpe.
        $this->assertSame([CompanyIntegration::AI_READ], $this->integration()->aiPermissions());
    }

    public function test_los_permisos_de_la_empresa_viajan_al_flujo(): void
    {
        Http::fake(['n8n.example.test/*' => Http::response(['handled' => false])]);
        $this->turnAiOn()->update(['abilities' => [
            CompanyIntegration::AI_READ,
            CompanyIntegration::AI_TICKETS,
        ]]);

        app(WhatsAppAiClient::class)->ask($this->instance, $this->conversation, 'cuanto debo');

        Http::assertSent(fn ($request) => $request->data()['ia']['permisos'] === [
            'leer' => true, 'radicados' => true, 'pagos' => false,
        ]);
    }

    public function test_un_permiso_que_no_existe_no_se_guarda(): void
    {
        $i = $this->turnAiOn();
        $i->update(['abilities' => ['leer', 'borrar_la_base_de_datos']]);

        $this->assertSame(['leer'], $i->fresh()->aiPermissions());
    }

    public function test_la_migracion_no_le_quita_funciones_a_quien_ya_usaba_la_ia(): void
    {
        // Una empresa con la IA encendida ya tiene radicados y pagos de hecho
        // (venían del flujo). Quitárselos en un despliegue sería apagarle
        // funciones en silencio a alguien que las está usando.
        $encendida = $this->turnAiOn();
        $encendida->update(['abilities' => null]);

        $otra = Company::create(['name' => 'Fibra ABC', 'slug' => 'fibra-abc', 'active' => true]);
        $apagada = CompanyIntegration::where('company_id', $otra->id)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->firstOrFail();
        $apagada->update(['abilities' => null]);

        (require base_path('database/migrations/2026_09_05_120000_add_ai_permissions_to_company_integrations.php'))->up();

        $this->assertSame(CompanyIntegration::AI_PERMISSIONS, $encendida->fresh()->aiPermissions());
        // La que nunca la usó, en cambio, empieza sólo con lectura.
        $this->assertSame([CompanyIntegration::AI_READ], $apagada->fresh()->aiPermissions());
    }

    public function test_una_fila_anterior_a_los_permisos_conserva_la_lectura(): void
    {
        // `null` es "fila vieja", no "sin permisos": dejarla sin lectura sería
        // apagarle la IA a alguien por una migración a medias.
        $i = $this->turnAiOn();
        $i->update(['abilities' => null]);

        $this->assertSame([CompanyIntegration::AI_READ], $i->fresh()->aiPermissions());
    }
}
