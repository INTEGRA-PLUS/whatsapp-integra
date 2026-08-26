<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MODIFY COLUMN / ENUM son sintaxis de MySQL. En sqlite (driver de las
        // pruebas) revientan y tumban toda la cadena de migraciones; allí la
        // columna es texto libre y no hay nada que ajustar.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // For MySQL, we can use DB::statement to update the enum
        DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with', 'always') DEFAULT 'contains'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE auto_responses MODIFY COLUMN match_type ENUM('exact', 'contains', 'starts_with') DEFAULT 'contains'");
    }
};
