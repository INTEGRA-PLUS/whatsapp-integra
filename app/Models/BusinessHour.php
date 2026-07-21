<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'instance_id',
        'name',
        'active',
        'timezone',
        'schedule_days',
        'out_of_hours_message',
        'cooldown_minutes',
        'fires_count',
        'last_fired_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'schedule_days' => 'array',
        'cooldown_minutes' => 'integer',
        'fires_count' => 'integer',
        'last_fired_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function isWithinHours(?Carbon $when = null): bool
    {
        $tz = $this->timezone ?: 'America/Bogota';
        $now = ($when ? $when->copy() : Carbon::now())->setTimezone($tz);

        $day = $this->dayConfig((int) $now->dayOfWeek);
        if (!($day['enabled'] ?? false)) {
            return false;
        }

        if ($day['all_day'] ?? false) {
            return true;
        }

        $current = $this->normalizeTime($now->format('H:i:s'));

        foreach (($day['ranges'] ?? []) as $range) {
            $start = $this->normalizeTime($range['start'] ?? null);
            $end = $this->normalizeTime($range['end'] ?? null);

            if ($start === $end) {
                return true;
            }

            if ($start < $end) {
                if ($current >= $start && $current <= $end) {
                    return true;
                }
            } elseif ($current >= $start || $current <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configuración del día (0=domingo..6=sábado): { enabled, all_day, ranges: [{start,end}] }.
     */
    public function dayConfig(int $dayOfWeek): array
    {
        $days = $this->schedule_days ?? [];
        return $days[(string) $dayOfWeek] ?? ['enabled' => false, 'all_day' => false, 'ranges' => []];
    }

    public function renderMessage(WhatsAppConversation $conversation): string
    {
        $tz = $this->timezone ?: 'America/Bogota';
        $today = $this->dayConfig((int) Carbon::now($tz)->dayOfWeek);
        $firstRange = $today['ranges'][0] ?? null;

        return strtr((string) $this->out_of_hours_message, [
            '{name}' => $conversation->name ?? '',
            '{phone}' => $conversation->phone_number ?? '',
            '{wa_id}' => $conversation->wa_id ?? '',
            '{start}' => $firstRange ? $this->formatTime($firstRange['start']) : '',
            '{end}' => $firstRange ? $this->formatTime($firstRange['end']) : '',
            '{schedule}' => $this->describeTodaySchedule($today),
        ]);
    }

    private function describeTodaySchedule(array $day): string
    {
        if (!($day['enabled'] ?? false)) {
            return 'cerrado hoy';
        }

        if ($day['all_day'] ?? false) {
            return 'abierto las 24 horas';
        }

        $parts = array_map(
            fn ($range) => $this->formatTime($range['start']) . ' a ' . $this->formatTime($range['end']),
            $day['ranges'] ?? []
        );

        return $parts ? implode(' y ', $parts) : 'cerrado hoy';
    }

    private function normalizeTime($value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('H:i:s');
        }

        $value = (string) $value;
        if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            return $value . ':00';
        }

        return $value;
    }

    private function formatTime($value): string
    {
        $v = $this->normalizeTime($value);
        return substr($v, 0, 5);
    }
}
