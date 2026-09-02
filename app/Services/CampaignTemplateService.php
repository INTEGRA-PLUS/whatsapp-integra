<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Traduce la configuración de plantilla de una campaña (lo que el usuario llenó
 * en el modal) a los `components` que espera Meta, y la valida contra la
 * definición real de la plantilla en el WABA antes de guardar.
 *
 * La validación previa es deliberada: enviar una plantilla mal armada solo
 * falla en Meta, destinatario por destinatario (error 132012 "Format mismatch"),
 * y esos fallos son permanentes: no sirve reintentarlos. En una campaña masiva
 * eso significa quemar la lista completa por un dato mal puesto.
 */
class CampaignTemplateService
{
    /** Formatos de encabezado que exigen adjuntar un archivo. */
    public const MEDIA_HEADERS = ['IMAGE', 'VIDEO', 'DOCUMENT'];

    /** Carpeta del bucket donde vive la copia propia del encabezado. */
    public const MEDIA_DIR = 'campaign-templates';

    /** Sustituto cuando el destinatario no tiene nombre guardado. */
    public const NAME_FALLBACK = 'cliente';

    public function __construct(protected MetaWhatsAppService $meta)
    {
    }

    /**
     * Definición de la plantilla en el WABA de la instancia, o null si no existe
     * con ese nombre e idioma.
     */
    public function fetchTemplate(Instance $instance, string $name, string $language): ?array
    {
        if (!$instance->waba_id || !$instance->access_token) {
            return null;
        }

        $result = $this->meta->listTemplates($instance->waba_id, $instance->access_token, [
            'name'  => $name,
            'limit' => 50,
        ]);

        if (!($result['success'] ?? false)) {
            return null;
        }

        foreach ($result['data']['data'] ?? [] as $tpl) {
            // Meta filtra `name` por coincidencia parcial: hay que comparar exacto.
            if (($tpl['name'] ?? '') === $name && ($tpl['language'] ?? '') === $language) {
                return $tpl;
            }
        }

        return null;
    }

    /**
     * Comprueba que el payload guardado encaje con la plantilla aprobada.
     * Devuelve un array de errores con la forma de un ValidationException
     * (campo => mensaje); vacío si todo cuadra.
     */
    public function validateAgainstTemplate(array $template, array $payload): array
    {
        $errors = [];

        $status = strtoupper((string) ($template['status'] ?? ''));
        if ($status !== 'APPROVED') {
            $errors['template_name'] = "La plantilla no está aprobada en WhatsApp (estado actual: {$status}).";
            return $errors;
        }

        // ── Cuerpo ───────────────────────────────────────────────────────────
        $expected = $this->countVars($this->bodyText($template));
        $given = array_values(array_filter(
            $payload['body_vars'] ?? [],
            fn ($v) => trim((string) $v) !== ''
        ));

        if (count($given) !== $expected) {
            $errors['template_payload'] = $expected === 0
                ? 'Esta plantilla no tiene variables; no envíes valores para ellas.'
                : "La plantilla espera {$expected} variable(s) y se completaron " . count($given) . '.';
        }

        // ── Encabezado ───────────────────────────────────────────────────────
        $format = $this->headerFormat($template);
        $header = $payload['header'] ?? null;
        $givenFormat = $header['format'] ?? null;

        if ($format !== $givenFormat) {
            // Este es exactamente el caso que Meta reporta como
            // "header: Format mismatch, expected IMAGE, received UNKNOWN".
            $errors['template_header'] = $format
                ? "El encabezado de la plantilla es de tipo {$format} y no se adjuntó ese contenido."
                : 'Esta plantilla no tiene encabezado multimedia; no adjuntes archivo.';
        } elseif (in_array($format, self::MEDIA_HEADERS, true)) {
            if (empty($header['path']) || !Storage::disk('s3_media')->exists($header['path'])) {
                $errors['template_header'] = 'El archivo del encabezado no está disponible. Vuelve a subirlo.';
            }
        } elseif ($format === 'LOCATION') {
            if (!is_numeric($header['lat'] ?? null) || !is_numeric($header['lng'] ?? null)) {
                $errors['template_header'] = 'Completa la latitud y la longitud del encabezado.';
            }
        }

        return $errors;
    }

    /**
     * Sube a Meta el archivo del encabezado y devuelve el media_id.
     *
     * Se hace una vez por corrida de la campaña, no por destinatario: el id vale
     * para todos los envíos. Y se rehace en cada corrida a propósito — los
     * media_id de Meta caducan a los 30 días, así que guardarlo en la campaña
     * rompería las recurrentes al mes de creadas.
     */
    public function uploadHeaderMedia(array $payload, Instance $instance): ?string
    {
        $header = $payload['header'] ?? null;
        if (!$header || !in_array($header['format'] ?? '', self::MEDIA_HEADERS, true)) {
            return null;
        }

        $path = $header['path'] ?? '';
        $disk = Storage::disk('s3_media');
        if (!$path || !$disk->exists($path)) {
            Log::channel('whatsapp')->warning('Encabezado de campaña sin archivo en el bucket', ['path' => $path]);
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'camp_hdr_');
        try {
            file_put_contents($tmp, $disk->get($path));
            $result = $this->meta->uploadMedia(
                $instance->phone_number_id,
                $tmp,
                $header['mime_type'] ?? 'application/octet-stream'
            );
        } finally {
            @unlink($tmp);
        }

        if (!($result['success'] ?? false)) {
            Log::channel('whatsapp')->error('No se pudo subir el encabezado de campaña a Meta', [
                'instance_id' => $instance->id,
                'error'       => $result['error'] ?? null,
            ]);
            return null;
        }

        return $result['id'];
    }

    /**
     * `components` de Meta para un destinatario concreto: el encabezado ya
     * resuelto (mismo media_id para toda la corrida) y el cuerpo con los tokens
     * {{nombre}} / {{telefono}} sustituidos por los datos de esa persona.
     */
    public function buildComponents(array $payload, ?string $headerMediaId, WhatsAppCampaignRecipient $recipient): array
    {
        $components = [];

        $header = $payload['header'] ?? null;
        $format = $header['format'] ?? null;

        if ($format === 'LOCATION') {
            $location = [
                'latitude'  => (string) ($header['lat'] ?? ''),
                'longitude' => (string) ($header['lng'] ?? ''),
            ];
            if (trim((string) ($header['name'] ?? '')) !== '') {
                $location['name'] = trim($header['name']);
            }
            if (trim((string) ($header['address'] ?? '')) !== '') {
                $location['address'] = trim($header['address']);
            }
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'location', 'location' => $location]]];
        } elseif (in_array($format, self::MEDIA_HEADERS, true) && $headerMediaId) {
            $kind = strtolower($format);
            $media = ['id' => $headerMediaId];
            if ($format === 'DOCUMENT') {
                $media['filename'] = $header['filename'] ?: 'documento.pdf';
            }
            $components[] = ['type' => 'header', 'parameters' => [['type' => $kind, $kind => $media]]];
        }

        $vars = $this->resolveVars($payload, $recipient);
        if (count($vars) > 0) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $v], $vars),
            ];
        }

        return $components;
    }

    /** Variables del cuerpo con los tokens ya resueltos para este destinatario. */
    public function resolveVars(array $payload, WhatsAppCampaignRecipient $recipient): array
    {
        $name = trim((string) ($recipient->name ?? ''));
        $replacements = [
            '{{nombre}}'   => $name !== '' ? $name : self::NAME_FALLBACK,
            '{{telefono}}' => (string) $recipient->phone_number,
        ];

        $out = [];
        foreach ($payload['body_vars'] ?? [] as $raw) {
            $value = strtr((string) $raw, $replacements);
            $out[] = $this->sanitizeParam($value);
        }

        return $out;
    }

    /**
     * Texto que se guarda como contenido del mensaje en el chat: el cuerpo de la
     * plantilla con las variables ya sustituidas.
     */
    public function renderPreview(?string $bodyText, array $vars): string
    {
        return preg_replace_callback(
            '/{{\s*(\d+)\s*}}/',
            fn ($m) => $vars[(int) $m[1] - 1] ?? $m[0],
            (string) $bodyText
        );
    }

    public function bodyText(array $template): ?string
    {
        foreach ($template['components'] ?? [] as $c) {
            if (strtoupper($c['type'] ?? '') === 'BODY') {
                return $c['text'] ?? null;
            }
        }
        return null;
    }

    /** IMAGE|VIDEO|DOCUMENT|LOCATION, o null si el encabezado es de texto o no existe. */
    public function headerFormat(array $template): ?string
    {
        foreach ($template['components'] ?? [] as $c) {
            if (strtoupper($c['type'] ?? '') !== 'HEADER') {
                continue;
            }
            $format = strtoupper($c['format'] ?? '');
            return in_array($format, [...self::MEDIA_HEADERS, 'LOCATION'], true) ? $format : null;
        }
        return null;
    }

    /** Número de variables {{n}} distintas en un texto de plantilla. */
    public function countVars(?string $text): int
    {
        preg_match_all('/{{\s*(\d+)\s*}}/', (string) $text, $m);
        return count(array_unique($m[1] ?? []));
    }

    /**
     * Meta rechaza (error 132007) los parámetros con saltos de línea, tabulaciones
     * o cuatro espacios seguidos. Se limpian aquí y no en el formulario porque el
     * valor final solo existe al resolver los tokens de cada destinatario.
     */
    private function sanitizeParam(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = preg_replace('/ {4,}/', '   ', $value);
        return trim($value);
    }
}
