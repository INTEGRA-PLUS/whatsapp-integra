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
        // Una nota puede ser solo una imagen: sin este respaldo la campana
        // mostraría una mención con el texto en blanco.
        $excerpt = mb_substr(trim((string) $this->note->content), 0, 140)
            ?: ($this->note->media_url ? '📎 Imagen' : '');

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
