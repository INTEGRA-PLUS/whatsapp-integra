<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CampaignTemplateBuilder;
use App\Services\MetaWhatsAppService;
use App\Services\TemplateParameterGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envía la plantilla de una campaña a **un** destinatario.
 *
 * Antes toda la campaña cabía en un solo job que iba llamando a Meta en bucle:
 * un fallo a mitad dejaba el resto sin enviar, no había forma de pausar, y el
 * envío no dejaba rastro en el chat, así que ni el agente veía lo que se le
 * había dicho a su cliente ni el acuse de Meta encontraba a quién actualizar.
 *
 * Un job por destinatario arregla las tres cosas: se puede reintentar solo lo
 * que falló, se puede parar en seco, y cada envío crea su burbuja en la
 * conversación —con `campaign_id`— para que el webhook la encuentre por wamid.
 */
class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 30;

    public function __construct(public int $recipientId)
    {
    }

    public function handle(
        MetaWhatsAppService $metaService,
        CampaignTemplateBuilder $builder,
        TemplateParameterGuard $guard
    ): void {
        $recipient = WhatsAppCampaignRecipient::with(['campaign.instance', 'contact'])->find($this->recipientId);

        if (!$recipient || !$recipient->campaign) {
            return;
        }

        $campaign = $recipient->campaign;
        $instance = $campaign->instance;

        // Idempotencia: un reintento de la cola no debe volver a escribirle al
        // cliente si el primero ya salió.
        if ($recipient->status !== 'pending' || $recipient->wamid) {
            return;
        }

        // Pausada o cancelada: el destinatario se queda pendiente, listo para
        // cuando se reanude.
        if ($campaign->paused_at || $campaign->cancelled_at || $campaign->status === 'cancelled') {
            return;
        }

        if (!$instance || !$instance->isMetaConfigured()) {
            $this->fail($recipient, 'La línea de WhatsApp de la empresa está sin configurar.');
            return;
        }

        if (!$campaign->usesTemplate()) {
            $this->fail($recipient, 'La campaña no tiene una plantilla aprobada asignada.');
            return;
        }

        $recipient->update(['status' => 'sending', 'attempts' => $recipient->attempts + 1]);

        $to = WhatsAppConversation::normalizeRecipient($recipient->phone_number);

        $conversation = WhatsAppConversation::resolveFor($instance->id, $to, [
            'phone_number'    => $to,
            'name'            => $recipient->name ?: $to,
            'status'          => 'open',
            'last_message_at' => now(),
        ]);

        $components = $builder->components($campaign, $recipient);

        $checked = $guard->check($instance, $campaign->template_name, $campaign->template_language, $components);

        if (!$checked['ok']) {
            $this->fail($recipient, $checked['error'], null, $checked['code'], $conversation->id);
            return;
        }

        $components = $checked['components'];
        $content = $builder->preview($campaign, $recipient);

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'campaign_id'     => $campaign->id,
            'type'            => 'template',
            'content'         => $content,
            'direction'       => 'outbound',
            'status'          => 'pending',
            'sent_by'         => $campaign->created_by,
            'sent_at'         => now(),
            'metadata'        => [
                'campaign'   => $campaign->name,
                'template'   => $campaign->template_name,
                'language'   => $campaign->template_language,
                'components' => $components,
            ],
        ]);

        $result = $metaService->sendTemplate(
            $instance->phone_number_id,
            $conversation->recipientId(),
            $campaign->template_name,
            $campaign->template_language ?: 'es',
            $components
        );

        if (!($result['success'] ?? false)) {
            $error = $result['error']['error']['message']
                ?? (is_string($result['error'] ?? null) ? $result['error'] : 'Error al enviar');
            $code = $result['error']['error']['code'] ?? null;
            $details = $result['error']['error']['error_data']['details'] ?? null;

            $message->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => mb_substr($error, 0, 2000),
                'error_code'    => $code,
                'error_details' => $details,
            ]);

            $this->fail($recipient, $error, $code, $details, $conversation->id, $message->id);
            return;
        }

        $wamid = $result['data']['messages'][0]['id'] ?? null;

        $message->update(['wamid' => $wamid, 'status' => 'sent']);

        $recipient->update([
            'status'          => 'sent',
            'wamid'           => $wamid,
            'sent_at'         => now(),
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'error_message'   => null,
            'error_code'      => null,
            'error_details'   => null,
        ]);

        $conversation->update([
            'last_message'    => $content,
            'last_message_at' => now(),
        ]);

        broadcast(new \App\Events\WhatsAppMessageEvent($message, $instance->id, 'new'));

        $this->closeCampaignIfDone($recipient);
    }

    public function failed(\Throwable $e): void
    {
        $recipient = WhatsAppCampaignRecipient::find($this->recipientId);

        if ($recipient && in_array($recipient->status, ['pending', 'sending'], true)) {
            $this->fail($recipient, $e->getMessage());
        }
    }

    private function fail(
        WhatsAppCampaignRecipient $recipient,
        ?string $error,
        $code = null,
        ?string $details = null,
        ?int $conversationId = null,
        ?int $messageId = null
    ): void {
        $recipient->update([
            'status'          => 'failed',
            'error_message'   => mb_substr((string) $error, 0, 2000),
            'error_code'      => $code ? mb_substr((string) $code, 0, 20) : null,
            'error_details'   => $details,
            'conversation_id' => $conversationId ?: $recipient->conversation_id,
            'message_id'      => $messageId ?: $recipient->message_id,
        ]);

        Log::channel('whatsapp')->warning('Destinatario de campaña fallido', [
            'campaign_id'  => $recipient->campaign_id,
            'recipient_id' => $recipient->id,
            'error'        => $error,
        ]);

        $this->closeCampaignIfDone($recipient);
    }

    /**
     * La campaña termina cuando no queda nadie pendiente. Se decide aquí, en el
     * último job que acaba, y no en el que reparte: repartir es instantáneo,
     * enviar puede durar horas.
     */
    private function closeCampaignIfDone(WhatsAppCampaignRecipient $recipient): void
    {
        $campaign = $recipient->campaign->fresh();

        if (!$campaign) {
            return;
        }

        $campaign->refreshCounters();

        if ($campaign->outstandingCount() > 0 || $campaign->status === 'cancelled') {
            return;
        }

        $campaign->update([
            // Todo fallido es un fallo de la campaña; con entregas parciales el
            // detalle ya dice cuántas y por qué.
            'status'       => $campaign->sent_count === 0 ? 'failed' : 'completed',
            'completed_at' => now(),
            'last_run_at'  => now(),
        ]);
    }
}
