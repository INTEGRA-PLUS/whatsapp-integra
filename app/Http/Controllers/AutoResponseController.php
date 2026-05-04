<?php

namespace App\Http\Controllers;

use App\Models\AutoResponse;
use App\Models\Instance;
use Illuminate\Http\Request;
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

        if (in_array($data['match_type'], ['always', 'welcome'], true)) {
            $exists = AutoResponse::where('company_id', $user->company_id)
                ->where('instance_id', $data['instance_id'] ?? null)
                ->where('match_type', $data['match_type'])
                ->exists();

            if ($exists) {
                $label = $data['match_type'] === 'always' ? '"Siempre responde"' : '"Mensaje de bienvenida"';
                return back()->withErrors(['match_type' => "Ya existe una respuesta configurada como {$label} para esta instancia."]);
            }
        }

        if (!empty($data['instance_id'])) {
            $this->ensureInstanceBelongsToCompany($data['instance_id'], $user->company_id);
        }

        $triggerlessTypes = ['always', 'welcome'];

        AutoResponse::create([
            'company_id' => $user->company_id,
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'trigger_text' => in_array($data['match_type'], $triggerlessTypes, true) ? '*' : $data['trigger_text'],
            'match_type' => $data['match_type'],
            'response_message' => $data['response_message'],
            'active' => $data['active'] ?? true,
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
        ]);

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

        if (in_array($data['match_type'], ['always', 'welcome'], true)) {
            $exists = AutoResponse::where('company_id', $user->company_id)
                ->where('instance_id', $data['instance_id'] ?? null)
                ->where('match_type', $data['match_type'])
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                $label = $data['match_type'] === 'always' ? '"Siempre responde"' : '"Mensaje de bienvenida"';
                return back()->withErrors(['match_type' => "Ya existe otra respuesta configurada como {$label} para esta instancia."]);
            }
        }

        if (!empty($data['instance_id'])) {
            $this->ensureInstanceBelongsToCompany($data['instance_id'], $user->company_id);
        }

        $triggerlessTypes = ['always', 'welcome'];

        $autoResponse->update([
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'trigger_text' => in_array($data['match_type'], $triggerlessTypes, true) ? '*' : $data['trigger_text'],
            'match_type' => $data['match_type'],
            'response_message' => $data['response_message'],
            'active' => $data['active'] ?? false,
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
        ]);

        return redirect()->route('auto-responses.index')
            ->with('success', 'Respuesta automática actualizada');
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
        return $request->validate([
            'name' => 'required|string|max:120',
            'trigger_text' => 'required_if:match_type,exact,contains,starts_with|nullable|string|max:1000',
            'match_type' => 'required|in:exact,contains,starts_with,always,welcome',
            'response_message' => 'required|string|max:4096',
            'instance_id' => 'nullable|integer|exists:instances,id',
            'active' => 'boolean',
            'cooldown_minutes' => 'nullable|integer|min:0|max:10080',
        ]);
    }

    private function ensureInstanceBelongsToCompany(int $instanceId, int $companyId): void
    {
        Instance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();
    }
}
