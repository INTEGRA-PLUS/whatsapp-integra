<?php

namespace App\Notifications;

use App\Models\ConversationDeletionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Pide a quien puede borrar que resuelva una petición de eliminación.
 *
 * La campana la muestra con botones de aprobar y rechazar, para que no haya que
 * ir a buscar la conversación.
 */
class ConversationDeletionRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(public ConversationDeletionRequest $request)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $conversation = $this->request->conversation;

        return [
            'type'            => 'deletion_request',
            'request_id'      => $this->request->id,
            'conversation_id' => $this->request->conversation_id,
            'instance_id'     => $conversation?->instance_id,
            'by_name'         => $this->request->requester?->name ?? 'Un agente',
            'contact_name'    => $conversation?->name ?: $conversation?->phone_number,
            'reason'          => $this->request->reason,
        ];
    }
}
