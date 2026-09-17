<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Support\PermisosCatalogo;
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
        
        // Con sus permisos: la pantalla enseñaba una tabla escrita a mano en el
        // JSX —«Ver Chats», «Borrar Datos», columnas Adm/Age/Usr— que no salía
        // de ningún sitio. Ni los roles son esos en todas las empresas —cada una
        // define los suyos— ni las marcas correspondían a los permisos reales.
        $roles = Role::with('permissions:id,name')
            ->where('company_id', $user->company_id)
            ->get()
            ->map(fn (Role $rol) => [
                'id' => $rol->id,
                'name' => $rol->name,
                'permisos' => $rol->permissions->count(),
                'resumen' => PermisosCatalogo::resumenDeRol($rol->permissions->pluck('name')->all()),
            ]);

        return Inertia::render('Users/Create', [
            'roles' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            // Acotado a SU empresa. `exists:roles,id` a secas acepta el id de
            // un rol de otro cliente: bastaba con cambiarlo en la petición para
            // asignarle a un usuario propio los permisos de un rol ajeno. El
            // aislamiento aquí no lo da ningún scope, lo da este `where`.
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $user->company_id)],
            'active' => 'boolean',
        ], [
            'email.unique' => 'Este correo ya está registrado. No se puede tener dos usuarios con el mismo correo.',
        ]);

        $newUser = User::create([
            'company_id' => $user->company_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'active' => $request->active ?? true,
        ]);

        $role = Role::where('company_id', $user->company_id)
            ->findOrFail($request->role_id);
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

        // Con sus permisos, igual que en el alta: la pantalla enseñaba una tabla
        // de permisos escrita a mano que no correspondía a los roles de nadie.
        $roles = Role::with('permissions:id,name')
            ->where('company_id', $currentUser->company_id)
            ->get()
            ->map(fn (Role $rol) => [
                'id' => $rol->id,
                'name' => $rol->name,
                'permisos' => $rol->permissions->count(),
                'resumen' => PermisosCatalogo::resumenDeRol($rol->permissions->pluck('name')->all()),
            ]);

        $user->load('roles');

        return Inertia::render('Users/Edit', [
            'user' => $user->only(['id', 'name', 'email', 'active', 'created_at']),
            'roles' => $roles,
            'userRoleId' => $user->roles->first()?->id,
            // Si es él mismo: quitarse el propio acceso o bajarse el rol deja a
            // la empresa sin quien administre, y el que lo hace se entera al
            // recargar.
            'es_uno_mismo' => $user->id === $currentUser->id,
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
            // Acotado a SU empresa. `exists:roles,id` a secas acepta el id de
            // un rol de otro cliente: bastaba con cambiarlo en la petición para
            // asignarle a un usuario propio los permisos de un rol ajeno. El
            // aislamiento aquí no lo da ningún scope, lo da este `where`.
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $currentUser->company_id)],
            'active' => 'boolean',
        ], [
            'email.unique' => 'Este correo ya está registrado. No se puede tener dos usuarios con el mismo correo.',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'active' => $request->active,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        // Qué cambió de verdad, antes de escribirlo. La pantalla prometía que
        // «los cambios son registrados en la bitácora de auditoría» y no se
        // registraba ninguno: cuando alguien apareciera con permisos que no le
        // tocaban, no habría forma de saber quién se los dio.
        $antes = $user->only(['name', 'email', 'active']);
        $rolAnterior = $user->roles->first()?->name;

        $user->update($data);

        $role = Role::where('company_id', $currentUser->company_id)
            ->findOrFail($request->role_id);
        $user->syncRoles([$role]);

        $cambios = array_keys(array_diff_assoc($user->only(['name', 'email', 'active']), $antes));

        if ($rolAnterior !== $role->name) {
            $cambios[] = 'rol: '.($rolAnterior ?? 'sin rol').' → '.$role->name;
        }

        if ($request->filled('password')) {
            $cambios[] = 'contraseña';
        }

        if ($cambios !== []) {
            Log::channel('whatsapp')->info('👤 Usuario modificado', [
                'company_id' => $currentUser->company_id,
                'usuario' => $user->id,
                'cambios' => $cambios,
                'por' => $currentUser->id,
            ]);
        }

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

    /**
     * Get users for the company (API).
     */
    public function getCompanyUsers()
    {
        $user = auth()->user();
        
        $users = User::where('company_id', $user->company_id)
            ->where('active', true)
            ->select(['id', 'name', 'email'])
            ->get();

        return response()->json($users);
    }
}
