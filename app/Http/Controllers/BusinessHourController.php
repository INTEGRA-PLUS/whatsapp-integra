<?php

namespace App\Http\Controllers;

use App\Models\BusinessHour;
use App\Models\Instance;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BusinessHourController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $items = BusinessHour::where('company_id', $user->company_id)
            ->with('instance:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        $instances = Instance::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'business_hours' => $items,
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

        $this->assertNoConflictingRule($data, $user->company_id);

        $hour = BusinessHour::create(array_merge($data, [
            'company_id' => $user->company_id,
        ]));

        return response()->json(['business_hour' => $hour->load('instance:id,name')], 201);
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();

        $hour = BusinessHour::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $data = $this->validateData($request);

        if (!empty($data['instance_id'])) {
            $this->ensureInstanceBelongsToCompany($data['instance_id'], $user->company_id);
        }

        $this->assertNoConflictingRule($data, $user->company_id, $hour->id);

        $hour->update($data);

        return response()->json(['business_hour' => $hour->load('instance:id,name')]);
    }

    public function destroy($id)
    {
        $user = auth()->user();

        $hour = BusinessHour::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $hour->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'instance_id' => 'nullable|integer|exists:instances,id',
            'active' => 'boolean',
            'timezone' => 'nullable|string|max:64',
            'schedule_days' => 'required|array',
            'schedule_days.*.enabled' => 'boolean',
            'schedule_days.*.all_day' => 'boolean',
            'schedule_days.*.ranges' => 'array',
            'schedule_days.*.ranges.*.start' => 'required|date_format:H:i',
            'schedule_days.*.ranges.*.end' => 'required|date_format:H:i',
            'out_of_hours_message' => 'required|string|max:4096',
            'cooldown_minutes' => 'nullable|integer|min:0|max:10080',
        ]);

        $data['schedule_days'] = $this->normalizeScheduleDays($data['schedule_days']);
        $data['timezone'] = $data['timezone'] ?? 'America/Bogota';
        $data['active'] = $data['active'] ?? true;
        $data['cooldown_minutes'] = $data['cooldown_minutes'] ?? 60;

        return $data;
    }

    /**
     * Rellena los 7 días (0=domingo..6=sábado) con defaults seguros, para que el
     * front nunca tenga que enviar un día "vacío" y el modelo siempre pueda leerlo.
     */
    private function normalizeScheduleDays(array $raw): array
    {
        $normalized = [];

        for ($day = 0; $day <= 6; $day++) {
            $config = $raw[$day] ?? $raw[(string) $day] ?? [];
            $enabled = (bool) ($config['enabled'] ?? false);
            $allDay = (bool) ($config['all_day'] ?? false);
            $ranges = $enabled && !$allDay
                ? array_values(array_map(
                    fn ($range) => ['start' => $range['start'] . ':00', 'end' => $range['end'] . ':00'],
                    $config['ranges'] ?? []
                ))
                : [];

            $normalized[(string) $day] = [
                'enabled' => $enabled,
                'all_day' => $enabled && $allDay,
                'ranges' => $ranges,
            ];
        }

        return $normalized;
    }

    /**
     * Impide que existan dos horarios ACTIVOS con el mismo alcance para una empresa
     * (dos para "todas las instancias", o dos para la misma instancia). Era la causa
     * de que una regla vieja invisible siguiera disparando aunque el usuario editara otra.
     */
    private function assertNoConflictingRule(array $data, int $companyId, ?int $ignoreId = null): void
    {
        if (!($data['active'] ?? true)) {
            return;
        }

        $instanceId = $data['instance_id'] ?? null;

        $conflict = BusinessHour::where('company_id', $companyId)
            ->where('active', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->when(
                $instanceId === null,
                fn ($q) => $q->whereNull('instance_id'),
                fn ($q) => $q->where('instance_id', $instanceId)
            )
            ->exists();

        if ($conflict) {
            $scope = $instanceId === null ? 'todas las instancias' : 'esta instancia';

            throw ValidationException::withMessages([
                'instance_id' => ["Ya existe un horario activo para {$scope}. Edita o desactiva el existente antes de crear otro."],
            ]);
        }
    }

    private function ensureInstanceBelongsToCompany(int $instanceId, int $companyId): void
    {
        Instance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();
    }
}
