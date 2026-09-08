<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppAi;
use App\Jobs\ProcessWhatsAppChatAi;
use App\Jobs\ProcessWhatsAppMenu;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppChatAiClient;
use App\Services\WhatsAppMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La IA de los chats: proceso aparte del de los menús.
 *
 * Lo que se prueba aquí es la frontera, que es donde se rompió antes: que se
 * habla SU contrato y no el del flujo de menús, y que la respuesta acaba en el
 * mismo job que ya sabe enviar.
 */
class AiChatIntegrationTest extends TestCase
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

        WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id,
            'wamid' => 'wamid.IN1',
            'type' => 'text',
            'content' => 'no me funciona el internet',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        config([
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'n8n_llave',
        ]);

        // El interruptor de la empresa, que se enciende desde Configuración →
        // Flujo IA. Sin él la plataforma puede estar lista y la IA callada.
        CompanyIntegration::create([
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_AI_CHAT,
            'enabled' => true,
        ]);
    }

    /** @test */
    public function habla_el_contrato_del_gateway_y_no_el_de_los_menus(): void
    {
        Http::fake(['*' => Http::response(['status' => 'done', 'answer' => 'Claro, te ayudo.'], 200)]);

        $decision = (new WhatsAppChatAiClient)->ask(
            $this->instance, $this->conversation, 'no me funciona el internet', 'wamid.IN1'
        );

        $this->assertNotNull($decision);
        $this->assertSame('Claro, te ayudo.', $decision->result->text);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request['message'] === 'no me funciona el internet'
                && $body['user_id'] === '573007852081'
                && $body['tenant_id'] === (string) $this->company->id
                && $body['message_id'] === 'wamid.IN1'
                && $body['channel'] === 'whatsapp'
                // Lo del flujo de menús no puede colarse aquí.
                && ! isset($body['mensaje'])
                && ! isset($body['integra']);
        });
    }

    /** @test */
    public function manda_la_llave_del_gateway(): void
    {
        Http::fake(['*' => Http::response(['answer' => 'hola'], 200)]);

        (new WhatsAppChatAiClient)->ask($this->instance, $this->conversation, 'hola', 'wamid.IN1');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'n8n_llave'));
    }

    /** @test */
    public function sin_llave_no_pregunta(): void
    {
        // El gateway va detrás de Header Auth: preguntar sin llave es un 403
        // garantizado y un mensaje perdido en un log.
        config(['services.ai_chat.api_key' => null]);
        Http::fake();

        $this->assertFalse(WhatsAppChatAiClient::enabledFor($this->company->id));
        $this->assertNull((new WhatsAppChatAiClient)->ask($this->instance, $this->conversation, 'hola', 'wamid.IN1'));

        Http::assertNothingSent();
    }

    /** @test */
    public function si_la_empresa_lo_tiene_apagado_no_pregunta(): void
    {
        // La plataforma está lista, pero esta empresa no lo encendió.
        CompanyIntegration::where('company_id', $this->company->id)
            ->where('key', CompanyIntegration::KEY_AI_CHAT)
            ->update(['enabled' => false]);
        Http::fake();

        $this->assertFalse(WhatsAppChatAiClient::enabledFor($this->company->id));
        $this->assertNull((new WhatsAppChatAiClient)->ask($this->instance, $this->conversation, 'hola', 'wamid.IN1'));

        Http::assertNothingSent();
    }

    /** @test */
    public function un_duplicado_no_se_contesta_dos_veces(): void
    {
        // Meta reintentó el webhook: la respuesta ya la dio la primera entrega.
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'duplicate'], 200)]);

        $this->assertNull((new WhatsAppChatAiClient)->ask($this->instance, $this->conversation, 'hola', 'wamid.IN1'));
    }

    /** @test */
    public function una_respuesta_en_lista_de_un_elemento_tambien_vale(): void
    {
        // n8n puede devolver el item envuelto en una lista segun como responda
        // el nodo; el texto es el mismo.
        Http::fake(['*' => Http::response([['status' => 'done', 'answer' => 'Listo.']], 200)]);

        $decision = (new WhatsAppChatAiClient)->ask($this->instance, $this->conversation, 'hola', 'wamid.IN1');

        $this->assertNotNull($decision);
        $this->assertSame('Listo.', $decision->result->text);
    }

    /** @test */
    public function sin_texto_no_se_contesta(): void
    {
        Http::fake(['*' => Http::response(['status' => 'done', 'answer' => '', 'degraded' => true], 200)]);

        $this->assertNull((new WhatsAppChatAiClient)->ask($this->instance, $this->conversation, 'hola', 'wamid.IN1'));
    }

    /** @test */
    public function si_el_gateway_rechaza_no_se_hace_cargo(): void
    {
        Http::fake(['*' => Http::response('Authorization data is wrong!', 403)]);

        $this->assertNull((new WhatsAppChatAiClient)->ask($this->instance, $this->conversation, 'hola', 'wamid.IN1'));
    }

    /** @test */
    public function la_respuesta_llega_al_job_que_envia(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response([
            'status' => 'done', 'answer' => '¿El módem tiene alguna luz roja?',
            'model' => 'gemma4:31b', 'latency_ms' => 700, 'degraded' => false,
        ], 200)]);

        (new ProcessWhatsAppChatAi(
            $this->instance->id, $this->conversation->id, 'no me funciona', 'wamid.IN1'
        ))->handle(new WhatsAppChatAiClient);

        Queue::assertPushed(ProcessWhatsAppMenu::class, function ($job) {
            return $job->conversationId === $this->conversation->id
                && $job->ai?->result->text === '¿El módem tiene alguna luz roja?'
                && data_get($job->ai->meta, 'modelo') === 'gemma4:31b';
        });
    }







    /** @test */
    public function entiende_la_respuesta_real_del_gateway(): void
    {
        // Copiada tal cual de una llamada de verdad al flujo (2026-09-07). Si
        // alguien cambia el nodo que responde en n8n, este test lo detecta
        // aquí en vez de dejar la IA muda en producción.
        Http::fake(['*' => Http::response([
            'message_id' => 'wamid.IN1',
            'trace_id' => 'wamid.IN1',
            'tenant_id' => '1',
            'user_id' => '573001112233',
            'channel' => 'whatsapp',
            'session_key' => 'chat:1:whatsapp:573001112233',
            'status' => 'done',
            'degraded' => false,
            'model' => 'primary',
            'answer' => 'Claro, te ayudamos con repuestos para la retroexcavadora 416F.',
            'latency_ms' => 7521,
            'finished_at' => '2026-09-08T03:04:14.822Z',
            'callback_url' => null,
        ], 200)]);

        $decision = (new WhatsAppChatAiClient)->ask(
            $this->instance, $this->conversation, 'necesito un repuesto', 'wamid.IN1'
        );

        $this->assertNotNull($decision);
        $this->assertSame('Claro, te ayudamos con repuestos para la retroexcavadora 416F.', $decision->result->text);
        $this->assertFalse($decision->result->handoff);
        $this->assertSame(7521, data_get($decision->meta, 'uso.planificador.ms'));
    }

    // ------------------------------------------------------------------
    // Coexistencia con la IA de los menús
    // ------------------------------------------------------------------

    /** @test */
    public function si_la_ia_de_menus_no_esta_encendida_atiende_el_chat_ia(): void
    {
        // Son dos funcionalidades distintas: una empresa puede tener la de
        // chats sin tener la de menús.
        config(['services.ai_menus.webhook_url' => null]);
        Queue::fake();

        $handled = app(WhatsAppMenuService::class)->handleInbound(
            $this->instance,
            $this->conversation,
            ['content' => 'buenas, una pregunta', 'metadata' => []],
            'wamid.IN1'
        );

        $this->assertTrue($handled);
        Queue::assertPushed(ProcessWhatsAppChatAi::class, fn ($job) => $job->wamid === 'wamid.IN1'
            && $job->message === 'buenas, una pregunta');
        Queue::assertNotPushed(ProcessWhatsAppAi::class);
    }

    /** @test */
    public function sin_ninguna_de_las_dos_el_mensaje_queda_para_un_agente(): void
    {
        config(['services.ai_menus.webhook_url' => null, 'services.ai_chat.webhook_url' => null]);
        Queue::fake();

        $handled = app(WhatsAppMenuService::class)->handleInbound(
            $this->instance,
            $this->conversation,
            ['content' => 'buenas, una pregunta', 'metadata' => []],
            'wamid.IN1'
        );

        $this->assertFalse($handled);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function sin_wamid_no_se_pregunta_al_chat_ia(): void
    {
        // El callback llega desnudo: sin wamid la respuesta no se podría casar
        // con ninguna conversación, así que preguntar sería tirarla.
        config(['services.ai_menus.webhook_url' => null]);
        Queue::fake();

        $handled = app(WhatsAppMenuService::class)->handleInbound(
            $this->instance,
            $this->conversation,
            ['content' => 'buenas', 'metadata' => []],
            ''
        );

        $this->assertFalse($handled);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function el_job_no_pregunta_si_un_agente_tomo_el_chat(): void
    {
        Http::fake();
        $this->conversation->update(['assigned_to' => null, 'status' => 'closed']);

        (new ProcessWhatsAppChatAi(
            $this->instance->id, $this->conversation->id, 'hola', 'wamid.IN1'
        ))->handle(new WhatsAppChatAiClient);

        Http::assertNothingSent();
    }
}
