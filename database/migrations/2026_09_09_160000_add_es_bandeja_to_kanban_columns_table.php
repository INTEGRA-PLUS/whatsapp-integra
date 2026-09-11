<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué columna recoge lo que no está clasificado deja de ser un accidente.
 *
 * Hasta ahora era «la primera del tablero», sin más. Con un solo tablero por
 * empresa eso colaba: la primera solía ser «Nuevo» o «Soporte», y hacía de
 * bandeja de entrada de todo lo que llegaba.
 *
 * Con grupos deja de colar. El grupo «Zona» de Star NET empieza por CERETE, y
 * CERETE no es la bandeja de nada: pasaría a mostrar las mil y pico
 * conversaciones que no tienen municipio asignado, como si fueran de Cereté.
 * En «Estado» sí quieres que «Nuevo» recoja lo que entra; en «Zona» prefieres
 * ver sólo lo que tiene zona, y que la suma de columnas sea menor que el total.
 *
 * Así que pasa a ser una marca por columna. El relleno deja a cada empresa
 * exactamente como estaba: la primera columna con etiqueta —la única que el
 * tablero pinta— es la que ya venía haciendo de bandeja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->boolean('es_bandeja')->default(false)->after('grupo');
        });

        // Cada empresa se queda como estaba: la primera columna visible.
        $primeras = DB::table('kanban_columns')
            ->whereNotNull('tag_id')
            ->orderBy('company_id')
            ->orderBy('position')
            ->get(['id', 'company_id'])
            ->unique('company_id')
            ->pluck('id');

        if ($primeras->isNotEmpty()) {
            DB::table('kanban_columns')->whereIn('id', $primeras)->update(['es_bandeja' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->dropColumn('es_bandeja');
        });
    }
};
