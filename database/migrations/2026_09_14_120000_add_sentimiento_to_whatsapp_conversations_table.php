<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El semáforo de emociones, en columnas y no en `metadata`.
 *
 * `whatsapp_conversations.metadata` es el cajón donde han ido a parar los datos
 * sueltos de una conversación (`integra`, `username`, `extension_follow_up_at`),
 * y habría sido lo barato. No vale aquí por dos motivos: la bandeja tiene que
 * **filtrar y ordenar** por color, y un `WHERE` sobre una ruta JSON no usa
 * índice; y el panel de métricas agrega por empresa, que sobre JSON es un
 * escaneo completo cada vez que alguien abre la pantalla.
 *
 * Hay un tercer motivo, menos evidente y que ya nos mordió: `ConversationEvent`
 * serializa `toArray()` del modelo, así que **los campos derivados no viajan por
 * Reverb**. `awaiting_reply` tiene ese fallo hoy —se calcula en
 * `ChatController::conversations()` y se pierde en cada evento en vivo—. Una
 * columna llega sola a los tres canales: la lista, el poll y el evento.
 *
 * Todo nullable y sin valor por defecto a propósito: `null` significa "todavía
 * nadie ha mirado esta conversación", que es distinto de "la miré y está bien".
 * El frontend pinta gris, no verde, y esa diferencia importa — un verde que en
 * realidad es "no analizado" es exactamente la clase de mentira tranquilizadora
 * que hace que la gente deje de mirar el semáforo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // 'verde' | 'amarillo' | 'rojo'. Texto y no enum: cambiar un enum en
            // MySQL reescribe la tabla entera, y esta crece rápido.
            $table->string('sentiment_level', 10)->nullable()->after('metadata');

            // -1.000 a 1.000. Decimal y no float: el suavizado exponencial suma
            // y compara estos valores, y un float acumula error hasta hacer que
            // dos conversaciones idénticas acaben en colores distintos.
            $table->decimal('sentiment_score', 4, 3)->nullable()->after('sentiment_level');

            // Por qué está en ese color, en cristiano, para la lista y el chat.
            $table->string('sentiment_reason', 200)->nullable()->after('sentiment_score');

            // 'matriz' | 'ia' | 'manual'. Sin esto no se puede responder a la
            // pregunta que se hace siempre al calibrar: ¿los rojos los pone el
            // léxico o el modelo?
            $table->string('sentiment_source', 10)->nullable()->after('sentiment_reason');

            $table->timestamp('sentiment_at')->nullable()->after('sentiment_source');

            // Quién lo corrigió a mano. Es el freno: mientras tenga valor, ni la
            // matriz ni la IA vuelven a tocar esta conversación.
            //
            // Un id y no un booleano porque la corrección del agente es la única
            // etiqueta humana que vamos a tener para calibrar, y saber quién la
            // puso es lo que permite descartar a quien las repartía al azar.
            $table->unsignedBigInteger('sentiment_locked_by')->nullable()->after('sentiment_at');

            $table->foreign('sentiment_locked_by')->references('id')->on('users')->nullOnDelete();

            // El índice que sostiene el filtro de la bandeja. Lleva
            // `instance_id` delante porque la lista SIEMPRE está acotada a una
            // instancia (ChatController::conversations) y el color sin esa
            // acotación no se consulta nunca.
            $table->index(['instance_id', 'sentiment_level'], 'wa_conv_instancia_sentimiento_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropForeign(['sentiment_locked_by']);
            $table->dropIndex('wa_conv_instancia_sentimiento_idx');
            $table->dropColumn([
                'sentiment_level',
                'sentiment_score',
                'sentiment_reason',
                'sentiment_source',
                'sentiment_at',
                'sentiment_locked_by',
            ]);
        });
    }
};
