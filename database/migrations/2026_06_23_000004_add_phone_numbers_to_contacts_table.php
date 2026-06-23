<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additional phone numbers for a contact (beyond the primary `phone_number`).
     * Lets one contact gather several WhatsApp numbers under a single record.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('phone_numbers')->nullable()->after('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('phone_numbers');
        });
    }
};
