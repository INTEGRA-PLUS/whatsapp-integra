<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo de eventos de webhooks salientes
    |--------------------------------------------------------------------------
    |
    | Clave del evento => etiqueta legible (mostrada en la UI de Integraciones).
    | Las empresas eligen a cuáles de estos eventos suscribir cada endpoint.
    | Agregar un evento aquí lo expone automáticamente en la UI; basta con
    | emitirlo desde el código con WebhookDispatcher::emit().
    |
    */

    'events' => [
        'conversation.assigned'        => 'Conversación asignada a un agente',
        'conversation.column_changed'  => 'Conversación movida de columna (Kanban)',
        'conversation.closed'          => 'Conversación cerrada',
        'conversation.tag_added'       => 'Etiqueta agregada a conversación',
        'conversation.tag_removed'     => 'Etiqueta quitada de conversación',
        'message.sent'                 => 'Mensaje enviado por un agente',

        // Autoservicio: lo que el cliente resuelve solo desde el menú de
        // WhatsApp. `payment.requested` es el que espera respuesta del otro
        // lado —el sistema de la empresa genera el cobro y le hace llegar el
        // enlace al cliente—; los demás son avisos de que algo ya pasó.
        'invoice.queried'              => 'Cliente consultó sus facturas desde el menú',
        'payment.requested'            => 'Cliente pidió pagar en línea desde el menú',
        'ticket.created'               => 'Radicado creado desde el menú',
        'service.checked'              => 'Cliente consultó el estado de su servicio',
        'advisor.requested'            => 'Cliente pidió hablar con un asesor',
    ],

];
