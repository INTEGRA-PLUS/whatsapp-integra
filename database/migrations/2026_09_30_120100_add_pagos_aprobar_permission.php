<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Migración de datos: crea el permiso para aprobar comprobantes de pago y se lo
 * da a los admin de cada empresa. Nadie más lo recibe solo: aprobar un pago
 * reconecta al cliente en el router, y quién puede hacerlo lo decide cada
 * empresa desde Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'pagos.aprobar', 'guard_name' => 'web']);

        if (! Schema::hasTable('companies')) {
            return;
        }

        DB::table('companies')->orderBy('id')->each(function ($company) use ($permission) {
            setPermissionsTeamId($company->id);

            Role::where('name', 'admin')
                ->where('company_id', $company->id)
                ->where('guard_name', 'web')
                ->first()
                ?->givePermissionTo($permission);
        });
    }

    public function down(): void
    {
        Permission::where('name', 'pagos.aprobar')->delete();
    }
};
