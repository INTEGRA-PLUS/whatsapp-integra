<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $sessions = collect();

        try {
            $sessions = DB::table('sessions')
                ->where('user_id', auth()->id())
                ->orderBy('last_activity', 'desc')
                ->get()
                ->map(function ($session) use ($request) {
                    return [
                        'id'            => $session->id,
                        'ip_address'    => $session->ip_address,
                        'user_agent'    => $session->user_agent,
                        'last_activity' => $session->last_activity,
                        'is_current'    => $session->id === $request->session()->getId(),
                    ];
                });
        } catch (\Exception $e) {
            // Sessions table may not exist yet
        }

        return Inertia::render('Settings/Index', [
            'sessions' => $sessions,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . auth()->id(),
        ]);

        auth()->user()->update($validated);

        return back()->with('success', 'Perfil actualizado correctamente.');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password'      => 'required',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        if (!Hash::check($request->current_password, auth()->user()->password)) {
            return back()->withErrors(['current_password' => 'La contraseña actual no es correcta.']);
        }

        auth()->user()->update(['password' => $request->password]);

        return back()->with('success', 'Contraseña actualizada correctamente.');
    }

    public function destroyOtherSessions(Request $request)
    {
        $request->validate(['password' => 'required']);

        if (!Hash::check($request->password, auth()->user()->password)) {
            return back()->withErrors(['password' => 'La contraseña no es correcta.']);
        }

        DB::table('sessions')
            ->where('user_id', auth()->id())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('success', 'Otras sesiones cerradas correctamente.');
    }
}
