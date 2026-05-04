<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'instance_id',
        'name',
        'trigger_text',
        'match_type',
        'response_message',
        'active',
        'cooldown_minutes',
        'fires_count',
        'last_fired_at',
    ];

    protected $casts = [
        'active' => 'boolean',
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

    public function matches(string $incoming): bool
    {
        if ($this->match_type === 'always' || $this->match_type === 'welcome') {
            return true;
        }

        $haystack = mb_strtolower(trim($incoming));

        if ($haystack === '') {
            return false;
        }

        $keywords = $this->keywords();

        if (empty($keywords)) {
            return false;
        }

        foreach ($keywords as $needle) {
            $matched = match ($this->match_type) {
                'exact' => $haystack === $needle,
                'starts_with' => str_starts_with($haystack, $needle),
                default => str_contains($haystack, $needle),
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    public function keywords(): array
    {
        return collect(explode(',', (string) $this->trigger_text))
            ->map(fn ($k) => mb_strtolower(trim($k)))
            ->filter(fn ($k) => $k !== '')
            ->values()
            ->all();
    }

    public function renderMessage(WhatsAppConversation $conversation): string
    {
        return strtr($this->response_message, [
            '{name}' => $conversation->name ?? '',
            '{phone}' => $conversation->phone_number ?? '',
            '{wa_id}' => $conversation->wa_id ?? '',
        ]);
    }
}
