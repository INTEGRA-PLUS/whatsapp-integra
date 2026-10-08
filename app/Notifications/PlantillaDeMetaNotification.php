<?php

namespace App\Notifications;

use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Lo que Meta decidió sobre una plantilla (pausa, rechazo, desactivación,
 * cambio de categoría), contado a los administradores de la empresa.
 *
 * El `type` es 'system' por la misma razón que ExtensionAlertNotification: la
 * campana pinta por tipo y uno desconocido cae en la rama de las menciones.
 * Lo que añade sobre SystemNotification es el nombre de la plantilla y la
 * instancia, para poder filtrar o enlazar sin parsear el texto.
 */
class PlantillaDeMetaNotification extends Notification
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $templateName,
        public ?int $instanceId = null
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
            // Más largo que el de SystemNotification: el motivo de Meta, las
            // campañas pausadas y si era la de respaldo no caben en 500.
            'body' => mb_substr($this->body, 0, 1200),
            'by_name' => 'Meta',
            'template_name' => $this->templateName,
            'instance_id' => $this->instanceId,
        ];
    }
}
