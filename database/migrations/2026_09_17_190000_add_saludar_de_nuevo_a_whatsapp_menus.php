<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada cuánto vuelve a saludar el menú de bienvenida.
 *
 * Hasta ahora «bienvenida» era literal e irrepetible: el primer mensaje
 * entrante de ese contacto, contando desde siempre. Quien escribió una vez hace
 * meses no volvía a recibir el saludo jamás, y probarlo con el propio número era
 * imposible en cuanto lo habías usado una vez.
 *
 * Nace con 24 h —la misma ventana de atención de Meta— y no con null: un día sin
 * escribir es una conversación nueva a todos los efectos, y ese es el
 * comportamiento que la gente espera al volver.
 *
 * `0` significa «sólo la primera vez en la vida», que es como se comportaba
 * antes: quien lo quiera así lo tiene a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_menus', function (Blueprint $table) {
            $table->unsignedSmallInteger('saludar_de_nuevo_horas')
                ->default(24)
                ->after('cooldown_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_menus', function (Blueprint $table) {
            $table->dropColumn('saludar_de_nuevo_horas');
        });
    }
};
