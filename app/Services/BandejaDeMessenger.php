<?php

namespace App\Services;

use App\Models\Instance;

/**
 * La bandeja de Messenger.
 *
 * Hereda entera la de Meta: el payload del tópico `page` es el mismo
 * `messaging[]` que el de `instagram`, así que reescribirlo habría sido copiar
 * 260 líneas para cambiar un host.
 *
 * Lo propio de este canal es a quién se le pregunta el nombre: en Messenger el
 * perfil del cliente se pide con el **token de la página** a
 * `graph.facebook.com`, y lo que devuelve es su nombre y apellido, no un
 * `@usuario` — en Facebook la gente no tiene uno.
 */
class BandejaDeMessenger extends BandejaDeMeta
{
    protected function canal(): string
    {
        return Instance::CANAL_MESSENGER;
    }

    protected function perfilDelCliente(Instance $linea, string $identidad): ?array
    {
        return app(MessengerMensajeriaService::class)->perfilDelCliente($linea, $identidad);
    }
}
