<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Hace que una notificación llegue a la campana por websocket, además de
 * guardarse en la tabla de notificaciones.
 *
 * Basta con añadir 'broadcast' al `via()` de la notificación y usar este trait:
 * el canal privado es el que ya autoriza `routes/channels.php`
 * (`App.Models.User.{id}`) y el payload es idéntico al que devuelve
 * `NotificationController@index`, así que la campana puede insertar el aviso
 * en su lista tal cual, sin volver a pedir nada al servidor.
 */
trait BroadcastsToBell
{
    /** Payload calculado en toBroadcast() y reutilizado en broadcastWith(). */
    protected array $bellPayload = [];

    /**
     * Se fuerza la conexión 'sync': el evento de notificación de Laravel es
     * `ShouldBroadcast` (encolado), y con QUEUE_CONNECTION=database la campana
     * se quedaría esperando al worker. Es un POST diminuto a Reverb.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $this->bellPayload = $this->toDatabase($notifiable);

        return (new BroadcastMessage($this->bellPayload))->onConnection('sync');
    }

    /**
     * Sin esto, Laravel aplana el payload y pisa nuestra clave `type` (el tipo
     * de aviso que la campana usa para elegir icono y redacción) con el nombre
     * de la clase de la notificación. Además así el objeto tiene la misma forma
     * que un elemento de la lista de la campana.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'data' => $this->bellPayload,
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
