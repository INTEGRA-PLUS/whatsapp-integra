<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién cerró la conversación y cuándo.
 *
 * Hasta ahora `status` pasaba a "closed" sin dejar rastro de quién lo hizo, así
 * que si un chat aparecía cerrado antes de tiempo no había forma de saber quién
 * lo cerró ni cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // nullOnDelete: si se borra el usuario, la conversación no se pierde;
            // solo deja de saberse quién la cerró.
            $table->foreignId('closed_by')->nullable()->after('assigned_to')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
    }
};
