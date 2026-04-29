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

        if (!empty($data['instance_id'])) {
            $this->ensureInstanceBelongsToCompany($data['instance_id'], $user->company_id);
        }

        AutoResponse::create([
            'company_id' => $user->company_id,
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'trigger_text' => $data['trigger_text'],
            'match_type' => $data['match_type'],
            'response_message' => $data['response_message'],
            'active' => $data['active'] ?? true,
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

        if (!empty($data['instance_id'])) {
            $this->ensureInstanceBelongsToCompany($data['instance_id'], $user->company_id);
        }

        $autoResponse->update([
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'trigger_text' => $data['trigger_text'],
            'match_type' => $data['match_type'],
            'response_message' => $data['response_message'],
            'active' => $data['active'] ?? false,
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
            'trigger_text' => 'required|string|max:255',
            'match_type' => 'required|in:exact,contains,starts_with',
            'response_message' => 'required|string|max:4096',
            'instance_id' => 'nullable|integer|exists:instances,id',
            'active' => 'boolean',
        ]);
    }

    private function ensureInstanceBelongsToCompany(int $instanceId, int $companyId): void
    {
        Instance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();
    }
}
