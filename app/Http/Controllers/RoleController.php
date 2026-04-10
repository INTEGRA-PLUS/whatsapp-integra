<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);

        $roles = Role::where('company_id', $user->company_id)
            ->with('permissions')
            ->get();

        return Inertia::render('Roles/Index', [
            'roles' => $roles
        ]);
    }

    public function create()
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);

        // All existing permissions to allow cross-module selection
        $permissions = Permission::all()->groupBy(function($perm) {
            return explode('.', $perm->name)[0];
        });

        return Inertia::render('Roles/Create', [
            'availablePermissions' => $permissions
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'array' // Extra permissions selected by user
        ]);

        return DB::transaction(function () use ($request, $user) {
            $roleName = $request->name;
            $modulePrefix = Str::slug($roleName);

            // 1. Create the role for this company
            $role = Role::create([
                'name' => $roleName,
                'company_id' => $user->company_id,
                'guard_name' => 'web'
            ]);

            // 2. Create base permissions for this "module" if they don't exist
            $baseActions = ['view', 'create', 'update', 'delete'];
            $newPermissions = [];

            foreach ($baseActions as $action) {
                $permName = "{$modulePrefix}.{$action}";
                $permission = Permission::firstOrCreate([
                    'name' => $permName,
                    'guard_name' => 'web'
                ]);
                $newPermissions[] = $permission->id;
            }

            // 3. Combine with extra permissions selected from other modules
            $allPermissions = array_unique(array_merge($newPermissions, $request->permissions ?? []));

            // 4. Special Case: If it's an Admin role, give ALL existing permissions
            if (Str::lower($roleName) === 'admin') {
                $allPermissions = Permission::all()->pluck('id')->toArray();
            }

            // 5. Assign everything to the role
            $role->syncPermissions($allPermissions);

            return redirect()->route('roles.index')
                ->with('success', 'Rol y módulo creados exitosamente');
        });
    }

    public function edit(Role $role)
    {
        $user = auth()->user();
        if ($role->company_id !== $user->company_id) {
            abort(403);
        }

        setPermissionsTeamId($user->company_id);

        $role->load('permissions');
        $permissions = Permission::all()->groupBy(function($perm) {
            return explode('.', $perm->name)[0];
        });

        return Inertia::render('Roles/Edit', [
            'role' => $role,
            'availablePermissions' => $permissions,
            'rolePermissions' => $role->permissions->pluck('id')
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $user = auth()->user();
        if ($role->company_id !== $user->company_id) {
            abort(403);
        }

        setPermissionsTeamId($user->company_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'array'
        ]);

        $role->update(['name' => $request->name]);
        $role->syncPermissions($request->permissions);

        return redirect()->route('roles.index')
            ->with('success', 'Rol actualizado exitosamente');
    }

    public function destroy(Role $role)
    {
        $user = auth()->user();
        if ($role->company_id !== $user->company_id) {
            abort(403);
        }

        setPermissionsTeamId($user->company_id);
        $role->delete();

        return redirect()->route('roles.index')
            ->with('success', 'Rol eliminado exitosamente');
    }
}
