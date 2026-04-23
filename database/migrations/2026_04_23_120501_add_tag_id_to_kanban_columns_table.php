<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->foreignId('tag_id')->nullable()->constrained('tags')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->dropForeign(['tag_id']);
            $table->dropColumn('tag_id');
        });
    }
};
