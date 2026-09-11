<?php

namespace App\Http\Controllers;

use App\Support\PermisosCatalogo;
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
            'roles'  => $roles,
            'modulos' => PermisosCatalogo::paraPantalla(),
        ]);
    }

    public function create()
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);

        return Inertia::render('Roles/Create', [
            'grupos'   => PermisosCatalogo::paraPantalla(),
            'acciones' => PermisosCatalogo::ACCIONES,
            'niveles'  => PermisosCatalogo::NIVELES,
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        setPermissionsTeamId($user->company_id);

        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        return DB::transaction(function () use ($request, $user) {
            $role = Role::create([
                'name'        => $request->name,
                'description' => $request->description,
                'company_id'  => $user->company_id,
                'guard_name'  => 'web',
            ]);

            // Un rol agrupa permisos de los módulos que ya existen; no inventa
            // módulos nuevos. Antes, guardar «Ventas» creaba también
            // ventas.view/create/update/delete: permisos que ningún `can()` de
            // la aplicación consulta, pero que quedaban en la tabla y a partir
            // de ahí aparecían como un módulo más en la matriz del siguiente
            // rol. Cada rol creado ensuciaba la pantalla del que venía.
            $permisos = $request->permissions ?? [];

            // El administrador es la excepción deliberada: se lleva todo lo que
            // exista, incluido lo que se agregue después de crearlo.
            if (Str::lower($request->name) === 'admin') {
                $permisos = Permission::pluck('id')->all();
            }

            $role->syncPermissions($permisos);

            return redirect()->route('roles.index')
                ->with('success', 'Rol creado exitosamente');
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

        return Inertia::render('Roles/Edit', [
            'role'            => $role,
            'grupos'          => PermisosCatalogo::paraPantalla(),
            'acciones'        => PermisosCatalogo::ACCIONES,
            'niveles'         => PermisosCatalogo::NIVELES,
            'rolePermissions' => $role->permissions->pluck('id'),
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
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        $role->update([
            'name'        => $request->name,
            'description' => $request->description,
        ]);

        $role->syncPermissions($request->permissions ?? []);

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
