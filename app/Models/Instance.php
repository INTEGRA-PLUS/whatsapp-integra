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
        'access_token'
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

    public function calls()
    {
        return $this->hasMany(WhatsAppCall::class);
    }

    /**
     * Sub-arreglo de configuración de llamadas dentro de `meta`.
     */
    public function callingSettings(): array
    {
        return $this->meta['calling'] ?? [];
    }

    /**
     * ¿La función de llamadas está habilitada en el número (vía Meta)?
     * Necesario para entrantes y salientes.
     */
    public function callingEnabled(): bool
    {
        return (bool) ($this->callingSettings()['enabled'] ?? false);
    }

    /**
     * ¿El negocio activó las llamadas SALIENTES? Va aparte por el costo por
     * minuto: las entrantes son gratis, las salientes se cobran.
     */
    public function outboundCallsEnabled(): bool
    {
        return (bool) ($this->callingSettings()['outbound_enabled'] ?? false);
    }

    /**
     * Actualiza una o varias claves de la configuración de llamadas en `meta`,
     * conservando el resto del JSON.
     */
    public function setCallingSettings(array $values): void
    {
        $meta = $this->meta ?? [];
        $meta['calling'] = array_merge($meta['calling'] ?? [], $values);
        $this->meta = $meta;
    }
}
