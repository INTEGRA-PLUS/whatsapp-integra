<?php

namespace App\Services;

use App\Models\Instance;

/**
 * La bandeja de Instagram.
 *
 * Todo lo que hace está en `BandejaDeMeta`: lo que llega por Instagram y lo que
 * llega por Messenger son el mismo `messaging[]` de Meta, con los mismos
 * campos y las mismas trampas —el timestamp en milisegundos, los ecos del
 * propio negocio, la idempotencia por `mid`—. Lo único suyo es a qué host se le
 * pregunta quién escribe.
 */
class BandejaDeInstagram extends BandejaDeMeta
{
    protected function canal(): string
    {
        return Instance::CANAL_INSTAGRAM;
    }

    protected function perfilDelCliente(Instance $linea, string $identidad): ?array
    {
        return app(InstagramMensajeriaService::class)->perfilDelCliente($linea, $identidad);
    }
}
