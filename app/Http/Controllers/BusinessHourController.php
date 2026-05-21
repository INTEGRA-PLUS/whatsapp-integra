<?php

namespace App\Http\Controllers;

use App\Models\BusinessHour;
use App\Models\Instance;
use Illuminate\Http\Request;

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
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'out_of_hours_message' => 'required|string|max:4096',
            'cooldown_minutes' => 'nullable|integer|min:0|max:10080',
        ]);

        $data['start_time'] = $data['start_time'] . ':00';
        $data['end_time'] = $data['end_time'] . ':00';
        $data['timezone'] = $data['timezone'] ?? 'America/Bogota';
        $data['active'] = $data['active'] ?? true;
        $data['cooldown_minutes'] = $data['cooldown_minutes'] ?? 60;

        return $data;
    }

    private function ensureInstanceBelongsToCompany(int $instanceId, int $companyId): void
    {
        Instance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();
    }
}
