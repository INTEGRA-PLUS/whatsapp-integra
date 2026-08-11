<?php

namespace App\Services;

use App\Models\WhatsAppMessage;

/**
 * Traduce el motivo por el que un mensaje no llegó a algo que entienda quien
 * atiende al cliente, no quien mantiene el servidor.
 *
 * WhatsApp devuelve cosas como "131047 - Re-engagement message" o
 * "132000 - number of parameters mismatch": exactas pero inútiles para un
 * agente que solo quiere saber si debe volver a escribirle al cliente. Aquí se
 * convierten en un titular, una explicación y una acción concreta. El texto
 * original nunca se pierde: viaja aparte, en el bloque técnico.
 */
class WhatsAppFailureTranslator
{
    /** Cajón de los fallos que no se reconocen; se filtra por exclusión. */
    public const OTHER_TITLE = 'Otros motivos';

    /**
     * Qué se puede hacer con el fallo, que es lo que de verdad quiere saber
     * quien lo lee:
     *
     * - temporary: fue pasajero, reintentar tiene sentido.
     * - permanent: reintentar dará el mismo resultado.
     * - window:    pasaron 24 h, hay que reabrir con una plantilla.
     * - config:    algo está mal configurado, lo arregla un administrador.
     */
    private const SEVERITIES = ['temporary', 'permanent', 'window', 'config'];

    /**
     * Códigos de error de la API de WhatsApp Cloud. Los que no estén aquí caen
     * en una explicación genérica que conserva el texto original.
     */
    private const CODES = [
        // ── El destinatario ──────────────────────────────────────────────────
        '131026' => [
            'title'    => 'El número no puede recibir mensajes de WhatsApp',
            'detail'   => 'WhatsApp no pudo entregar el mensaje: lo más habitual es que el número no tenga cuenta de WhatsApp, esté escrito con un error o haya sido dado de baja.',
            'action'   => 'Verifica el número con el cliente por otro medio antes de volver a intentarlo.',
            'severity' => 'permanent',
        ],
        '131021' => [
            'title'    => 'El número de destino es el mismo que el de envío',
            'detail'   => 'El mensaje iba dirigido a la propia línea de la empresa, y WhatsApp no permite enviarse mensajes a sí mismo.',
            'action'   => 'Corrige el número del contacto.',
            'severity' => 'permanent',
        ],
        '131050' => [
            'title'    => 'WhatsApp no entrega mensajes de este tipo a esta persona',
            'detail'   => 'WhatsApp aplica restricciones a los mensajes promocionales según la configuración y los hábitos de cada usuario. Este quedó fuera por esa razón, no por un problema del envío.',
            'action'   => 'Si es algo que el cliente necesita saber, contáctalo por otro canal o espera a que él escriba primero.',
            'severity' => 'permanent',
        ],
        '135000' => [
            'title'    => 'WhatsApp rechazó el mensaje',
            'detail'   => 'WhatsApp no aceptó el mensaje y no dio un motivo concreto. Suele deberse al contenido del mensaje o al estado de la cuenta del destinatario.',
            'action'   => 'Intenta de nuevo; si vuelve a fallar, contacta al cliente por otro canal.',
            'severity' => 'permanent',
        ],

        // ── Ventana de 24 horas ──────────────────────────────────────────────
        '131047' => [
            'title'    => 'Pasaron más de 24 horas desde el último mensaje del cliente',
            'detail'   => 'WhatsApp solo permite escribir libremente durante las 24 horas siguientes al último mensaje del cliente. Fuera de ese plazo únicamente se puede retomar la conversación con una plantilla aprobada.',
            'action'   => 'Reabre la conversación enviando una plantilla desde el chat; cuando el cliente responda podrás escribirle con normalidad.',
            'severity' => 'window',
        ],
        '470' => [
            'title'    => 'La conversación se cerró por inactividad',
            'detail'   => 'Pasó el plazo de 24 horas en el que se puede escribir libremente al cliente.',
            'action'   => 'Reabre la conversación con una plantilla aprobada.',
            'severity' => 'window',
        ],
        '480' => [
            'title'    => 'La conversación necesita reabrirse con una plantilla',
            'detail'   => 'WhatsApp considera que esta conversación ya no está activa, así que no acepta un mensaje escrito libremente.',
            'action'   => 'Envía una plantilla aprobada para retomar el contacto.',
            'severity' => 'window',
        ],

        // ── Plantillas ───────────────────────────────────────────────────────
        '132000' => [
            'title'    => 'La plantilla se envió con datos incompletos',
            'detail'   => 'La plantilla espera un número determinado de datos variables (nombre, valor, fecha…) y no se enviaron todos.',
            'action'   => 'Vuelve a enviarla desde el chat completando todos los campos.',
            'severity' => 'permanent',
        ],
        '132001' => [
            'title'    => 'La plantilla no existe',
            'detail'   => 'La plantilla que se intentó enviar no está disponible en la cuenta de WhatsApp de la empresa, o fue enviada con un idioma distinto al aprobado.',
            'action'   => 'Revisa el listado de plantillas y usa una que esté aprobada.',
            'severity' => 'config',
        ],
        '132005' => [
            'title'    => 'El texto de la plantilla quedó demasiado largo',
            'detail'   => 'Al rellenar los datos variables, el mensaje superó el largo máximo que permite WhatsApp.',
            'action'   => 'Acorta los datos que se insertan en la plantilla y vuelve a enviarla.',
            'severity' => 'permanent',
        ],
        '132007' => [
            'title'    => 'El contenido de la plantilla no cumple el formato permitido',
            'detail'   => 'Alguno de los datos insertados tiene caracteres que WhatsApp no acepta, como saltos de línea o espacios de más.',
            'action'   => 'Revisa los datos que se rellenan en la plantilla y vuelve a enviarla.',
            'severity' => 'permanent',
        ],
        '132012' => [
            'title'    => 'Un dato de la plantilla no tiene el formato esperado',
            'detail'   => 'WhatsApp esperaba otro formato en uno de los campos variables de la plantilla.',
            'action'   => 'Corrige el dato y vuelve a enviar la plantilla desde el chat.',
            'severity' => 'permanent',
        ],
        '132015' => [
            'title'    => 'La plantilla está pausada',
            'detail'   => 'WhatsApp pausó esta plantilla porque muchos destinatarios la marcaron como no deseada.',
            'action'   => 'Usa otra plantilla; un administrador debe revisar el contenido de la pausada.',
            'severity' => 'config',
        ],
        '132016' => [
            'title'    => 'La plantilla fue deshabilitada',
            'detail'   => 'WhatsApp deshabilitó esta plantilla de forma definitiva por su calidad.',
            'action'   => 'Usa otra plantilla; esta hay que rehacerla y volver a aprobarla.',
            'severity' => 'config',
        ],

        // ── Límites y calidad ────────────────────────────────────────────────
        '130429' => [
            'title'    => 'Se alcanzó el límite de mensajes por ahora',
            'detail'   => 'Se enviaron demasiados mensajes en poco tiempo y WhatsApp frenó el resto temporalmente.',
            'action'   => 'Espera unos minutos y reintenta.',
            'severity' => 'temporary',
        ],
        '131048' => [
            'title'    => 'WhatsApp limitó los envíos de esta línea',
            'detail'   => 'Se detectó un volumen de mensajes que WhatsApp considera excesivo, o varios destinatarios los marcaron como no deseados.',
            'action'   => 'Espera antes de reintentar y revisa el ritmo de envío de las campañas.',
            'severity' => 'temporary',
        ],
        '131049' => [
            'title'    => 'WhatsApp no entregó este mensaje para cuidar la experiencia del usuario',
            'detail'   => 'WhatsApp limita cuántos mensajes de tipo promocional recibe una persona. Este quedó fuera de ese límite.',
            'action'   => 'Espera al día siguiente o contacta al cliente por otro canal.',
            'severity' => 'temporary',
        ],
        '471' => [
            'title'    => 'Envíos bloqueados temporalmente por volumen',
            'detail'   => 'WhatsApp detuvo los envíos de esta línea durante un tiempo por exceso de mensajes.',
            'action'   => 'Espera antes de reintentar y baja el ritmo de las campañas.',
            'severity' => 'temporary',
        ],
        '368' => [
            'title'    => 'La cuenta está bloqueada temporalmente',
            'detail'   => 'WhatsApp bloqueó los envíos de esta cuenta por incumplir sus políticas.',
            'action'   => 'Un administrador debe revisar el estado de la cuenta en WhatsApp Business.',
            'severity' => 'config',
        ],

        // ── Cuenta y configuración ───────────────────────────────────────────
        '131031' => [
            'title'    => 'La cuenta de WhatsApp de la empresa está bloqueada',
            'detail'   => 'WhatsApp inhabilitó la cuenta, así que ningún mensaje puede salir.',
            'action'   => 'Un administrador debe revisar la cuenta en WhatsApp Business.',
            'severity' => 'config',
        ],
        '131042' => [
            'title'    => 'Hay un problema con el pago de la cuenta de WhatsApp',
            'detail'   => 'WhatsApp no permite enviar mensajes porque la forma de pago de la cuenta tiene un problema.',
            'action'   => 'Un administrador debe revisar el método de pago en WhatsApp Business.',
            'severity' => 'config',
        ],
        '133010' => [
            'title'    => 'La línea no está registrada en WhatsApp',
            'detail'   => 'El número desde el que se intentó enviar no está dado de alta en WhatsApp.',
            'action'   => 'Un administrador debe completar el registro de la línea.',
            'severity' => 'config',
        ],
        '33' => [
            'title'    => 'La línea de envío no está disponible',
            'detail'   => 'WhatsApp no reconoce el número desde el que se intentó enviar.',
            'action'   => 'Un administrador debe revisar la configuración de la línea.',
            'severity' => 'config',
        ],
        '0' => [
            'title'    => 'La conexión con WhatsApp no está autorizada',
            'detail'   => 'Las credenciales con las que el sistema se conecta a WhatsApp dejaron de ser válidas.',
            'action'   => 'Un administrador debe renovar la conexión con WhatsApp.',
            'severity' => 'config',
        ],
        '3' => [
            'title'    => 'La conexión con WhatsApp no tiene permisos suficientes',
            'detail'   => 'La cuenta conectada no tiene autorización para enviar este tipo de mensaje.',
            'action'   => 'Un administrador debe revisar los permisos de la conexión.',
            'severity' => 'config',
        ],
        '131005' => [
            'title'    => 'La conexión con WhatsApp no tiene acceso',
            'detail'   => 'WhatsApp negó el acceso a esta operación con las credenciales actuales.',
            'action'   => 'Un administrador debe revisar la conexión con WhatsApp.',
            'severity' => 'config',
        ],
        '100' => [
            'title'    => 'El mensaje se armó con datos incorrectos',
            'detail'   => 'WhatsApp rechazó el mensaje porque alguno de sus datos no era válido o faltaba.',
            'action'   => 'Vuelve a enviarlo desde el chat; si insiste, avisa a soporte con el número del mensaje.',
            'severity' => 'permanent',
        ],
        '131008' => [
            'title'    => 'Al mensaje le faltaba información obligatoria',
            'detail'   => 'WhatsApp esperaba un dato que el mensaje no traía.',
            'action'   => 'Vuelve a enviarlo desde el chat; si insiste, avisa a soporte con el número del mensaje.',
            'severity' => 'permanent',
        ],
        '131009' => [
            'title'    => 'Uno de los datos del mensaje no era válido',
            'detail'   => 'WhatsApp no aceptó el valor de alguno de los campos del mensaje.',
            'action'   => 'Vuelve a enviarlo desde el chat; si insiste, avisa a soporte con el número del mensaje.',
            'severity' => 'permanent',
        ],

        // ── Adjuntos ─────────────────────────────────────────────────────────
        '131051' => [
            'title'    => 'WhatsApp no admite este tipo de mensaje',
            'detail'   => 'El formato del mensaje o del archivo no es uno de los que WhatsApp permite enviar.',
            'action'   => 'Envía el contenido en otro formato, por ejemplo como PDF o imagen.',
            'severity' => 'permanent',
        ],
        '131052' => [
            'title'    => 'WhatsApp no pudo leer el archivo adjunto',
            'detail'   => 'El archivo no se pudo descargar para enviarlo. Puede estar dañado o haber dejado de estar disponible.',
            'action'   => 'Vuelve a adjuntar el archivo desde el chat.',
            'severity' => 'permanent',
        ],
        '131053' => [
            'title'    => 'El archivo adjunto no se pudo subir a WhatsApp',
            'detail'   => 'WhatsApp rechazó el archivo, normalmente por su tamaño o su formato.',
            'action'   => 'Reduce el tamaño del archivo o cámbialo de formato y vuelve a enviarlo.',
            'severity' => 'permanent',
        ],

        // ── Fallos pasajeros de WhatsApp ─────────────────────────────────────
        '131000' => [
            'title'    => 'WhatsApp tuvo un problema al procesar el mensaje',
            'detail'   => 'Fue un fallo interno de WhatsApp, ajeno al contenido del mensaje.',
            'action'   => 'Reintenta el envío; suele funcionar al segundo intento.',
            'severity' => 'temporary',
        ],
        '131016' => [
            'title'    => 'El servicio de WhatsApp no estaba disponible',
            'detail'   => 'WhatsApp no pudo atender el envío en ese momento.',
            'action'   => 'Reintenta en unos minutos.',
            'severity' => 'temporary',
        ],
        '133004' => [
            'title'    => 'El servicio de WhatsApp estaba caído',
            'detail'   => 'WhatsApp no estaba operativo cuando se intentó enviar el mensaje.',
            'action'   => 'Reintenta en unos minutos.',
            'severity' => 'temporary',
        ],
    ];

    /**
     * Texto con el que WhatsApp describe el error → código equivalente.
     *
     * Hace falta porque `error_code` llegó vacío en buena parte del histórico:
     * el webhook guardó el mensaje pero no el código. Sin esto, miles de fallos
     * perfectamente identificables se quedaban en "no pudo entregarse".
     *
     * El orden importa: se busca por coincidencia parcial, así que lo específico
     * va antes que lo genérico ("spam rate limit" antes de "rate limit").
     */
    private const MESSAGE_PATTERNS = [
        'message undeliverable'              => '131026',
        'not a whatsapp user'               => '131026',
        'business eligibility payment issue' => '131042',
        'part of an experiment'              => '131050',
        're-engagement message'              => '131047',
        'recipient cannot be sender'         => '131021',
        'spam rate limit hit'                => '131048',
        'rate limit hit'                     => '130429',
        'too many messages'                  => '130429',
        'business account is locked'         => '131031',
        'account has been locked'            => '131031',
        'template does not exist'            => '132001',
        'template name does not exist'       => '132001',
        'template is paused'                 => '132015',
        'template is disabled'               => '132016',
        'number of parameters'               => '132000',
        'parameter count'                    => '132000',
        'hydrated text'                      => '132005',
        'unsupported message type'           => '131051',
        'media download error'               => '131052',
        'media upload error'                 => '131053',
        'required parameter is missing'      => '131008',
        'invalid parameter'                  => '100',
        'access denied'                      => '131005',
        'phone number is not registered'     => '133010',
        'service unavailable'                => '131016',
        'temporarily unavailable'            => '131016',
        'something went wrong'               => '131000',
    ];

    /**
     * Fallos que genera el propio sistema antes de llegar a WhatsApp. Se
     * reconocen por un trozo del texto que dejó el proceso de envío.
     */
    private const INTERNAL = [
        'Instancia no configurada' => [
            'title'    => 'La línea de WhatsApp de la empresa está sin configurar',
            'detail'   => 'El mensaje no pudo salir porque la conexión con WhatsApp de esta empresa está incompleta.',
            'action'   => 'Un administrador debe terminar de configurar la línea en Instancias.',
            'severity' => 'config',
        ],
        // Nuestro propio bug histórico: se llamaba a WhatsApp sin el nombre de
        // la plantilla y devolvía este error. Ya está corregido en el envío, pero
        // los fallos viejos siguen en la base y merecen una explicación.
        'template.name' => [
            'title'    => 'No se conserva la plantilla original de este mensaje',
            'detail'   => 'Este mensaje lo envió un sistema externo y aquí solo quedó constancia de él, sin los datos que WhatsApp necesita para volver a enviarlo.',
            'action'   => 'Vuelve a lanzarlo desde el sistema que lo originó, o envía una plantilla equivalente desde el chat.',
            'severity' => 'permanent',
        ],
        'nombre de la plantilla' => [
            'title'    => 'No se conserva la plantilla original de este mensaje',
            'detail'   => 'Este mensaje lo envió un sistema externo y aquí solo quedó constancia de él, sin los datos que WhatsApp necesita para volver a enviarlo.',
            'action'   => 'Vuelve a lanzarlo desde el sistema que lo originó, o envía una plantilla equivalente desde el chat.',
            'severity' => 'permanent',
        ],
        'Tipo no soportado' => [
            'title'    => 'Este tipo de mensaje no se puede enviar',
            'detail'   => 'El sistema no sabe enviar mensajes de este tipo.',
            'action'   => 'Envía el contenido como texto, imagen o documento desde el chat.',
            'severity' => 'permanent',
        ],
    ];

    /**
     * Explica en lenguaje llano por qué el cliente no recibió el mensaje.
     *
     * @return array{title:string,detail:string,action:string,severity:string}
     */
    public function explain(WhatsAppMessage $message): array
    {
        if ($message->status === 'pending') {
            return [
                'title'    => 'El mensaje quedó sin enviar',
                'detail'   => 'El mensaje se guardó pero nunca salió hacia WhatsApp, así que el cliente no lo recibió ni lo verá.',
                'action'   => 'Reintenta el envío. Si varios mensajes quedan igual, avisa a soporte porque el envío puede estar detenido.',
                'severity' => 'temporary',
            ];
        }

        if ($message->status === 'sent') {
            return [
                'title'    => 'WhatsApp aún no confirma la entrega',
                'detail'   => 'El mensaje salió correctamente, pero WhatsApp todavía no avisa que llegó al teléfono. Suele pasar cuando el destinatario tiene el teléfono apagado o sin conexión; también puede ser que el número no use WhatsApp.',
                'action'   => 'Espera un poco más. Si el cliente necesita la información ya, contáctalo por otro medio.',
                'severity' => 'temporary',
            ];
        }

        if ($explanation = $this->fromCode($message->error_code)) {
            return $explanation;
        }

        // Los fallos internos se comprueban antes que el texto de WhatsApp: un
        // "template.name is required" es nuestro, no de ellos.
        if ($explanation = $this->fromInternalMessage($message->error_message)) {
            return $explanation;
        }

        if ($explanation = $this->fromWhatsAppMessage($message->error_message)) {
            return $explanation;
        }

        return [
            'title'    => 'WhatsApp no pudo entregar el mensaje',
            'detail'   => $this->genericDetail($message),
            'action'   => 'Reintenta el envío. Si vuelve a fallar, avisa a soporte indicando el número del mensaje.',
            'severity' => 'temporary',
        ];
    }

    /**
     * Titular llano con el que se agrupan los fallos en el resumen de motivos.
     * Acepta el código y el texto porque en el histórico a veces solo hay uno.
     */
    public function titleFor(?string $code, ?string $errorMessage): string
    {
        foreach ([
            $this->fromCode($code),
            $this->fromInternalMessage($errorMessage),
            $this->fromWhatsAppMessage($errorMessage),
        ] as $explanation) {
            if ($explanation) {
                return $explanation['title'];
            }
        }

        return self::OTHER_TITLE;
    }

    /**
     * Todo lo que el traductor sabe reconocer. Sirve para acotar "Otros motivos"
     * por exclusión, en vez de dejar ese grupo sin poder filtrarse.
     *
     * @return array{codes:array<string>,needles:array<string>}
     */
    public function allMatchers(): array
    {
        return [
            'codes'   => array_map('strval', array_keys(self::CODES)),
            'needles' => array_merge(
                array_keys(self::MESSAGE_PATTERNS),
                array_keys(self::INTERNAL),
            ),
        ];
    }

    /**
     * Con qué buscar en la base todos los fallos que comparten un titular. Lo
     * necesita el filtro del resumen: agrupa por explicación, no por código, así
     * que al pulsar un motivo hay que recomponer la consulta.
     *
     * @return array{codes:array<string>,needles:array<string>}
     */
    public function matchersForTitle(string $title): array
    {
        $codes = [];
        $needles = [];

        // Ojo: PHP convierte las claves numéricas del array en enteros, así que
        // los códigos se normalizan a texto antes de compararlos con los
        // patrones, que sí son strings.
        foreach (self::CODES as $code => $explanation) {
            if ($explanation['title'] === $title) {
                $codes[] = (string) $code;
            }
        }

        foreach (self::MESSAGE_PATTERNS as $needle => $code) {
            if (in_array((string) $code, $codes, true)) {
                $needles[] = $needle;
            }
        }

        foreach (self::INTERNAL as $needle => $explanation) {
            if ($explanation['title'] === $title) {
                $needles[] = $needle;
            }
        }

        return ['codes' => $codes, 'needles' => $needles];
    }

    public static function severities(): array
    {
        return self::SEVERITIES;
    }

    private function fromCode($code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::CODES[trim((string) $code)] ?? null;
    }

    private function fromInternalMessage(?string $errorMessage): ?array
    {
        if (! $errorMessage) {
            return null;
        }

        foreach (self::INTERNAL as $needle => $explanation) {
            if (mb_stripos($errorMessage, $needle) !== false) {
                return $explanation;
            }
        }

        return null;
    }

    /**
     * Reconoce el error por el texto que devolvió WhatsApp, para el histórico
     * que se guardó sin código.
     */
    private function fromWhatsAppMessage(?string $errorMessage): ?array
    {
        if (! $errorMessage) {
            return null;
        }

        foreach (self::MESSAGE_PATTERNS as $needle => $code) {
            if (mb_stripos($errorMessage, $needle) !== false) {
                return self::CODES[$code] ?? null;
            }
        }

        return null;
    }

    /**
     * Sin código conocido lo único cierto es que no llegó. Se evita volcar el
     * texto de WhatsApp —está en inglés y en jerga— salvo que no haya nada más.
     */
    private function genericDetail(WhatsAppMessage $message): string
    {
        $base = 'WhatsApp rechazó el mensaje y el motivo que devolvió no es uno de los habituales.';

        return $message->error_message
            ? $base . ' El detalle técnico está más abajo, por si soporte lo necesita.'
            : $base . ' No devolvió ninguna explicación.';
    }
}
