<?php

namespace App\Notifications;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MentionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public WhatsAppMessage $note,
        public WhatsAppConversation $conversation,
        public string $byName
    ) {
    }

    /**
     * Delivery channels. In-app only (database) for now.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
