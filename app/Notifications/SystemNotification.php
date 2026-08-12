<?php

namespace App\Notifications;

use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    use Queueable;
    use BroadcastsToBell;

    public function __construct(
        public string $title,
        public string $body,
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
        return [
            'type'    => 'system',
            'title'   => $this->title,
            'body'    => mb_substr($this->body, 0, 500),
            'by_name' => $this->byName,
        ];
    }
}
