<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        setPermissionsTeamId($user->company_id);

        $users = User::where('company_id', $user->company_id)
            ->with('roles')
            ->get()
            ->filter(fn($u) => !$u->hasRole('master'));

        $stats = [
            'total' => $users->count(),
            'active' => $users->where('active', true)->count(),
            'admins' => $users->filter(fn($u) => $u->hasRole('admin'))->count(),
            'agents' => $users->filter(fn($u) => $u->hasRole('agent'))->count(),
        ];

        return Inertia::render('Users/Index', [
            'users' => $users->values(),
            'stats' => $stats,
        ]);
    }

    public function create()
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);
        
        $roles = Role::where('company_id', $user->company_id)->get();

        return Inertia::render('Users/Create', [
            'roles' => $roles
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'active' => 'boolean',
        ]);

        $newUser = User::create([
            'company_id' => $user->company_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'active' => $request->active ?? true,
        ]);

        $role = Role::findById($request->role_id);
        $newUser->assignRole($role);

        return redirect()->route('users.index')
            ->with('success', 'Usuario creado exitosamente');
    }

    public function edit(User $user)
    {
        $currentUser = auth()->user();

        if ($user->company_id !== $currentUser->company_id) {
            abort(403);
        }

        setPermissionsTeamId($currentUser->company_id);
        $roles = Role::where('company_id', $currentUser->company_id)->get();
        $user->load('roles');

        return Inertia::render('Users/Edit', [
            'user' => $user,
            'roles' => $roles,
            'userRoleId' => $user->roles->first()?->id
        ]);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = auth()->user();

        if ($user->company_id !== $currentUser->company_id) {
            abort(403);
        }

        setPermissionsTeamId($currentUser->company_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'active' => 'boolean',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'active' => $request->active,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        $role = Role::findById($request->role_id);
        $user->syncRoles([$role]);

        return redirect()->route('users.index')
            ->with('success', 'Usuario actualizado exitosamente');
    }

    public function destroy(User $user)
    {
        $currentUser = auth()->user();

        // Ensure user belongs to the same company
        if ($user->company_id !== $currentUser->company_id) {
            abort(403);
        }

        // Prevent self-deletion
        if ($user->id === $currentUser->id) {
            return redirect()->route('users.index')
                ->with('error', 'No puedes eliminarte a ti mismo');
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'Usuario eliminado exitosamente');
    }
}
