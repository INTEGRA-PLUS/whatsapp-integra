<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotente a propósito: estos índices se aplicaron por SQL directo
        // en producción el 2026-07-08 (diagnóstico de lentitud) antes de que
        // esta migración llegara por deploy.
        if (! $this->indexExists('whatsapp_messages', 'msg_nit_created_idx')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                // GET /api/v1/whatsapp-messages:
                //   WHERE incoming_company_nit = ? AND created_at BETWEEN ? AND ?
                //   ORDER BY created_at DESC
                // Sin esto cada golpe del integrador hace full scan + filesort
                // sobre la tabla más grande.
                $table->index(
                    ['incoming_company_nit', 'created_at'],
                    'msg_nit_created_idx'
                );
            });
        }

        if (! $this->indexExists('whatsapp_conversations', 'conv_instance_updated_idx')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                // GET /api/chat/updates (polling del chat web cada 10s):
                //   WHERE instance_id = ? AND updated_at > ?
                // El índice existente cubre last_message_at, no updated_at.
                $table->index(
                    ['instance_id', 'updated_at'],
                    'conv_instance_updated_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('msg_nit_created_idx');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex('conv_instance_updated_idx');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($index);
    }
};
