<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El índice del ranking de empresas del panel maestro.
 *
 * `msg_created_direction_idx` (created_at, direction) se añadió para la tarjeta
 * y el gráfico de volumen, que sólo necesitan contar. El ranking de empresas
 * necesita además saber de qué conversación es cada mensaje —para subir por
 * conversación → instancia → empresa—, y `conversation_id` no estaba en el
 * índice: MySQL acotaba el rango por índice y luego iba a buscar la fila
 * completa de cada uno de los 314.000 mensajes del mes.
 *
 * Medido en producción el 15-sep-2026, con 1,1 M de mensajes:
 *
 * - sin acotar por fecha y sin filtrar dirección: 3,2 s
 * - sin acotar por fecha y filtrando dirección:  69,1 s  ← lo que se desplegó
 * - acotado al rango del panel, sin este índice:  9,1 s
 *
 * Los 69 segundos son la lección: añadir `direction` a esa subconsulta parecía
 * gratis —la columna ya estaba en un índice— pero dejó el plan sin cobertura y
 * multiplicó por veinte el tiempo. El panel dejó de cargar.
 *
 * Se sustituye el índice de dos columnas por el de tres en vez de añadirlo al
 * lado: un índice cuyo prefijo es exactamente otro índice hace el mismo trabajo
 * y el de dos columnas sólo ocuparía sitio y trabajo en cada escritura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if ($this->indexExists('msg_created_direction_conv_idx')) {
                return;
            }

            $table->index(['created_at', 'direction', 'conversation_id'], 'msg_created_direction_conv_idx');
        });

        if ($this->indexExists('msg_created_direction_idx')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->dropIndex('msg_created_direction_idx');
            });
        }
    }

    public function down(): void
    {
        if (! $this->indexExists('msg_created_direction_idx')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->index(['created_at', 'direction'], 'msg_created_direction_idx');
            });
        }

        if ($this->indexExists('msg_created_direction_conv_idx')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->dropIndex('msg_created_direction_conv_idx');
            });
        }
    }

    /**
     * Los tests corren en sqlite, donde `show index` no existe.
     */
    private function indexExists(string $nombre): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::select(
            'show index from whatsapp_messages where key_name = ?',
            [$nombre]
        ) !== [];
    }
};
