<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La API pública (/api/v1) se consulta por polling externo decenas de
        // veces por minuto. Sin estos índices compuestos cada golpe hace
        // filesort sobre toda la instancia/conversación y quema CPU.
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // GET /api/v1/conversations:
            //   WHERE instance_id = ? ORDER BY last_message_at DESC
            $table->index(
                ['instance_id', 'last_message_at'],
                'conv_instance_last_msg_idx'
            );
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // GET /api/v1/conversations/{id}/messages:
            //   WHERE conversation_id = ? ORDER BY sent_at DESC
            // (el índice existente cubre created_at, no sent_at)
            $table->index(
                ['conversation_id', 'sent_at'],
                'msg_conversation_sent_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex('conv_instance_last_msg_idx');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('msg_conversation_sent_idx');
        });
    }
};
