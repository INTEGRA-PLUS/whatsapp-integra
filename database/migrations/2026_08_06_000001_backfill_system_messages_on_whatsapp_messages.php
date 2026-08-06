<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Los avisos `system` de WhatsApp (cambio de número / de identidad) se venían
     * guardando como texto con el literal "Tipo de mensaje no soportado: system".
     * Se reclasifican a type='system' para que el chat los pinte como aviso y no
     * como una burbuja del cliente.
     */
    public function up(): void
    {
        // Best-effort: no debe impedir el arranque del contenedor (el entrypoint
        // corre migrate).
        try {
            DB::statement("
                UPDATE whatsapp_messages
                SET type = 'system',
                    content = 'Aviso del sistema de WhatsApp (cambio de número o de identidad)'
                WHERE content = 'Tipo de mensaje no soportado: system'
            ");

            // El resto de tipos sin soporte conserva cuál era, con el texto nuevo.
            DB::statement("
                UPDATE whatsapp_messages
                SET type = 'system',
                    content = CONCAT('Mensaje no compatible (', SUBSTRING(content, 31), ')')
                WHERE content LIKE 'Tipo de mensaje no soportado: %'
            ");

            DB::statement("
                UPDATE whatsapp_conversations
                SET last_message = CONCAT('ℹ️ ', 'Aviso del sistema de WhatsApp')
                WHERE last_message LIKE 'Tipo de mensaje no soportado: %'
            ");
        } catch (\Throwable $e) {
            Log::warning('No se pudieron reclasificar los mensajes de sistema: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // El texto original ya no se puede reconstruir con fidelidad; se deja el
        // tipo anterior para que el chat vuelva a renderizarlos como texto.
        DB::statement("UPDATE whatsapp_messages SET type = 'text' WHERE type = 'system'");
    }
};
