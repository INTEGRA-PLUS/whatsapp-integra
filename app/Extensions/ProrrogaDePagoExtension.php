<?php

namespace App\Extensions;

use App\Services\IntegraClient;
use App\Support\IntegrationProvider;

/**
 * Prórroga de pago: el asesor deja radicada la promesa de pago del cliente
 * desde el contrato.
 *
 * «Le pago el viernes, no me corten» es una conversación diaria en un
 * proveedor de internet. Hasta ahora el asesor la anotaba y alguien la
 * pasaba a Integra a mano —o no la pasaba, y el corte llegaba igual—.
 *
 * ## Pide, no concede
 *
 * **No le da el plazo a nadie.** Deja una solicitud pendiente en Integra, en la
 * misma bandeja que las que llegan de la app de clientes, y alguien del
 * proveedor la aprueba o la rechaza. Lo único honesto que el asesor puede
 * decirle al cliente es «queda radicada, te avisamos».
 *
 * Los topes —días de plazo, promesas al año, una sola sin atender— los aplica
 * Integra y no se copian aquí: si cambian allí, aquí cambian solos. Cuando
 * rechaza, el motivo viene redactado y es el que ve el asesor, porque explica
 * por qué no se pudo, que es lo que evita que el cliente insista.
 *
 * @see IntegraClient::requestPaymentExtension() La llamada.
 */
class ProrrogaDePagoExtension extends Extension
{
    public function slug(): string
    {
        return 'payment_extension';
    }

    public function name(): string
    {
        return 'Prórroga de pago';
    }

    public function description(): string
    {
        return 'Radica en Integra la promesa de pago del cliente desde su contrato, sin salir del chat.';
    }

    public function detail(): string
    {
        return 'Dentro del contrato, en el panel del cliente, cada factura pendiente tiene un botón '
            .'para pedir prórroga: se elige la fecha en la que el cliente promete pagar y, si '
            .'quieres, un comentario.'
            ."\n\n"
            .'No concede el plazo. Queda como solicitud en Integra, en la misma bandeja que las '
            .'de la app de clientes, y alguien de tu equipo la aprueba o la rechaza. Al cliente '
            .'hay que decirle eso: que quedó radicada.'
            ."\n\n"
            .'Los topes los pone Integra: cuántos días de plazo das, cuántas promesas al año y '
            .'que no haya otra sin atender. Si la solicitud no cabe, sale el motivo tal cual lo '
            .'explica Integra.'
            ."\n\n"
            .'Necesita tu cuenta de Integra conectada con el permiso «contratos.prorroga». Si '
            .'conectaste Integra antes de que existiera, reconéctalo con tu usuario y contraseña.';
    }

    public function icon(): string
    {
        return 'CalendarClock';
    }

    public function category(): string
    {
        return self::CATEGORIA_PRODUCTIVIDAD;
    }

    public function requiresIntegration(): ?string
    {
        return IntegrationProvider::INTEGRA;
    }

    public function permissions(): array
    {
        return [
            'Leer las facturas pendientes del contrato del cliente con el que se está conversando',
            'Registrar en tu Integra una solicitud de prórroga sobre una de esas facturas',
        ];
    }

    public function hooks(): array
    {
        return [
            'Cuando un asesor abre un contrato en el panel de Integra y pulsa «Pedir prórroga»',
        ];
    }
}
