<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un rol se llama «Soporte» o «Ventas» y con eso nadie sabe qué alcanza a
 * hacer. Guardamos en una frase para qué se creó, y así el listado de roles
 * responde la pregunta sin obligar a entrar a revisar las casillas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabla = config('permission.table_names.roles', 'roles');

        if (Schema::hasColumn($tabla, 'description')) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        $tabla = config('permission.table_names.roles', 'roles');

        if (! Schema::hasColumn($tabla, 'description')) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
