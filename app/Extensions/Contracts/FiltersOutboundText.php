<?php

namespace App\Extensions\Contracts;

use App\Models\CompanyExtension;
use App\Models\WhatsAppMessage;

/**
 * Gancho de salida: transforma el texto que va hacia Meta, ya en el job de
 * entrega.
 *
 * Se filtra el texto que sale, no el que se guarda. En el CRM la burbuja tiene
 * que seguir mostrando lo que el agente escribió: si la firma se guardara, el
 * historial se llenaría de ruido y editar un mensaje reenviaría la firma dentro
 * del texto.
 *
 * Si varias extensiones lo implementan se encadenan en el orden del catálogo, y
 * cada una recibe el resultado de la anterior.
 */
interface FiltersOutboundText
{
    public function filterOutboundText(
        string $text,
        WhatsAppMessage $message,
        CompanyExtension $installed
    ): string;
}
