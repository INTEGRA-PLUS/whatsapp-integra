<?php

namespace App\Http\Controllers;

use App\Models\AutoResponse;
use App\Models\Instance;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AutoResponseController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $autoResponses = AutoResponse::where('company_id', $user->company_id)
            ->with('instance:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        $instances = Instance::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('AutoResponses/Index', [
            'autoResponses' => $autoResponses,
            'instances' => $instances,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $user = auth()->user();

        if ($conflict = $this->triggerlessConflict($data, $user->company_id)) {
            return $conflict;
        }

        if (!empty($data['instance_id'])) {
            $this->ensureInstanceBelongsToCompany($data['instance_id'], $user->company_id);
        }

        AutoResponse::create(array_merge(
            ['company_id' => $user->company_id],
            $this->mappedAttributes($data, true)
        ));

        return redirect()->route('auto-responses.index')
            ->with('success', 'Respuesta automática creada');
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();

        $autoResponse = AutoResponse::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $data = $this->validateData($request);

        if ($conflict = $this->triggerlessConflict($data, $user->company_id, $id)) {
            return $conflict;
        }

        if (!empty($data['instance_id'])) {
            $this->ensureInstanceBelongsToCompany($data['instance_id'], $user->company_id);
        }

        $autoResponse->update($this->mappedAttributes($data, false));

        return redirect()->route('auto-responses.index')
            ->with('success', 'Respuesta automática actualizada');
    }

    /**
     * Construye los atributos a persistir a partir de los datos validados.
     */
    private function mappedAttributes(array $data, bool $creating): array
    {
        $types = array_values(array_unique($data['match_types']));
        $hasKeyword = count(array_intersect($types, AutoResponse::KEYWORD_TYPES)) > 0;
        $hasReopen = in_array('reopen', $types, true);

        return [
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'trigger_text' => $hasKeyword ? $data['trigger_text'] : '*',
            'match_type' => $types[0], // legacy: primer tipo, para compatibilidad/listados
            'match_types' => $types,
            'response_message' => $data['response_message'],
            'active' => $data['active'] ?? ($creating ? true : false),
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
            'reopen_hours' => $hasReopen ? ($data['reopen_hours'] ?? AutoResponse::DEFAULT_REOPEN_HOURS) : null,
        ];
    }

    /**
     * Solo puede existir UNA regla por instancia para cada tipo sin disparador
     * (bienvenida, siempre, reabrir) para evitar respuestas duplicadas.
     */
    private function triggerlessConflict(array $data, int $companyId, $ignoreId = null)
    {
        $labels = [
            'always' => '"Siempre responde"',
            'welcome' => '"Mensaje de bienvenida"',
            'reopen' => '"Saludo al reabrir"',
        ];

        foreach (array_intersect($data['match_types'], AutoResponse::TRIGGERLESS_TYPES) as $type) {
            $exists = AutoResponse::where('company_id', $companyId)
                ->where('instance_id', $data['instance_id'] ?? null)
                ->whereJsonContains('match_types', $type)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                return back()->withErrors([
                    'match_types' => "Ya existe una respuesta configurada como {$labels[$type]} para esta instancia.",
                ]);
            }
        }

        return null;
    }

    public function destroy($id)
    {
        $user = auth()->user();

        $autoResponse = AutoResponse::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $autoResponse->delete();

        return redirect()->route('auto-responses.index')
            ->with('success', 'Respuesta automática eliminada');
    }

    private function validateData(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'match_types' => 'required|array|min:1',
            'match_types.*' => 'in:exact,contains,starts_with,always,welcome,reopen',
            'trigger_text' => 'nullable|string|max:1000',
            'response_message' => 'required|string|max:4096',
            'instance_id' => 'nullable|integer|exists:instances,id',
            'active' => 'boolean',
            'cooldown_minutes' => 'nullable|integer|min:0|max:10080',
            'reopen_hours' => 'nullable|integer|min:1|max:8760',
        ]);

        $hasKeyword = count(array_intersect($validated['match_types'], AutoResponse::KEYWORD_TYPES)) > 0;

        if ($hasKeyword && trim((string) ($validated['trigger_text'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'trigger_text' => 'Indica el texto disparador para los tipos por palabra clave.',
            ]);
        }

        return $validated;
    }

    private function ensureInstanceBelongsToCompany(int $instanceId, int $companyId): void
    {
        Instance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();
    }
}
