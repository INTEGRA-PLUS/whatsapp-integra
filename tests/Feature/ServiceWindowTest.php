<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ventana de servicio de 24h de Meta.
 *
 * Fuera de ella WhatsApp sólo acepta plantillas aprobadas. Con texto libre Meta
 * responde 200 y devuelve wamid, y sólo después avisa por webhook de que falló:
 * quien envía se queda creyendo que el aviso salió. En 5 días eso perdió 3.494
 * notificaciones de facturación sin que nadie se enterara.
 */
class ServiceWindowTest extends TestCase
{
    use RefreshDatabase;

    private function metaInstance(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        return Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '1177962515404155',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-meta',
        ]);
    }

    private function conversationWithInbound(Instance $instance, ?string $sentAt, ?string $createdAt = null): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::create([
            'instance_id'  => $instance->id,
            'wa_id'        => '573007852081',
            'phone_number' => '573007852081',
            'name'         => 'Cliente',
            'status'       => 'open',
        ]);

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid'           => 'wamid.' . Str::random(10),
            'type'            => 'text',
            'content'         => 'Buenas',
            'direction'       => 'inbound',
            'status'          => 'delivered',
            'sent_at'         => $sentAt,
        ]);

        if ($createdAt) {
            // created_at lo pone Eloquent; se fuerza para simular el desfase.
            $message->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $conversation->fresh();
    }

    public function test_el_sent_at_del_entrante_se_guarda_en_la_hora_de_la_app(): void
    {
        config(['app.timezone' => 'America/Bogota']);
        date_default_timezone_set('America/Bogota');

        $instance = $this->metaInstance();
        $timestamp = 1786590653; // instante fijo, para que la aserción no dependa de "ahora"

        $this->postJson('/webhooks/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => $instance->phone_number_id],
                'contacts' => [['profile' => ['name' => 'Cliente'], 'wa_id' => '573007852081']],
                'messages' => [[
                    'from' => '573007852081',
                    'id' => 'wamid.' . Str::random(10),
                    'timestamp' => (string) $timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Buenas'],
                ]],
            ]]]]],
        ])->assertOk();

        $message = WhatsAppMessage::where('direction', 'inbound')->firstOrFail();

        // Carbon 3 devuelve UTC en createFromTimestamp; sin zona explícita esto
        // quedaba cinco horas por delante del resto de fechas y la ventana de
        // 24h se daba por abierta de más.
        $this->assertSame(
            \Carbon\Carbon::createFromTimestamp($timestamp, 'America/Bogota')->format('Y-m-d H:i:s'),
            $message->sent_at->format('Y-m-d H:i:s')
        );
    }

    public function test_la_ventana_esta_abierta_si_el_cliente_escribio_hace_poco(): void
    {
        $instance = $this->metaInstance();
        $conversation = $this->conversationWithInbound($instance, now()->subHours(2)->toDateTimeString());

        $this->assertTrue($conversation->isWindowOpen());
    }

    public function test_la_ventana_esta_cerrada_si_el_cliente_escribio_hace_mas_de_24h(): void
    {
        $instance = $this->metaInstance();
        $conversation = $this->conversationWithInbound($instance, now()->subHours(30)->toDateTimeString());

        $this->assertFalse($conversation->isWindowOpen());
    }

    public function test_un_mensaje_atrasado_de_meta_no_reabre_la_ventana(): void
    {
        $instance = $this->metaInstance();

        // El cliente escribió hace 3 días; Meta tuvo el webhook atascado y nos lo
        // entregó hace un minuto. Medir por created_at daba la ventana por
        // abierta y el envío moría con "Re-engagement".
        $conversation = $this->conversationWithInbound(
            $instance,
            now()->subDays(3)->toDateTimeString(),
            now()->subMinute()->toDateTimeString()
        );

        $this->assertFalse($conversation->isWindowOpen());
    }

    public function test_en_modo_sombra_el_envio_pasa_igual_que_hoy_pero_queda_marcado(): void
    {
        config(['whatsapp.window_guard.mode' => 'shadow', 'whatsapp.window_guard.enforce_companies' => []]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $instance = $this->metaInstance();
        $this->conversationWithInbound($instance, now()->subDays(2)->toDateTimeString());

        // Nada cambia para el ERP: mismo 200 de siempre.
        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => '573007852081',
                'message' => 'Fue Generada su Factura, realice el pago en nuestra oficina.',
            ])->assertOk();

        $message = WhatsAppMessage::where('direction', 'outbound')->firstOrFail();

        $this->assertSame('shadow_pass', $message->metadata['window_guard'] ?? null);
    }

    public function test_en_modo_enforce_rechaza_el_texto_libre_fuera_de_la_ventana(): void
    {
        config(['whatsapp.window_guard.mode' => 'enforce']);
        Http::fake();

        $instance = $this->metaInstance();
        $this->conversationWithInbound($instance, now()->subDays(2)->toDateTimeString());

        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => '573007852081',
                'message' => 'Fue Generada su Factura, realice el pago en nuestra oficina.',
            ])->assertStatus(422)->assertJson(['code' => 'window_closed']);

        // Ni se llama a Meta ni queda un saliente fantasma en el hilo.
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    public function test_se_puede_encender_una_sola_empresa_sin_tocar_las_demas(): void
    {
        Http::fake();

        $instance = $this->metaInstance();
        $this->conversationWithInbound($instance, now()->subDays(2)->toDateTimeString());

        config([
            'whatsapp.window_guard.mode' => 'shadow',
            'whatsapp.window_guard.enforce_companies' => [(string) $instance->company_id],
        ]);

        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => '573007852081',
                'message' => 'Fue Generada su Factura.',
            ])->assertStatus(422)->assertJson(['code' => 'window_closed']);
    }

    public function test_fuera_de_ventana_el_aviso_sale_como_plantilla_de_respaldo(): void
    {
        config(['whatsapp.window_guard.mode' => 'enforce']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL']]], 200)]);

        $instance = $this->metaInstance();
        $this->conversationWithInbound($instance, now()->subDays(2)->toDateTimeString());

        // Aun en modo enforce: si hay plantilla de respaldo el aviso se entrega
        // en vez de rechazarse. Entregar es mejor que avisar del error.
        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => '573007852081',
                'message' => 'CMNET le informa que su soporte de pago ha sido generado.',
                'template_name' => 'tirillas',
                'components' => [],
            ])->assertOk();

        $message = WhatsAppMessage::where('direction', 'outbound')->firstOrFail();

        $this->assertSame('[Plantilla: tirillas]', $message->content);
        $this->assertSame('tirillas', $message->metadata['template'] ?? null);

        Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'template');
    }

    public function test_dentro_de_ventana_se_usa_el_texto_y_no_la_plantilla(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $instance = $this->metaInstance();
        $this->conversationWithInbound($instance, now()->subHour()->toDateTimeString());

        // La plantilla es un respaldo, no un reemplazo: dentro de la ventana el
        // texto libre es más barato y más natural para el cliente.
        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => '573007852081',
                'message' => 'Su soporte de pago ha sido generado.',
                'template_name' => 'tirillas',
            ])->assertOk();

        $message = WhatsAppMessage::where('direction', 'outbound')->firstOrFail();

        $this->assertSame('Su soporte de pago ha sido generado.', $message->content);
        Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'text');
    }

    public function test_el_informe_del_guardarrail_cuenta_los_marcados_en_sombra(): void
    {
        config(['whatsapp.window_guard.mode' => 'shadow', 'whatsapp.window_guard.enforce_companies' => []]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $instance = $this->metaInstance();
        $this->conversationWithInbound($instance, now()->subDays(2)->toDateTimeString());

        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => '573007852081',
                'message' => 'Fue Generada su Factura.',
            ])->assertOk();

        // Ejercita también el desglose por empresa, que hace join con
        // conversations, instances y companies.
        $this->artisan('whatsapp:window-guard', ['--days' => 7])
            ->expectsOutputToContain('Envíos que se rechazarían')
            ->assertExitCode(0);
    }

    public function test_la_api_del_erp_deja_pasar_el_texto_libre_dentro_de_la_ventana(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $instance = $this->metaInstance();
        $this->conversationWithInbound($instance, now()->subHour()->toDateTimeString());

        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => '573007852081',
                'message' => 'Su servicio ya quedó activo.',
            ])->assertOk();

        $this->assertSame(1, WhatsAppMessage::where('direction', 'outbound')->count());
    }
}
