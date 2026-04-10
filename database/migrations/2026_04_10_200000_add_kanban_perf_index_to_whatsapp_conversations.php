<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // Composite index for the kanban column-cards query:
            //   WHERE kanban_column_id = ? ORDER BY last_message_at DESC
            //   WHERE kanban_column_id IS NULL ORDER BY last_message_at DESC
            // Without this, MySQL must choose between the kanban_column_id index
            // (good for WHERE) and the last_message_at index (good for ORDER BY),
            // often resulting in a filesort on large tables.
            $table->index(
                ['kanban_column_id', 'last_message_at'],
                'kanban_col_last_msg_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex('kanban_col_last_msg_idx');
        });
    }
};
