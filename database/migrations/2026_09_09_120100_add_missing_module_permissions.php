<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Hay rutas protegidas con permisos que nunca se sembraron.
 *
 *   Route::...->middleware('permission:macros.view')
 *
 * Si `macros.view` no existe como permiso, no hay forma de concedérselo a
 * nadie: el módulo queda inaccesible para todo el que no sea admin y, peor,
 * tampoco aparece en la pantalla de roles para poder repartirlo.
 *
 * Aquí se siembran dos tandas:
 *
 *  1. Los que las rutas exigen y nadie creó nunca: macros, menús de WhatsApp
 *     y horarios de atención.
 *  2. Los módulos base que hoy solo existen si alguien corrió a mano el
 *     InitPermissionsSeeder (chat, CRM, respuestas rápidas, plantillas,
 *     instancias, usuarios y roles). Un entorno que corrió `migrate` pero no
 *     `db:seed` se queda sin ellos y el menú lateral le aparece vacío. Como
 *     todo va con firstOrCreate, donde ya existan esto no hace nada.
 */
return new class extends Migration
{
    private array $permisos = [
        'macros.view',
        'macros.create',
        'macros.update',
        'macros.delete',
        'macros.run',
        'whatsapp_menus.view',
        'whatsapp_menus.create',
        'whatsapp_menus.update',
        'whatsapp_menus.delete',
        'business_hours.view',
        'business_hours.create',
        'business_hours.update',
        'business_hours.delete',
        'chat.view',
        'chat.update',
        'chat.delete',
        'crm.view',
        'crm.create',
        'crm.update',
        'crm.delete',
        'quick_replies.view',
        'quick_replies.create',
        'quick_replies.update',
        'quick_replies.delete',
        'templates.view',
        'templates.create',
        'templates.update',
        'templates.delete',
        'instances.view',
        'instances.create',
        'instances.update',
        'instances.delete',
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
    ];

    public function up(): void
    {
        foreach ($this->permisos as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $nuevos = Permission::whereIn('name', $this->permisos)->get();

        if (! Schema::hasTable('companies')) {
            return;
        }

        DB::table('companies')->orderBy('id')->each(function ($company) use ($nuevos) {
            setPermissionsTeamId($company->id);

            $admin = Role::where('name', 'admin')
                ->where('company_id', $company->id)
                ->where('guard_name', 'web')
                ->first();

            if ($admin) {
                $admin->givePermissionTo($nuevos);
            }
        });
    }

    public function down(): void
    {
        // A propósito no borra nada. La mayoría de estos permisos ya existían
        // en los entornos donde alguien corrió el seeder; un rollback que los
        // eliminara dejaría sin menú a empresas que llevan meses trabajando.
        // Para quitarlos hace falta una decisión explícita, no un `migrate:rollback`.
    }
};
