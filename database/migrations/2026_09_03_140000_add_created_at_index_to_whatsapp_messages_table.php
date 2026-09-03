<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('whatsapp_messages', 'msg_created_direction_idx')) {
            return;
        }

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Panel master (/master):
            //   COUNT(*)                       WHERE created_at BETWEEN ? AND ?
            //   SUM(direction = 'inbound'|...) WHERE created_at BETWEEN ? AND ?
            //
            // Ningún índice de la tabla empezaba por created_at —los que hay
            // van encabezados por conversation_id o por incoming_company_nit—
            // así que ambas consultas barrían entera la tabla más grande del
            // sistema, dos veces por carga del dashboard.
            //
            // La segunda columna es lo que convierte el índice en cubridor para
            // la de volumen: el rango se resuelve por índice y `direction` sale
            // de ahí mismo, sin ir a buscar la fila.
            $table->index(['created_at', 'direction'], 'msg_created_direction_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('msg_created_direction_idx');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($index);
    }
};
