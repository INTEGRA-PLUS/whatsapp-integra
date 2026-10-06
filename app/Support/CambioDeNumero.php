<?php

namespace App\Support;

use App\Models\WhatsAppMessage;

/**
 * El cliente le escribió al número anterior de la línea, no al actual.
 *
 * ## El caso
 *
 * El 6-oct-2026 CMNET pasó su línea a un número nuevo para salir de una cuenta
 * de Meta con los pagos restringidos. Desde el número nuevo salía todo, menos a
 * los clientes cuya última conversación había sido con el viejo: a Auriestela
 * Meta le rechazó una imagen y después un «buenas tardes», los dos con
 * «(#131005) Access denied». El agente veía eso y nada más.
 *
 * Para Meta son dos números distintos. La ventana de 24 h la abrió el cliente
 * con el viejo; con el nuevo no ha hablado nunca, y un número recién estrenado
 * no puede escribirle libremente a quien no le escribió.
 *
 * ## Qué hace
 *
 * Si un envío falla con uno de esos códigos y el último mensaje del cliente es
 * de antes del cambio, devuelve la explicación para el agente. El error de Meta
 * no se pierde: queda en `error_message` y `error_code`.
 */
class CambioDeNumero
{
    /**
     * Los rechazos que se ven en este caso: el de acceso, que es el que dio
     * Meta, y los de ventana cerrada, que son lo que en el fondo pasa.
     */
    private const CODIGOS = ['131005', '131047', '470', '480'];

    public static function explicarFallo(WhatsAppMessage $message, $codigo): ?string
    {
        if (! in_array((string) $codigo, self::CODIGOS, true)) {
            return null;
        }

        $conversacion = $message->conversation;
        $instancia = $conversacion?->instance;

        if (! $instancia?->numero_cambiado_at) {
            return null;
        }

        // Si ya le escribió al número nuevo, el problema es otro. Cuenta cuándo
        // llegó al CRM, no cuándo se escribió: lo que entra tras el cambio es
        // del número nuevo aunque Meta lo entregue con retraso.
        if (! $conversacion->escribioSoloAlNumeroAnterior()) {
            return null;
        }

        $anterior = $instancia->numero_anterior_visible ? "al número anterior ({$instancia->numero_anterior_visible})" : 'al número anterior';
        $actual = $instancia->display_phone_number ? " ({$instancia->display_phone_number})" : '';

        return "Este cliente te escribió por última vez {$anterior}. Desde que la línea cambió al número nuevo{$actual} "
            .'todavía no te ha escrito, y para WhatsApp son dos números distintos: no deja escribirle libremente desde el nuevo. '
            .'Para retomar la conversación, envíale una plantilla aprobada desde el chat o pídele por otro medio que te escriba al número nuevo. '
            .'En cuanto lo haga, podrás responderle con normalidad.';
    }
}
