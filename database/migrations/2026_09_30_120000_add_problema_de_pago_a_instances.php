<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La línea no puede enviar porque a su cuenta de Meta le falta la moneda o la
 * tarjeta.
 *
 * El 30-sep-2026 las facturas de JHeda Comunicaciones empezaron a volver con
 * «your WhatsApp Business account currency is not configured» y el cliente sólo
 * veía «Fallido» en cada mensaje. Nadie en su empresa sabía que tenía que ir a
 * Meta a poner una tarjeta: el cobro de Meta va directo al cliente, no pasa por
 * nosotros.
 *
 * Columnas y no una clave más en `instances.meta`: se consulta en cada página
 * (la alerta roja va en el layout) y filtrar por un JSON en cada petición es
 * pagar un barrido por nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            // `sin_moneda` o `sin_metodo_de_pago`. Null = sin problema conocido.
            $table->string('problema_de_pago', 30)->nullable()->after('puede_enviar_visto_at');
            $table->timestamp('problema_de_pago_desde')->nullable()->after('problema_de_pago');
            // El enlace que trae el propio error de Meta: abre el asistente
            // exacto de esa cuenta, con el portafolio ya elegido.
            $table->string('enlace_de_pago', 1000)->nullable()->after('problema_de_pago_desde');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['problema_de_pago', 'problema_de_pago_desde', 'enlace_de_pago']);
        });
    }
};
