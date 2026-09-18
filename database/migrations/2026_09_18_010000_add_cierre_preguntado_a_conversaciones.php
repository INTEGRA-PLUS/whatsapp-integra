<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se le preguntó al cliente si necesitaba algo más.
 *
 * Lo usa el cierre automático para saber a quién le toca cerrarse. El primer
 * intento fue reconocer la pregunta por el `metadata` del mensaje enviado, y no
 * llegaba: `ProcessWhatsAppMenu::aiTrace()` reconstruye ese metadata con lista
 * blanca y cualquier clave que no esté nombrada ahí se descarta — el mismo
 * patrón que tumbó el `asistente` del gateway el 16-sep.
 *
 * Una columna además quita un N+1: la pasada preguntaba por los mensajes de cada
 * conversación una por una para saber si ya se le había preguntado.
 *
 * `null` significa «no hay ninguna pregunta en el aire», y es lo que se pone en
 * cuanto el cliente vuelve a escribir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->timestamp('cierre_preguntado_at')->nullable()->after('last_message_at');
            $table->index(['status', 'cierre_preguntado_at'], 'conv_cierre_pendiente_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex('conv_cierre_pendiente_idx');
            $table->dropColumn('cierre_preguntado_at');
        });
    }
};
