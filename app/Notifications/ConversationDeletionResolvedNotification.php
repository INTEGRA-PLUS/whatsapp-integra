<?php

namespace App\Notifications;

use App\Models\ConversationDeletionRequest;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Le dice a quien pidió la eliminación en qué quedó su petición.
 *
 * Sin esto, el agente se queda esperando sin saber si alguien la miró; y si se
 * aprobó, la conversación simplemente desaparece de su lista sin explicación.
 */
class ConversationDeletionResolvedNotification extends Notification
{
    use Queueable;
    use BroadcastsToBell;

    public function __construct(
        public ConversationDeletionRequest $request,
        public string $contactName
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->request->status === ConversationDeletionRequest::STATUS_APPROVED;

        return [
            'type'         => 'deletion_resolved',
            'approved'     => $approved,
            'by_name'      => $this->request->reviewer?->name ?? 'Un administrador',
            'contact_name' => $this->contactName,
            'review_note'  => $this->request->review_note,
            // Si se aprobó, la conversación ya no existe: la campana no debe
            // ofrecer abrirla.
            'conversation_id' => $approved ? null : $this->request->conversation_id,
            'instance_id'     => $approved ? null : $this->request->conversation?->instance_id,
        ];
    }
}
