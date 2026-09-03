<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            // Integra puede convertir la factura estándar en electrónica y
            // emitirla a la DIAN en el mismo momento en que se registra el pago.
            // Es una decisión de la empresa (no todas facturan electrónicamente
            // ni quieren que un pago desde el chat dispare una emisión fiscal),
            // así que vive junto al resto de la activación de "Pagos a facturas"
            // y viaja como `emitir_electronica` en cada POST de pago.
            //
            // Apagado por defecto: mientras esté en false el CRM ni siquiera
            // manda el parámetro, y el comportamiento es idéntico al de hoy.
            $table->boolean('emit_electronic_invoice')->default(false)->after('trigger_command');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn('emit_electronic_invoice');
        });
    }
};
