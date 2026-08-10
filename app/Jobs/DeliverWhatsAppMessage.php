<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Entrega a Meta un mensaje saliente ya persistido (status "pending").
 *
 * El controlador crea la fila y responde de inmediato; la llamada HTTP a Graph
 * (hasta 60s) ocurre aquí, en un worker de cola, en vez de bloquear un worker
 * FPM durante todo el request. El polling del chat (`/api/chat/updates`)
 * propaga el cambio de estado pending → sent/failed a la UI.
 */
class DeliverWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 90;
    public int $backoff = 10;

    public function __construct(public int $messageId) {}

    public function handle(MetaWhatsAppService $metaService): void
    {
        $message = WhatsAppMessage::with(['conversation.instance', 'sender'])
            ->find($this->messageId);

        if (! $message) {
            return;
        }

        // Idempotencia: si un reintento corre después de que Meta ya aceptó el
        // mensaje (wamid asignado) no lo reenviamos.
        if ($message->wamid || $message->status !== 'pending') {
            return;
        }

        $conversation = $message->conversation;
        $instance = $conversation?->instance;

        if (! $instance || ! $instance->isMetaConfigured()) {
            $this->markFailed($message, 'Instancia no configurada');
            return;
        }

        $to = $conversation->phone_number;
        $phoneNumberId = $instance->phone_number_id;

        $result = match ($message->type) {
            'text' => $metaService->sendMessage(
                $phoneNumberId,
                $to,
                '*' . ($message->sender->name ?? 'Agente') . ':*' . "\n" . $message->content,
                $message->reply_to_wamid ?: null
            ),
            'image' => $metaService->sendImage(
                $phoneNumberId,
                $to,
                $message->media_url,
                $message->content ?? '',
                $message->reply_to_wamid ?: null
            ),
            'audio' => $metaService->sendAudio(
                $phoneNumberId,
                $to,
                $message->media_url
            ),
            'document' => $metaService->sendDocument(
                $phoneNumberId,
                $to,
                $message->media_url,
                $message->filename ?: 'documento',
                $message->content ?: '',
                $message->reply_to_wamid ?: null
            ),
            'template' => $metaService->sendTemplate(
                $phoneNumberId,
                $to,
                $message->metadata['template'] ?? '',
                $message->metadata['language'] ?? 'es',
                $message->metadata['components'] ?? []
            ),
            default => ['success' => false, 'error' => "Tipo no soportado: {$message->type}"],
        };

        if (! ($result['success'] ?? false)) {
            $error = $result['error']['error']['message']
                ?? (is_string($result['error'] ?? null) ? $result['error'] : 'Error al enviar');
            $errorCode = $result['error']['error']['code'] ?? null;
            $errorDetails = $result['error']['error']['error_data']['details'] ?? null;
            $this->markFailed($message, $error, $errorCode, $errorDetails);
            return;
        }

        $wamid = $result['data']['messages'][0]['id'] ?? null;

        // Las plantillas con header multimedia guardan una copia en nuestro S3
        // para que el archivo quede visible en el chat (no solo el texto).
        if ($message->type === 'template' && empty($message->media_url)) {
            $this->attachTemplateHeaderMedia($message, $metaService, $instance->access_token);
        }

        $message->update([
            'wamid'    => $wamid,
            'status'   => 'sent',
            'sent_at'  => $message->sent_at ?: now(),
        ]);

        // Tiempo real: empuja el saliente ya confirmado a los demás agentes
        // conectados (el emisor ya lo ve optimista; el front deduplica por id).
        broadcast(new \App\Events\WhatsAppMessageEvent($message->load('sender'), $instance->id, 'new'));

        WebhookDispatcher::emit(
            $conversation->instance->company_id,
            'message.sent',
            WebhookDispatcher::conversationPayload($conversation, [
                'message' => [
                    'id'        => $message->id,
                    'wamid'     => $message->wamid,
                    'type'      => $message->type,
                    'content'   => $message->content,
                    'media_url' => $message->media_url,
                    'sent_by'   => $message->sent_by,
                ],
            ])
        );
    }

    public function failed(\Throwable $e): void
    {
        $message = WhatsAppMessage::find($this->messageId);
        if ($message && $message->status === 'pending') {
            $this->markFailed($message, $e->getMessage());
        }
    }

    private function markFailed(WhatsAppMessage $message, string $error, $errorCode = null, ?string $errorDetails = null): void
    {
        $message->update([
            'status'        => 'failed',
            'failed_at'     => now(),
            'error_message' => mb_substr($error, 0, 2000),
            'error_code'    => $errorCode,
            'error_details' => $errorDetails,
        ]);

        // Tiempo real: la burbuja del emisor pasa a "fallido" sin esperar el poll.
        $instanceId = $message->conversation?->instance_id
            ?? $message->conversation()->value('instance_id');
        if ($instanceId) {
            broadcast(new \App\Events\WhatsAppMessageEvent($message, $instanceId, 'status'));
        }

        Log::warning('DeliverWhatsAppMessage falló', [
            'message_id' => $message->id,
            'type'       => $message->type,
            'error'      => $error,
        ]);
    }

    private function attachTemplateHeaderMedia(WhatsAppMessage $message, MetaWhatsAppService $metaService, string $accessToken): void
    {
        foreach ($message->metadata['components'] ?? [] as $component) {
            if (($component['type'] ?? '') !== 'header') {
                continue;
            }
            foreach ($component['parameters'] ?? [] as $param) {
                $mediaKey = $param['type'] ?? '';
                if (in_array($mediaKey, ['document', 'image', 'video']) && ! empty($param[$mediaKey]['id'])) {
                    // El media_id se guarda pase lo que pase: si la copia falla,
                    // el chat puede reintentar la descarga al abrir el mensaje.
                    $message->media_id = $param[$mediaKey]['id'];
                    $message->filename = $param[$mediaKey]['filename'] ?? $message->filename;

                    $mediaInfo = $metaService->downloadMedia($param[$mediaKey]['id'], $accessToken);
                    if ($mediaInfo) {
                        $message->media_url = $mediaInfo['url'];
                        $message->media_mime_type = $mediaInfo['mime_type'];
                        $message->filename = $message->filename ?: $mediaInfo['filename'];
                    }
                }
            }
        }
    }
}
