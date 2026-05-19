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
