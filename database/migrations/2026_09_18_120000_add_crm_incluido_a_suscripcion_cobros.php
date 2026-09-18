<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Si el CRM de este cobro iba dentro del paquete de Integra.
 *
 * Sin esto, el recibo de un cliente de Integra que compra IA decía «Pro + IA
 * Completa · $49» y no había forma de explicar el importe: parecía que los dos
 * costaban 49. Son 49 de la IA, y el Pro se lo cubre su ERP.
 *
 * Va en la fila y no se deduce de la empresa a propósito: el recibo guarda una
 * copia de lo que se cobró, y una empresa que deje de venir de Integra el año
 * que viene no puede cambiar lo que dice un recibo de este mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suscripcion_cobros', function (Blueprint $table) {
            $table->boolean('crm_incluido')->nullable()->after('ciclo');
        });

        // Los ya emitidos: se rellenan mirando a la empresa, que hoy sigue
        // siendo la verdad. Es la única oportunidad de hacerlo bien.
        DB::table('suscripcion_cobros')
            ->whereIn('company_id', DB::table('companies')
                ->where(fn ($q) => $q->where('viene_de_integra', true)->orWhere('cobro', 'integra'))
                ->pluck('id'))
            ->update(['crm_incluido' => true]);
    }

    public function down(): void
    {
        Schema::table('suscripcion_cobros', function (Blueprint $table) {
            $table->dropColumn('crm_incluido');
        });
    }
};
