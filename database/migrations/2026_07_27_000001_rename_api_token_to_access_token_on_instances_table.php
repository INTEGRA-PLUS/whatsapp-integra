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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->renameColumn('api_token', 'access_token');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->renameColumn('access_token', 'api_token');
        });
    }
};
