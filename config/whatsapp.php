<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guardarraíl de la ventana de 24h
    |--------------------------------------------------------------------------
    |
    | Fuera de la ventana de servicio WhatsApp sólo acepta plantillas aprobadas.
    | Con texto libre Meta responde 200 y devuelve wamid, y sólo después avisa
    | por webhook de que falló: quien llama a la API se queda creyendo que el
    | aviso salió, y el cliente final nunca lo recibe.
    |
    | Rechazarlo de golpe convierte meses de pérdidas silenciosas en cientos de
    | errores visibles al día para el ERP, así que el modo por defecto es
    | "shadow": deja pasar el envío exactamente como hoy, pero lo marca para
    | poder medir cuántos se rechazarían y comprobar que el guardarraíl no tiene
    | falsos positivos antes de encenderlo.
    |
    |   shadow  → no rechaza; marca y registra (por defecto)
    |   enforce → rechaza con 422 y code "window_closed"
    |
    | `enforce_companies` permite encender empresa por empresa sin cambiar el
    | modo global: una lista de company_id separados por comas.
    |
    */

    'window_guard' => [
        'mode' => env('WHATSAPP_WINDOW_GUARD', 'shadow'),

        'enforce_companies' => array_filter(
            array_map('trim', explode(',', (string) env('WHATSAPP_WINDOW_GUARD_COMPANIES', '')))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | Cuántos mensajes trae de una vez al abrir una conversación. El resto se
    | pide hacia atrás con "cargar mensajes anteriores".
    |
    | Antes se traía el hilo entero. Con la coexistencia importando hasta seis
    | meses, abrir un chat viejo eran miles de filas por clic —y el agente lee
    | los últimos, porque el chat se abre abajo.
    |
    */

    'chat' => [
        'message_window' => (int) env('CHAT_MESSAGE_WINDOW', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Campañas
    |--------------------------------------------------------------------------
    |
    | `max_selection` es el techo de "seleccionar todos los resultados". Existe
    | para que una consulta sin filtros no traiga la base entera al navegador,
    | no como límite de negocio: cuando corta, la respuesta lo dice
    | (`truncated`) y el asistente lo avisa en pantalla. Antes era un 5000 fijo
    | y mudo, así que una cooperativa de 12.000 socios se quedaba en 5.000 sin
    | que nadie lo supiera hasta contar los enviados.
    |
    | `pacing.scope` decide contra qué reloj se escalonan los envíos:
    |
    |   instance → un solo reloj por número de WhatsApp (por defecto)
    |   campaign → cada campaña con el suyo, como se hacía antes
    |
    | El ritmo lo pide cada campaña (`rate_per_minute`), pero quien manda es el
    | número: Meta cuenta los mensajes por `phone_number_id`, no por campaña.
    | Con el reloj por campaña, tres campañas a 60/min sobre el mismo número
    | eran 180/min reales contra Meta y ninguna sabía de las otras. Con el reloj
    | por instancia se reparten los turnos y el número nunca supera su ritmo.
    |
    */

    'campaigns' => [
        'max_selection' => (int) env('CAMPAIGNS_MAX_SELECTION', 25000),

        'pacing' => [
            'scope' => env('CAMPAIGNS_PACING_SCOPE', 'instance'),
        ],
    ],

];
