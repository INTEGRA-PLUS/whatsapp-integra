<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Instance extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'uuid',
        'name',
        'phone_number_id',
        'waba_id',
        'display_phone_number',
        'type',
        'status',
        'active',
        'meta',
        'api_token'
    ];

    protected $casts = [
        'active' => 'boolean',
        'meta' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function conversations()
    {
        return $this->hasMany(WhatsAppConversation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeMeta($query)
    {
        return $query->where('type', 'meta');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isMetaConfigured()
    {
        return !empty($this->phone_number_id) && !empty($this->waba_id);
    }
}
