<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas veces un documento ha contestado de verdad.
 *
 * Es la única forma de saber si esto funciona o sólo lo parece. Un documento que
 * lleva tres semanas subido y cero usos es una de dos cosas, y las dos importan:
 * o nadie pregunta por lo que hay dentro, o la búsqueda no lo encuentra. Sin el
 * contador, las dos se ven igual que un documento que sí trabaja.
 *
 * Se cuenta por documento y no por fragmento a propósito: el número que el admin
 * puede entender —y sobre el que puede decidir borrar algo— es «este PDF ha
 * respondido 40 veces», no «el fragmento 217 se usó 3 veces».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_documentos', function (Blueprint $table) {
            $table->unsignedInteger('usos')->default(0)->after('fragmentos');
            $table->timestamp('ultimo_uso_at')->nullable()->after('usos');
        });
    }

    public function down(): void
    {
        Schema::table('ai_documentos', function (Blueprint $table) {
            $table->dropColumn(['usos', 'ultimo_uso_at']);
        });
    }
};
