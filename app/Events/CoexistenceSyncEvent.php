<?php

namespace App\Events;

use App\Models\CoexistenceSync;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Empuja el avance de la importación de coexistencia a la pantalla del cliente.
 *
 * La importación tarda minutos y sin esto es una pantalla muda: quien no ve
 * nada asume que falló y llama a soporte, o peor, desconecta el número y
 * gasta el único intento.
 *
 * Va en el mismo canal privado por instancia que los mensajes y las llamadas
 * (`instance.{id}`), que ya está autorizado por empresa en `routes/channels.php`:
 * no hace falta canal nuevo ni una regla de permisos más que mantener.
 *
 * `ShouldBroadcastNow` porque esto se emite desde la cola, donde ya estamos
 * fuera de la petición: encolar el broadcast otra vez sólo añadiría retraso a
 * lo único que el cliente está mirando.
 */
class CoexistenceSyncEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    private array $payload;

    public function __construct(CoexistenceSync $sync)
    {
        // Se serializa en el constructor, no en `broadcastWith()`: quien emite
        // sigue tocando el modelo después (contadores, cambios de fase) y el
        // broadcast debe reflejar el estado del momento de emitir.
        $this->payload = $sync->paraPantalla();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('instance.' . $this->payload['instance_id'])];
    }

    public function broadcastAs(): string
    {
        return 'coexistence.sync';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
