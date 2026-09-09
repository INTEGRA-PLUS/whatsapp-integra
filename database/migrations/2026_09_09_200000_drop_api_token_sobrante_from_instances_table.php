<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se lleva la columna que tumbó el despliegue del 9-sep-2026.
 *
 * Historia corta, en `docs/incidente-columna-api-token.md`: la migración de
 * julio que renombró `api_token` a `access_token` se salía sin hacer nada si
 * `access_token` ya existía. En producción existía, así que la vieja se quedó
 * ahí, muerta, hasta que una migración nueva quiso crear una columna con ese
 * mismo nombre y chocó. Para desatascar el servidor se la renombró a
 * `api_token_sobrante_jul2026` en vez de borrarla, que fue lo prudente.
 *
 * Borrarla ya es seguro, y no es una corazonada: en producción estaba **nula en
 * las 50 instancias**, ningún código del repo la lee, y la columna que de verdad
 * guarda el token de Meta —`access_token`— está aparte y con sus datos.
 *
 * Va guardada por `hasColumn` porque sólo existe en los servidores que
 * arrastran esa historia. Donde no esté, esto no hace nada.
 */
return new class extends Migration
{
    private const SOBRANTE = 'api_token_sobrante_jul2026';

    public function up(): void
    {
        if (! Schema::hasColumn('instances', self::SOBRANTE)) {
            return;
        }

        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(self::SOBRANTE);
        });
    }

    /**
     * Se recrea vacía, que es como estaba.
     *
     * No se intenta devolver ningún contenido porque no lo había: revertir esto
     * restituye la forma de la tabla, no unos datos que nunca existieron.
     */
    public function down(): void
    {
        if (Schema::hasColumn('instances', self::SOBRANTE)) {
            return;
        }

        Schema::table('instances', function (Blueprint $table) {
            $table->text(self::SOBRANTE)->nullable();
        });
    }
};
