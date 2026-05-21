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
        'start_time',
        'end_time',
        'days_of_week',
        'out_of_hours_message',
        'cooldown_minutes',
        'fires_count',
        'last_fired_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'days_of_week' => 'array',
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

        $days = $this->days_of_week;
        if (is_array($days) && count($days) > 0 && !in_array((int) $now->dayOfWeek, array_map('intval', $days), true)) {
            return false;
        }

        $current = $this->normalizeTime($now->format('H:i:s'));
        $start = $this->normalizeTime($this->start_time);
        $end = $this->normalizeTime($this->end_time);

        if ($start === $end) {
            return true;
        }

        if ($start < $end) {
            return $current >= $start && $current <= $end;
        }

        return $current >= $start || $current <= $end;
    }

    public function renderMessage(WhatsAppConversation $conversation): string
    {
        return strtr((string) $this->out_of_hours_message, [
            '{name}' => $conversation->name ?? '',
            '{phone}' => $conversation->phone_number ?? '',
            '{wa_id}' => $conversation->wa_id ?? '',
            '{start}' => $this->formatTime($this->start_time),
            '{end}' => $this->formatTime($this->end_time),
        ]);
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
