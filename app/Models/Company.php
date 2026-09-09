<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'domain',
        'active',
        'settings',
        'ai_flow_unlocked_at',
        'ai_flow_unlocked_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
        'ai_flow_unlocked_at' => 'datetime',
    ];

    /**
     * El slug de una empresa a partir de su nombre, garantizando que no choque
     * con el de otra.
     *
     * `companies.slug` tiene índice único y nadie lo lee: no aparece en ninguna
     * ruta ni en ninguna consulta, sólo se escribe. Aun así, dar de alta dos
     * empresas cuyos nombres slugifican igual —"Fibra Sur" y "FIBRA SUR", o
     * "Fibra-Sur"— tumbaba el alta con un 500 sin explicación en el panel
     * maestro (8-sep-2026). Se resuelve con sufijo, no con validación, porque
     * dos empresas con nombres parecidos es algo legítimo y el slug es interno.
     *
     * Si el nombre no deja ni un carácter slugificable (nombres en alfabetos no
     * latinos, o sólo emojis) la base es "empresa": es preferible
     * `empresa-2` a un slug vacío, que además chocaría con el siguiente.
     */
    public static function slugUnico(string $nombre, ?int $ignorarId = null): string
    {
        $base = Str::slug($nombre) ?: 'empresa';

        $ocupados = static::query()
            ->where('slug', 'like', $base.'%')
            ->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))
            ->pluck('slug')
            ->all();

        if (! in_array($base, $ocupados, true)) {
            return $base;
        }

        $sufijo = 2;
        while (in_array($base.'-'.$sufijo, $ocupados, true)) {
            $sufijo++;
        }

        return $base.'-'.$sufijo;
    }

    /**
     * ¿Alguien desbloqueó ya el apartado de IA para esta empresa?
     *
     * Mientras sea false el panel sólo muestra la casilla del secreto: ni los
     * interruptores ni los permisos existen todavía para el admin.
     */
    public function aiFlowUnlocked(): bool
    {
        return $this->ai_flow_unlocked_at !== null;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function instances()
    {
        return $this->hasMany(Instance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
