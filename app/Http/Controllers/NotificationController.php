<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // GET /api/notifications
    public function index(Request $request)
    {
        $user = auth()->user();

        $unreadCount = $user->unreadNotifications()->count();

        $notifications = $user->notifications()
            ->latest()
            ->limit(30)
            ->get()
            ->map(function ($n) {
                return [
                    'id'         => $n->id,
                    'data'       => $n->data,
                    'read_at'    => $n->read_at,
                    'created_at' => $n->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'unread_count'  => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    // POST /api/notifications/{id}/read
    public function markRead(Request $request, string $id)
    {
        $notification = auth()->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();
        }

        return response()->json(['success' => true]);
    }

    // POST /api/notifications/read-all
    public function markAllRead(Request $request)
    {
        auth()->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }

    // DELETE /api/notifications/{id}
    public function destroy(Request $request, string $id)
    {
        auth()->user()->notifications()->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    // DELETE /api/notifications
    public function destroyAll(Request $request)
    {
        auth()->user()->notifications()->delete();

        return response()->json(['success' => true]);
    }
}
