<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_responses', function (Blueprint $table) {
            $table->unsignedInteger('cooldown_minutes')->default(60)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('auto_responses', function (Blueprint $table) {
            $table->dropColumn('cooldown_minutes');
        });
    }
};
