<?php

namespace App\Events;

use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Empuja al frontend, vía Reverb, los cambios de un mensaje de WhatsApp para
 * que el chat se actualice en tiempo real sin depender del polling:
 *   - action "new":    un mensaje nuevo (entrante del cliente o saliente ya
 *                      confirmado por Meta) en la conversación.
 *   - action "status": cambio de estado de un mensaje saliente (sent/delivered/
 *                      read/failed).
 *
 * Se emite en el mismo canal privado por instancia que las llamadas, así que
 * solo lo reciben los agentes de la empresa dueña de la instancia.
 */
class WhatsAppMessageEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WhatsAppMessage $message,
        public int $instanceId,
        public string $action = 'new',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('instance.' . $this->instanceId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.event';
    }

    public function broadcastWith(): array
    {
        $m = $this->message;

        // En "status" mandamos solo lo justo para parchear la burbuja; en "new"
        // el objeto suficiente para renderizar el mensaje sin ir a la BD.
        if ($this->action === 'status') {
            return [
                'action' => 'status',
                'conversation_id' => $m->conversation_id,
                'message' => [
                    'id' => $m->id,
                    'wamid' => $m->wamid,
                    'status' => $m->status,
                    'delivered_at' => $m->delivered_at?->toIso8601String(),
                    'read_at' => $m->read_at?->toIso8601String(),
                    'error_message' => $m->error_message,
                ],
            ];
        }

        return [
            'action' => 'new',
            'conversation_id' => $m->conversation_id,
            'message' => [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'wamid' => $m->wamid,
                'reply_to_wamid' => $m->reply_to_wamid,
                'type' => $m->type,
                'content' => $m->content,
                'media_url' => $m->media_url,
                'filename' => $m->filename,
                'direction' => $m->direction,
                'is_internal' => (bool) $m->is_internal,
                'status' => $m->status,
                'sent_by' => $m->sent_by,
                'sender' => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ],
        ];
    }
}
