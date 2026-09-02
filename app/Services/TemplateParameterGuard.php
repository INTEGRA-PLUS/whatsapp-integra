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

        $definition = $this->definition($instance, $templateName, $language);
        if (!$definition) {
            // Sin catálogo no hay nada contra qué comparar: se deja pasar con lo
            // ya normalizado, que de por sí arregla los tipos en mayúscula.
            return $this->ok($components);
        }

        $header = $this->checkHeader($instance, $definition, $components);
        if (!$header['ok']) {
            return $header;
        }

        return $this->checkBody($definition, $header['components']);
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

    // ── Encabezado ───────────────────────────────────────────────────────────

    private function checkHeader(Instance $instance, array $definition, array $components): array
    {
        $expected = $this->headerFormat($definition);

        $index = $this->componentIndex($components, 'header');
        $parameter = $index === null ? null : ($components[$index]['parameters'][0] ?? null);

        // Plantilla sin encabezado, o con encabezado de texto: no hay archivo que
        // validar. El desajuste de variables de texto lo cubre Meta con un 132000
        // y no merece bloquear aquí.
        if (!in_array($expected, self::MEDIA_FORMATS, true)) {
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

        $info = Cache::remember(
            "wa:media:{$mediaId}",
            now()->addHour(),
            fn () => $this->meta->mediaInfo($mediaId, $instance->access_token) ?? ['missing' => true]
        );

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
     * Cuántas variables declara el cuerpo de la plantilla frente a cuántas manda
     * el envío. Es el 132000 de Meta, avisado antes de gastarlo.
     */
    private function checkBody(array $definition, array $components): array
    {
        $body = null;
        foreach ($definition['components'] ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                $body = $component;
                break;
            }
        }

        $expected = $body ? $this->countVariables($body['text'] ?? '') : 0;

        $index = $this->componentIndex($components, 'body');
        $given = $index === null ? 0 : count($components[$index]['parameters'] ?? []);

        if ($expected === $given) {
            return $this->ok($components);
        }

        return $this->fail(
            'template_body_parameters',
            "La plantilla «{$definition['name']}» necesita {$expected} " . ($expected === 1 ? 'dato' : 'datos')
            . " en el cuerpo y el envío manda {$given}."
        );
    }

    private function countVariables(string $text): int
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);

        return count(array_unique($matches[1] ?? []));
    }

    // ── Definición de la plantilla ───────────────────────────────────────────

    /**
     * La plantilla tal y como está aprobada en el WABA. Cacheada por WABA: una
     * campaña de 500 destinatarios hace una sola llamada a Graph.
     */
    public function definition(Instance $instance, string $templateName, ?string $language): ?array
    {
        if (empty($instance->waba_id) || empty($instance->access_token)) {
            return null;
        }

        $catalog = Cache::remember(
            "wa:templates:{$instance->waba_id}",
            now()->addMinutes(10),
            function () use ($instance) {
                $result = $this->meta->listTemplates($instance->waba_id, $instance->access_token, ['limit' => 200]);

                if (!($result['success'] ?? false)) {
                    Log::channel('whatsapp')->warning('No se pudo leer el catálogo de plantillas para validar el envío', [
                        'waba_id' => $instance->waba_id,
                    ]);
                    return null;
                }

                return $result['data']['data'] ?? [];
            }
        );

        if (!is_array($catalog)) {
            Cache::forget("wa:templates:{$instance->waba_id}");
            return null;
        }

        $matches = array_values(array_filter(
            $catalog,
            fn ($template) => ($template['name'] ?? null) === $templateName
        ));

        if ($matches === []) {
            // Puede ser un catálogo paginado o una plantilla recién creada: no es
            // asunto de este guardarraíl decidir que no existe.
            return null;
        }

        if ($language) {
            foreach ($matches as $template) {
                if (($template['language'] ?? null) === $language) {
                    return $template;
                }
            }
        }

        return $matches[0];
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
