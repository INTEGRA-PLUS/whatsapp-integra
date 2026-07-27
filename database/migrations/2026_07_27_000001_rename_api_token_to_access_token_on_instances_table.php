<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El commit 71b7c63 ("Bombita...Bum") migró todo el código de Instance del
 * campo `api_token` a `access_token` sin agregar la migración que renombrara
 * la columna física, dejando la tabla desincronizada con el modelo desde
 * entonces (toda query con whereNotNull('access_token') fallaba con
 * "Column not found").
 *
 * Distintos entornos quedaron en distintos estados: bases de datos migradas
 * antes de ese commit todavía tienen `api_token` (hay que renombrarla);
 * bases de datos nunca migradas con la migración vieja de `api_token` (ya
 * borrada del repo) no tienen ninguna de las dos columnas (hay que crear
 * `access_token` directamente). Por eso esto se resuelve en runtime en vez
 * de asumir un punto de partida fijo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('instances', 'access_token')) {
            return;
        }

        if (Schema::hasColumn('instances', 'api_token')) {
            Schema::table('instances', function (Blueprint $table) {
                $table->renameColumn('api_token', 'access_token');
            });
            return;
        }

        Schema::table('instances', function (Blueprint $table) {
            $table->text('access_token')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('instances', 'access_token') && !Schema::hasColumn('instances', 'api_token')) {
            Schema::table('instances', function (Blueprint $table) {
                $table->renameColumn('access_token', 'api_token');
            });
        }
    }
};
