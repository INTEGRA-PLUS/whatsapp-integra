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

    public function store(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'name' => 'required|string|max:512|regex:/^[a-z0-9_]+$/',
            'language' => 'required|string|max:10',
            'category' => 'required|in:MARKETING,UTILITY,AUTHENTICATION',
            'allow_category_change' => 'nullable|boolean',
            'components' => 'required|array|min:1',
            'components.*.type' => 'required|in:HEADER,BODY,FOOTER,BUTTONS',
            'components.*.format' => 'nullable|in:TEXT,IMAGE,VIDEO,DOCUMENT',
            'components.*.text' => 'nullable|string|max:1024',
            'components.*.example' => 'nullable|array',
            'components.*.buttons' => 'nullable|array|max:10',
            'components.*.buttons.*.type' => 'nullable|in:QUICK_REPLY,URL,PHONE_NUMBER',
            'components.*.buttons.*.text' => 'nullable|string|max:25',
            'components.*.buttons.*.url' => 'nullable|string|max:2000',
            'components.*.buttons.*.phone_number' => 'nullable|string|max:20',
        ]);

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

        $result = $this->meta->createTemplate($instance->waba_id, $instance->access_token, $payload);

        if (!$result['success']) {
            return response()->json([
                'message' => 'Error creando la plantilla en Meta.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        return response()->json(['data' => $result['data']], 201);
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
            } elseif ($c['type'] === 'FOOTER') {
                if (!empty($c['text'])) $component['text'] = $c['text'];
            } elseif ($c['type'] === 'BUTTONS') {
                $component['buttons'] = array_map(function ($b) {
                    $btn = ['type' => $b['type'], 'text' => $b['text']];
                    if ($b['type'] === 'URL' && !empty($b['url'])) $btn['url'] = $b['url'];
                    if ($b['type'] === 'PHONE_NUMBER' && !empty($b['phone_number'])) $btn['phone_number'] = $b['phone_number'];
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
