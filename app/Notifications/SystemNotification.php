<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
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
        return [
            'type'    => 'system',
            'title'   => $this->title,
            'body'    => mb_substr($this->body, 0, 500),
            'by_name' => $this->byName,
        ];
    }
}
