<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with', 'always', 'welcome') DEFAULT 'contains'");
        }

        Schema::table('auto_responses', function (Blueprint $table) {
            $table->unsignedInteger('fires_count')->default(0)->after('cooldown_minutes');
            $table->timestamp('last_fired_at')->nullable()->after('fires_count');
        });
    }

    public function down(): void
    {
        Schema::table('auto_responses', function (Blueprint $table) {
            $table->dropColumn(['fires_count', 'last_fired_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with', 'always') DEFAULT 'contains'");
        }
    }
};
