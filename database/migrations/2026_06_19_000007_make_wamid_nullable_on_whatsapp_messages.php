<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal notes have no WhatsApp message id (wamid). Allow it to be null
     * so notes can be stored; real inbound/outbound messages still set it.
     *
     * En MySQL se conserva el ALTER crudo para no alterar el tipo exacto en
     * producción; en el resto de drivers (sqlite en las pruebas) se usa el
     * Schema Builder, que sí sabe recrear la tabla.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `wamid` VARCHAR(500) NULL");

            return;
        }

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('wamid', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN `wamid` VARCHAR(500) NOT NULL");

            return;
        }

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('wamid', 500)->nullable(false)->change();
        });
    }
};
