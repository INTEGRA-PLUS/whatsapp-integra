<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de la importación de contactos e historial de una instancia conectada
 * por coexistencia.
 *
 * Existe por una razón concreta: la importación es de **un solo uso** y con una
 * ventana de 24 horas. Sin una fila que diga "esto ya se lanzó" el job se
 * repetiría en el primer reintento de la cola y gastaría el único intento del
 * cliente, que sólo se recupera desconectando el número y rehaciendo el
 * registro insertado entero. El índice único sobre `instance_id` es el candado.
 *
 * De paso es lo que alimenta la barra de progreso: Meta manda el porcentaje y
 * la fase en cada webhook de `history`, y aquí es donde quedan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coexistence_syncs', function (Blueprint $table) {
            $table->id();

            // Único: una instancia sólo puede sincronizarse una vez.
            $table->unsignedBigInteger('instance_id')->unique();

            // pendiente  → aún no se ha pedido nada a Meta
            // solicitada → se pidió y estamos esperando los webhooks
            // importando → ya llegó el primer lote
            // completada → progreso 100 en la última fase
            // rechazada  → el cliente eligió no compartir el historial
            // fallida    → Meta devolvió error al pedirla
            $table->string('status', 20)->default('pendiente');

            // Los identificadores que devuelve Meta al aceptar cada petición.
            // Son lo único que sirve para reclamar si el contenido no llega.
            $table->string('contacts_request_id')->nullable();
            $table->string('history_request_id')->nullable();

            // Fase 0 (día del registro), 1 (hasta 3 meses), 2 (hasta 6 meses).
            $table->unsignedTinyInteger('phase')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);

            $table->unsignedInteger('contacts_imported')->default(0);
            $table->unsignedInteger('messages_imported')->default(0);
            $table->unsignedInteger('conversations_touched')->default(0);

            // Para vigilar la ventana de 24 horas sin adivinar.
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('first_chunk_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->foreign('instance_id')->references('id')->on('instances')->onDelete('cascade');
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coexistence_syncs');
    }
};
