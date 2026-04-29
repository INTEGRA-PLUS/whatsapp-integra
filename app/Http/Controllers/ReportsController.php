<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AgentReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportsController extends Controller
{
    public function index(Request $request, AgentReportService $service)
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'user_id' => 'nullable|integer',
        ]);

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $agents = User::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $userId = $request->integer('user_id');
        $userIsValid = $userId && $agents->firstWhere('id', $userId);

        $payload = [
            'agents' => $agents,
            'filters' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'user_id' => $userIsValid ? $userId : null,
            ],
        ];

        if ($userIsValid) {
            $payload['mode'] = 'agent';
            $payload['agentReport'] = $service->buildForAgent($user->company_id, $userId, $from, $to);
        } else {
            $payload['mode'] = 'company';
            $payload['report'] = $service->build($user->company_id, $from, $to);
        }

        return Inertia::render('Reports/Index', $payload);
    }
}
