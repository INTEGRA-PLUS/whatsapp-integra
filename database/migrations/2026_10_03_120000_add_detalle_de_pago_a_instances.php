<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que dijo Meta, palabra por palabra, cuando rechazó el envío por cobro.
 *
 * El 3-oct-2026 siete empresas tenían la alerta roja de «tu cuenta no tiene un
 * método de pago» y una respondió, con razón, que su tarjeta no estaba
 * desasociada. Meta decía otra cosa: «has unsettled payments» —un cobro
 * rechazado, casi siempre por saldo— o «payment has been restricted». El CRM
 * sólo guardaba el título genérico («Business eligibility payment issue») y
 * con eso no se distingue nada. Se guarda el texto entero para enseñarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->text('detalle_de_pago')->nullable()->after('enlace_de_pago');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn('detalle_de_pago');
        });
    }
};
