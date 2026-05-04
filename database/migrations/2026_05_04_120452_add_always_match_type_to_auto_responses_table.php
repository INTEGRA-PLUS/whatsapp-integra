<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For MySQL, we can use DB::statement to update the enum
        DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with', 'always') DEFAULT 'contains'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with') DEFAULT 'contains'");
    }
};
