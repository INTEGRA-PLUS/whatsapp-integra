<?php

namespace App\Notifications;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MentionNotification extends Notification
{
    use Queueable;
    use BroadcastsToBell;

    public function __construct(
        public WhatsAppMessage $note,
        public WhatsAppConversation $conversation,
        public string $byName
    ) {
    }

    /**
     * Canales: se guarda en la tabla de notificaciones y se empuja a la campana
     * por websocket, para que aparezca sin esperar al poll de 25s.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Payload stored in the notifications table and read by the bell.
     */
    public function toDatabase(object $notifiable): array
    {
        $excerpt = mb_substr((string) $this->note->content, 0, 140);

        return [
            'type'            => 'mention',
            'conversation_id' => $this->conversation->id,
            'instance_id'     => $this->conversation->instance_id,
            'message_id'      => $this->note->id,
            'by_name'         => $this->byName,
            'contact_name'    => $this->conversation->name ?? $this->conversation->phone_number,
            'excerpt'         => $excerpt,
        ];
    }
}
