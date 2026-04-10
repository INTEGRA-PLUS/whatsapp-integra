<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use Spatie\Permission\Models\Role;

class AssignMasterRoleSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'master@sistema.com')->first();
        $company = Company::where('slug', 'master-admin')->first();
        
        if (!$company) {
            $company = Company::create([
                'name' => 'Master Admin',
                'slug' => 'master-admin',
                'email' => 'master@whatsappintegra.com',
                'active' => true,
            ]);
        }

        if ($user) {
            $user->company_id = $company->id;
            $user->save();
            
            setPermissionsTeamId($company->id);
            
            $role = Role::firstOrCreate([
                'name' => 'master',
                'company_id' => $company->id,
                'guard_name' => 'web'
            ]);
            
            $user->assignRole($role);
            $this->command->info("Usuario vinculado a la empresa Master y rol asignado.");
        } else {
            $this->command->error("Usuario master@sistema.com no encontrado.");
        }
    }
}
