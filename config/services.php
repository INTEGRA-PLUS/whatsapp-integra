<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta' => [
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        // Secretos aceptados al validar la firma de los webhooks entrantes.
        //
        // Varias apps de Meta (Integra e Ispintegra) entregan al MISMO callback y
        // cada una firma con su propio secreto, así que no basta con uno solo:
        // validar contra uno dejaría sin mensajes a las empresas de la otra.
        // Se declara aparte de 'app_secret' porque ese sigue siendo un valor
        // único (se usa para armar el app access token "app_id|app_secret").
        //
        // Formato: uno o varios secretos separados por coma.
        'webhook_app_secrets' => env('META_APP_SECRETS', env('META_APP_SECRET')),
        'api_version' => env('META_API_VERSION', 'v21.0'),
        // La Calling API requiere una versión más reciente del Graph API que la
        // mensajería. Se mantiene separada para no afectar el resto de llamadas.
        'calling_api_version' => env('META_CALLING_API_VERSION', 'v23.0'),
    ],

    // Software Integra (integración "Pagos a facturas").
    // Integra es multi-tenant: cada empresa indica la URL de SU entorno al conectar
    // (se guarda en company_integrations.base_url), por eso NO se configura aquí.

    /*
    |--------------------------------------------------------------------------
    | IA de los menús de WhatsApp (flujo n8n)
    |--------------------------------------------------------------------------
    |
    | El razonamiento vive en un flujo de n8n, no aquí: recibe el mensaje del
    | cliente con su contexto y devuelve una decisión con la forma de
    | MenuActionResult. Es de la plataforma y no de cada empresa —una sola URL
    | para todas—; lo que cambia por empresa es su Ollama y sus permisos, y eso
    | va en company_integrations.
    |
    | Sin la URL configurada la IA no se puede encender: el panel lo dice y
    | ninguna empresa queda a medias.
    |
    */
    'ai_menus' => [
        'webhook_url' => env('AI_MENUS_WEBHOOK_URL'),
        // Margen sobre el timeout que la empresa le da a Ollama: si n8n espera
        // 120 s por el modelo, cortar a los 30 s aquí tiraría respuestas buenas.
        'timeout' => (int) env('AI_MENUS_TIMEOUT', 180),
    ],

];
