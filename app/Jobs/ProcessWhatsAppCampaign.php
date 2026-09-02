<?php

namespace App\Jobs;

use App\Events\WhatsAppMessageEvent;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CampaignTemplateService;
use App\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public int $campaignId, public int $delayMs = 250)
    {
    }

    public function handle(MetaWhatsAppService $metaService, CampaignTemplateService $templates): void
    {
        $campaign = WhatsAppCampaign::with('instance')->find($this->campaignId);

        if (!$campaign || !$campaign->instance) {
            Log::channel('whatsapp')->warning('Campaña no encontrada para procesar', ['campaign_id' => $this->campaignId]);
            return;
        }

        if (!in_array($campaign->status, ['queued', 'sending'], true)) {
            Log::channel('whatsapp')->info('Campaña en estado no procesable', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);
            return;
        }

        $campaign->update([
            'status' => 'sending',
            'started_at' => $campaign->started_at ?? now(),
        ]);

        $instance = $campaign->instance;
        $delayUs = max(0, $this->delayMs) * 1000;

        // El encabezado multimedia se sube a Meta una sola vez por corrida: el
        // media_id sirve para todos los destinatarios. Se rehace en cada corrida
        // porque caduca a los 30 días y las campañas recurrentes viven más.
        $headerMediaId = null;
        if ($campaign->isTemplate()) {
            $headerMediaId = $templates->uploadHeaderMedia($campaign->template_payload ?? [], $instance);

            $format = $campaign->template_payload['header']['format'] ?? null;
            if (in_array($format, CampaignTemplateService::MEDIA_HEADERS, true) && !$headerMediaId) {
                // Sin encabezado no hay envío posible: Meta rechazaría uno a uno
                // los mensajes con "Format mismatch". Se corta antes de quemar la lista.
                $campaign->update(['status' => 'failed', 'completed_at' => now()]);
                $campaign->recipients()->where('status', 'pending')->update([
                    'error_message' => 'No se pudo preparar el archivo del encabezado de la plantilla.',
                ]);
                Log::channel('whatsapp')->error('Campaña de plantilla abortada: encabezado no disponible', [
                    'campaign_id' => $campaign->id,
                ]);
                return;
            }
        }

        WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(100, function ($recipients) use ($campaign, $instance, $metaService, $templates, $headerMediaId, $delayUs) {
                foreach ($recipients as $recipient) {
                    $this->sendOne($recipient, $campaign, $instance, $metaService, $templates, $headerMediaId);
                    if ($delayUs > 0) {
                        usleep($delayUs);
                    }
                }
            });

        $campaign->refresh();
        $hasPending = $campaign->recipients()->where('status', 'pending')->exists();
        $campaign->update([
            'status' => $hasPending ? 'failed' : 'completed',
            'completed_at' => now(),
        ]);
    }

    private function sendOne(
        WhatsAppCampaignRecipient $recipient,
        WhatsAppCampaign $campaign,
        $instance,
        MetaWhatsAppService $metaService,
        CampaignTemplateService $templates,
        ?string $headerMediaId
    ): void {
        try {
            if ($campaign->isTemplate()) {
                $payload = $campaign->template_payload ?? [];
                $components = $templates->buildComponents($payload, $headerMediaId, $recipient);
                $content = $templates->renderPreview(
                    $payload['body_text'] ?? null,
                    $templates->resolveVars($payload, $recipient)
                );

                $result = $metaService->sendTemplate(
                    $instance->phone_number_id,
                    $recipient->phone_number,
                    $campaign->template_name,
                    $campaign->template_language ?: 'es',
                    $components
                );
            } else {
                $components = null;
                $content = $campaign->message;
                $result = $metaService->sendMessage(
                    $instance->phone_number_id,
                    $recipient->phone_number,
                    $campaign->message
                );
            }

            if ($result['success'] ?? false) {
                $wamid = $result['data']['messages'][0]['id'] ?? null;
                $message = $this->recordMessage($recipient, $campaign, $instance, $content, $wamid, $components, $headerMediaId);

                $recipient->update([
                    'status' => 'sent',
                    'wamid' => $wamid,
                    'message_id' => $message?->id,
                    'sent_at' => now(),
                    'error_message' => null,
                ]);
                $campaign->increment('sent_count');
            } else {
                $recipient->update([
                    'status' => 'failed',
                    'error_message' => is_string($result['error'] ?? null)
                        ? $result['error']
                        : json_encode($result['error'] ?? 'Error desconocido', JSON_UNESCAPED_UNICODE),
                ]);
                $campaign->increment('failed_count');
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('Error enviando recipient de campaña', [
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
            $recipient->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $campaign->increment('failed_count');
        }
    }

    /**
     * Deja el envío en el hilo del chat. Antes la campaña solo escribía en su
     * propia tabla: si el cliente respondía, el agente veía la respuesta sin
     * saber qué se le había mandado. Además, con el mensaje registrado los
     * webhooks de entregado/leído tienen dónde caer.
     */
    private function recordMessage(
        WhatsAppCampaignRecipient $recipient,
        WhatsAppCampaign $campaign,
        $instance,
        string $content,
        ?string $wamid,
        ?array $components,
        ?string $headerMediaId
    ): ?WhatsAppMessage {
        try {
            $conversation = WhatsAppConversation::resolveFor(
                $instance->id,
                $recipient->phone_number,
                [
                    'phone_number' => $recipient->phone_number,
                    'name' => $recipient->name ?: $recipient->phone_number,
                    'status' => 'open',
                    'last_message_at' => now(),
                ]
            );

            $metadata = ['campaign_id' => $campaign->id];
            if ($components !== null) {
                $metadata['template'] = $campaign->template_name;
                $metadata['language'] = $campaign->template_language;
                $metadata['components'] = $components;
            }

            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $wamid,
                // Solo las plantillas con adjunto necesitan la burbuja de plantilla;
                // el resto se lee mejor como texto, igual que en el chat.
                'type' => $headerMediaId ? 'template' : 'text',
                'content' => $content,
                'media_id' => $headerMediaId,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_by' => $campaign->created_by,
                'sent_at' => now(),
                'metadata' => $metadata,
            ]);

            $conversation->update([
                'last_message' => $content,
                'last_message_at' => now(),
            ]);

            broadcast(new WhatsAppMessageEvent($message, $instance->id, 'new'));

            return $message;
        } catch (\Throwable $e) {
            // El mensaje ya salió a WhatsApp: no registrarlo en el chat es un
            // problema menor que marcar el envío como fallido y reintentarlo.
            Log::channel('whatsapp')->warning('No se pudo registrar el envío de campaña en el chat', [
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
