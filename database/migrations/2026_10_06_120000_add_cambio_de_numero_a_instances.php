<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo cambió de número una línea, y cuál tenía antes.
 *
 * El 6-oct-2026 CMNET pasó su línea a un número nuevo para salir de una
 * cuenta de Meta con los pagos restringidos. Los clientes que le habían
 * escrito al número viejo no le habían escrito nunca al nuevo, y Meta
 * rechazaba con «(#131005) Access denied» hasta un «buenas tardes». El agente
 * no tenía forma de entender eso: el aviso tiene que poder decir «este cliente
 * te escribió al número anterior», y para eso hay que saber cuándo cambió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->string('numero_anterior_id', 64)->nullable()->after('detalle_de_pago');
            $table->string('numero_anterior_visible', 32)->nullable()->after('numero_anterior_id');
            $table->timestamp('numero_cambiado_at')->nullable()->after('numero_anterior_visible');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['numero_anterior_id', 'numero_anterior_visible', 'numero_cambiado_at']);
        });
    }
};
