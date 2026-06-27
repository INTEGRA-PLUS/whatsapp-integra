<?php

namespace App\Events;

use App\Models\WhatsAppCall;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifica al frontend, vía Reverb, los eventos de una llamada de WhatsApp
 * (timbre entrante, cambios de estado, fin). Se emite en un canal privado por
 * instancia para que solo los agentes de esa empresa lo reciban.
 */
class WhatsAppCallEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WhatsAppCall $call,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('instance.' . $this->call->instance_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'call.event';
    }

    public function broadcastWith(): array
    {
        $call = $this->call;

        return [
            'action' => $this->action,
            'call' => [
                'id' => $call->id,
                'wacid' => $call->wacid,
                'conversation_id' => $call->conversation_id,
                'instance_id' => $call->instance_id,
                'direction' => $call->direction,
                'status' => $call->status,
                'from' => $call->from,
                'to' => $call->to,
                'duration_seconds' => $call->duration_seconds,
                'started_at' => $call->started_at?->toIso8601String(),
                'connected_at' => $call->connected_at?->toIso8601String(),
                'ended_at' => $call->ended_at?->toIso8601String(),
            ],
        ];
    }
}
