<?php

namespace App\Extensions\Contracts;

use App\Models\CompanyExtension;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;

/**
 * Gancho de entrada: corre justo después de guardar un mensaje del cliente y
 * antes de que se decida la respuesta automática.
 *
 * Antes y no después a propósito: lo que hace una extensión aquí —etiquetar,
 * asignar— es contexto que el menú y la respuesta automática pueden querer
 * mirar, y al revés llegaría tarde.
 *
 * Lo que se devuelva se ignora: este gancho clasifica y reparte, no contesta.
 * Una extensión que respondiera por su cuenta competiría con el menú y con la
 * respuesta automática, y el cliente recibiría las dos cosas.
 */
interface HandlesInboundMessage
{
    public function onInboundMessage(
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        CompanyExtension $installed
    ): void;
}
