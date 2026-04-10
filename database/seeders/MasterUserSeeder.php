<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Str;

class MasterUserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Master Company
        $masterCompany = Company::firstOrCreate(
            ['email' => 'master@whatsappintegra.com'],
            [
                'name' => 'Master Admin',
                'slug' => 'master-admin',
                'active' => true,
            ]
        );

        // 2. Setup Spatie Roles for Master Company
        setPermissionsTeamId($masterCompany->id);

        $masterRole = Role::firstOrCreate([
            'name' => 'master',
            'company_id' => $masterCompany->id,
            'guard_name' => 'web'
        ]);

        // 3. Create Master User
        $masterUser = User::firstOrCreate(
            ['email' => 'master@whatsappintegra.com'],
            [
                'company_id' => $masterCompany->id,
                'name' => 'Soporte Master',
                'password' => Hash::make('MasterIntegra2026!'),
                'active' => true,
            ]
        );

        $masterUser->assignRole($masterRole);

        // 4. Setup default roles for other companies (existing users)
        $companies = Company::all(); // Include all to be safe

        foreach ($companies as $company) {
            setPermissionsTeamId($company->id);

            $adminRole = Role::firstOrCreate([
                'name' => 'admin',
                'company_id' => $company->id,
                'guard_name' => 'web'
            ]);

            $agentRole = Role::firstOrCreate([
                'name' => 'agent',
                'company_id' => $company->id,
                'guard_name' => 'web'
            ]);

            // Create basic permissions for users and roles modules
            $modules = ['users', 'roles', 'instances', 'chat', 'crm'];
            $actions = ['view', 'create', 'update', 'delete'];

            foreach ($modules as $mod) {
                foreach ($actions as $act) {
                    $perm = Permission::firstOrCreate([
                        'name' => "{$mod}.{$act}",
                        'guard_name' => 'web'
                    ]);
                    
                    // Assign all to admin
                    $adminRole->givePermissionTo($perm);
                    
                    // Assign only view to agent (example)
                    if ($act === 'view' || $mod === 'chat' || $mod === 'crm') {
                        $agentRole->givePermissionTo($perm);
                    }
                }
            }

            // Migrar usuarios existentes
            $users = User::where('company_id', $company->id)->get();
            foreach ($users as $u) {
                if ($u->email === 'master@whatsappintegra.com') continue;

                // Si no tiene rol asignado aún, intentamos deducirlo o asignar agent
                if ($u->roles->count() === 0) {
                    // Aquí podrías leer una columna legacy 'role' si aún existe
                    $u->assignRole($agentRole);
                }
            }
        }
    }
}
