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
    ],

];
