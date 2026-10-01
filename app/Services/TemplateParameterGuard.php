<?php

namespace App\Services;

use App\Models\Instance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Revisa —y arregla cuando puede— los parámetros de una plantilla antes de que
 * salgan hacia Meta.
 *
 * El caso que lo motiva: el CRM manda una plantilla con encabezado de imagen y
 * Meta responde 200 con wamid. El aviso parece enviado, pero minutos después
 * llega por webhook un 132012 "header: Format mismatch, expected IMAGE,
 * received UNKNOWN" y el cliente nunca vio nada. Quien lanzó el envío no se
 * entera, porque para él la llamada fue un éxito.
 *
 * Aquí se corta ese silencio. Dos trabajos, en este orden:
 *
 * 1. **Normalizar.** Meta exige los tipos en minúscula (`"type": "image"`); un
 *    `"IMAGE"` se convierte en un parámetro que no reconoce, y de ahí sale
 *    literalmente el "received UNKNOWN". También acepta `{"image": "https://…"}`
 *    en vez del objeto `{"link": …}` esperado. Ambos se corrigen en silencio: es
 *    un error de forma, no de intención, y rechazarlos no ayudaría a nadie.
 * 2. **Validar contra la definición real de la plantilla.** Si el encabezado
 *    falta, es de otro tipo, lleva un handle de creación en vez de un media id,
 *    o el archivo enlazado no existe o no es una imagen, se devuelve el motivo
 *    concreto y el envío no se hace.
 *
 * Regla de oro: **ante la duda, dejar pasar**. Si Meta no contesta al pedir la
 * definición, o la plantilla no aparece en el catálogo, el envío sigue su curso
 * como hasta ahora. Un guardarraíl que bloquea envíos buenos porque Graph tuvo
 * un mal minuto es peor que el problema que resuelve.
 */
class TemplateParameterGuard
{
    /** Código estable que reciben los sistemas externos cuando esto rechaza un envío. */
    public const CODE = 'template_parameter_invalid';

    /** Formatos de encabezado multimedia y lo que Meta acepta en cada uno. */
    private const MEDIA_FORMATS = ['IMAGE', 'VIDEO', 'DOCUMENT'];

    private const MIMES = [
        'IMAGE'    => ['image/jpeg', 'image/jpg', 'image/png'],
        'VIDEO'    => ['video/mp4', 'video/3gpp'],
        'DOCUMENT' => ['application/pdf'],
    ];

    /** Límites de tamaño de Meta por formato, en bytes. */
    private const MAX_BYTES = [
        'IMAGE'    => 5 * 1024 * 1024,
        'VIDEO'    => 16 * 1024 * 1024,
        'DOCUMENT' => 100 * 1024 * 1024,
    ];

    private const LABELS = [
        'IMAGE'    => 'una imagen',
        'VIDEO'    => 'un video',
        'DOCUMENT' => 'un documento',
    ];

    public function __construct(private MetaWhatsAppService $meta)
    {
    }

    /**
     * @return array{ok: bool, code: ?string, error: ?string, components: array}
     */
    public function check(Instance $instance, string $templateName, ?string $language, array $components): array
    {
        $components = $this->normalize($components);

        // Un dato vacío no necesita catálogo para saberse malo: Meta no acepta un
        // parámetro de texto en blanco, y lo que llega al cliente —cuando llega—
        // es un «Hola , tu factura…». Se dice aquí, con el sitio exacto.
        $vacio = $this->checkEmpty($templateName, $components);
        if (!$vacio['ok']) {
            return $vacio;
        }

        $definition = $this->definition($instance, $templateName, $language);
        if (!$definition) {
            // Sin catálogo no hay nada contra qué comparar: se deja pasar con lo
            // ya normalizado, que de por sí arregla los tipos en mayúscula.
            return $this->ok($components);
        }

        $estado = $this->checkStatus($instance, $definition, $templateName, $language);
        if (!$estado['ok']) {
            return $estado;
        }

        $header = $this->checkHeader($instance, $definition, $components);
        if (!$header['ok']) {
            return $header;
        }

        $body = $this->checkBody($definition, $header['components']);
        if (!$body['ok']) {
            return $body;
        }

        return $this->checkButtons($definition, $body['components']);
    }

    // ── Normalización ────────────────────────────────────────────────────────

    /**
     * Deja los componentes en la forma exacta que espera Meta. Lo que se corrige
     * aquí son erratas de formato de quien construye el payload, no decisiones.
     */
    public function normalize(array $components): array
    {
        $out = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $component['type'] = strtolower((string) ($component['type'] ?? ''));

            $parameters = [];
            foreach ($component['parameters'] ?? [] as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }

                $type = strtolower((string) ($parameter['type'] ?? ''));
                $parameter['type'] = $type;

                if ($type === 'text' && array_key_exists('text', $parameter) && is_scalar($parameter['text'])) {
                    $parameter['text'] = self::cleanText((string) $parameter['text']);
                }

                if ($type === 'coupon_code' && isset($parameter['coupon_code']) && is_scalar($parameter['coupon_code'])) {
                    $parameter['coupon_code'] = trim((string) $parameter['coupon_code']);
                }

                // La clave del media viene con el mismo nombre que el tipo, y a
                // veces en mayúscula ("IMAGE" => {...}); se unifica.
                foreach (['image', 'video', 'document', 'audio'] as $kind) {
                    $upper = strtoupper($kind);
                    if (isset($parameter[$upper]) && !isset($parameter[$kind])) {
                        $parameter[$kind] = $parameter[$upper];
                        unset($parameter[$upper]);
                    }

                    // {"image": "https://…"} en vez de {"image": {"link": "https://…"}}
                    if (isset($parameter[$kind]) && is_string($parameter[$kind])) {
                        $value = trim($parameter[$kind]);
                        $parameter[$kind] = str_starts_with($value, 'http')
                            ? ['link' => $value]
                            : ['id' => $value];
                    }
                }

                $parameters[] = $parameter;
            }

            if ($parameters !== []) {
                $component['parameters'] = $parameters;
            }

            $out[] = $component;
        }

        return $out;
    }

    /**
     * Meta rechaza un parámetro de texto con saltos de línea, tabuladores o más
     * de cuatro espacios seguidos: es el 132018, «There was an issue with the
     * parameters in your template» (a menudo citado aquí, por error, como el
     * 132007, que es otra cosa: contenido contra las políticas). El dato puede
     * venir del ERP, de un CSV o de alguien pegando una dirección en dos líneas;
     * ninguno lo hace a propósito, así que se aplana en vez de rechazarlo.
     *
     * Público y estático porque es la misma regla para todos los caminos que
     * arman parámetros: el chat, la API, las campañas y el respaldo.
     */
    public static function cleanText(string $value): string
    {
        $value = preg_replace('/[\r\n\t\x{2028}\x{2029}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/ {4,}/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Ningún parámetro de texto, ni el código de un botón de copiar, puede ir
     * vacío. Pasa sobre todo cuando el dato sale de un campo del contacto que
     * nadie rellenó, o de un ERP que manda `""` en vez de no mandar nada.
     */
    private function checkEmpty(string $templateName, array $components): array
    {
        foreach ($components as $component) {
            $type = $component['type'] ?? '';

            foreach ($component['parameters'] ?? [] as $i => $parameter) {
                $vacio = match ($parameter['type'] ?? '') {
                    'text' => trim((string) ($parameter['text'] ?? '')) === '',
                    'coupon_code' => trim((string) ($parameter['coupon_code'] ?? '')) === '',
                    default => false,
                };

                if (!$vacio) {
                    continue;
                }

                $donde = match ($type) {
                    'header' => 'del encabezado',
                    'button' => 'del botón ' . (((int) ($component['index'] ?? 0)) + 1),
                    default => 'del cuerpo',
                };

                $cual = !empty($parameter['parameter_name'])
                    ? '«' . $parameter['parameter_name'] . '»'
                    : '{{' . ($i + 1) . '}}';

                return $this->fail(
                    'template_parameter_empty',
                    "El dato {$cual} {$donde} de «{$templateName}» está vacío y WhatsApp no acepta datos en blanco. "
                    . 'Rellénalo antes de enviar la plantilla.'
                );
            }
        }

        return $this->ok($components);
    }

    // ── Estado de la plantilla ───────────────────────────────────────────────

    /**
     * Una plantilla pausada o deshabilitada se acepta con 200 y se rechaza
     * después por webhook (132015 / 132016). El catálogo ya dice su estado: no
     * hay por qué gastar el envío para enterarse.
     *
     * Antes de bloquear se vuelve a pedir el catálogo sin caché: el que se
     * guardó puede ser de hace diez minutos, y una plantilla recién aprobada
     * seguiría «en revisión» ahí dentro.
     */
    private function checkStatus(Instance $instance, array $definition, string $templateName, ?string $language): array
    {
        $status = strtoupper((string) ($definition['status'] ?? ''));

        if ($status === '' || $status === 'APPROVED') {
            return $this->ok([]);
        }

        $fresca = $this->definition($instance, $templateName, $language, true);
        $status = strtoupper((string) ($fresca['status'] ?? $status));

        if ($status === 'APPROVED') {
            return $this->ok([]);
        }

        $motivo = match ($status) {
            'PAUSED' => 'está pausada por Meta porque varios destinatarios la marcaron como no deseada. Usa otra plantilla mientras un administrador la revisa',
            'DISABLED' => 'fue deshabilitada por Meta de forma definitiva por su calidad. Hay que crear una nueva con otro contenido',
            'PENDING', 'IN_APPEAL' => 'todavía está en revisión en Meta. Espera a que la aprueben o usa otra',
            'REJECTED' => 'fue rechazada por Meta. Revisa el motivo en Plantillas y corrígela',
            'LIMIT_EXCEEDED' => 'superó el límite de plantillas de la cuenta y Meta no la deja usar',
            default => "no está aprobada en Meta (estado {$status})",
        };

        return $this->fail(
            'template_not_approved',
            "La plantilla «{$templateName}» {$motivo}."
        );
    }

    // ── Encabezado ───────────────────────────────────────────────────────────

    private function checkHeader(Instance $instance, array $definition, array $components): array
    {
        $expected = $this->headerFormat($definition);

        $index = $this->componentIndex($components, 'header');
        $parameter = $index === null ? null : ($components[$index]['parameters'][0] ?? null);

        if (!in_array($expected, self::MEDIA_FORMATS, true)) {
            // Al revés que el caso de abajo: la plantilla NO lleva archivo y el
            // envío manda uno. Meta lo rechaza con un 132018 —«Template does not
            // contain title component»— y eso llega al operador como «no se pudo
            // enviar la factura», sin decirle qué mirar.
            //
            // Le pasó a Enternet el 22-sep-2026 con la plantilla `tirillas`:
            // marcada en Integra como «con documento» y aprobada en Meta sin
            // encabezado. Son dos sistemas y sólo uno de los dos lo sabía.
            $sobra = $this->formatoDelParametro($parameter);

            if ($sobra !== null) {
                $comoEsta = $expected === null
                    ? 'no tiene encabezado'
                    : 'tiene el encabezado de texto';

                return $this->fail(
                    'template_header_not_expected',
                    "La plantilla «{$definition['name']}» {$comoEsta}, pero el envío le adjunta "
                    . self::LABELS[$sobra] . '. Quita el archivo del envío, o añádele el encabezado '
                    . 'a la plantilla en Meta y espera a que la aprueben.'
                );
            }

            if ($expected === 'TEXT') {
                return $this->checkHeaderText($definition, $components, $index);
            }

            return $this->ok($components);
        }

        $kind = strtolower($expected);
        $label = self::LABELS[$expected];

        if (!$parameter) {
            return $this->fail(
                'template_header_missing',
                "La plantilla «{$definition['name']}» lleva {$label} en el encabezado y el envío no incluye ninguna. "
                . "Añade el componente header con el archivo antes de enviarla."
            );
        }

        if (($parameter['type'] ?? '') !== $kind) {
            $received = strtoupper((string) ($parameter['type'] ?? '')) ?: 'nada';
            return $this->fail(
                'template_header_type_mismatch',
                "El encabezado de «{$definition['name']}» debe ser {$label} ({$expected}), pero el envío manda {$received}."
            );
        }

        $media = $parameter[$kind] ?? [];
        if (!is_array($media) || (empty($media['id']) && empty($media['link']))) {
            return $this->fail(
                'template_header_empty',
                "El encabezado de «{$definition['name']}» viene sin archivo: hace falta un `id` de media de WhatsApp "
                . "o un `link` público a {$label}."
            );
        }

        if (!empty($media['id'])) {
            $checked = $this->checkMediaId($instance, (string) $media['id'], $expected, $definition['name']);
            if (!$checked['ok']) {
                return $checked;
            }

            return $this->ok($components);
        }

        $resolved = $this->resolveLink($instance, (string) $media['link'], $expected, $definition['name']);
        if (!$resolved['ok']) {
            return $resolved;
        }

        // El link se cambia por el media id ya subido a Meta: así el envío deja de
        // depender de que Meta alcance nuestra URL en ese instante.
        $media = ['id' => $resolved['media_id']] + array_intersect_key($media, ['filename' => true]);
        $components[$index]['parameters'][0][$kind] = $media;

        return $this->ok($components);
    }

    /**
     * Encabezado de texto con variable: «Tu factura de {{1}}» o «{{mes}}».
     *
     * Meta admite como mucho una variable en el encabezado. Si la plantilla la
     * tiene y el envío no la manda, o al revés, el rechazo es un 132000 que el
     * agente lee como «no se pudo enviar»; el chat ni siquiera dejaba
     * rellenarla. Si la definición no trae el texto del encabezado no se sabe
     * qué espera, y ante la duda se deja pasar.
     */
    private function checkHeaderText(array $definition, array $components, ?int $index): array
    {
        $header = $this->definitionComponent($definition, 'HEADER');

        if (!$header || !array_key_exists('text', $header)) {
            return $this->ok($components);
        }

        $expected = $this->variableNames((string) $header['text']);
        $given = $index === null ? [] : array_values(array_filter(
            $components[$index]['parameters'] ?? [],
            fn ($p) => ($p['type'] ?? '') === 'text'
        ));

        if ($expected === [] && $given === []) {
            return $this->ok($components);
        }

        if ($expected === []) {
            return $this->fail(
                'template_header_text_not_expected',
                "El encabezado de «{$definition['name']}» es un texto fijo y el envío le manda un dato. Quita el dato del encabezado."
            );
        }

        if ($given === []) {
            return $this->fail(
                'template_header_text_missing',
                "El encabezado de «{$definition['name']}» lleva un dato ({$this->listaDeVariables($expected)}) y el envío no lo incluye. "
                . 'Rellénalo antes de enviar la plantilla.'
            );
        }

        $checked = $this->checkParameterSet($definition, $expected, $given, 'el encabezado', $this->isNamed($definition, $expected));

        return $checked['ok'] ? $this->ok($components) : $checked;
    }

    /**
     * Un media id de Meta es un número. Un `h:ARb...` es el handle que devuelve la
     * subida reanudable y solo sirve para *crear* plantillas: usarlo al enviar es
     * el error clásico, y Meta lo reporta como "received UNKNOWN".
     */
    private function checkMediaId(Instance $instance, string $mediaId, string $expected, string $templateName): array
    {
        $label = self::LABELS[$expected];

        if (!preg_match('/^\d{5,}$/', $mediaId)) {
            return $this->fail(
                'template_header_handle',
                "El encabezado de «{$templateName}» lleva «" . mb_substr($mediaId, 0, 24) . "…» como identificador del archivo, "
                . "y eso no es un media id de WhatsApp. El identificador que empieza por «h:» solo sirve para crear la plantilla; "
                . "para enviarla hay que subir el archivo a /media y usar el id numérico que devuelve."
            );
        }

        if (empty($instance->access_token)) {
            return $this->ok([]);
        }

        // La clave lleva la instancia: un media id sólo existe para el número que
        // lo subió, y con la clave a secas el «no existe» de una empresa le
        // tapaba el archivo bueno a otra durante una hora.
        $key = "wa:media:{$instance->id}:{$mediaId}";
        $info = Cache::get($key);

        if ($info === null) {
            $info = $this->meta->mediaInfo($mediaId, $instance->access_token);

            if ($info === null) {
                // Sin respuesta clara —timeout, 5xx, 429, token caducado— no se
                // sabe si el archivo existe. Antes eso se cacheaba una hora como
                // «borrado» y bloqueaba todos los envíos con ese archivo por un
                // mal minuto de Graph. Ahora no se guarda, y se deja pasar.
                return $this->ok([]);
            }

            // Un 400/404 sí es claro, pero se guarda poco: si alguien lo arregla,
            // no debería esperar una hora para comprobarlo.
            Cache::put($key, $info, !empty($info['missing']) ? now()->addMinutes(10) : now()->addHour());
        }

        if (!empty($info['missing'])) {
            return $this->fail(
                'template_header_media_gone',
                "El archivo del encabezado de «{$templateName}» ya no existe en WhatsApp (Meta los borra a los 30 días) "
                . "o pertenece a otra línea. Vuelve a subirlo y usa el media id nuevo."
            );
        }

        if (!$this->mimeMatches($info['mime_type'] ?? '', $expected)) {
            return $this->fail(
                'template_header_type_mismatch',
                "El encabezado de «{$templateName}» espera {$label} y el archivo subido es «" . ($info['mime_type'] ?: 'desconocido') . "»."
            );
        }

        return $this->ok([]);
    }

    /**
     * Comprueba el link y lo sube a Meta. Se hace en dos tiempos para no
     * descargar de más: primero una cabecera, y solo se baja el archivo si hay
     * que subirlo.
     */
    private function resolveLink(Instance $instance, string $link, string $expected, string $templateName): array
    {
        $label = self::LABELS[$expected];

        if (!filter_var($link, FILTER_VALIDATE_URL) || !str_starts_with($link, 'http')) {
            return $this->fail(
                'template_header_link_invalid',
                "El encabezado de «{$templateName}» apunta a «{$link}», que no es una URL válida."
            );
        }

        // Primero se pregunta el tamaño. Descargar a ciegas un PDF de 100 MB en un
        // worker de 512 MB de memoria es la forma de tumbar la cola entera por un
        // archivo que además íbamos a rechazar.
        $peso = $this->pesoDeclarado($link);
        if ($peso !== null && $peso > self::MAX_BYTES[$expected]) {
            $max = round(self::MAX_BYTES[$expected] / 1048576);
            return $this->fail(
                'template_header_too_big',
                "El archivo del encabezado de «{$templateName}» pesa " . round($peso / 1048576, 1)
                . " MB y WhatsApp acepta como mucho {$max} MB."
            );
        }

        try {
            $response = Http::timeout(20)->withOptions(['stream' => false])->get($link);
        } catch (\Throwable $e) {
            return $this->fail(
                'template_header_link_unreachable',
                "No se pudo descargar {$label} del encabezado de «{$templateName}»: {$e->getMessage()}. "
                . "La URL debe ser pública y accesible sin contraseña."
            );
        }

        if (!$response->successful()) {
            return $this->fail(
                'template_header_link_unreachable',
                "La URL {$label} del encabezado de «{$templateName}» respondió {$response->status()}. "
                . "Debe ser pública y accesible sin contraseña."
            );
        }

        $body = $response->body();
        $mime = $this->sniffMime($body, $response->header('Content-Type'));

        if (!$this->mimeMatches($mime, $expected)) {
            return $this->fail(
                'template_header_link_wrong_type',
                "El encabezado de «{$templateName}» espera {$label} y esa URL devuelve «{$mime}». "
                . "Comprueba que el enlace lleva directo al archivo y no a una página web."
            );
        }

        if (strlen($body) > self::MAX_BYTES[$expected]) {
            $max = round(self::MAX_BYTES[$expected] / 1048576);
            return $this->fail(
                'template_header_too_big',
                "El archivo del encabezado de «{$templateName}» pesa más de {$max} MB, el máximo que acepta WhatsApp."
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wa_header_');
        file_put_contents($tmp, $body);

        try {
            $upload = $this->meta->uploadMedia($instance->phone_number_id, $tmp, $mime);
        } finally {
            @unlink($tmp);
        }

        if (!($upload['success'] ?? false)) {
            return $this->fail(
                'template_header_upload_failed',
                "WhatsApp no aceptó el archivo del encabezado de «{$templateName}». Prueba con otro archivo "
                . "({$label}, formato " . implode(' o ', self::MIMES[$expected]) . ")."
            );
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'media_id' => (string) $upload['id'], 'components' => []];
    }

    /**
     * El `Content-Length` que anuncia el servidor, o null si no lo dice o no
     * admite HEAD. Es una pista, no una garantía: el tamaño real se vuelve a
     * comprobar sobre los bytes descargados.
     */
    private function pesoDeclarado(string $link): ?int
    {
        try {
            $head = Http::timeout(8)->head($link);
        } catch (\Throwable $e) {
            return null;
        }

        $length = $head->header('Content-Length');

        return is_numeric($length) ? (int) $length : null;
    }

    private function sniffMime(string $body, ?string $declared): string
    {
        // El content-type declarado miente a menudo (application/octet-stream, o
        // text/html de una página de error con estado 200), así que manda el
        // contenido real.
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_buffer($finfo, $body);
            finfo_close($finfo);
            if ($detected && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }

        return strtolower(trim(explode(';', (string) $declared)[0])) ?: 'desconocido';
    }

    private function mimeMatches(string $mime, string $expected): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return in_array($mime, self::MIMES[$expected], true);
    }

    // ── Cuerpo ───────────────────────────────────────────────────────────────

    /**
     * Las variables que declara el cuerpo frente a las que manda el envío. Es el
     * 132000 de Meta, avisado antes de gastarlo.
     *
     * Con parámetros con nombre (`parameter_format: NAMED`, variables como
     * `{{nombre_cliente}}`) no basta con contar: cada parámetro tiene que llevar
     * su `parameter_name`, y el que no lo lleva Meta no lo sabe colocar.
     */
    private function checkBody(array $definition, array $components): array
    {
        $body = $this->definitionComponent($definition, 'BODY');
        $expected = $body ? $this->variableNames((string) ($body['text'] ?? '')) : [];

        $index = $this->componentIndex($components, 'body');
        $given = $index === null ? [] : array_values($components[$index]['parameters'] ?? []);

        if (!$this->isNamed($definition, $expected)) {
            if (count($expected) === count($given)) {
                return $this->ok($components);
            }

            $n = count($expected);

            return $this->fail(
                'template_body_parameters',
                "La plantilla «{$definition['name']}» necesita {$n} " . ($n === 1 ? 'dato' : 'datos')
                . ' en el cuerpo y el envío manda ' . count($given) . '.'
            );
        }

        $checked = $this->checkParameterSet($definition, $expected, $given, 'el cuerpo', true);

        return $checked['ok'] ? $this->ok($components) : $checked;
    }

    /**
     * Compara los parámetros de un componente con las variables de la
     * definición. Sólo hace falta para los nombrados (o el encabezado): los
     * posicionales del cuerpo se cuentan en `checkBody()`.
     */
    private function checkParameterSet(array $definition, array $expected, array $given, string $donde, bool $named): array
    {
        if (!$named) {
            if (count($expected) === count($given)) {
                return $this->ok([]);
            }

            return $this->fail(
                'template_body_parameters',
                "La plantilla «{$definition['name']}» necesita " . count($expected) . " dato(s) en {$donde} y el envío manda " . count($given) . '.'
            );
        }

        $nombres = array_map(fn ($p) => (string) ($p['parameter_name'] ?? ''), $given);

        if (in_array('', $nombres, true)) {
            return $this->fail(
                'template_parameter_name_missing',
                "La plantilla «{$definition['name']}» usa datos con nombre ({$this->listaDeVariables($expected)}) en {$donde}, "
                . 'y cada dato del envío tiene que decir a cuál corresponde con `parameter_name`. Sin eso WhatsApp no sabe dónde va cada uno.'
            );
        }

        $faltan = array_values(array_diff($expected, $nombres));
        $sobran = array_values(array_diff($nombres, $expected));

        if ($faltan === [] && $sobran === []) {
            return $this->ok([]);
        }

        $partes = [];
        if ($faltan !== []) {
            $partes[] = 'faltan ' . $this->listaDeVariables($faltan);
        }
        if ($sobran !== []) {
            $partes[] = 'sobran ' . $this->listaDeVariables($sobran) . ', que la plantilla no tiene';
        }

        return $this->fail(
            'template_body_parameters',
            "Los datos de {$donde} de «{$definition['name']}» no cuadran con la plantilla: " . implode(' y ', $partes) . '.'
        );
    }

    // ── Botones ──────────────────────────────────────────────────────────────

    /**
     * Los botones que cambian en cada envío: una URL con `{{1}}` al final, un
     * código para copiar, o el código de una plantilla de autenticación.
     *
     * Meta los exige como componente aparte —`type: button`, con su `sub_type`
     * y el `index` del botón dentro de la plantilla— y sin él rechaza el envío
     * entero. El chat ni siquiera los pedía: esas plantillas eran imposibles de
     * mandar desde Integra.
     */
    private function checkButtons(array $definition, array $components): array
    {
        foreach (self::dynamicButtons($definition) as $boton) {
            $dado = null;
            foreach ($components as $component) {
                if (($component['type'] ?? '') === 'button' && (string) ($component['index'] ?? '') === (string) $boton['index']) {
                    $dado = $component;
                    break;
                }
            }

            $etiqueta = $boton['text'] !== '' ? "«{$boton['text']}»" : 'número ' . ($boton['index'] + 1);
            $queLleva = $boton['sub_type'] === 'copy_code' ? 'el código que se copia' : 'el dato que completa el enlace';
            if ($boton['otp']) {
                $queLleva = 'el código de verificación';
            }

            if ($dado === null) {
                return $this->fail(
                    'template_button_missing',
                    "El botón {$etiqueta} de «{$definition['name']}» cambia en cada envío y el envío no incluye {$queLleva}. "
                    . 'Rellénalo antes de enviar la plantilla.'
                );
            }

            if (strtolower((string) ($dado['sub_type'] ?? '')) !== $boton['sub_type']) {
                return $this->fail(
                    'template_button_type_mismatch',
                    "El botón {$etiqueta} de «{$definition['name']}» es de tipo «{$boton['sub_type']}» y el envío lo manda como «"
                    . ($dado['sub_type'] ?? 'nada') . '».'
                );
            }

            $parametro = $dado['parameters'][0] ?? null;
            $tipo = $boton['sub_type'] === 'copy_code' ? 'coupon_code' : 'text';

            if (!is_array($parametro) || ($parametro['type'] ?? '') !== $tipo || trim((string) ($parametro[$tipo] ?? '')) === '') {
                return $this->fail(
                    'template_button_missing',
                    "El botón {$etiqueta} de «{$definition['name']}» llega sin {$queLleva}."
                );
            }
        }

        return $this->ok($components);
    }

    /**
     * Qué botones de la definición necesitan un dato en el envío.
     *
     * @return array<int, array{index:int, sub_type:string, text:string, otp:bool}>
     */
    public static function dynamicButtons(array $definition): array
    {
        $out = [];

        foreach ($definition['components'] ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }

            foreach (array_values($component['buttons'] ?? []) as $i => $button) {
                $type = strtoupper((string) ($button['type'] ?? ''));
                $text = (string) ($button['text'] ?? '');

                if ($type === 'URL' && str_contains((string) ($button['url'] ?? ''), '{{')) {
                    $out[] = ['index' => $i, 'sub_type' => 'url', 'text' => $text, 'otp' => false];
                } elseif ($type === 'COPY_CODE') {
                    $out[] = ['index' => $i, 'sub_type' => 'copy_code', 'text' => $text, 'otp' => false];
                } elseif ($type === 'OTP') {
                    // Las de autenticación mandan el código como si fuera el
                    // sufijo de una URL, sea cual sea el tipo de OTP.
                    $out[] = ['index' => $i, 'sub_type' => 'url', 'text' => $text, 'otp' => true];
                }
            }
        }

        return $out;
    }

    /**
     * Nombres de las variables de un texto, en orden de primera aparición y sin
     * repetir: `['1', '2']` o `['nombre', 'factura']`.
     *
     * @return array<int, string>
     */
    private function variableNames(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * ¿La plantilla usa parámetros con nombre? Lo dice `parameter_format` si el
     * catálogo lo trae; si no, se nota en las propias variables: Meta sólo deja
     * números en las posicionales y sólo minúsculas y guiones bajos en las
     * nombradas, así que no hay ambigüedad.
     */
    private function isNamed(array $definition, array $names): bool
    {
        $format = strtoupper((string) ($definition['parameter_format'] ?? ''));

        if ($format !== '') {
            return $format === 'NAMED';
        }

        foreach ($names as $name) {
            if (!ctype_digit((string) $name)) {
                return true;
            }
        }

        return false;
    }

    private function listaDeVariables(array $names): string
    {
        return implode(', ', array_map(fn ($n) => '{{' . $n . '}}', $names));
    }

    private function definitionComponent(array $definition, string $type): ?array
    {
        foreach ($definition['components'] ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === $type) {
                return $component;
            }
        }

        return null;
    }

    /**
     * El cuerpo de la plantilla ya resuelto: lo que de verdad va a leer el
     * cliente en su teléfono.
     *
     * Existe porque el chat guardaba «[Plantilla: facturacion]» en los envíos
     * que entran por la API — los que manda el ERP— mientras que el chat y las
     * campañas sí guardan el texto compuesto. La diferencia no es cosmética: en
     * Conecta Comunicaciones el ERP llevaba días mandando los parámetros
     * descolocados, así que a los clientes les llegaba «tu factura ha sido
     * generada bajo el número **y la fecha de vencimiento es 2026-09-27**», y en
     * el CRM no se veía porque la burbuja sólo decía el nombre de la plantilla.
     * Con el texto compuesto, eso se ve el primer día con sólo abrir el chat.
     *
     * Devuelve `null` cuando no hay catálogo —sin `waba_id`, sin token, o Graph
     * caído—: quien llama decide el respaldo. Aquí no se inventa un texto.
     */
    public function preview(Instance $instance, string $templateName, ?string $language, array $components): ?string
    {
        $definition = $this->definition($instance, $templateName, $language);

        if (! $definition) {
            return null;
        }

        $cuerpo = null;

        foreach ($definition['components'] ?? [] as $componente) {
            if (strtoupper($componente['type'] ?? '') === 'BODY') {
                $cuerpo = (string) ($componente['text'] ?? '');
                break;
            }
        }

        if (blank($cuerpo)) {
            return null;
        }

        $valores = [];
        $porNombre = [];

        foreach ($this->normalize($components) as $componente) {
            if (strtolower($componente['type'] ?? '') !== 'body') {
                continue;
            }

            foreach ($componente['parameters'] ?? [] as $parametro) {
                $valores[] = (string) ($parametro['text'] ?? '');

                if (!empty($parametro['parameter_name'])) {
                    $porNombre[(string) $parametro['parameter_name']] = (string) ($parametro['text'] ?? '');
                }
            }
        }

        // Un {{n}} sin valor se deja tal cual y no se borra: si el ERP manda tres
        // parámetros para una plantilla de cuatro, el hueco en el texto es la
        // señal de que falta uno. Sustituirlo por vacío lo escondería. Lo mismo
        // con un {{nombre}} que el envío no trae.
        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            function (array $coincidencia) use ($valores, $porNombre) {
                $clave = $coincidencia[1];

                if (ctype_digit($clave)) {
                    return $valores[((int) $clave) - 1] ?? $coincidencia[0];
                }

                return $porNombre[$clave] ?? $coincidencia[0];
            },
            $cuerpo
        );
    }

    // ── Definición de la plantilla ───────────────────────────────────────────

    /**
     * La plantilla tal y como está aprobada en el WABA. Cacheada por WABA: una
     * campaña de 500 destinatarios hace una sola llamada a Graph.
     *
     * `$fresh` se salta la caché: lo usa la comprobación de estado antes de
     * bloquear, para no frenar una plantilla que se aprobó hace dos minutos.
     */
    public function definition(Instance $instance, string $templateName, ?string $language, bool $fresh = false): ?array
    {
        if (empty($instance->waba_id) || empty($instance->access_token)) {
            return null;
        }

        $key = "wa:templates:{$instance->waba_id}";

        if ($fresh) {
            Cache::forget($key);
        }

        $catalog = Cache::remember($key, now()->addMinutes(10), fn () => $this->fetchCatalog($instance));

        if (!is_array($catalog)) {
            Cache::forget($key);
            return null;
        }

        $matches = array_values(array_filter(
            $catalog,
            fn ($template) => ($template['name'] ?? null) === $templateName
        ));

        if ($matches === []) {
            // Una plantilla recién creada todavía puede no aparecer: no es
            // asunto de este guardarraíl decidir que no existe.
            return null;
        }

        if ($language) {
            foreach ($matches as $template) {
                if (($template['language'] ?? null) === $language) {
                    return $template;
                }
            }

            // Se pidió un idioma que no está. Antes se devolvía la primera
            // versión del nombre —p. ej. la `en_US` para un envío `es`— y se
            // validaba el envío contra una plantilla que no es la que se manda:
            // variables distintas, encabezado distinto, estado distinto. Meta
            // lo rechazará con un 132001; aquí no se adivina.
            return null;
        }

        // Sin idioma sólo hay respuesta segura si el nombre existe en uno solo.
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * El catálogo entero, siguiendo la paginación de Graph. Con 200 por página
     * las cuentas grandes —una agencia con plantillas por cliente— se quedaban a
     * medias, y una plantilla de la segunda página nunca se validaba.
     *
     * Tope de páginas por si Graph devolviera un cursor que no avanza.
     */
    private function fetchCatalog(Instance $instance): ?array
    {
        $templates = [];
        $after = null;

        for ($page = 0; $page < 10; $page++) {
            $params = ['limit' => 200];
            if ($after) {
                $params['after'] = $after;
            }

            $result = $this->meta->listTemplates($instance->waba_id, $instance->access_token, $params);

            if (!($result['success'] ?? false)) {
                Log::channel('whatsapp')->warning('No se pudo leer el catálogo de plantillas para validar el envío', [
                    'waba_id' => $instance->waba_id,
                    'pagina' => $page + 1,
                ]);

                // Media lista es peor que ninguna: haría creer que una plantilla
                // de la página que falta no existe.
                return null;
            }

            $templates = array_merge($templates, $result['data']['data'] ?? []);

            $next = $result['data']['paging']['next'] ?? null;
            $cursor = $result['data']['paging']['cursors']['after'] ?? null;

            if (!$next || !$cursor || $cursor === $after) {
                break;
            }

            $after = $cursor;
        }

        return $templates;
    }

    /**
     * Qué tipo de archivo trae este parámetro de encabezado, si trae alguno.
     *
     * `null` cuando es texto o cuando no hay parámetro: lo que interesa es
     * distinguir «viene un archivo» de «no viene», no validar el texto.
     */
    private function formatoDelParametro(?array $parameter): ?string
    {
        if (!$parameter) {
            return null;
        }

        $tipo = strtoupper((string) ($parameter['type'] ?? ''));

        return in_array($tipo, self::MEDIA_FORMATS, true) ? $tipo : null;
    }

    private function headerFormat(array $definition): ?string
    {
        foreach ($definition['components'] ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === 'HEADER') {
                return strtoupper($component['format'] ?? 'TEXT');
            }
        }

        return null;
    }

    private function componentIndex(array $components, string $type): ?int
    {
        foreach ($components as $i => $component) {
            if (strtolower($component['type'] ?? '') === $type) {
                return $i;
            }
        }

        return null;
    }

    private function ok(array $components): array
    {
        return ['ok' => true, 'code' => null, 'error' => null, 'components' => $components];
    }

    private function fail(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'components' => []];
    }
}
