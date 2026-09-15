<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué empresas son nuestras y no clientes.
 *
 * De las 55 empresas del sistema, siete son de la casa: `Master Admin`,
 * `PRUEBAS`, `Empresa Demo`, `Meta App Review`, `Integra Pay`,
 * `Cootramed (DEMO)` y `Unimos Partner`. Y tres de ellas tienen contactos de
 * verdad —`Master Admin` 1.332, `Meta App Review` 673, `PRUEBAS` 672— porque
 * se usan para probar con números reales.
 *
 * Eso significa que **no se distinguen por estar vacías**. Cualquier lista de
 * cobro que se haga contando contactos las mete dentro, y la primera vez que se
 * exporte «a quién cobrar este mes» van a salir facturas para nuestras propias
 * pruebas. Es el tipo de error que se descubre delante de un cliente.
 *
 * `active` no sirve para esto: están activas a propósito, se usan todos los
 * días. Y el plan tampoco: `Meta App Review` necesitaba todo encendido para el
 * App Review de Meta.
 *
 * ## Lo que esta marca NO hace
 *
 * No apaga nada, no limita nada y no cambia lo que la empresa puede usar. Sólo
 * dice «esta no entra en las cuentas». Una empresa interna sigue funcionando
 * exactamente igual que antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('interna')->default(false)->after('cobro');
        });

        // Se marcan por nombre exacto y no por un LIKE '%demo%': hay clientes
        // de verdad cuyo nombre podría contener esas palabras, y marcar a un
        // cliente como interno es dejar de cobrarle sin que nadie se entere.
        //
        // Si alguno no existe en esta base, el update no hace nada y no pasa
        // nada: es una siembra, no un requisito.
        DB::table('companies')
            ->whereIn('name', [
                'Master Admin',
                'PRUEBAS',
                'Empresa Demo',
                'Meta App Review',
                'Integra Pay',
                'Cootramed (DEMO)',
                'Unimos Partner',
            ])
            ->update(['interna' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('interna');
        });
    }
};
