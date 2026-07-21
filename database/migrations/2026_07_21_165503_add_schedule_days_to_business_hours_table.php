<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_hours', function (Blueprint $table) {
            $table->json('schedule_days')->nullable()->after('timezone');
        });

        // Migra cada regla existente (un start_time/end_time compartido + días sueltos)
        // al nuevo formato por día con soporte de varios rangos (ej. horario partido).
        DB::table('business_hours')->orderBy('id')->each(function ($row) {
            $selectedDays = json_decode($row->days_of_week ?? '[]', true) ?: [];
            $applyAllDays = empty($selectedDays);
            $range = ['start' => substr($row->start_time, 0, 5), 'end' => substr($row->end_time, 0, 5)];

            $scheduleDays = [];
            for ($day = 0; $day <= 6; $day++) {
                $enabled = $applyAllDays || in_array($day, $selectedDays, false);
                $scheduleDays[(string) $day] = [
                    'enabled' => $enabled,
                    'all_day' => $enabled && $range['start'] === $range['end'],
                    'ranges' => $enabled ? [$range] : [],
                ];
            }

            DB::table('business_hours')->where('id', $row->id)->update([
                'schedule_days' => json_encode($scheduleDays),
            ]);
        });

        Schema::table('business_hours', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time', 'days_of_week']);
        });
    }

    public function down(): void
    {
        Schema::table('business_hours', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('timezone');
            $table->time('end_time')->nullable()->after('start_time');
            $table->json('days_of_week')->nullable()->after('end_time');
        });

        DB::table('business_hours')->orderBy('id')->each(function ($row) {
            $scheduleDays = json_decode($row->schedule_days ?? '{}', true) ?: [];
            $enabledDays = [];
            $firstRange = null;

            foreach ($scheduleDays as $day => $config) {
                if (!($config['enabled'] ?? false)) {
                    continue;
                }
                $enabledDays[] = (int) $day;
                if (!$firstRange && !empty($config['ranges'])) {
                    $firstRange = $config['ranges'][0];
                }
            }

            DB::table('business_hours')->where('id', $row->id)->update([
                'start_time' => ($firstRange['start'] ?? '08:00') . ':00',
                'end_time' => ($firstRange['end'] ?? '18:00') . ':00',
                'days_of_week' => json_encode($enabledDays),
            ]);
        });

        Schema::table('business_hours', function (Blueprint $table) {
            $table->dropColumn('schedule_days');
        });
    }
};
