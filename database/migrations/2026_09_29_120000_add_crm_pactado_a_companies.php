<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El paquete de Integra trae el Básico; subir de plan se paga por diferencia.
 *
 * Hasta el 29-sep-2026 al cliente de Integra no se le cobraba el CRM fuera cual
 * fuera su plan, y la pantalla de Planes decía «Incluido» en los tres. Un cliente
 * pidió el Pro gratis con la captura en la mano.
 *
 * **Es también una migración de datos.** Los que ya estaban en un plan mayor
 * antes del cambio —Transinternet, Comuna13 y Megastore en Pro, Star NET en
 * Avanzado— lo conservan sin cargo: es lo que se les vendió. Se marcan aquí por
 * lo que eran en el momento de migrar, no por nombre, para que el criterio sea
 * exactamente el que se aplicó.
 *
 * `crm_usd` en los recibos guarda cuánto del importe fue CRM. Sin él, el recibo
 * de un cliente de Integra en Pro no puede decir si pagó la diferencia o si la
 * tiene pactada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('crm_pactado')->default(false)->after('viene_de_integra');
        });

        Schema::table('suscripcion_cobros', function (Blueprint $table) {
            $table->unsignedInteger('crm_usd')->nullable()->after('crm_incluido');
        });

        DB::table('companies')
            ->where(fn ($q) => $q->where('viene_de_integra', true)->orWhere('cobro', 'integra'))
            ->whereIn('plan', ['pro', 'avanzado'])
            ->update(['crm_pactado' => true]);
    }

    public function down(): void
    {
        Schema::table('suscripcion_cobros', function (Blueprint $table) {
            $table->dropColumn('crm_usd');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('crm_pactado');
        });
    }
};
