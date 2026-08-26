<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * El ENUM original de `type` en producción no incluía 'location'/'contacts',
     * por lo que MySQL guardaba '' y el chat mostraba burbujas vacías. Se cambia
     * a VARCHAR para aceptar cualquier tipo presente y futuro sin alterar ENUMs.
     */
    public function up(): void
    {
        // En sqlite la columna ya es texto libre: sólo MySQL necesita el ALTER.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE whatsapp_messages MODIFY type VARCHAR(32) NOT NULL DEFAULT 'text'");
        }

        // Repara mensajes de ubicación/contacto ya guardados con type vacío:
        // el metadata sí se almacenó, así que se puede restaurar el tipo real.
        // Best-effort: si el metadata de filas viejas no es JSON válido, no debe
        // impedir el arranque del contenedor (el entrypoint corre migrate).
        try {
            DB::statement("UPDATE whatsapp_messages SET type = 'location' WHERE type = '' AND metadata IS NOT NULL AND JSON_VALID(metadata) AND JSON_EXTRACT(metadata, '$.location') IS NOT NULL");
            DB::statement("UPDATE whatsapp_messages SET type = 'contacts' WHERE type = '' AND metadata IS NOT NULL AND JSON_VALID(metadata) AND JSON_EXTRACT(metadata, '$.contacts') IS NOT NULL");
            DB::statement("UPDATE whatsapp_messages SET type = 'text' WHERE type = ''");
        } catch (\Throwable $e) {
            Log::warning('No se pudieron reparar mensajes con type vacío: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE whatsapp_messages MODIFY type ENUM('text','image','document','audio','video','sticker','location','contacts','template') NOT NULL");
    }
};
