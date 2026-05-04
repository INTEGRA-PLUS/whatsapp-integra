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
    ];

    protected $casts = [
        'active' => 'boolean',
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
        if ($this->match_type === 'always') {
            return true;
        }

        $needle = mb_strtolower(trim($this->trigger_text));
        $haystack = mb_strtolower(trim($incoming));

        if ($needle === '' || $haystack === '') {
            return false;
        }

        return match ($this->match_type) {
            'exact' => $haystack === $needle,
            'starts_with' => str_starts_with($haystack, $needle),
            default => str_contains($haystack, $needle),
        };
    }
}
