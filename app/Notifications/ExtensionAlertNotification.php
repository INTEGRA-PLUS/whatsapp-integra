<?php

namespace App\Notifications;

use App\Models\WhatsAppConversation;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * El aviso que manda una extensión a la campana.
 *
 * El `type` es 'system' y no uno propio a propósito: la campana pinta por tipo
 * con una cadena de ternarios, y un tipo desconocido cae en la rama de las
 * menciones —arroba naranja y la redacción de "te mencionaron"—, que no tiene
 * nada que ver. Con 'system' se reutiliza el megáfono y el cuerpo de
 * título + texto sin tocar una línea de React.
 *
 * Lo que SÍ añade sobre SystemNotification es `conversation_id`: la campana
 * navega al chat cuando el aviso lo trae, sea del tipo que sea, y un aviso de
 * "este chat lleva media hora sin respuesta" que no lleve al chat obliga a
 * buscarlo a mano, que es exactamente el trabajo que venía a ahorrar.
 */
class ExtensionAlertNotification extends Notification
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $extensionName,
        public ?WhatsAppConversation $conversation = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'system',
            'title' => $this->title,
            'body' => mb_substr($this->body, 0, 500),
            'by_name' => $this->extensionName,
            'conversation_id' => $this->conversation?->id,
            'instance_id' => $this->conversation?->instance_id,
        ];
    }
}
