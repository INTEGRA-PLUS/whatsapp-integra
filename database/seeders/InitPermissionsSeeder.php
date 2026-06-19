<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use App\Models\Company;

class InitPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear permisos base para módulos fijos
        $modules = ['users', 'roles', 'instances', 'chat', 'crm', 'quick_replies', 'auto_responses', 'campaigns', 'reports', 'templates', 'integrations'];
        $actions = ['view', 'create', 'update', 'delete'];

        foreach ($modules as $mod) {
            foreach ($actions as $act) {
                Permission::firstOrCreate([
                    'name' => "{$mod}.{$act}",
                    'guard_name' => 'web'
                ]);
            }
        }

        // Permisos sueltos que no siguen el patrón módulo.acción
        Permission::firstOrCreate(['name' => 'notifications.send', 'guard_name' => 'web']);

        $allPermissions = Permission::all();

        // 2. Procesar cada empresa
        Company::all()->each(function($company) use ($allPermissions) {
            setPermissionsTeamId($company->id);

            // Crear el rol admin para esta empresa
            $adminRole = Role::firstOrCreate([
                'name' => 'admin',
                'company_id' => $company->id,
                'guard_name' => 'web'
            ]);

            // Asignar TODOS los permisos al admin
            $adminRole->syncPermissions($allPermissions);

            // Asignar el rol al primer usuario
            $firstUser = User::where('company_id', $company->id)->oldest()->first();
            if ($firstUser) {
                $firstUser->assignRole($adminRole);
            }
        });
    }
}
