<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->string('schedule_type', 20)->default('manual')->after('status');
            $table->json('schedule_days')->nullable()->after('schedule_type');
            $table->time('schedule_time')->nullable()->after('schedule_days');
            $table->string('schedule_timezone', 64)->nullable()->after('schedule_time');
            $table->timestamp('last_run_at')->nullable()->after('completed_at');
            $table->timestamp('next_run_at')->nullable()->after('last_run_at');
            $table->index('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->dropIndex(['next_run_at']);
            $table->dropColumn([
                'schedule_type',
                'schedule_days',
                'schedule_time',
                'schedule_timezone',
                'last_run_at',
                'next_run_at',
            ]);
        });
    }
};
