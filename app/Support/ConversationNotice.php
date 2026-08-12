<?php

namespace App\Support;

use App\Events\WhatsAppMessageEvent;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;

/**
 * Deja constancia en el propio hilo de lo que le pasa a la conversación
 * (cierres, reaperturas), como la pastilla centrada que pinta el chat.
 *
 * Vive aquí y no en el controlador porque la reapertura ocurre en dos sitios
 * distintos: cuando un agente pulsa "Reabrir" (ChatController) y cuando el
 * cliente vuelve a escribir (WhatsAppWebhookController). Tenerlo solo en el
 * primero era justo lo que dejaba el hilo con dos "cerrada" seguidas y nada en
 * medio que explicara por qué se había vuelto a abrir.
 */
class ConversationNotice
{
    public static function record(WhatsAppConversation $conversation, string $text): ?WhatsAppMessage
    {
        try {
            $notice = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'type'            => 'system',
                'content'         => $text,
                'direction'       => 'internal',
                'is_internal'     => false,
                'status'          => 'sent',
                'sent_at'         => now(),
            ]);

            // Quien tenga el hilo abierto ve la pastilla al instante. Sin esto,
            // solo la vería quien ejecutó la acción (su respuesta trae `notice`)
            // y el resto tendría que esperar al poll.
            Realtime::push(new WhatsAppMessageEvent(
                $notice,
                (int) $conversation->instance_id,
                'new',
            ));

            return $notice;
        } catch (\Throwable $e) {
            // El aviso es informativo: si falla, la acción ya ocurrió.
            Log::warning('No se pudo registrar el aviso en el hilo', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
