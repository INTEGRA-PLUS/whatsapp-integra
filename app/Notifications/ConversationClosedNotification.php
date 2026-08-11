<?php

namespace App\Notifications;

use App\Models\WhatsAppConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Avisa a los administradores de que un agente cerró una conversación.
 *
 * Cerrar un chat lo saca de la bandeja de todo el equipo, así que el
 * administrador necesita enterarse sin tener que ir a mirar la lista de
 * cerradas una por una.
 */
class ConversationClosedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public WhatsAppConversation $conversation,
        public string $byName,
        public int $total = 1,
        public ?int $byId = null
    ) {
    }

    /**
     * Delivery channels. In-app only (database), igual que el resto.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload que guarda la tabla de notificaciones y lee la campana.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type'            => 'conversation_closed',
            'conversation_id' => $this->conversation->id,
            'instance_id'     => $this->conversation->instance_id,
            'by_name'         => $this->byName,
            // Permite que la campana lo diga en primera persona cuando quien
            // lee es quien cerró ("Cerraste" en vez de "Fulano cerró").
            'by_id'           => $this->byId,
            'contact_name'    => $this->conversation->name ?: $this->conversation->phone_number,
            // Cierre masivo: cuántas se cerraron en la misma acción. La campana
            // lo menciona para que no parezca que solo se cerró una.
            'total'           => $this->total,
        ];
    }
}
