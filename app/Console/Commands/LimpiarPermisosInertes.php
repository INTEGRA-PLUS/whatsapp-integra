<?php

namespace App\Console\Commands;

use App\Support\PermisosCatalogo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Borra los permisos que se creaban solos al guardar un rol.
 *
 * Hasta ahora, crear el rol «Ventas» creaba además los permisos
 * ventas.view / ventas.create / ventas.update / ventas.delete. Ninguna ruta ni
 * ninguna vista los consulta, así que no habilitan nada; pero quedaban en la
 * tabla `permissions` y desde ahí la pantalla de roles los pintaba como si
 * fueran un módulo del sistema. Cada rol nuevo agregaba cuatro casillas falsas
 * a la pantalla del siguiente.
 *
 * Por omisión solo informa. Para borrar de verdad hay que pasar --aplicar.
 */
class LimpiarPermisosInertes extends Command
{
    protected $signature = 'permisos:limpiar-inertes {--aplicar : Borra de verdad; sin esta bandera solo muestra el informe}';

    protected $description = 'Elimina los permisos que no corresponden a ningún módulo real del sistema';

    public function handle(): int
    {
        $reales = PermisosCatalogo::modulosReales();

        $inertes = Permission::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->reject(function ($permiso) use ($reales) {
                $modulo = explode('.', $permiso->name)[0];

                return in_array($modulo, $reales, true);
            })
            ->values();

        if ($inertes->isEmpty()) {
            $this->info('No hay permisos inertes. La tabla solo tiene módulos reales.');

            return self::SUCCESS;
        }

        $tablaPivote = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        // Estas claves existen en el config pero vienen en null: Spatie cae al
        // nombre por defecto dentro del modelo. `config(..., $default)` no
        // ayuda porque el default solo aplica si la clave NO existe.
        $columnaPermiso = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $columnaRol = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $tablaRoles = config('permission.table_names.roles', 'roles');

        $filas = [];

        foreach ($inertes as $permiso) {
            $roles = DB::table($tablaPivote)
                ->join($tablaRoles, "$tablaRoles.id", '=', "$tablaPivote.$columnaRol")
                ->where("$tablaPivote.$columnaPermiso", $permiso->id)
                ->pluck("$tablaRoles.name")
                ->all();

            $filas[] = [
                $permiso->name,
                count($roles),
                $roles ? implode(', ', array_slice($roles, 0, 4)).(count($roles) > 4 ? '…' : '') : '—',
            ];
        }

        $this->newLine();
        $this->line("Permisos inertes encontrados: <options=bold>{$inertes->count()}</>");
        $this->table(['Permiso', 'Roles que lo tienen', 'Cuáles'], $filas);
        $this->line('Estos permisos no los valida ninguna ruta ni ninguna vista: quitarlos no le');
        $this->line('cambia el acceso a nadie. Solo dejan de ensuciar la pantalla de roles.');
        $this->newLine();

        if (! $this->option('aplicar')) {
            $this->comment('Informe solamente. Para borrarlos: php artisan permisos:limpiar-inertes --aplicar');

            return self::SUCCESS;
        }

        $ids = $inertes->pluck('id')->all();

        DB::transaction(function () use ($ids, $tablaPivote, $columnaPermiso) {
            DB::table($tablaPivote)->whereIn($columnaPermiso, $ids)->delete();
            Permission::whereIn('id', $ids)->delete();
        });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Eliminados '.count($ids).' permisos inertes.');

        return self::SUCCESS;
    }
}
