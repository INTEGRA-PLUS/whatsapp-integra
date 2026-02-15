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

        // Update enum definition to include 'master'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('master', 'admin', 'agent', 'user') DEFAULT 'user'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert enum
        DB::statement("UPDATE users SET role = 'admin' WHERE role = 'master'");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'agent', 'user') DEFAULT 'user'");

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
