<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_responses', function (Blueprint $table) {
            $table->json('match_types')->nullable()->after('match_type');
            $table->unsignedInteger('reopen_hours')->nullable()->after('cooldown_minutes');
        });

        // Permitir el nuevo tipo "reopen" en la columna legacy match_type.
        // El ENUM sólo existe en MySQL; en sqlite la columna ya acepta el valor.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with', 'always', 'welcome', 'reopen') DEFAULT 'contains'");
        }

        // Backfill: cada regla existente conserva su tipo único dentro del nuevo arreglo.
        DB::statement("UPDATE auto_responses SET match_types = JSON_ARRAY(match_type) WHERE match_types IS NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with', 'always', 'welcome') DEFAULT 'contains'");
        }

        Schema::table('auto_responses', function (Blueprint $table) {
            $table->dropColumn(['match_types', 'reopen_hours']);
        });
    }
};
