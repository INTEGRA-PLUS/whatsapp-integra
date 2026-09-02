<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo pidió el cliente, por escrito, que dejaran de mandarle campañas.
 *
 * Es una petición pendiente de confirmar, no la baja: la baja vive en el
 * contacto (`contacts.opted_out_at`) y la aplica una persona. Aquí solo queda
 * anotado que alguien lo pidió, para que aparezca en la lista de Contactos y no
 * se pierda entre los mensajes del día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->timestamp('opt_out_requested_at')->nullable()->after('last_message_at');
            $table->index('opt_out_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex(['opt_out_requested_at']);
            $table->dropColumn('opt_out_requested_at');
        });
    }
};
