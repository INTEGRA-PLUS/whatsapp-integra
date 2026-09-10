<?php

namespace Tests\Feature;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Responder por Instagram desde el CRM.
 *
 * Sale por el mismo job que WhatsApp a propósito: lo único que cambia es a quién
 * se llama. Todo lo de después —guardar el identificador, marcar enviado, emitir
 * en tiempo real— sigue siendo el mismo código, que es lo que evita que el chat
 * se comporte distinto según el canal.
 */
class EnviarPorInstagramTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_texto_sale_por_graph_instagram_con_el_token_de_la_cuenta(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response([
            'recipient_id' => '1089304012345678',
            'message_id' => 'mid-devuelto-por-meta',
        ])]);

        $mensaje = $this->mensajePendiente('Sí, tenemos cobertura en Tarso.');

        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        Http::assertSent(function ($peticion) {
            $cuerpo = $peticion->data();

            return str_contains($peticion->url(), 'graph.instagram.com')
                && str_contains($peticion->url(), '/me/messages')
                && $peticion->hasHeader('Authorization', 'Bearer el-token-largo')
                && $cuerpo['recipient']['id'] === '1089304012345678'
                // Sin el prefijo "*Agente:*" que sí lleva WhatsApp: en Instagram
                // el asesor escribe desde la cuenta de la empresa.
                && $cuerpo['message']['text'] === 'Sí, tenemos cobertura en Tarso.';
        });

        $mensaje->refresh();
        $this->assertSame('sent', $mensaje->status);
        $this->assertSame('mid-devuelto-por-meta', $mensaje->wamid);
    }

    /**
     * El identificador que devuelve Meta es el mismo que vuelve luego como eco.
     * Guardarlo es lo que impide que la respuesta salga dos veces en el chat.
     */
    public function test_guarda_el_identificador_que_luego_llega_como_eco(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['message_id' => 'mid-compartido'])]);

        $mensaje = $this->mensajePendiente('Hola');
        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        $this->assertSame('mid-compartido', $mensaje->refresh()->wamid);
        $this->assertSame(1, WhatsAppMessage::where('wamid', 'mid-compartido')->count());
    }

    public function test_si_meta_rechaza_el_envio_el_mensaje_queda_fallido_con_el_motivo(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response([
            'error' => ['message' => 'This message is sent outside of allowed window.', 'code' => 10],
        ], 400)]);

        $mensaje = $this->mensajePendiente('Hola');
        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        $mensaje->refresh();
        $this->assertSame('failed', $mensaje->status);
        $this->assertStringContainsString('outside of allowed window', $mensaje->error_message);
        $this->assertNull($mensaje->wamid);
    }

    /**
     * Los adjuntos por Instagram todavía no están hechos. Mejor decirlo en la
     * burbuja que fallar con un error de Meta que no explica nada.
     */
    public function test_un_adjunto_se_rechaza_diciendo_por_que(): void
    {
        Http::fake();

        $mensaje = $this->mensajePendiente('foto', tipo: 'image');
        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        $mensaje->refresh();
        $this->assertSame('failed', $mensaje->status);
        $this->assertStringContainsString('solo se puede enviar texto', $mensaje->error_message);
        Http::assertNothingSent();
    }

    /**
     * La protección que ya existía y que no se puede haber roto: una línea sin
     * cuenta conectada no intenta hablar con Meta.
     */
    public function test_una_linea_sin_cuenta_conectada_no_envia(): void
    {
        Http::fake();

        $mensaje = $this->mensajePendiente('Hola', cuenta: null);
        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        $this->assertSame('failed', $mensaje->refresh()->status);
        Http::assertNothingSent();
    }

    private function mensajePendiente(string $texto, string $tipo = 'text', ?string $cuenta = '17841400008460056'): WhatsAppMessage
    {
        $empresa = Company::create([
            'name' => 'Empresa',
            'slug' => 'e-'.Str::random(8),
            'active' => true,
        ]);

        $linea = Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => '@integracolombia',
            'channel' => Instance::CANAL_INSTAGRAM,
            'external_account_id' => $cuenta,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'el-token-largo',
            'token_expires_at' => now()->addDays(60),
        ]);

        $conversacion = WhatsAppConversation::create([
            'instance_id' => $linea->id,
            'wa_id' => '1089304012345678',
            'name' => 'Alguien por Instagram',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        return WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'type' => $tipo,
            'content' => $texto,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_at' => now(),
        ]);
    }
}
