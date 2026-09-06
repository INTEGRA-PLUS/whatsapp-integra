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
        'health_status',
        'health_checked_at',
        'health_error',
        'meta',
        'access_token'
    ];

    protected $casts = [
        'active' => 'boolean',
        'health_checked_at' => 'datetime',
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

    /**
     * Sub-arreglo de configuración de la plantilla de reinicio de conversación
     * (para reabrir chats fuera de la ventana de 24h) dentro de `meta`.
     */
    public function resumeTemplateSettings(): array
    {
        return $this->meta['resume_template'] ?? [];
    }

    public function resumeTemplateName(): ?string
    {
        return $this->resumeTemplateSettings()['name'] ?? null;
    }

    public function resumeTemplateLanguage(): ?string
    {
        return $this->resumeTemplateSettings()['language'] ?? null;
    }

    /**
     * Configura (o limpia, pasando $name null) la plantilla de reinicio de
     * conversación de esta instancia, conservando el resto del JSON `meta`.
     */
    public function setResumeTemplate(?string $name, ?string $language): void
    {
        $meta = $this->meta ?? [];
        if ($name) {
            $meta['resume_template'] = ['name' => $name, 'language' => $language];
        } else {
            unset($meta['resume_template']);
        }
        $this->meta = $meta;
    }

    /**
     * Sub-arreglo de la plantilla de respaldo para avisos automáticos fuera de
     * la ventana de 24h, dentro de `meta`. Guarda tanto la elección de la
     * empresa (nombre, idioma, mapeo de variables) como el último estado
     * conocido en Meta (APPROVED/PENDING/REJECTED) para no consultar el Graph
     * en cada envío. Ver WhatsAppFallbackTemplateService.
     */
    public function fallbackTemplateSettings(): array
    {
        return $this->meta['fallback_template'] ?? [];
    }

    public function fallbackTemplateName(): ?string
    {
        return $this->fallbackTemplateSettings()['name'] ?? null;
    }

    public function fallbackTemplateLanguage(): ?string
    {
        return $this->fallbackTemplateSettings()['language'] ?? null;
    }

    /**
     * ¿La empresa apagó a propósito el respaldo automático? Sin esto, la única
     * forma de desactivarlo sería borrar la plantilla de Meta, y el servicio la
     * volvería a crear en el siguiente aviso fuera de ventana.
     */
    public function fallbackTemplateDisabled(): bool
    {
        return (bool) ($this->fallbackTemplateSettings()['disabled'] ?? false);
    }

    /**
     * Mezcla valores en la configuración de la plantilla de respaldo
     * conservando el resto del JSON `meta` y las claves que no se tocan.
     */
    public function mergeFallbackTemplate(array $values): void
    {
        $meta = $this->meta ?? [];
        $meta['fallback_template'] = array_merge($meta['fallback_template'] ?? [], $values);
        $this->meta = $meta;
    }

    /**
     * Borra por completo la configuración de la plantilla de respaldo: la
     * siguiente consulta vuelve a partir del catálogo por defecto.
     */
    public function clearFallbackTemplate(): void
    {
        $meta = $this->meta ?? [];
        unset($meta['fallback_template']);
        $this->meta = $meta;
    }

    /**
     * Nombre comercial con el que firmar los avisos automáticos: lo que el
     * cliente final debe leer, no el nombre interno de la instancia. Se prefiere
     * el nombre verificado que Meta muestra en el perfil del número.
     */
    public function businessDisplayName(): string
    {
        $candidates = [
            $this->meta['verified_name'] ?? null,
            $this->company?->name,
            $this->name,
            $this->display_phone_number,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'nuestro equipo';
    }
}
