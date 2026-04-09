<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('kanban_column_id')->nullable()->after('status');
            $table->foreign('kanban_column_id')->references('id')->on('kanban_columns')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropForeign(['kanban_column_id']);
            $table->dropColumn('kanban_column_id');
        });
    }
};
