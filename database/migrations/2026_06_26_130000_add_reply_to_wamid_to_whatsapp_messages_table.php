<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_messages', 'reply_to_wamid')) {
                // wamid del mensaje citado (responder). Se resuelve contra los
                // mensajes cargados para mostrar la cita estilo WhatsApp.
                $table->string('reply_to_wamid', 500)->nullable()->after('wamid')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_messages', 'reply_to_wamid')) {
                $table->dropColumn('reply_to_wamid');
            }
        });
    }
};
