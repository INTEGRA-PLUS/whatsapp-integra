<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Emisión de eventos en tiempo real que nunca tumba la petición que la dispara.
 *
 * Los eventos del chat son `ShouldBroadcastNow`: se publican en Reverb dentro
 * del mismo request, con una llamada HTTP. Si Reverb está caído o tarda, un
 * `broadcast()` pelado lanzaría la excepción hacia arriba y el agente vería un
 * error 500 al cerrar un chat que en realidad SÍ se cerró.
 *
 * El tiempo real es una mejora, no la fuente de la verdad: cuando falla se
 * registra y se sigue. El poll de respaldo del chat recoge el cambio en el
 * siguiente ciclo.
 */
class Realtime
{
    public static function push(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('No se pudo emitir el evento en tiempo real', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
