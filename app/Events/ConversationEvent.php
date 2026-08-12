<?php

namespace App\Events;

use App\Models\WhatsAppConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Empuja al frontend, vía Reverb, los cambios de una CONVERSACIÓN (no de sus
 * mensajes: de eso se encarga WhatsAppMessageEvent):
 *   - action "updated": la fila cambió y hay que repintarla en la lista —
 *                       conversación nueva, asignación, cierre/reapertura,
 *                       etiquetas, movimiento en el kanban.
 *   - action "deleted": desapareció y hay que sacarla de la lista.
 *
 * Va en el mismo canal privado por instancia que los mensajes y las llamadas,
 * así que solo lo reciben los agentes de la empresa dueña de la instancia.
 *
 * El payload de "updated" es la MISMA forma que devuelve `ChatController@updates`
 * (con assignedAgent, closedByUser, tags y contact cargados) para que el cliente
 * lo pase por su `mergeConversations` sin ninguna traducción: lo que llega por
 * websocket y lo que llega por el poll de respaldo son intercambiables.
 *
 * `SerializesModels` NO se usa a propósito: el evento se emite en el mismo
 * request (ShouldBroadcastNow) y en `deleted` el modelo ya no existe en la BD,
 * así que reserializarlo desde la clave primaria fallaría.
 */
class ConversationEvent implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /** Relaciones que el chat necesita para pintar una fila de la lista. */
    public const RELATIONS = [
        'assignedAgent:id,name',
        'closedByUser:id,name',
        'tags',
        'contact:id,name,phone_number,email,notes',
    ];

    private array $payload;

    private int $instanceId;

    private function __construct(int $instanceId, array $payload)
    {
        $this->instanceId = $instanceId;
        $this->payload = $payload;
    }

    /**
     * La conversación cambió: se manda entera, ya serializada.
     *
     * Se serializa aquí y no en `broadcastWith()` porque quien dispara el evento
     * puede seguir tocando el modelo después (p. ej. detach de etiquetas antes
     * de borrar), y el broadcast debe reflejar el estado del momento de emitir.
     */
    public static function updated(WhatsAppConversation $conversation, string $reason = 'updated'): self
    {
        $conversation->load(self::RELATIONS);

        return new self((int) $conversation->instance_id, [
            'action' => 'updated',
            'reason' => $reason,
            'conversation_id' => (int) $conversation->id,
            'conversation' => self::sanitizeUtf8($conversation->toArray()),
        ]);
    }

    /**
     * La conversación se borró. Se construye ANTES del delete: después ya no
     * hay de dónde sacar el instance_id.
     */
    public static function deleted(WhatsAppConversation $conversation): self
    {
        return new self((int) $conversation->instance_id, [
            'action' => 'deleted',
            'reason' => 'deleted',
            'conversation_id' => (int) $conversation->id,
            'conversation' => null,
        ]);
    }

    /**
     * Cierre masivo: un solo evento con los ids en vez de uno por conversación.
     *
     * `closeBulk` puede tocar cientos de hilos de golpe y el broadcast es
     * síncrono (ShouldBroadcastNow, un POST a Reverb por evento): emitir uno por
     * conversación dejaría la petición del agente colgada varios segundos.
     */
    public static function bulkClosed(int $instanceId, array $ids, string $byName): self
    {
        return new self($instanceId, [
            'action' => 'bulk_closed',
            'reason' => 'bulk_closed',
            'ids' => array_values(array_map('intval', $ids)),
            'by_name' => $byName,
        ]);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('instance.' . $this->instanceId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.event';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }

    /**
     * Un solo byte inválido en un nombre o en una nota (los datos vienen de
     * WhatsApp) revienta el json_encode del broadcast y tira abajo la petición
     * entera. Mismo saneado que aplica ChatController a sus respuestas.
     */
    private static function sanitizeUtf8(array $input): array
    {
        foreach ($input as &$value) {
            if (is_string($value)) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            } elseif (is_array($value)) {
                $value = self::sanitizeUtf8($value);
            }
        }
        unset($value);

        return $input;
    }
}
