<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Una campaña sale como plantilla aprobada, personalizada, y deja rastro.
 *
 * Antes una campaña era texto libre: Meta contestaba 200, la fila quedaba
 * "enviada" y el rechazo por ventana de 24h llegaba después por webhook, donde
 * nadie lo veía porque el envío no creaba ningún mensaje en el chat. Una
 * campaña podía reportar 100% enviada habiendo entregado cero.
 */
class CampaignTemplateSendTest extends TestCase
{
    use RefreshDatabase;

    private const CUERPO = 'Hola {{1}}, tu factura de {{2}} está lista.';

    private function instancia(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-' . Str::random(4), 'active' => true]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);
    }

    private function fakeGraph(): void
    {
        $enviados = 0;

        Http::fake(function ($request) use (&$enviados) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-1',
                    'name' => 'aviso_factura',
                    'language' => 'es',
                    'status' => 'APPROVED',
                    'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => self::CUERPO]],
                ]]], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . (++$enviados)]]], 200);
        });
    }

    private function campana(Instance $instance, array $overrides = []): WhatsAppCampaign
    {
        return WhatsAppCampaign::create(array_merge([
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'name' => 'Facturación septiembre',
            'message' => null,
            'message_type' => 'template',
            'template_name' => 'aviso_factura',
            'template_language' => 'es',
            'template_components' => [['type' => 'BODY', 'text' => self::CUERPO]],
            'variable_map' => [
                'body' => [
                    ['source' => 'field', 'field' => 'name'],
                    ['source' => 'fixed', 'value' => 'septiembre'],
                ],
            ],
            'status' => 'queued',
            'schedule_type' => 'manual',
            'total_recipients' => 0,
        ], $overrides));
    }

    private function destinatario(WhatsAppCampaign $campaign, string $phone, ?string $name): WhatsAppCampaignRecipient
    {
        return WhatsAppCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'phone_number' => $phone,
            'name' => $name,
            'status' => 'pending',
        ]);
    }

    public function test_cada_destinatario_recibe_su_propia_plantilla(): void
    {
        $this->fakeGraph();

        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        $this->destinatario($campaign, '573007852081', 'Daniela');
        $this->destinatario($campaign, '573145550011', 'Andrés');

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $enviadas = collect(Http::recorded())
            ->map(fn ($par) => $par[0]->data())
            ->filter(fn ($data) => ($data['type'] ?? null) === 'template')
            ->values();

        $this->assertCount(2, $enviadas);

        $nombres = $enviadas
            ->map(fn ($d) => $d['template']['components'][0]['parameters'][0]['text'])
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Andrés', 'Daniela'], $nombres);
        // El segundo dato es fijo: el mismo para todos.
        $this->assertSame('septiembre', $enviadas[0]['template']['components'][0]['parameters'][1]['text']);
    }

    /**
     * Sin burbuja en el chat, ni el agente ve lo que se le dijo a su cliente ni
     * el acuse de Meta encuentra a quién actualizar.
     */
    public function test_la_campana_se_ve_en_el_chat(): void
    {
        $this->fakeGraph();

        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        $this->destinatario($campaign, '573007852081', 'Daniela');

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $mensaje = WhatsAppMessage::where('campaign_id', $campaign->id)->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('template', $mensaje->type);
        $this->assertSame('outbound', $mensaje->direction);
        $this->assertSame('sent', $mensaje->status);
        // El contenido es lo que el cliente leyó, no el nombre de la plantilla.
        $this->assertSame('Hola Daniela, tu factura de septiembre está lista.', $mensaje->content);
        $this->assertNotNull($mensaje->conversation_id);
    }

    public function test_el_acuse_de_entrega_llega_al_destinatario(): void
    {
        $this->fakeGraph();
        config(['services.meta.webhook_app_secrets' => 'secreto']);

        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        $this->destinatario($campaign, '573007852081', 'Daniela');

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $recipient = $campaign->recipients()->first();
        $this->assertSame('sent', $recipient->status);
        $this->assertNotNull($recipient->wamid);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $instance->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $instance->phone_number_id],
                        'statuses' => [[
                            'id' => $recipient->wamid,
                            'status' => 'delivered',
                            'timestamp' => (string) now()->timestamp,
                            'recipient_id' => '573007852081',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secreto'),
        ]), $body)->assertOk();

        $this->assertSame('delivered', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->delivered_at);
    }

    /**
     * El caso que motivó todo esto: una campaña de texto libre no puede salir,
     * porque WhatsApp no la entrega fuera de la ventana de 24h.
     */
    public function test_una_campana_de_texto_libre_no_se_envia_y_lo_explica(): void
    {
        $this->fakeGraph();

        $instance = $this->instancia();
        $campaign = $this->campana($instance, [
            'message' => 'Hola, tenemos una promo para ti',
            'message_type' => 'text',
            'template_name' => null,
            'template_components' => null,
        ]);
        $this->destinatario($campaign, '573007852081', 'Daniela');

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $this->assertSame('failed', $campaign->fresh()->status);
        $this->assertStringContainsString(
            'plantilla aprobada',
            $campaign->recipients()->first()->error_message
        );
        $this->assertCount(0, collect(Http::recorded())->filter(
            fn ($par) => str_contains($par[0]->url(), '/messages')
        ));
    }

    public function test_la_campana_se_cierra_cuando_no_queda_nadie(): void
    {
        $this->fakeGraph();

        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        $this->destinatario($campaign, '573007852081', 'Daniela');
        $this->destinatario($campaign, '573145550011', 'Andrés');

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $campaign->refresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(2, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertNotNull($campaign->completed_at);
    }

    /**
     * El media_id de Meta caduca a los 30 días. Sin volver a subir el archivo,
     * una campaña recurrente moriría al mes con un "Format mismatch" por cada
     * destinatario, y nadie lo relacionaría con la fecha de creación.
     */
    public function test_el_encabezado_se_vuelve_a_subir_en_cada_corrida(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3_media');
        \Illuminate\Support\Facades\Storage::disk('s3_media')->put('whatsapp/campaigns/x.png', 'bytes-de-la-imagen');

        $subidas = 0;
        Http::fake(function ($request) use (&$subidas) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-1', 'name' => 'aviso_factura', 'language' => 'es',
                    'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [
                        ['type' => 'HEADER', 'format' => 'IMAGE'],
                        ['type' => 'BODY', 'text' => self::CUERPO],
                    ],
                ]]], 200);
            }

            if (str_ends_with($request->url(), '/media')) {
                $subidas++;
                return Http::response(['id' => '556677889900'], 200);
            }

            // La ficha del media que consulta el guardarraíl antes de enviar.
            if (preg_match('#/\d{6,}$#', $request->url())) {
                return Http::response(['mime_type' => 'image/png', 'file_size' => 2048, 'url' => 'https://lookaside.fb/x'], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(6)]]], 200);
        });

        $instance = $this->instancia();
        $campaign = $this->campana($instance, [
            'template_components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => self::CUERPO],
            ],
            // El id viejo, el que habría caducado.
            'header_media_id' => '111111111',
            'header_media_path' => 'whatsapp/campaigns/x.png',
            'header_media_mime' => 'image/png',
        ]);
        $this->destinatario($campaign, '573007852081', 'Daniela');

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $this->assertSame(1, $subidas, 'El archivo debía subirse una vez por corrida.');
        $this->assertSame('556677889900', $campaign->fresh()->header_media_id);

        $enviada = collect(Http::recorded())
            ->map(fn ($par) => $par[0]->data())
            ->first(fn ($d) => ($d['type'] ?? null) === 'template');

        $this->assertSame('556677889900', $enviada['template']['components'][0]['parameters'][0]['image']['id']);
    }

    public function test_un_envio_rechazado_por_meta_queda_con_su_motivo(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response([
                'error' => [
                    'message' => 'Template name does not exist in the translation',
                    'code' => 132001,
                ],
            ], 400);
        });

        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        $this->destinatario($campaign, '573007852081', 'Daniela');

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $recipient = $campaign->recipients()->first();

        $this->assertSame('failed', $recipient->status);
        $this->assertSame('132001', (string) $recipient->error_code);
        $this->assertStringContainsString('Template name does not exist', $recipient->error_message);
        // La burbuja del chat cuenta lo mismo que la fila del destinatario.
        $this->assertSame('failed', WhatsAppMessage::where('campaign_id', $campaign->id)->first()->status);
    }
}
