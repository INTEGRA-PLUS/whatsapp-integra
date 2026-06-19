<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    use HasFactory;

    protected $table = 'webhook_endpoints';

    protected $fillable = [
        'company_id',
        'name',
        'url',
        'secret',
        'events',
        'headers',
        'active',
        'created_by',
    ];

    protected $casts = [
        'events'  => 'array',
        'headers' => 'array',
        'active'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            if (empty($endpoint->secret)) {
                $endpoint->secret = Str::random(48);
            }
        });
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}
