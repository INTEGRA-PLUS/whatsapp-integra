<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si Meta deja enviar por esta cuenta, y por qué no cuando no deja.
 *
 * Es distinto de `health_status`, y por eso va aparte. Aquél responde «¿Meta
 * reconoce este número y este token?»; éste responde «¿y le deja enviar?». Se
 * puede estar perfectamente conectado y bloqueado: la causa más común es que al
 * portafolio del cliente se le venció la tarjeta.
 *
 * Los arreglos también son distintos —uno se arregla reconectando la línea y el
 * otro entrando a la facturación de Meta— así que mezclarlos en una sola
 * columna mandaría a la mitad de la gente al sitio equivocado.
 *
 * El medio de pago en sí **no se puede leer**: `primary_funding_id` responde
 * «You do not have permission». Somos Tech Provider, no BSP, y la tarjeta vive
 * en el portafolio del cliente. Esto es la consecuencia, que es lo que importa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            // El valor de Meta tal cual: AVAILABLE, LIMITED, BLOCKED…
            $table->string('puede_enviar', 32)->nullable()->after('health_error');
            $table->text('puede_enviar_motivo')->nullable()->after('puede_enviar');
            $table->timestamp('puede_enviar_visto_at')->nullable()->after('puede_enviar_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['puede_enviar', 'puede_enviar_motivo', 'puede_enviar_visto_at']);
        });
    }
};
