<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        // Update enum definition to include 'master'.
        // MODIFY COLUMN y ENUM son de MySQL; en sqlite (el driver de las pruebas)
        // la sentencia revienta y tumba todas las migraciones. sqlite guarda role
        // como texto libre, así que allí no hay nada que ajustar.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('master', 'admin', 'agent', 'user') DEFAULT 'user'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert enum
        DB::statement("UPDATE users SET role = 'admin' WHERE role = 'master'");

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'agent', 'user') DEFAULT 'user'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
