<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El resumen con IA de una conversación, guardado junto a ella.
 *
 * En columnas y no en `metadata` por lo mismo que el semáforo
 * (`2026_09_14_120000`): `ConversationEvent` serializa `toArray()` del modelo,
 * así que lo que no es columna no viaja por Reverb y se pierde en cada evento
 * en vivo.
 *
 * La columna que hace el trabajo de verdad es `summary_until_message_id`. Sin
 * ella sólo se podría responder "¿hay resumen?"; con ella se responde la
 * pregunta útil: **¿el resumen que hay sigue valiendo?**. Se compara con el
 * último mensaje del hilo y, si coincide, el botón no gasta una inferencia para
 * reescribir lo mismo. Un resumen bajo demanda que se regenera cada vez que
 * alguien lo abre es un resumen que nadie vuelve a abrir.
 *
 * Todo nullable: `null` es "nadie lo ha pedido todavía", que no es un estado de
 * error ni hay que mostrarlo como tal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // El resumen en prosa. `text` y no `string`: son varios párrafos y
            // el modelo no siempre respeta el límite que se le pide.
            $table->text('summary')->nullable()->after('sentiment_locked_by');

            // Los puntos clave y lo que queda pendiente, como listas. En JSON
            // porque son listas de longitud variable que sólo se leen enteras
            // —nunca se filtra ni se ordena por ellas—, que es justo el caso en
            // el que una columna JSON sí es la respuesta correcta.
            $table->json('summary_highlights')->nullable()->after('summary');

            $table->timestamp('summary_at')->nullable()->after('summary_highlights');

            // Hasta qué mensaje cubre. Es lo que permite decir "este resumen ya
            // está al día" en vez de volver a pedirlo.
            $table->unsignedBigInteger('summary_until_message_id')->nullable()->after('summary_at');

            // Quién lo pidió. Sirve para responder a quién le está resultando
            // útil la extensión antes de decidir si se amplía.
            $table->unsignedBigInteger('summary_by')->nullable()->after('summary_until_message_id');

            $table->foreign('summary_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropForeign(['summary_by']);
            $table->dropColumn([
                'summary',
                'summary_highlights',
                'summary_at',
                'summary_until_message_id',
                'summary_by',
            ]);
        });
    }
};
