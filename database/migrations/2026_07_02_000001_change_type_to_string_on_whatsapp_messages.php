<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El ENUM original de `type` en producción no incluía 'location'/'contacts',
     * por lo que MySQL guardaba '' y el chat mostraba burbujas vacías. Se cambia
     * a VARCHAR para aceptar cualquier tipo presente y futuro sin alterar ENUMs.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE whatsapp_messages MODIFY type VARCHAR(32) NOT NULL DEFAULT 'text'");

        // Repara mensajes de ubicación/contacto ya guardados con type vacío:
        // el metadata sí se almacenó, así que se puede restaurar el tipo real.
        DB::statement("UPDATE whatsapp_messages SET type = 'location' WHERE type = '' AND JSON_EXTRACT(metadata, '$.location') IS NOT NULL");
        DB::statement("UPDATE whatsapp_messages SET type = 'contacts' WHERE type = '' AND JSON_EXTRACT(metadata, '$.contacts') IS NOT NULL");
        DB::statement("UPDATE whatsapp_messages SET type = 'text' WHERE type = ''");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE whatsapp_messages MODIFY type ENUM('text','image','document','audio','video','sticker','location','contacts','template') NOT NULL");
    }
};
