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
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_messages', 'is_internal')) {
                $table->boolean('is_internal')->default(false)->index()->after('direction');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'mentions')) {
                $table->json('mentions')->nullable()->after('is_internal');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['is_internal', 'mentions']);
        });
    }
};
