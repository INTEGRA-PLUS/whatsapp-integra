<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            // Scopes que el entorno Integra concedió al emitir este token.
            //
            // Integra no expone forma de preguntarle a un token qué puede
            // hacer, y cuando le falta un scope no responde 403: responde
            // "no_aplica" y sigue. Sin guardar esto, la casilla de emisión
            // electrónica se podría encender sobre un token que no la
            // autoriza, y el admin se enteraría en el peor momento posible:
            // sobre un pago real, por un agente que no puede arreglarlo.
            //
            // Queda null en los tokens pegados a mano (no los emitimos
            // nosotros) y en los de empresas conectadas antes de esta
            // migración: null significa "no sabemos", no "no tiene".
            $table->json('abilities')->nullable()->after('account');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });
    }
};
