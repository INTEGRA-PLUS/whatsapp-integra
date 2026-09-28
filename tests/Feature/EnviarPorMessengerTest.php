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
 * Responder por Messenger desde el CRM.
 *
 * EL FALLO QUE SE PREVIENE: el 27-sep-2026 la primera respuesta real desde el
 * chat a la página Integra falló con «Instancia no configurada» sin llegar a
 * salir. `isMetaConfigured()` seguía diciendo que Messenger no estaba
 * construido, y el envío —que sí existía— nunca se alcanzaba. Había tests de la
 * entrada, ninguno de la salida.
 */
class EnviarPorMessengerTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_respuesta_sale_por_la_send_api_con_el_token_de_la_pagina(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'recipient_id' => '28192292610452575',
            'message_id' => 'mid-devuelto-por-meta',
        ])]);

        $mensaje = $this->mensajePendiente('Hola, respuesta de prueba desde Integra CRM');

        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        Http::assertSent(function ($peticion) {
            $cuerpo = $peticion->data();

            return str_contains($peticion->url(), 'graph.facebook.com')
                && str_contains($peticion->url(), '/me/messages')
                && $peticion->hasHeader('Authorization', 'Bearer token-de-la-pagina')
                && $cuerpo['recipient']['id'] === '28192292610452575'
                && $cuerpo['messaging_type'] === 'RESPONSE'
                && $cuerpo['message']['text'] === 'Hola, respuesta de prueba desde Integra CRM';
        });

        $mensaje->refresh();
        $this->assertSame('sent', $mensaje->status);
        $this->assertSame('mid-devuelto-por-meta', $mensaje->wamid);
    }

    /** Una página conectada cuenta como configurada; sin página ni token, no. */
    public function test_una_pagina_con_token_esta_configurada(): void
    {
        $this->assertTrue($this->linea('1426150013911590')->isMetaConfigured());
        $this->assertFalse($this->linea(null)->isMetaConfigured());
    }

    public function test_una_linea_sin_pagina_conectada_no_envia(): void
    {
        Http::fake();

        $mensaje = $this->mensajePendiente('Hola', pagina: null);
        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        $this->assertSame('failed', $mensaje->refresh()->status);
        Http::assertNothingSent();
    }

    private function linea(?string $pagina): Instance
    {
        $empresa = Company::create(['name' => 'Integra', 'slug' => 'i-'.Str::random(8), 'active' => true]);

        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Integra',
            'channel' => Instance::CANAL_MESSENGER,
            'external_account_id' => $pagina,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-de-la-pagina',
        ]);
    }

    private function mensajePendiente(string $texto, ?string $pagina = '1426150013911590'): WhatsAppMessage
    {
        $conversacion = WhatsAppConversation::create([
            'instance_id' => $this->linea($pagina)->id,
            'wa_id' => '28192292610452575',
            'name' => 'Juan José Tuiran',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        return WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'type' => 'text',
            'content' => $texto,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_at' => now(),
        ]);
    }
}
