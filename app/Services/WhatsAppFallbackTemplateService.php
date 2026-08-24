<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Plantilla de respaldo para los avisos automáticos fuera de la ventana de 24h.
 *
 * Fuera de esa ventana WhatsApp sólo acepta plantillas aprobadas, pero la Cloud
 * API responde 200 con wamid al texto libre y sólo avisa del fallo después, por
 * webhook: el ERP cree que el aviso salió y el cliente nunca lo recibe. Este
 * servicio convierte ese texto libre en una plantilla aprobada de la propia
 * empresa, y si la empresa todavía no la tiene, la crea en su WABA.
 *
 * Todo es por instancia: cada empresa tiene su propio WABA, su propio token y su
 * propia copia de la plantilla. Nada aquí asume una empresa concreta ni un
 * nombre de plantilla fijo en Meta; el catálogo sólo aporta el punto de partida
 * (config/whatsapp_default_templates.php) y la empresa puede sustituirlo por una
 * plantilla suya desde Ajustes.
 *
 * El estado en Meta (aprobada / pendiente / rechazada) se guarda en
 * `instances.meta.fallback_template`, con un `checked_at` que marca cada cuánto
 * se vuelve a preguntar: sin eso, cada aviso fuera de ventana costaría una
 * llamada extra al Graph y agotaría el rate limit del tenant.
 */
class WhatsAppFallbackTemplateService
{
    /** Clave del catálogo que se usa mientras la empresa no elija otra. */
    public const CATALOG_KEY = 'aviso_automatico_cliente';

    public const STATUS_APPROVED    = 'APPROVED';
    public const STATUS_PENDING     = 'PENDING';
    public const STATUS_REJECTED    = 'REJECTED';
    public const STATUS_MISSING     = 'MISSING';      // no existe y no se pudo crear
    public const STATUS_DISABLED    = 'DISABLED';     // la empresa lo apagó a propósito
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';  // sin WABA/token, o Meta no respondió

    /**
     * Cada cuánto se vuelve a preguntar a Meta, en minutos, según en qué estado
     * quedó la última consulta. Aprobada es el caso normal y casi nunca cambia;
     * pendiente sí, porque Meta suele resolver las UTILITY en minutos.
     */
    private const TTL_MINUTES = [
        self::STATUS_APPROVED    => 720,
        self::STATUS_PENDING     => 5,
        self::STATUS_REJECTED    => 360,
        self::STATUS_MISSING     => 60,
        self::STATUS_UNAVAILABLE => 30,
    ];

    /** Tope de Meta para el cuerpo ya renderizado de una plantilla. */
    private const BODY_MAX = 1024;

    public function __construct(private MetaWhatsAppService $meta)
    {
    }

    /**
     * Estado actual de la plantilla de respaldo de esta instancia, refrescándolo
     * contra Meta cuando toca y creándola en el WABA de la empresa si aún no
     * existe. Devuelve siempre un arreglo con al menos `status`.
     *
     * @param bool $force Ignora el TTL y vuelve a preguntar a Meta ahora mismo.
     */
    public function ensure(Instance $instance, bool $force = false): array
    {
        $settings = $instance->fallbackTemplateSettings();

        // array_merge y no `+`: el estado guardado casi siempre trae ya un
        // `status`, y con `+` el de la izquierda gana. Apagar el respaldo de una
        // instancia con la plantilla aprobada no habría apagado nada.
        if (!empty($settings['disabled'])) {
            return array_merge($settings, ['status' => self::STATUS_DISABLED]);
        }

        if (empty($instance->waba_id) || empty($instance->access_token)) {
            // Sin WABA no hay plantillas que consultar ni que crear. No se
            // persiste: en cuanto se configure el WABA debe reintentarse solo.
            return array_merge($settings, [
                'status' => self::STATUS_UNAVAILABLE,
                'last_error' => 'La instancia no tiene WABA o token configurado.',
            ]);
        }

        $definition = $this->definition($instance);

        if (!$force && $this->isFresh($settings, $definition)) {
            return $settings;
        }

        // El `checked_at` se escribe antes de hablar con Meta, no después: si la
        // llamada falla o revienta, el TTL igual corre y un tenant con el WABA
        // mal configurado no dispara una petición al Graph por cada aviso.
        $this->persist($instance, [
            'name'       => $definition['name'],
            'language'   => $definition['language'],
            'source'     => $definition['source'],
            'checked_at' => now()->toIso8601String(),
        ]);

        $lookup = $this->lookup($instance, $definition['name']);

        if (!$lookup['ok']) {
            $this->persist($instance, [
                'status'     => self::STATUS_UNAVAILABLE,
                'last_error' => $lookup['error'],
            ]);

            Log::channel('whatsapp')->warning('No se pudo consultar la plantilla de respaldo en Meta', [
                'company_id'  => $instance->company_id,
                'instance_id' => $instance->id,
                'template'    => $definition['name'],
                'error'       => $lookup['error'],
            ]);

            return $instance->fallbackTemplateSettings();
        }

        $entry = $this->pickLanguage($lookup['family'], $definition['language']);

        if (!$entry) {
            return $this->provision($instance, $definition);
        }

        return $this->absorb($instance, $entry);
    }

    /**
     * Prepara el envío del texto libre como plantilla. Devuelve siempre un
     * arreglo: `ok` dice si se puede enviar y `reason` por qué no, para que
     * quien llama pueda registrarlo y responderle algo útil al ERP.
     *
     * @return array{ok:bool,status:string,reason:?string,name:?string,language:?string,components:array,preview:?string}
     */
    public function prepare(
        Instance $instance,
        string $text,
        ?WhatsAppConversation $conversation = null,
        bool $force = false
    ): array {
        $state = $this->ensure($instance, $force);
        $status = $state['status'] ?? self::STATUS_MISSING;

        $fail = fn (string $reason) => [
            'ok' => false, 'status' => $status, 'reason' => $reason,
            'name' => $state['name'] ?? null, 'language' => $state['language'] ?? null,
            'components' => [], 'preview' => null,
        ];

        if ($status !== self::STATUS_APPROVED) {
            return $fail('not_approved');
        }

        $body = (string) ($state['body'] ?? '');
        if ($body === '') {
            return $fail('unknown_body');
        }

        $slots = $this->slots($body);
        $mapping = $this->mapping($state, $slots);

        // Un desajuste de variables no se envía "a ver si suena": Meta lo
        // rechaza con 132000 y el aviso se pierde igual, sólo que además
        // habiendo pagado la plantilla. Mejor caer al camino de siempre.
        if ($mapping === null) {
            Log::channel('whatsapp')->error('Plantilla de respaldo con variables que no cuadran', [
                'company_id'  => $instance->company_id,
                'instance_id' => $instance->id,
                'template'    => $state['name'] ?? null,
                'slots'       => $slots,
                'variables'   => $state['variables'] ?? null,
            ]);

            return $fail('variable_mismatch');
        }

        $values = $this->values($mapping, $instance, $text, $conversation);
        $values = $this->fitToBody($body, $values);

        return [
            'ok'         => true,
            'status'     => $status,
            'reason'     => null,
            'name'       => $state['name'],
            'language'   => $state['language'],
            'components' => $this->components($slots, $values),
            'preview'    => $this->render($body, $values),
        ];
    }

    /* --------------------------- Definición --------------------------- */

    /**
     * Qué plantilla es la de respaldo de esta instancia: la que la empresa haya
     * elegido, o la del catálogo por defecto mientras no elija ninguna.
     */
    private function definition(Instance $instance): array
    {
        $settings = $instance->fallbackTemplateSettings();
        $catalog = $this->catalog();

        $name = trim((string) ($settings['name'] ?? '')) ?: self::CATALOG_KEY;
        $isCatalog = $name === self::CATALOG_KEY;

        return [
            'name'     => $name,
            'language' => trim((string) ($settings['language'] ?? ''))
                ?: ($isCatalog ? ($catalog['language'] ?? 'es') : 'es'),
            'source'   => $isCatalog ? 'catalog' : 'custom',
        ];
    }

    private function catalog(): array
    {
        return config('whatsapp_default_templates.' . self::CATALOG_KEY, []);
    }

    /**
     * ¿El estado guardado sigue sirviendo, o toca volver a preguntar a Meta?
     * Un cambio de plantilla en Ajustes invalida lo guardado aunque esté fresco.
     */
    private function isFresh(array $settings, array $definition): bool
    {
        $status = $settings['status'] ?? null;
        $checkedAt = $settings['checked_at'] ?? null;

        if (!$status || !$checkedAt) {
            return false;
        }

        if (($settings['name'] ?? null) !== $definition['name']
            || ($settings['language'] ?? null) !== $definition['language']) {
            return false;
        }

        try {
            $checked = Carbon::parse($checkedAt);
        } catch (\Throwable) {
            return false;
        }

        return $checked->gt(now()->subMinutes(self::TTL_MINUTES[$status] ?? 60));
    }

    /* ------------------------- Meta: consulta -------------------------- */

    /**
     * Familia completa de la plantilla (todos sus idiomas) en el WABA de la
     * empresa. Se pide por nombre para no paginar el catálogo entero del tenant.
     */
    private function lookup(Instance $instance, string $name): array
    {
        $result = $this->meta->listTemplates($instance->waba_id, $instance->access_token, [
            'name'  => $name,
            'limit' => 50,
        ]);

        if (!($result['success'] ?? false)) {
            return ['ok' => false, 'error' => $this->errorMessage($result['error'] ?? null), 'family' => []];
        }

        return ['ok' => true, 'error' => null, 'family' => $result['data']['data'] ?? []];
    }

    /**
     * Elige qué traducción de la familia usar. Se prefiere la configurada, pero
     * si la empresa la aprobó como "es_ES" y aquí se pedía "es" se adopta la
     * suya en vez de crear un duplicado que Meta rechazaría por nombre repetido.
     */
    private function pickLanguage(array $family, string $language): ?array
    {
        if (!$family) {
            return null;
        }

        $base = strtolower(explode('_', $language)[0]);

        $exact = collect($family)->first(fn ($t) => strtolower($t['language'] ?? '') === strtolower($language));
        if ($exact) {
            return $exact;
        }

        $sameBase = collect($family)
            ->filter(fn ($t) => strtolower(explode('_', (string) ($t['language'] ?? ''))[0]) === $base);

        return $sameBase->firstWhere('status', self::STATUS_APPROVED)
            ?? $sameBase->first()
            ?? collect($family)->firstWhere('status', self::STATUS_APPROVED)
            ?? $family[0];
    }

    /** Guarda en la instancia lo que Meta dice de la plantilla encontrada. */
    private function absorb(Instance $instance, array $entry): array
    {
        $values = [
            'name'       => $entry['name'] ?? null,
            'language'   => $entry['language'] ?? null,
            'status'     => $entry['status'] ?? self::STATUS_PENDING,
            'category'   => $entry['category'] ?? null,
            'body'       => $this->bodyText($entry),
            'checked_at' => now()->toIso8601String(),
            'last_error' => null,
        ];

        if (($entry['status'] ?? null) === self::STATUS_REJECTED) {
            $values['last_error'] = 'Meta rechazó la plantilla: ' . ($entry['rejected_reason'] ?? 'motivo no informado');

            Log::channel('whatsapp')->error('Plantilla de respaldo rechazada por Meta', [
                'company_id'  => $instance->company_id,
                'instance_id' => $instance->id,
                'template'    => $entry['name'] ?? null,
                'reason'      => $entry['rejected_reason'] ?? null,
            ]);
        }

        $this->persist($instance, $values);

        return $instance->fallbackTemplateSettings();
    }

    /* --------------------- Meta: alta automática ----------------------- */

    /**
     * Crea la plantilla del catálogo en el WABA de la empresa. Sólo se autocrea
     * la del catálogo: una plantilla propia que el tenant eligió y luego borró
     * es decisión suya, no un hueco que rellenar.
     *
     * Queda PENDING: Meta tarda de segundos a horas en aprobar una UTILITY, así
     * que el aviso que disparó el alta todavía no puede salir como plantilla.
     * Los siguientes sí.
     */
    private function provision(Instance $instance, array $definition): array
    {
        if ($definition['source'] !== 'catalog') {
            $this->persist($instance, [
                'status'     => self::STATUS_MISSING,
                'last_error' => 'La plantilla configurada no existe en el WABA de la empresa.',
            ]);

            Log::channel('whatsapp')->warning('La plantilla de respaldo configurada no existe en Meta', [
                'company_id'  => $instance->company_id,
                'instance_id' => $instance->id,
                'template'    => $definition['name'],
            ]);

            return $instance->fallbackTemplateSettings();
        }

        $entry = $this->catalog();

        if (!$entry) {
            $this->persist($instance, [
                'status'     => self::STATUS_MISSING,
                'last_error' => 'El catálogo por defecto no define la plantilla de respaldo.',
            ]);

            return $instance->fallbackTemplateSettings();
        }

        $payload = [
            'name'       => $definition['name'],
            'language'   => $entry['language'],
            'category'   => $entry['category'],
            'components' => $entry['components'],
        ];

        if (!empty($entry['parameter_format'])) {
            $payload['parameter_format'] = $entry['parameter_format'];
        }

        $result = $this->meta->createTemplate($instance->waba_id, $instance->access_token, $payload);

        if (!($result['success'] ?? false)) {
            $inner = $result['error']['error'] ?? null;
            $code = is_array($inner) ? (int) ($inner['code'] ?? 0) : 0;
            $subcode = is_array($inner) ? (int) ($inner['error_subcode'] ?? 0) : 0;

            // Nombre duplicado: existe pero la consulta anterior no la vio
            // (propagación de Meta, o creada mientras tanto por otra petición).
            // Se deja pendiente y se vuelve a mirar en el próximo TTL corto.
            if ($code === 100 && $subcode === 2388023) {
                $this->persist($instance, [
                    'status'     => self::STATUS_PENDING,
                    'body'       => $this->bodyText($entry),
                    'last_error' => null,
                    'checked_at' => now()->toIso8601String(),
                ]);

                return $instance->fallbackTemplateSettings();
            }

            $this->persist($instance, [
                'status'     => self::STATUS_MISSING,
                'last_error' => $this->errorMessage($result['error'] ?? null),
            ]);

            Log::channel('whatsapp')->error('No se pudo crear la plantilla de respaldo en el WABA de la empresa', [
                'company_id'  => $instance->company_id,
                'instance_id' => $instance->id,
                'template'    => $definition['name'],
                'error'       => $this->errorMessage($result['error'] ?? null),
            ]);

            return $instance->fallbackTemplateSettings();
        }

        $this->persist($instance, [
            'status'         => $result['data']['status'] ?? self::STATUS_PENDING,
            'category'       => $result['data']['category'] ?? ($entry['category'] ?? null),
            'body'           => $this->bodyText($entry),
            'provisioned_at' => now()->toIso8601String(),
            'checked_at'     => now()->toIso8601String(),
            'last_error'     => null,
        ]);

        Log::channel('whatsapp')->info('Plantilla de respaldo creada en el WABA de la empresa', [
            'company_id'  => $instance->company_id,
            'instance_id' => $instance->id,
            'template'    => $definition['name'],
            'meta_status' => $result['data']['status'] ?? null,
        ]);

        return $instance->fallbackTemplateSettings();
    }

    /* ----------------------- Variables y cuerpo ------------------------ */

    /**
     * Huecos del cuerpo, en orden de aparición y sin repetidos: ['1','2'] en una
     * plantilla posicional, ['nombre','aviso'] en una con parámetros nombrados.
     */
    private function slots(string $body): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Qué dato va en cada hueco. Por defecto el contrato del catálogo
     * (`auto_fill`), pero la empresa puede declarar el suyo al elegir una
     * plantilla propia. Devuelve null si el mapeo no cubre exactamente los
     * huecos que tiene el cuerpo.
     *
     * @return array<string,string>|null  hueco => token
     */
    private function mapping(array $state, array $slots): ?array
    {
        if (!$slots) {
            return [];
        }

        $declared = $state['variables'] ?? null;

        if (!is_array($declared) || !$declared) {
            $declared = ($state['source'] ?? 'catalog') === 'catalog'
                ? ($this->catalog()['auto_fill'] ?? [])
                : [];
        }

        if (!$declared) {
            // Un solo hueco sin mapeo declarado sólo puede ser el aviso.
            return count($slots) === 1 ? [$slots[0] => 'message'] : null;
        }

        // Lista => posicional, en el orden de los huecos. Mapa => por nombre.
        $isList = array_keys($declared) === range(0, count($declared) - 1);

        if ($isList) {
            if (count($declared) !== count($slots)) {
                return null;
            }

            $ordered = $slots;
            if (collect($slots)->every(fn ($slot) => ctype_digit((string) $slot))) {
                sort($ordered, SORT_NUMERIC);
            }

            return array_combine($ordered, array_values($declared));
        }

        $mapping = [];
        foreach ($slots as $slot) {
            if (!isset($declared[$slot])) {
                return null;
            }
            $mapping[$slot] = $declared[$slot];
        }

        return $mapping;
    }

    /** Resuelve cada token del mapeo a su texto ya saneado. */
    private function values(array $mapping, Instance $instance, string $text, ?WhatsAppConversation $conversation): array
    {
        $values = [];

        foreach ($mapping as $slot => $token) {
            $values[$slot] = $this->sanitize(match ($token) {
                'message'       => $text,
                'business_name' => $instance->businessDisplayName(),
                'customer_name' => $this->customerName($conversation),
                'date'          => now()->format('d/m/Y'),
                // Cualquier otra cosa se toma como texto fijo elegido por la
                // empresa, que es lo que permite adaptar plantillas propias sin
                // tocar código.
                default         => (string) $token,
            });
        }

        return $values;
    }

    /**
     * Nombre utilizable del cliente. El de la conversación arranca siendo el
     * propio número (o un BSUID), y saludar a alguien por su número de teléfono
     * es peor que no saludarlo.
     */
    private function customerName(?WhatsAppConversation $conversation): string
    {
        $name = trim((string) ($conversation->name ?? ''));
        $phone = trim((string) ($conversation->phone_number ?? ''));

        if ($name === '' || $name === $phone || preg_match('/^[\d\s+.\-]+$/', $name)) {
            return 'cliente';
        }

        return $name;
    }

    /**
     * Meta rechaza (132007) los parámetros con saltos de línea, tabuladores o
     * más de cuatro espacios seguidos. El texto del ERP viene multilínea, así
     * que se aplana: preferimos un aviso en una línea a un 132007.
     */
    private function sanitize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Recorta el aviso lo justo para que el cuerpo renderizado quepa en el tope
     * de Meta. Se recorta el más largo porque es el de longitud imprevisible;
     * los demás huecos son nombres cortos.
     */
    private function fitToBody(string $body, array $values): array
    {
        $overflow = mb_strlen($this->render($body, $values)) - self::BODY_MAX;

        if ($overflow <= 0 || !$values) {
            return $values;
        }

        $longest = array_key_first($values);
        foreach ($values as $slot => $value) {
            if (mb_strlen($value) > mb_strlen($values[$longest])) {
                $longest = $slot;
            }
        }

        $keep = max(0, mb_strlen($values[$longest]) - $overflow - 1);
        $values[$longest] = rtrim(mb_substr($values[$longest], 0, $keep)) . '…';

        return $values;
    }

    /**
     * Rellena el cuerpo tal y como lo hará Meta. Con callback y no con
     * `preg_replace` a secas porque el aviso puede traer `$1` o `\0` y el
     * reemplazo los interpretaría como referencias al patrón.
     */
    private function render(string $body, array $values): string
    {
        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            fn ($match) => $values[$match[1]] ?? $match[0],
            $body
        ) ?? $body;
    }

    /**
     * Componentes en el formato de la Cloud API. Las plantillas con parámetros
     * nombrados necesitan `parameter_name`; las posicionales van por orden.
     */
    private function components(array $slots, array $values): array
    {
        if (!$slots) {
            return [];
        }

        $positional = collect($slots)->every(fn ($slot) => ctype_digit((string) $slot));

        if ($positional) {
            // El orden lo manda el número del hueco, no el de aparición: {{2}}
            // podría escribirse antes que {{1}} en el cuerpo.
            $ordered = $slots;
            sort($ordered, SORT_NUMERIC);

            $parameters = array_map(
                fn ($slot) => ['type' => 'text', 'text' => $values[$slot] ?? ''],
                $ordered
            );
        } else {
            $parameters = array_map(
                fn ($slot) => ['type' => 'text', 'parameter_name' => $slot, 'text' => $values[$slot] ?? ''],
                $slots
            );
        }

        return [['type' => 'body', 'parameters' => $parameters]];
    }

    private function bodyText(array $entry): ?string
    {
        foreach ($entry['components'] ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return $component['text'] ?? null;
            }
        }

        return null;
    }

    private function errorMessage($error): string
    {
        if (is_string($error)) {
            return $error;
        }

        if (is_array($error)) {
            return $error['error']['error_user_msg']
                ?? $error['error']['message']
                ?? json_encode($error, JSON_UNESCAPED_UNICODE);
        }

        return 'Error desconocido consultando Meta.';
    }

    /**
     * Escribe la configuración sobre la fila recién leída y no sobre la copia en
     * memoria: `meta` es un JSON compartido con llamadas, perfil y plantilla de
     * reinicio, y guardar una copia vieja de esa columna borraría lo que otra
     * petición del mismo tenant acabara de escribir ahí.
     */
    private function persist(Instance $instance, array $values): void
    {
        $instance->mergeFallbackTemplate($values);

        $fresh = Instance::find($instance->id);

        if ($fresh) {
            $fresh->mergeFallbackTemplate($values);
            $fresh->save();
        }
    }
}
