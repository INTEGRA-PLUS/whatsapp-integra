<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El histórico del semáforo: cada vez que una conversación CAMBIA de color.
 *
 * Sólo los cambios, no cada mensaje. Un hilo de cuarenta mensajes que se
 * mantiene en verde deja una fila, no cuarenta: lo que se quiere contar es
 * cuántas veces se torció una conversación, y el ruido de repetir "sigue igual"
 * haría la tabla ingobernable sin añadir un solo dato.
 *
 * ## Por qué lleva `company_id` teniendo `conversation_id`
 *
 * Es una desviación consciente de la regla del proyecto. Los diez modelos que se
 * aíslan saltando por la instancia lo hacen porque siempre se consultan **desde
 * una conversación**, y ahí el salto es natural. Éste no: se consulta **agregado
 * por empresa** cada vez que alguien abre el panel de informes, y sin la columna
 * cada gráfica serían dos saltos —a conversaciones y a instancias— sobre un
 * histórico que crece con cada mensaje del mes.
 *
 * El precio es que hay que acordarse de filtrar por ella a mano, igual que en
 * todas las demás.
 *
 * `assigned_to` se copia en el momento del cambio en vez de leerse de la
 * conversación al consultar, y tampoco es por rendimiento: la conversación se
 * reasigna, y entonces el histórico atribuiría a quien la tiene hoy los enfados
 * que ocurrieron con otro agente delante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_sentiment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('conversation_id');
            $table->string('nivel', 10);
            $table->decimal('score', 4, 3)->nullable();
            $table->string('origen', 10);
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            // El índice del panel: "lo de esta empresa entre estas dos fechas".
            $table->index(['company_id', 'created_at']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_sentiment_events');
    }
};
