<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El id que OnePay le da a la factura, guardado para poder reconocerla.
 *
 * Es distinto de `referencia`, y por eso son dos columnas: `referencia` es el
 * identificador del **pago** —el comprobante, el número de transferencia— y
 * `referencia_onepay` es el de la **factura** en la pasarela.
 *
 * Integra aprendió a golpes por qué hacen falta los dos: cuando la pasarela
 * reemplaza una solicitud de pago, estrena id y pierde el `metadata`, así que su
 * webhook busca la factura por cinco caminos distintos. Aquí se guardan los dos
 * identificadores desde el principio para no tener que inventar el tercero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suscripcion_cobros', function (Blueprint $table) {
            $table->string('referencia_onepay', 120)->nullable()->after('referencia')->index();
        });
    }

    public function down(): void
    {
        Schema::table('suscripcion_cobros', function (Blueprint $table) {
            $table->dropColumn('referencia_onepay');
        });
    }
};
