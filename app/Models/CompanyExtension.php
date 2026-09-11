<?php

namespace App\Models;

use App\Extensions\Extension;
use App\Extensions\ExtensionRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una extensión instalada por una empresa.
 *
 * Es sólo el estado: qué instaló, si lo tiene encendido y con qué ajustes. El
 * comportamiento y el manifiesto están en la clase del catálogo, a la que se
 * llega con extension().
 */
class CompanyExtension extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'slug',
        'enabled',
        'settings',
        'installed_by',
        'installed_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
        'installed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /** La clase del catálogo, o null si el slug ya no existe. */
    public function extension(): ?Extension
    {
        return app(ExtensionRegistry::class)->find($this->slug);
    }

    /**
     * Los ajustes con los que trabajar de verdad.
     *
     * Se mezclan sobre los de fábrica en vez de devolver la columna a secas:
     * una extensión que estrena un ajuste lo encuentra a null en todas las
     * empresas que ya la tenían instalada, y cada consumidor tendría que
     * acordarse de poner el valor por defecto a mano.
     */
    public function settings(): array
    {
        $extension = $this->extension();

        if (! $extension) {
            return $this->settings ?? [];
        }

        return array_merge($extension->defaultSettings(), $this->settings ?? []);
    }

    /** ¿Está instalada Y encendida? Las dos cosas: instalar no es encender. */
    public function isActive(): bool
    {
        return (bool) $this->enabled;
    }
}
