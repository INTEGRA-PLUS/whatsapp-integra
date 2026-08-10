<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El panel Master necesita saber *cuándo* falló un mensaje y si ya se
     * reintentó. Hasta ahora el único rastro era `updated_at`, que cualquier
     * cambio posterior sobrescribe.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('read_at');
            $table->unsignedBigInteger('retry_of_message_id')->nullable()->after('error_details');
            $table->unsignedInteger('retry_count')->default(0)->after('retry_of_message_id');
            $table->timestamp('last_retried_at')->nullable()->after('retry_count');
            $table->unsignedBigInteger('last_retried_by')->nullable()->after('last_retried_at');

            $table->index(['status', 'failed_at'], 'wam_status_failed_at_idx');
            $table->index('retry_of_message_id', 'wam_retry_of_idx');
        });

        // Los fallos ya registrados se fechan con su último cambio de estado:
        // es la mejor aproximación disponible y evita una columna medio vacía.
        DB::table('whatsapp_messages')
            ->where('status', 'failed')
            ->whereNull('failed_at')
            ->update(['failed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('wam_status_failed_at_idx');
            $table->dropIndex('wam_retry_of_idx');
            $table->dropColumn([
                'failed_at',
                'retry_of_message_id',
                'retry_count',
                'last_retried_at',
                'last_retried_by',
            ]);
        });
    }
};
