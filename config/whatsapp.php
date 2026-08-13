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

];
