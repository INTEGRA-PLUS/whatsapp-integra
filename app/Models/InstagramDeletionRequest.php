<?php

namespace App\Models;

use App\Http\Controllers\InstagramPrivacidadController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Una petición de borrado de datos llegada por Instagram.
 *
 * @see InstagramPrivacidadController
 */
class InstagramDeletionRequest extends Model
{
    public const RECIBIDA = 'recibida';

    public const COMPLETADA = 'completada';

    protected $fillable = [
        'instagram_user_id',
        'confirmation_code',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    /**
     * Registra la petición y devuelve el código con el que se consulta.
     *
     * Si el mismo usuario vuelve a pedirlo mientras la anterior sigue abierta se
     * le devuelve **la misma**: Meta reintenta estos avisos y abrir una petición
     * nueva por cada reintento llenaría la tabla de duplicados que dicen lo mismo.
     */
    public static function abrirPara(string $instagramUserId): self
    {
        $abierta = static::where('instagram_user_id', $instagramUserId)
            ->where('status', self::RECIBIDA)
            ->first();

        if ($abierta) {
            return $abierta;
        }

        return static::create([
            'instagram_user_id' => $instagramUserId,
            'confirmation_code' => Str::lower(Str::random(24)),
            'status' => self::RECIBIDA,
        ]);
    }

    public function estaCompletada(): bool
    {
        return $this->status === self::COMPLETADA;
    }
}
