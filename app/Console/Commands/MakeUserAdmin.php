<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class MakeUserAdmin extends Command
{
    protected $signature = 'user:make-admin
        {email? : Correo del usuario (si se omite, toma el primer usuario)}
        {--all : Vuelve admin al primer usuario de CADA empresa}';
    protected $description = 'Asigna el rol admin (con todos los permisos) a un usuario, respetando el team de su empresa';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->handleAllCompanies();
        }

        $email = $this->argument('email');

        $user = $email
            ? User::where('email', $email)->first()
            : User::orderBy('id')->first();

        if (! $user) {
            $this->error($email ? "No existe un usuario con el correo {$email}." : 'No hay usuarios en la base de datos.');
            return self::FAILURE;
        }

        if (! $user->company_id) {
            $this->error("El usuario {$user->email} no tiene company_id asignado.");
            return self::FAILURE;
        }

        $this->promote($user);
        $this->info("✅ {$user->email} ahora es admin de la empresa #{$user->company_id} con ".Permission::count().' permisos.');

        return self::SUCCESS;
    }

    private function handleAllCompanies(): int
    {
        $companies = Company::orderBy('id')->get();
        $done = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            $user = User::where('company_id', $company->id)->orderBy('id')->first();

            if (! $user) {
                $this->warn("— Empresa #{$company->id} ({$company->name}): sin usuarios, se omite.");
                $skipped++;
                continue;
            }

            $this->promote($user);
            $this->line("✅ Empresa #{$company->id} ({$company->name}): admin → {$user->email}");
            $done++;
        }

        $this->info("Listo: {$done} empresas con admin asignado, {$skipped} omitidas.");
        return self::SUCCESS;
    }

    /**
     * Fija el team de la empresa, crea el rol admin si falta, le sincroniza
     * todos los permisos y se lo asigna al usuario.
     */
    private function promote(User $user): void
    {
        // Spatie usa teams (team_foreign_key = company_id): hay que fijar el team
        // antes de crear/asignar roles.
        setPermissionsTeamId($user->company_id);

        $role = Role::firstOrCreate([
            'name'       => 'admin',
            'company_id' => $user->company_id,
            'guard_name' => 'web',
        ]);

        // El admin siempre lleva todos los permisos disponibles.
        $role->syncPermissions(Permission::all());

        $user->assignRole($role);
    }
}
