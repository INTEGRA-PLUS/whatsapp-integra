<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Internal notes have no WhatsApp message id (wamid). Allow it to be null
     * so notes can be stored; real inbound/outbound messages still set it.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `wamid` VARCHAR(500) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `wamid` VARCHAR(500) NOT NULL");
    }
};
