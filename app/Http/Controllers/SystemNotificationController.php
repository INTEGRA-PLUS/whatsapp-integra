<?php

namespace App\Http\Controllers;

use App\Models\SystemAnnouncement;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class SystemNotificationController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()->company_id;

        return Inertia::render('Notifications/Index', [
            'users'    => User::where('company_id', $companyId)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'announcements' => $this->companyHistory($companyId),
        ]);
    }

    public function history()
    {
        return response()->json($this->companyHistory(auth()->user()->company_id));
    }

    public function store(Request $request)
    {
        $user      = auth()->user();
        $companyId = $user->company_id;

        $validated = $request->validate([
            'title'   => 'required|string|max:120',
            'body'    => 'required|string|max:2000',
            'target'  => 'required|in:all,user',
            'user_id' => 'required_if:target,user|nullable|integer',
        ]);

        // Resolve recipients (always scoped to the company).
        $recipientsQuery = User::where('company_id', $companyId)->where('active', true);

        if ($validated['target'] === 'user') {
            $recipientsQuery->where('id', $validated['user_id']);
        }

        $recipients = $recipientsQuery->get();

        if ($recipients->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No hay destinatarios válidos para este anuncio.',
            ], 422);
        }

        Notification::send(
            $recipients,
            new SystemNotification($validated['title'], $validated['body'], $user->name)
        );

        $announcement = SystemAnnouncement::create([
            'company_id'       => $companyId,
            'sent_by'          => $user->id,
            'title'            => $validated['title'],
            'body'             => $validated['body'],
            'target'           => $validated['target'],
            'target_user_id'   => $validated['target'] === 'user' ? $validated['user_id'] : null,
            'recipients_count' => $recipients->count(),
        ]);

        return response()->json([
            'success'      => true,
            'message'      => "Anuncio enviado a {$recipients->count()} usuario(s).",
            'announcement' => $announcement->load(['sender:id,name', 'targetUser:id,name']),
        ], 201);
    }

    private function companyHistory(int $companyId)
    {
        return SystemAnnouncement::forCompany($companyId)
            ->with(['sender:id,name', 'targetUser:id,name'])
            ->latest('id')
            ->limit(30)
            ->get();
    }
}
