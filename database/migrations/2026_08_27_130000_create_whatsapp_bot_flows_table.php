<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El dato que el bot está esperando del cliente.
     *
     * Hasta ahora el menú era de un solo turno: el cliente tocaba y recibía la
     * respuesta. Las acciones de negocio no caben en un turno —reportar una
     * falla exige que el cliente describa la falla, y consultar la factura
     * exige saber quién es cuando su celular no está en Integra—, así que hace
     * falta recordar entre mensajes en qué punto de la conversación va.
     *
     * Es una tabla aparte de whatsapp_menu_sessions y no una columna suya
     * porque responden a preguntas distintas: la sesión sabe qué menú tiene el
     * cliente delante (para entender un "1" suelto) y esto sabe qué pregunta le
     * hicimos. Un flujo abierto sobrevive al menú que lo originó.
     */
    public function up(): void
    {
        Schema::create('whatsapp_bot_flows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('menu_option_id')->nullable();

            // Qué acción se está resolviendo (consultar_factura, reportar_falla…)
            // y en qué paso va (awaiting_identification, awaiting_report…).
            $table->string('action_type', 32);
            $table->string('step', 40);

            // Lo aprendido hasta ahora: cliente de Integra, contrato elegido,
            // intentos de identificación fallidos…
            $table->json('context')->nullable();

            // Un flujo abandonado no puede secuestrar la conversación para
            // siempre: pasado el plazo el mensaje del cliente vuelve a ser un
            // mensaje normal y los menús vuelven a dispararse.
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->onDelete('cascade');
            // Si borran la opción, el flujo en curso conserva su action_type y
            // puede terminar: lo que falta es sólo la configuración del admin.
            $table->foreign('menu_option_id')->references('id')->on('whatsapp_menu_options')->nullOnDelete();

            // Una conversación sólo espera una cosa a la vez.
            $table->unique('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_flows');
    }
};
