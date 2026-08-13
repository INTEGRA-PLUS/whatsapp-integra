<?php

use App\Models\WhatsAppConversation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad de ámbito de negocio (BSUID) del cliente, en columna propia.
 *
 * Meta manda el BSUID en todos los webhooks de mensajes, pero deja de mandar el
 * teléfono cuando el cliente lo oculta y no hay contacto reciente. Guardarlo
 * sólo cuando el teléfono ya faltaba llegaba tarde: el mismo cliente entraba
 * como identidad nueva y se le abría un hilo aparte, partiendo el historial.
 *
 * Va en columna indexada y no en `metadata` porque se consulta en cada mensaje
 * entrante: buscar dentro de un JSON obliga a recorrer la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // 131 es el máximo real (2 de país + punto + 128), y los BSUID de
            // cartera traen además "ENT." intercalado; 160 deja margen sin
            // acercarse al límite de longitud de clave de MySQL.
            $table->string('bsuid', 160)->nullable()->after('wa_id');
            $table->index(['instance_id', 'bsuid']);
        });

        // Los hilos que ya se crearon con el BSUID como identidad lo tienen en
        // `wa_id`, y los que pasaron por rememberIdentity lo tienen en el JSON.
        // Sin este relleno, el primer mensaje de cada uno volvería a no casar.
        //
        // Se recorre en PHP en vez de con un UPDATE ... REGEXP porque ni el
        // REGEXP ni JSON_EXTRACT existen en sqlite, y los tests migran ahí.
        DB::table('whatsapp_conversations')
            ->select('id', 'wa_id', 'metadata')
            ->orderBy('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $fila) {
                    $bsuid = null;

                    if (WhatsAppConversation::isBsuid($fila->wa_id)) {
                        $bsuid = $fila->wa_id;
                    } elseif ($fila->metadata) {
                        $metadata = json_decode($fila->metadata, true);
                        $bsuid = $metadata['bsuid'] ?? null;
                    }

                    if ($bsuid) {
                        DB::table('whatsapp_conversations')
                            ->where('id', $fila->id)
                            ->update(['bsuid' => $bsuid]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex(['instance_id', 'bsuid']);
            $table->dropColumn('bsuid');
        });
    }
};
