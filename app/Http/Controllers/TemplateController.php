<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TemplateController extends Controller
{
    public function __construct(protected MetaWhatsAppService $meta) {}

    public function index()
    {
        $user = auth()->user();

        $instances = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token')
            ->orderBy('name')
            ->get(['id', 'name', 'display_phone_number', 'waba_id']);

        return Inertia::render('Templates/Index', [
            'instances' => $instances,
        ]);
    }

    public function create(Request $request)
    {
        $user = auth()->user();

        $instances = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token')
            ->orderBy('name')
            ->get(['id', 'name', 'display_phone_number', 'waba_id']);

        return Inertia::render('Templates/Create', [
            'instances' => $instances,
            'prefill' => [
                'mode' => $request->query('mode') === 'translation' ? 'translation' : 'new',
                'family' => $request->query('family'),
                'source_id' => $request->query('source_id'),
                'instance_id' => $request->query('instance_id'),
            ],
        ]);
    }

    public function analyticsIndex()
    {
        $user = auth()->user();

        $instances = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token')
            ->orderBy('name')
            ->get(['id', 'name', 'display_phone_number', 'waba_id']);

        return Inertia::render('Templates/Analytics', [
            'instances' => $instances,
        ]);
    }

    public function analytics(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'start' => 'required|integer',
            'end' => 'required|integer',
            'granularity' => 'nullable|in:DAILY,WEEKLY,MONTHLY',
            'metric_types' => 'nullable|array',
            'metric_types.*' => 'in:SENT,DELIVERED,READ,CLICKED',
            'template_ids' => 'nullable|array',
            'template_ids.*' => 'string',
        ]);

        // Meta requires: start < end, end <= now, window <= 90 days
        $now = time();
        if ($data['end'] > $now) {
            $data['end'] = $now;
        }
        if ($data['start'] >= $data['end']) {
            return response()->json([
                'message' => 'El rango es inválido: la fecha de inicio debe ser anterior a la final.',
            ], 422);
        }
        if (($data['end'] - $data['start']) > 90 * 86400) {
            $data['start'] = $data['end'] - 90 * 86400;
        }

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $templateIds = $data['template_ids'] ?? [];
        $templatesIndex = [];

        if (empty($templateIds)) {
            $listResult = $this->meta->listTemplates($instance->waba_id, $instance->access_token, [
                'fields' => 'id,name,language',
                'limit' => 500,
            ]);
            if (!$listResult['success']) {
                if ($this->isTemplatesUnavailableError($listResult['error'] ?? null)) {
                    return response()->json([
                        'data' => [],
                        'templates' => [],
                        'totals' => $this->emptyTotals(),
                        'series' => [],
                        'message' => 'Esta cuenta no tiene plantillas disponibles.',
                    ]);
                }

                return response()->json([
                    'message' => 'No se pudo obtener la lista de plantillas para la analítica.',
                    'error' => $listResult['error'] ?? null,
                ], 502);
            }
            foreach ($listResult['data']['data'] ?? [] as $t) {
                $templateIds[] = (string) $t['id'];
                $templatesIndex[(string) $t['id']] = ['name' => $t['name'], 'language' => $t['language']];
            }
        }

        if (empty($templateIds)) {
            return response()->json([
                'data' => [],
                'templates' => [],
                'totals' => $this->emptyTotals(),
                'series' => [],
                'message' => 'Esta cuenta no tiene plantillas disponibles.',
            ]);
        }

        $result = $this->meta->templateAnalytics($instance->waba_id, $instance->access_token, [
            'start' => $data['start'],
            'end' => $data['end'],
            'granularity' => $data['granularity'] ?? 'DAILY',
            'metric_types' => $data['metric_types'] ?? ['SENT', 'DELIVERED', 'READ', 'CLICKED'],
            'template_ids' => $templateIds,
        ]);

        if (!$result['success']) {
            if ($this->isInsightsDisabledError($result['error'] ?? null)) {
                return response()->json([
                    'needs_activation' => true,
                    'message' => 'Las analíticas no están habilitadas para esta cuenta de WhatsApp Business.',
                ], 200);
            }

            return response()->json([
                'message' => 'Error obteniendo analítica de plantillas en Meta.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        // If templatesIndex empty (because user passed ids), enrich it now with one extra fetch.
        if (empty($templatesIndex) && !empty($templateIds)) {
            $listResult = $this->meta->listTemplates($instance->waba_id, $instance->access_token, [
                'fields' => 'id,name,language',
                'limit' => 500,
            ]);
            foreach ($listResult['data']['data'] ?? [] as $t) {
                $templatesIndex[(string) $t['id']] = ['name' => $t['name'], 'language' => $t['language']];
            }
        }

        return response()->json(
            $this->shapeAnalytics($result['data'], $templatesIndex)
        );
    }

    public function enableInsights(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $result = $this->meta->enableInsights($instance->waba_id, $instance->access_token);

        if (!$result['success']) {
            return response()->json([
                'message' => 'No se pudo activar la analítica en Meta.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    protected function isTemplatesUnavailableError($error): bool
    {
        if (!is_array($error)) return false;

        $inner = $error['error'] ?? $error;
        $code = $inner['code'] ?? null;
        $subcode = $inner['error_subcode'] ?? null;

        // Meta code 100 / subcode 33: object does not exist, missing permissions,
        // or unsupported operation. Tratamos esto como "sin plantillas disponibles"
        // para no exponer el error crudo al usuario final.
        return ((int) $code === 100 && (int) $subcode === 33);
    }

    protected function isInsightsDisabledError($error): bool
    {
        if (!is_array($error)) return false;

        $inner = $error['error'] ?? $error;
        $subcode = $inner['error_subcode'] ?? null;
        if (in_array($subcode, [4182004, 4182005], true)) {
            return true;
        }

        $msg = strtolower(json_encode($error));
        return str_contains($msg, 'is_enabled_for_insights')
            || str_contains($msg, 'insights have not been enabled')
            || str_contains($msg, 'insights is not enabled')
            || str_contains($msg, 'analytics is not enabled')
            || str_contains($msg, 'not enabled for insights')
            || str_contains($msg, 'template insights')
            || str_contains($msg, 'estadísticas de plantillas')
            || str_contains($msg, 'enable analytics');
    }

    public function conversationAnalytics(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'start' => 'required|integer',
            'end' => 'required|integer',
            'granularity' => 'nullable|in:HALF_HOUR,DAILY,MONTHLY',
        ]);

        $now = time();
        if ($data['end'] > $now) $data['end'] = $now;
        if ($data['start'] >= $data['end']) {
            return response()->json(['message' => 'Rango inválido.'], 422);
        }

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $result = $this->meta->conversationAnalytics($instance->waba_id, $instance->access_token, [
            'start' => $data['start'],
            'end' => $data['end'],
            'granularity' => $data['granularity'] ?? 'DAILY',
            'dimensions' => ['CONVERSATION_CATEGORY', 'CONVERSATION_TYPE'],
        ]);

        if (!$result['success']) {
            if ($this->isInsightsDisabledError($result['error'] ?? null)) {
                return response()->json(['needs_activation' => true], 200);
            }
            return response()->json([
                'message' => 'Error obteniendo analítica de conversaciones.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        return response()->json($this->shapeConversationAnalytics($result['data']));
    }

    protected function shapeConversationAnalytics(array $raw): array
    {
        $points = $raw['conversation_analytics']['data'][0]['data_points'] ?? [];
        $byCategory = [];
        $byType = [];
        $byDay = [];
        $totals = ['conversation' => 0, 'cost' => 0.0];

        foreach ($points as $p) {
            $count = (int) ($p['conversation'] ?? 0);
            $cost = (float) ($p['cost'] ?? 0);
            $category = $p['conversation_category'] ?? 'UNKNOWN';
            $type = $p['conversation_type'] ?? 'UNKNOWN';
            $start = (int) ($p['start'] ?? 0);

            $totals['conversation'] += $count;
            $totals['cost'] += $cost;

            $byCategory[$category] = ($byCategory[$category] ?? 0) + $count;
            $byType[$type] = ($byType[$type] ?? 0) + $count;

            if ($start > 0) {
                if (!isset($byDay[$start])) {
                    $byDay[$start] = ['start' => $start, 'conversation' => 0, 'cost' => 0.0];
                }
                $byDay[$start]['conversation'] += $count;
                $byDay[$start]['cost'] += $cost;
            }
        }

        $series = array_values($byDay);
        usort($series, fn($a, $b) => $a['start'] <=> $b['start']);

        return [
            'totals' => $totals,
            'by_category' => $byCategory,
            'by_type' => $byType,
            'series' => $series,
        ];
    }

    protected function emptyTotals(): array
    {
        return ['sent' => 0, 'delivered' => 0, 'read' => 0, 'clicked' => 0];
    }

    protected function shapeAnalytics(array $raw, array $templatesIndex): array
    {
        $perTemplate = [];
        $perDay = [];
        $totals = $this->emptyTotals();

        foreach ($raw['data'] ?? [] as $bucket) {
            foreach ($bucket['data_points'] ?? [] as $dp) {
                $tid = (string) ($dp['template_id'] ?? '');
                $sent = (int) ($dp['sent'] ?? 0);
                $delivered = (int) ($dp['delivered'] ?? 0);
                $read = (int) ($dp['read'] ?? 0);
                $clickedTotal = 0;
                $clickedBreakdown = [];
                foreach ($dp['clicked'] ?? [] as $click) {
                    $count = (int) ($click['count'] ?? 0);
                    $clickedTotal += $count;
                    $clickedBreakdown[] = [
                        'type' => $click['type'] ?? null,
                        'button_content' => $click['button_content'] ?? null,
                        'count' => $count,
                    ];
                }

                $totals['sent'] += $sent;
                $totals['delivered'] += $delivered;
                $totals['read'] += $read;
                $totals['clicked'] += $clickedTotal;

                if (!isset($perTemplate[$tid])) {
                    $meta = $templatesIndex[$tid] ?? ['name' => $tid, 'language' => null];
                    $perTemplate[$tid] = [
                        'template_id' => $tid,
                        'name' => $meta['name'],
                        'language' => $meta['language'],
                        'sent' => 0, 'delivered' => 0, 'read' => 0, 'clicked' => 0,
                        'clicks_breakdown' => [],
                    ];
                }
                $perTemplate[$tid]['sent'] += $sent;
                $perTemplate[$tid]['delivered'] += $delivered;
                $perTemplate[$tid]['read'] += $read;
                $perTemplate[$tid]['clicked'] += $clickedTotal;
                foreach ($clickedBreakdown as $b) {
                    $perTemplate[$tid]['clicks_breakdown'][] = $b;
                }

                $dayKey = $dp['start'] ?? null;
                if ($dayKey !== null) {
                    if (!isset($perDay[$dayKey])) {
                        $perDay[$dayKey] = ['start' => $dayKey, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'clicked' => 0];
                    }
                    $perDay[$dayKey]['sent'] += $sent;
                    $perDay[$dayKey]['delivered'] += $delivered;
                    $perDay[$dayKey]['read'] += $read;
                    $perDay[$dayKey]['clicked'] += $clickedTotal;
                }
            }
        }

        $templates = array_values($perTemplate);
        usort($templates, fn($a, $b) => $b['sent'] <=> $a['sent']);

        $series = array_values($perDay);
        usort($series, fn($a, $b) => $a['start'] <=> $b['start']);

        return [
            'totals' => $totals,
            'templates' => $templates,
            'series' => $series,
        ];
    }

    public function list(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $params = $request->only([
            'name', 'name_or_content', 'content', 'language',
            'status', 'category', 'quality_score', 'since', 'until',
            'limit', 'after', 'before',
        ]);

        $result = $this->meta->listTemplates($instance->waba_id, $instance->access_token, $params);

        if (!$result['success']) {
            if ($this->isTemplatesUnavailableError($result['error'] ?? null)) {
                return response()->json([
                    'data' => [],
                    'paging' => null,
                    'summary' => null,
                    'message' => 'Esta cuenta no tiene plantillas disponibles.',
                ]);
            }

            return response()->json([
                'message' => 'Error consultando plantillas en Meta.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        $payload = $result['data'];

        return response()->json([
            'data' => $payload['data'] ?? [],
            'paging' => $payload['paging'] ?? null,
            'summary' => $payload['summary'] ?? null,
        ]);
    }

    public function family(Request $request, string $name)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $result = $this->meta->listTemplates($instance->waba_id, $instance->access_token, [
            'name' => $name,
            'limit' => 100,
        ]);

        if (!$result['success']) {
            if ($this->isTemplatesUnavailableError($result['error'] ?? null)) {
                return response()->json([
                    'name' => $name,
                    'data' => [],
                    'message' => 'Esta cuenta no tiene plantillas disponibles.',
                ]);
            }

            return response()->json([
                'message' => 'Error consultando la familia de plantillas.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        return response()->json([
            'name' => $name,
            'data' => $result['data']['data'] ?? [],
        ]);
    }

    /**
     * Sube el archivo de muestra de un encabezado multimedia (IMAGE/VIDEO/DOCUMENT)
     * a Meta mediante subida reanudable y devuelve el header_handle resultante.
     * El frontend lo adjunta a components[].example.header_handle al crear la plantilla.
     */
    public function uploadMedia(Request $request)
    {
        $request->validate([
            'instance_id' => 'nullable|integer',
            // Imagen/Video/Documento de muestra. Meta limita ~16MB video, 5MB imagen, 100MB doc.
            'file' => 'required|file|max:102400',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $debug = $this->meta->debugToken($instance->access_token);
        if (!$debug['success'] || empty($debug['data']['app_id'])) {
            return response()->json([
                'message' => 'No se pudo identificar la app del token para subir el archivo.',
                'error' => $debug['error'] ?? null,
            ], 502);
        }
        $appId = $debug['data']['app_id'];

        $file = $request->file('file');
        $upload = $this->meta->uploadResumable(
            $appId,
            $instance->access_token,
            $file->getRealPath(),
            $file->getMimeType()
        );

        if (!$upload['success']) {
            return response()->json([
                'message' => 'No se pudo subir el archivo a Meta.',
                'stage' => $upload['stage'] ?? null,
                'error' => $upload['error'] ?? null,
            ], 502);
        }

        return response()->json([
            'handle' => $upload['handle'],
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'name' => 'required|string|max:512|regex:/^[a-z0-9_]+$/',
            'language' => 'required|string|max:10',
            'category' => 'required|in:MARKETING,UTILITY,AUTHENTICATION',
            'allow_category_change' => 'nullable|boolean',
            'parameter_format' => 'nullable|in:POSITIONAL,NAMED',
            'components' => 'required|array|min:1',
            'components.*.type' => 'required|in:HEADER,BODY,FOOTER,BUTTONS',
            'components.*.format' => 'nullable|in:TEXT,IMAGE,VIDEO,DOCUMENT,LOCATION',
            'components.*.text' => 'nullable|string|max:1024',
            'components.*.example' => 'nullable|array',
            'components.*.add_security_recommendation' => 'nullable|boolean',
            'components.*.code_expiration_minutes' => 'nullable|integer|min:1|max:90',
            'components.*.buttons' => 'nullable|array|max:10',
            'components.*.buttons.*.type' => 'nullable|in:QUICK_REPLY,URL,PHONE_NUMBER,COPY_CODE,OTP',
            'components.*.buttons.*.text' => 'nullable|string|max:25',
            'components.*.buttons.*.url' => 'nullable|string|max:2000',
            'components.*.buttons.*.phone_number' => 'nullable|string|max:20',
            'components.*.buttons.*.example' => 'nullable|array',
            'components.*.buttons.*.otp_type' => 'nullable|in:COPY_CODE,ONE_TAP,ZERO_TAP',
            'components.*.buttons.*.autofill_text' => 'nullable|string|max:25',
            'components.*.buttons.*.package_name' => 'nullable|string|max:200',
            'components.*.buttons.*.signature_hash' => 'nullable|string|max:50',
        ]);

        $semanticErrors = $this->validateComponentsRules(
            $data['components'],
            $data['category'],
            $data['parameter_format'] ?? 'POSITIONAL'
        );
        if (!empty($semanticErrors)) {
            return response()->json([
                'message' => 'Componentes inválidos.',
                'errors' => $semanticErrors,
            ], 422);
        }

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $payload = [
            'name' => $data['name'],
            'language' => $data['language'],
            'category' => $data['category'],
            'components' => $this->sanitizeComponents($data['components']),
        ];

        if ($request->has('allow_category_change')) {
            $payload['allow_category_change'] = (bool) $data['allow_category_change'];
        }

        if (!empty($data['parameter_format'])) {
            $payload['parameter_format'] = $data['parameter_format'];
        }

        $result = $this->meta->createTemplate($instance->waba_id, $instance->access_token, $payload);

        if (!$result['success']) {
            return response()->json([
                'message' => $this->extractMetaErrorMessage($result['error']) ?? 'Error creando la plantilla en Meta.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        $metaTemplateId = $result['data']['id'] ?? null;
        $verified = false;
        if ($metaTemplateId) {
            $check = $this->meta->getTemplate((string) $metaTemplateId, $instance->access_token, ['fields' => 'id,name,language,status']);
            $verified = !empty($check['success']);
        }

        return response()->json([
            'data' => $result['data'],
            'waba_id' => $instance->waba_id,
            'instance' => [
                'id' => $instance->id,
                'name' => $instance->name,
                'display_phone_number' => $instance->display_phone_number,
            ],
            'verified_in_meta' => $verified,
        ], 201);
    }

    /**
     * Reglas semánticas de Meta no cubiertas por validación estándar:
     * - Variables {{1}},{{2}},... deben ser secuenciales sin saltos.
     * - body.example.body_text debe tener el mismo número de valores que variables.
     * - header.example.header_text idem para HEADER TEXT.
     * - URL con {{1}} debe traer example: ["url_completa"].
     * - Conteo de botones por tipo (Meta: 1 PHONE_NUMBER, 2 URL, 1 COPY_CODE, 1 OTP, 1 VOICE_CALL).
     * - AUTHENTICATION exige botón OTP.
     * - Con parameter_format NAMED las variables son {{nombre}} y los ejemplos van en
     *   header_text_named_params / body_text_named_params.
     */
    protected function validateComponentsRules(array $components, string $category, string $parameterFormat = 'POSITIONAL'): array
    {
        $named = $parameterFormat === 'NAMED';
        $errors = [];
        $typesSeen = [];
        $hasOtp = false;
        $btnCount = ['PHONE_NUMBER' => 0, 'URL' => 0, 'COPY_CODE' => 0, 'OTP' => 0, 'QUICK_REPLY' => 0];

        foreach ($components as $i => $c) {
            $type = $c['type'] ?? null;
            if ($type && in_array($type, $typesSeen, true) && $type !== 'BUTTONS') {
                $errors[] = "Componente {$type} duplicado.";
            }
            $typesSeen[] = $type;

            if ($type === 'HEADER') {
                $format = $c['format'] ?? 'TEXT';
                if ($format === 'TEXT') {
                    $text = $c['text'] ?? '';
                    if (mb_strlen($text) > 60) {
                        $errors[] = 'El encabezado de texto no puede superar 60 caracteres.';
                    }
                    if ($named) {
                        $vars = $this->extractNamedVariables($text);
                        if (count($vars) > 1) {
                            $errors[] = 'El encabezado de texto admite máximo una variable.';
                        }
                        $errors = array_merge($errors, $this->validateNamedVariableNames($vars, 'encabezado'));
                        $params = $c['example']['header_text_named_params'] ?? [];
                        if (!$this->namedExamplesMatch($vars, $params)) {
                            $errors[] = 'Debes proveer un ejemplo por cada variable del encabezado (header_text_named_params).';
                        }
                    } else {
                        $errors = array_merge($errors, $this->rejectNamedInPositional($text, 'encabezado'));
                        $vars = $this->extractVariables($text);
                        if (count($vars) > 1) {
                            $errors[] = 'El encabezado de texto admite máximo una variable {{1}}.';
                        }
                        if (!$this->isSequential($vars)) {
                            $errors[] = 'Las variables del encabezado deben ser secuenciales desde {{1}}.';
                        }
                        $examples = $c['example']['header_text'] ?? [];
                        if (count($vars) !== count($examples)) {
                            $errors[] = 'Debes proveer un ejemplo por cada variable del encabezado.';
                        }
                    }
                } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                    $handle = $c['example']['header_handle'] ?? null;
                    if (empty($handle) || !is_array($handle) || empty($handle[0])) {
                        $errors[] = "El encabezado {$format} requiere un media handle de Meta en example.header_handle.";
                    }
                }
            }

            if ($type === 'BODY') {
                $text = $c['text'] ?? '';
                if ($text === '' && $category !== 'AUTHENTICATION') {
                    $errors[] = 'El cuerpo es obligatorio.';
                }
                if (mb_strlen($text) > 1024) {
                    $errors[] = 'El cuerpo no puede superar 1024 caracteres.';
                }
                if ($named) {
                    $vars = $this->extractNamedVariables($text);
                    $errors = array_merge($errors, $this->validateNamedVariableNames($vars, 'cuerpo'));
                    $params = $c['example']['body_text_named_params'] ?? [];
                    if (!$this->namedExamplesMatch($vars, $params)) {
                        $errors[] = 'Debes proveer un ejemplo por cada variable del cuerpo (body_text_named_params).';
                    }
                } else {
                    $errors = array_merge($errors, $this->rejectNamedInPositional($text, 'cuerpo'));
                    $vars = $this->extractVariables($text);
                    if (!$this->isSequential($vars)) {
                        $errors[] = 'Las variables del cuerpo deben ser secuenciales desde {{1}} sin saltos.';
                    }
                    $examples = $c['example']['body_text'][0] ?? [];
                    if (count($vars) !== count($examples)) {
                        $errors[] = 'Debes proveer un ejemplo por cada variable del cuerpo.';
                    }
                }
            }

            if ($type === 'FOOTER') {
                $text = $c['text'] ?? '';
                if (mb_strlen($text) > 60) {
                    $errors[] = 'El pie no puede superar 60 caracteres.';
                }
                if (str_contains($text, '{{')) {
                    $errors[] = 'El pie no admite variables.';
                }
            }

            if ($type === 'BUTTONS') {
                foreach (($c['buttons'] ?? []) as $j => $b) {
                    $btType = $b['type'] ?? null;
                    if (!$btType) continue;

                    if (!isset($btnCount[$btType])) $btnCount[$btType] = 0;
                    $btnCount[$btType]++;

                    if ($btType === 'OTP') $hasOtp = true;

                    if ($btType === 'URL') {
                        $url = $b['url'] ?? '';
                        if ($url === '') {
                            $errors[] = "Botón URL #" . ($j + 1) . ": URL requerida.";
                        }
                        $urlVars = $this->extractVariables($url);
                        if (count($urlVars) > 0) {
                            $example = $b['example'] ?? [];
                            if (empty($example) || empty($example[0])) {
                                $errors[] = "Botón URL #" . ($j + 1) . ": al usar {{1}} debes proveer example con la URL completa.";
                            }
                        }
                    }

                    if ($btType === 'PHONE_NUMBER' && empty($b['phone_number'])) {
                        $errors[] = "Botón teléfono #" . ($j + 1) . ": número requerido.";
                    }

                    if ($btType === 'OTP') {
                        $otpType = $b['otp_type'] ?? 'COPY_CODE';
                        if (!in_array($otpType, ['COPY_CODE', 'ONE_TAP', 'ZERO_TAP'], true)) {
                            $errors[] = "Botón OTP #" . ($j + 1) . ": otp_type inválido.";
                        }
                        if (in_array($otpType, ['ONE_TAP', 'ZERO_TAP'], true) && empty($b['package_name'])) {
                            $errors[] = "Botón OTP {$otpType} #" . ($j + 1) . ": package_name requerido.";
                        }
                    }

                    if (in_array($btType, ['QUICK_REPLY', 'URL', 'PHONE_NUMBER', 'COPY_CODE', 'OTP'], true)) {
                        if ($btType !== 'OTP' && empty($b['text'])) {
                            $errors[] = "Botón #" . ($j + 1) . ": texto requerido.";
                        }
                    }
                }
            }
        }

        if ($btnCount['PHONE_NUMBER'] > 1) $errors[] = 'Meta solo permite 1 botón de teléfono.';
        if ($btnCount['URL'] > 2) $errors[] = 'Meta solo permite hasta 2 botones URL.';
        if ($btnCount['COPY_CODE'] > 1) $errors[] = 'Meta solo permite 1 botón COPY_CODE.';
        if ($btnCount['OTP'] > 1) $errors[] = 'Meta solo permite 1 botón OTP.';

        if ($category === 'AUTHENTICATION' && !$hasOtp) {
            $errors[] = 'Las plantillas AUTHENTICATION requieren un botón OTP.';
        }

        return $errors;
    }

    protected function extractVariables(string $text): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);
        $nums = array_unique(array_map('intval', $matches[1] ?? []));
        sort($nums);
        return $nums;
    }

    protected function extractNamedVariables(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    protected function validateNamedVariableNames(array $vars, string $where): array
    {
        $errors = [];
        foreach ($vars as $v) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $v)) {
                $errors[] = "Variable {{{$v}}} del {$where}: con tipo de variable \"Nombre\" usa minúsculas, números y guiones bajos, empezando por letra.";
            }
        }
        return $errors;
    }

    protected function namedExamplesMatch(array $vars, array $params): bool
    {
        if (empty($vars)) return true;
        $names = array_map(fn ($p) => $p['param_name'] ?? null, $params);
        return count($vars) === count($params)
            && empty(array_diff($vars, $names))
            && !in_array(null, array_map(fn ($p) => ($p['example'] ?? '') === '' ? null : true, $params), true);
    }

    protected function rejectNamedInPositional(string $text, string $where): array
    {
        $all = $this->extractNamedVariables($text);
        $nonNumeric = array_filter($all, fn ($v) => !ctype_digit($v));
        return empty($nonNumeric)
            ? []
            : ["El {$where} usa variables con nombre ({{" . reset($nonNumeric) . '}}), pero el tipo de variable es "Número". Cambia el tipo de variable a "Nombre" o usa {{1}}, {{2}}...'];
    }

    protected function isSequential(array $nums): bool
    {
        if (empty($nums)) return true;
        for ($i = 0; $i < count($nums); $i++) {
            if ($nums[$i] !== $i + 1) return false;
        }
        return true;
    }

    protected function extractMetaErrorMessage($error): ?string
    {
        if (!is_array($error)) return null;
        $inner = $error['error'] ?? $error;
        return $inner['error_user_msg']
            ?? $inner['message']
            ?? $inner['error_user_title']
            ?? null;
    }

    protected function sanitizeComponents(array $components): array
    {
        $clean = [];
        foreach ($components as $c) {
            $component = ['type' => $c['type']];

            if ($c['type'] === 'HEADER') {
                $component['format'] = $c['format'] ?? 'TEXT';
                if (!empty($c['text'])) $component['text'] = $c['text'];
                if (!empty($c['example'])) $component['example'] = $c['example'];
            } elseif ($c['type'] === 'BODY') {
                if (!empty($c['text'])) $component['text'] = $c['text'];
                if (!empty($c['example'])) $component['example'] = $c['example'];
                if (!empty($c['add_security_recommendation'])) {
                    $component['add_security_recommendation'] = true;
                }
            } elseif ($c['type'] === 'FOOTER') {
                if (!empty($c['text'])) $component['text'] = $c['text'];
                if (!empty($c['code_expiration_minutes'])) {
                    $component['code_expiration_minutes'] = (int) $c['code_expiration_minutes'];
                }
            } elseif ($c['type'] === 'BUTTONS') {
                $component['buttons'] = array_map(function ($b) {
                    $btn = ['type' => $b['type']];
                    if ($b['type'] !== 'OTP' && !empty($b['text'])) {
                        $btn['text'] = $b['text'];
                    }
                    if ($b['type'] === 'URL') {
                        if (!empty($b['url'])) $btn['url'] = $b['url'];
                        if (!empty($b['example'])) $btn['example'] = array_values($b['example']);
                    }
                    if ($b['type'] === 'PHONE_NUMBER' && !empty($b['phone_number'])) {
                        $btn['phone_number'] = $b['phone_number'];
                    }
                    if ($b['type'] === 'COPY_CODE' && !empty($b['example'])) {
                        $btn['example'] = array_values($b['example']);
                    }
                    if ($b['type'] === 'OTP') {
                        $btn['otp_type'] = $b['otp_type'] ?? 'COPY_CODE';
                        if (!empty($b['text'])) $btn['text'] = $b['text'];
                        if (!empty($b['autofill_text'])) $btn['autofill_text'] = $b['autofill_text'];
                        if (!empty($b['package_name'])) $btn['package_name'] = $b['package_name'];
                        if (!empty($b['signature_hash'])) $btn['signature_hash'] = $b['signature_hash'];
                    }
                    return $btn;
                }, $c['buttons'] ?? []);
            }

            $clean[] = $component;
        }
        return $clean;
    }

    public function show(Request $request, string $templateId)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $result = $this->meta->getTemplate($templateId, $instance->access_token);

        if (!$result['success']) {
            return response()->json([
                'message' => 'Error consultando la plantilla.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        return response()->json(['data' => $result['data']]);
    }

    protected function resolveInstance(Request $request)
    {
        $user = auth()->user();
        $instanceId = $request->input('instance_id');

        $query = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token');

        $instance = $instanceId
            ? $query->where('id', $instanceId)->first()
            : $query->orderBy('id')->first();

        if (!$instance) {
            return response()->json([
                'message' => 'No hay una instancia activa con WABA configurado.',
            ], 422);
        }

        return $instance;
    }
}
