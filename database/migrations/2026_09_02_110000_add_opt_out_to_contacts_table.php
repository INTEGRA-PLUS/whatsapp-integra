<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien pide no recibir campañas deja de recibirlas.
 *
 * No existía ningún sitio donde anotarlo: un cliente podía decir «no me manden
 * más publicidad» y la siguiente campaña volvía a escribirle, porque la lista se
 * arma de cero cada vez. Con envíos masivos eso no es un descuido, es la forma
 * más rápida de que reporten el número y WhatsApp baje la calidad de la línea.
 *
 * Es la baja de **campañas**, no del servicio: al cliente se le sigue pudiendo
 * responder en el chat y se le siguen mandando los avisos que pide el ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('opted_out_at')->nullable()->after('source');
            // 'manual' (lo marcó un agente) o 'client' (lo pidió el cliente).
            $table->string('opt_out_source', 20)->nullable()->after('opted_out_at');
            $table->unsignedBigInteger('opted_out_by')->nullable()->after('opt_out_source');

            $table->foreign('opted_out_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['company_id', 'opted_out_at']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['opted_out_by']);
            $table->dropIndex(['company_id', 'opted_out_at']);
            $table->dropColumn(['opted_out_at', 'opt_out_source', 'opted_out_by']);
        });
    }
};
