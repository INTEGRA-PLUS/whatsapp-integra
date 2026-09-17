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
        // ID de la app que sirve a los clientes (Ispintegra). Es público: viaja
        // al navegador para inicializar el SDK del registro insertado.
        'app_id' => env('META_APP_ID'),
        // Registro insertado (Embedded Signup): la ventana oficial de Meta donde
        // el cliente conecta su propio WhatsApp sin que nadie pegue tokens a
        // mano. El identificador sale del panel de la app, en
        // "Inicio de sesión con Facebook para empresas > Configuraciones", y
        // tampoco es secreto: va en el JavaScript de la página.
        //
        // Sin él (o sin app_id, o sin app_secret) el botón simplemente no se
        // muestra y se sigue conectando a mano, que es como funcionó siempre.
        'embedded_signup_config_id' => env('META_ES_CONFIG_ID'),
        // Versión de Graph con la que se abre la VENTANA del registro insertado.
        //
        // Va aparte de `api_version` a propósito: esa la usan los envíos de
        // los 11 clientes en producción y subirla es un riesgo que no hace
        // falta correr. Esta sólo afecta al diálogo de Meta.
        //
        // El registro insertado v4 —el que trae la coexistencia— salió en
        // octubre de 2025, alineado con Graph v25. Abrir el diálogo en v21,
        // de un año antes, sirve el flujo antiguo: pide un número nuevo y
        // rechaza cualquiera que ya tenga WhatsApp, sin decir por qué.
        'embedded_signup_graph_version' => env('META_ES_GRAPH_VERSION', 'v25.0'),
        // Secretos aceptados al validar la firma de los webhooks entrantes.
        //
        // Varias apps de Meta (Integra e Ispintegra) entregan al MISMO callback y
        // cada una firma con su propio secreto, así que no basta con uno solo:
        // validar contra uno dejaría sin mensajes a las empresas de la otra.
        // Se declara aparte de 'app_secret' porque ese sigue siendo un valor
        // único (se usa para armar el app access token "app_id|app_secret").
        //
        // Formato: uno o varios secretos separados por coma.
        //
        // `?:` y NO el segundo argumento de env(): el compose declara
        // `META_APP_SECRETS: ${META_APP_SECRETS:-}`, así que la variable llega
        // al contenedor **definida y vacía**, no ausente — y env() sólo aplica
        // su valor por defecto cuando la clave no existe. Escrito como
        // `env('META_APP_SECRETS', env('META_APP_SECRET'))` el respaldo parecía
        // estar y nunca se activaba: una instalación que configurara sólo el
        // singular se quedaba sin secretos y respondía 403 a TODOS los webhooks.
        // Es el mismo fallo que costó el token de verificación de Instagram.
        'webhook_app_secrets' => env('META_APP_SECRETS') ?: env('META_APP_SECRET'),
        // Instagram. El caso de uso «API con inicio de sesión con Instagram»
        // hace que Meta cree una app APARTE —«Integra CRM-IG»— con identidad
        // propia: su App ID es el client_id del OAuth y su clave secreta es la
        // que firma sus webhooks. Los de Facebook no sirven aquí.
        //
        // La clave secreta NO va en su propia variable: se AÑADE a la lista de
        // META_APP_SECRETS como "<app_id>:<secreto>", igual que las demás. El
        // validador de firmas ya prueba todas las entradas, así que sumar un
        // canal no obliga a tocar el código que valida.
        'instagram' => [
            'app_id' => env('META_IG_APP_ID'),
            // Token de verificación del tópico `instagram`. Cae en el de
            // WhatsApp si no se declara: son webhooks distintos pero Meta no
            // exige que el token lo sea, y obligar a inventar uno el día del
            // despliegue es una forma barata de bloquearse.
            //
            // Con `?:` y no con el segundo argumento de env(), y eso importa:
            // el bloque x-app-env declara `${META_IG_WEBHOOK_VERIFY_TOKEN:-}`,
            // así que cuando no está en el .env.docker la variable llega al
            // contenedor **definida y vacía**, no ausente. env() sólo usa su
            // valor por defecto si la clave NO existe, de modo que devolvía ''
            // y el respaldo no se activaba nunca. Costó una verificación
            // fallida en producción (10-sep-2026).
            'verify_token' => env('META_IG_WEBHOOK_VERIFY_TOKEN') ?: env('META_WEBHOOK_VERIFY_TOKEN'),
            // Aparte de `api_version` por lo mismo que las demás: esa la usan
            // los envíos de WhatsApp de los once clientes en producción y
            // moverla para tocar Instagram es un riesgo que no hace falta.
            'api_version' => env('META_IG_API_VERSION', 'v23.0'),
        ],
        'api_version' => env('META_API_VERSION', 'v21.0'),
        // La coexistencia (`smb_app_data`, `is_on_biz_app`) no existe en la v21
        // con la que envían los 11 clientes en producción. Va aparte por lo
        // mismo que la del diálogo: subir `api_version` movería el suelo a
        // todos los envíos para arreglar una función que sólo usa un número.
        'coexistence_api_version' => env('META_COEXISTENCE_API_VERSION', 'v25.0'),
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
        // La misma cabecera `X-Api-Key` que el chat, el semáforo y el resumen:
        // los cuatro flujos viven en el mismo n8n y comparten credencial. Se
        // añadió tarde —el webhook de menús nació abierto a internet, y con
        // Ollama detrás eso es crédito que cualquiera puede gastar—. Si se
        // deja vacía el envío sigue saliendo sin cabecera, para no tumbar a
        // quien todavía tenga el flujo sin autenticar.
        'api_key' => env('AI_MENUS_API_KEY'),
        // Margen sobre el timeout que la empresa le da a Ollama: si n8n espera
        // 120 s por el modelo, cortar a los 30 s aquí tiraría respuestas buenas.
        //
        // OJO: ProcessWhatsAppAi se da este tiempo + 30 s, y ese total tiene
        // que caber dentro del `retry_after` de la cola (config/queue.php). Si
        // se sube esto, hay que subir aquello.
        'timeout' => (int) env('AI_MENUS_TIMEOUT', 180),
        // Segundos que se espera antes de preguntarle al modelo, para que el
        // cliente que escribe en ráfagas ("hola" / "no tengo internet" /
        // "desde ayer") se lleve una sola inferencia y una sola respuesta.
        // Es lo que el cliente nota de más: subirlo junta mejor, pero se hace
        // notar. En 0 se desactiva y sólo protege el candado del job.
        'debounce' => (int) env('AI_MENUS_DEBOUNCE', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | IA de los chats (flujo n8n)
    |--------------------------------------------------------------------------
    |
    | Proceso DISTINTO al de los menús, con su propio flujo y su propio
    | contrato: aquél resuelve peticiones contra Integra, éste conversa. Los dos
    | responden en la misma llamada, pero el payload no se parece en nada —el
    | gateway habla `message`/`user_id`, no `mensaje`/`conversacion`—, así que
    | no comparten ni variable ni cliente. Mezclarlos fue lo que dejó la IA muda
    | sin que nadie lo notara.
    |
    */
    'ai_chat' => [
        'webhook_url' => env('AI_CHAT_WEBHOOK_URL'),
        // El gateway va detrás de Header Auth: sin esto responde 403 y el
        // mensaje se pierde en un log.
        'api_key' => env('AI_CHAT_API_KEY'),
        // Es casi todo la espera del modelo: el gateway ya no responde 202,
        // espera al worker y devuelve la respuesta. Igual que en ai_menus,
        // ProcessWhatsAppChatAi se da esto + 30 s y ese total tiene que caber
        // en el `retry_after` de la cola.
        'timeout' => (int) env('AI_CHAT_TIMEOUT', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flujo de sentimiento (semáforo de emociones)
    |--------------------------------------------------------------------------
    |
    | La capa 2 del semáforo: un flujo de n8n que lee la conversación y devuelve
    | el color con su motivo. Es un flujo aparte del de chats y del de menús
    | porque hace otra cosa —clasifica, no conversa— y porque su coste hay que
    | poder apagarlo por su cuenta.
    |
    | Sin configurar, el semáforo NO se apaga: sigue funcionando con la matriz.
    | Es toda la razón de que la capa 1 exista y de que corra primero.
    |
    | El timeout es bajo a propósito. Esto no le contesta a nadie: si tarda más
    | que eso, el color que ya puso la matriz es mejor que un worker ocupado.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | OnePay / IntegraPay — la pasarela que cobra las suscripciones
    |--------------------------------------------------------------------------
    |
    | La misma API que usa Integra 2.0 para facturar a los abonados de los ISPs,
    | pero con **cuenta aparte**: el webhook de OnePay se configura por cuenta, y
    | apuntar el de Integra hacia aquí le quitaría el suyo — dejaría de
    | registrarse el pago de todos los ISPs a la vez.
    |
    | Sin token, `OnePayClient::configurado()` devuelve false y los cobros se
    | quedan sólo en el CRM: se pueden emitir y marcar pagados a mano, que es
    | como se operaba antes de la pasarela.
    |
    */
    'onepay' => [
        'base_url' => env('ONEPAY_BASE_URL', 'https://api.onepay.la/v1'),
        'token' => env('ONEPAY_TOKEN'),
        'timeout' => (int) env('ONEPAY_TIMEOUT', 30),
        // Lo que OnePay manda para probar que el aviso es suyo. Son dos valores
        // distintos y no se sabe cuál viaja en qué cabecera, porque el webhook
        // de Integra 2.0 no verifica nada y no hay de dónde copiarlo.
        'webhook_secret' => env('ONEPAY_WEBHOOK_SECRET'),
        'webhook_header' => env('ONEPAY_WEBHOOK_HEADER'),

        // `aprender` | `exigir`.
        //
        // En `aprender` se procesa todo y se apunta en el log si la firma habría
        // pasado y por qué cabecera llegó. En `exigir` se rechaza lo que no
        // valide.
        //
        // Se arranca en `aprender` a propósito: no hay sandbox —se prueba
        // cobrando de verdad— así que rechazar por una cabecera mal adivinada
        // sería perder un pago real sin saber por qué. Con el primer pago que
        // entre, el log dice el nombre exacto y se pasa a `exigir`.
        'webhook_modo' => env('ONEPAY_WEBHOOK_MODO', 'aprender'),
    ],

    'resumen' => [
        'webhook_url' => env('RESUMEN_WEBHOOK_URL'),
        'api_key' => env('RESUMEN_API_KEY'),
        // Más corto que el del semáforo a propósito: aquí hay una persona
        // esperando delante de un botón, no un job en segundo plano.
        'timeout' => (int) env('RESUMEN_TIMEOUT', 30),
    ],

    'sentimiento' => [
        'webhook_url' => env('SENTIMIENTO_WEBHOOK_URL'),
        'api_key' => env('SENTIMIENTO_API_KEY'),
        'timeout' => (int) env('SENTIMIENTO_TIMEOUT', 45),
        // Cuántos segundos se deja en paz a una conversación entre inferencias.
        // Sin esto, una ráfaga de seis mensajes son seis inferencias para
        // decidir el mismo color.
        'debounce' => (int) env('SENTIMIENTO_DEBOUNCE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Secreto que desbloquea el apartado de IA
    |--------------------------------------------------------------------------
    |
    | La IA no se enciende sola desde el panel: el admin tiene que escribir este
    | secreto una vez por empresa. Es un freno deliberado —encenderla pone a un
    | modelo a hablar con clientes reales—, no una credencial: no da acceso a
    | nada, sólo abre el apartado.
    |
    | Sin configurar, el apartado queda cerrado para todos. Es lo correcto: es
    | preferible que nadie pueda encenderla a que cualquiera pueda.
    |
    */
    'ai_activation' => [
        'secret' => env('SECRET_ACTIVATION'),
    ],

    /*
     * El modelo que convierte texto en vectores, para buscar dentro de los
     * documentos que sube una empresa.
     *
     * **Corre en el propio servidor y no en Ollama Cloud**, que no ofrece
     * ningún modelo de embeddings: su catálogo en la nube son modelos de chat.
     * Y aunque lo ofreciera, esta función va incluida en el complemento de IA
     * sin cobrarse aparte, así que su coste tiene que ser fijo: un proveedor
     * por token convierte cada mensaje de cada cliente en una factura variable
     * sobre algo que no factura.
     *
     * Sin `url` configurada no se calcula ningún vector y la búsqueda cae a
     * palabras sueltas. Es peor —no sabe que «préstamo» y «crédito» son lo
     * mismo— pero contesta, que es mejor que no contestar.
     *
     * Cambiar de modelo obliga a reindexar: los vectores de dos modelos
     * distintos no se pueden comparar entre sí. Lo hace `ia:revectorizar`.
     *
     * ## Por qué `paraphrase-multilingual` y no otro
     *
     * Medido en este mismo servidor, el 16-sep-2026, con la pregunta «quiero
     * pedir un préstamo» contra un párrafo sobre créditos y otro sobre internet:
     *
     * | modelo                  | dim  | 1 pregunta | separación |
     * |-------------------------|------|-----------:|-----------:|
     * | all-minilm              |  384 |     0,62 s |     −0,013 |
     * | paraphrase-multilingual |  768 |     0,42 s |      0,183 |
     * | bge-m3                  | 1024 |     4,19 s |      0,142 |
     *
     * «Separación» es cuánto más se parece el párrafo del sinónimo que el que no
     * viene a cuento. En `all-minilm` **sale negativa**: se parece más el
     * equivocado, así que en español no sirve para nada por rápido que sea.
     *
     * Y «1 pregunta» corre **en cada mensaje entrante**, con el cliente
     * esperando: los 4,19 s de `bge-m3` son cuatro segundos añadidos a cada
     * respuesta, por una separación peor que la del modelo diez veces más
     * rápido.
     */
    /*
     * El modelo que mira las imágenes que manda el cliente.
     *
     * **En la nube y no aquí**, al revés que los embeddings, y por una razón
     * medida: el modelo de visión más pequeño que existe —`minicpm-v4.6`, de
     * 1B— no terminó de describir una sola imagen en diez minutos sobre estos
     * seis núcleos compartidos. La visión local no es viable en este servidor.
     *
     * En la nube sí: la cuenta de Ollama que ya atiende los chats tiene modelos
     * con visión, así que no hay ni proveedor nuevo ni factura nueva. Se paga
     * por token, como el chat.
     *
     * **El nombre del modelo hay que sacarlo de la cuenta, no del catálogo web**
     * de ollama.com: allí se anuncian nombres que la cuenta no sirve. El primer
     * intento fue con `qwen3.5:27b` y la respuesta fue
     * `model 'qwen3.5:27b' not found`; lo que la cuenta ofrece es
     * `qwen3.5:397b`. La lista de verdad sale de `GET /api/tags` con el token.
     *
     * Medido el 17-sep-2026 con una foto de comprobante de pago, los tres lo
     * leyeron bien y `deepseek-v4.1-flash` fue el más rápido:
     *
     * | modelo                | tiempo |
     * |-----------------------|-------:|
     * | `deepseek-v4.1-flash` |  3,5 s |
     * | `gemma4:31b`          |  4,2 s |
     * | `glm-5.3-flash`       |  6,1 s |
     *
     * Y el tiempo importa: corre con un cliente esperando en WhatsApp.
     *
     * Sin `token` no se mira ninguna imagen y las fotos siguen su camino hacia
     * un asesor, que es lo que pasaba hasta ahora.
     */
    'vision' => [
        'url' => env('VISION_URL', 'https://ollama.com'),
        'token' => env('VISION_TOKEN'),
        'model' => env('VISION_MODEL', 'deepseek-v4.1-flash'),
        // Una imagen es más lenta que un texto, y esto corre con un cliente
        // esperando: más de esto y la ventana de 24 h se vuelve el problema.
        'timeout' => (int) env('VISION_TIMEOUT', 90),
    ],

    'embeddings' => [
        'url' => env('EMBEDDINGS_URL'),
        'model' => env('EMBEDDINGS_MODEL', 'paraphrase-multilingual'),
        'timeout' => (int) env('EMBEDDINGS_TIMEOUT', 120),
        /*
         * Por debajo de esto, el fragmento no habla de lo que se preguntó.
         *
         * **Depende del modelo y hay que volver a medirlo al cambiarlo.** Con
         * `paraphrase-multilingual` el sinónimo puntúa 0,401 y el texto ajeno
         * 0,218: 0,30 deja pasar uno y corta el otro. Con otro modelo esos dos
         * números son otros, y un umbral heredado deja entrar basura o no deja
         * pasar nada, sin fallar ni avisar.
         */
        'minimo_parecido' => (float) env('EMBEDDINGS_MINIMO_PARECIDO', 0.30),
    ],

];
