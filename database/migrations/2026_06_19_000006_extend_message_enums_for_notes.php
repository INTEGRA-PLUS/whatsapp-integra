<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the 'note' message type and 'internal' direction so internal notes
     * are stored cleanly without colliding with real (outbound/inbound) messages.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `type` ENUM('text','image','document','audio','video','sticker','location','contacts','template','note') NOT NULL");
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `direction` ENUM('inbound','outbound','internal') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE whatsapp_messages SET `type` = 'text' WHERE `type` = 'note'");
        DB::statement("UPDATE whatsapp_messages SET `direction` = 'outbound' WHERE `direction` = 'internal'");
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `type` ENUM('text','image','document','audio','video','sticker','location','contacts','template') NOT NULL DEFAULT 'text'");
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `direction` ENUM('inbound','outbound') NOT NULL");
    }
};
