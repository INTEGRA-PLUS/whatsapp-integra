<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaign extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_campaigns';

    public const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = [
        'company_id',
        'instance_id',
        'created_by',
        'name',
        'message',
        'message_type',
        'status',
        'schedule_type',
        'schedule_days',
        'schedule_time',
        'schedule_timezone',
        'total_recipients',
        'sent_count',
        'failed_count',
        'started_at',
        'completed_at',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'schedule_days' => 'array',
    ];

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients()
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'campaign_id');
    }

    public function pendingRecipients()
    {
        return $this->recipients()->where('status', 'pending');
    }

    public function isLaunchable(): bool
    {
        return $this->schedule_type === 'manual'
            && in_array($this->status, ['draft', 'failed'], true)
            && $this->total_recipients > 0;
    }

    public function isRecurring(): bool
    {
        return $this->schedule_type === 'recurring';
    }

    public function computeNextRun(?\Carbon\Carbon $from = null): ?\Carbon\Carbon
    {
        $days = $this->schedule_days ?: [];
        if (!$this->isRecurring() || empty($days) || !$this->schedule_time) {
            return null;
        }

        $tz = $this->schedule_timezone ?: config('app.timezone');
        $base = ($from ?? now())->copy()->setTimezone($tz);

        [$h, $m] = array_pad(explode(':', (string) $this->schedule_time), 2, 0);

        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $allowed = [];
        foreach ($days as $d) {
            if (isset($dayMap[$d])) $allowed[] = $dayMap[$d];
        }
        if (empty($allowed)) return null;

        for ($i = 0; $i < 8; $i++) {
            $candidate = $base->copy()->addDays($i)->setTime((int) $h, (int) $m, 0);
            if (in_array($candidate->dayOfWeek, $allowed, true) && $candidate->gt($base)) {
                return $candidate->setTimezone(config('app.timezone'));
            }
        }
        return null;
    }
}
