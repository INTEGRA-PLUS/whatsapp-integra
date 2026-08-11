<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trigger_text` era varchar(255) mientras el formulario aceptaba hasta 1000
 * caracteres. Con MySQL en STRICT_TRANS_TABLES una lista larga de disparadores
 * pasaba la validación y luego reventaba al guardar, así que la regla se
 * quedaba sin las últimas palabras (o sin guardar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_responses', function (Blueprint $table) {
            $table->text('trigger_text')->change();
        });
    }

    public function down(): void
    {
        Schema::table('auto_responses', function (Blueprint $table) {
            $table->string('trigger_text')->change();
        });
    }
};
