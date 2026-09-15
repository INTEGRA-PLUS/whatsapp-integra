<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que una empresa consumió de IA en un mes.
 *
 * Se escribe con un UPSERT desde `ContadorDeIa` y se lee desde el panel maestro.
 * El modelo existe para leer; no lo uses para incrementar contadores o volverás
 * a tener la carrera que el UPSERT evita.
 */
class CompanyAiUsage extends Model
{
    protected $table = 'company_ai_usage';

    protected $fillable = [
        'company_id',
        'periodo',
        'conversaciones',
        'eventos_menu',
        'eventos_chat',
        'eventos_semaforo',
        'eventos_resumen',
        'coste_usd',
    ];

    protected $casts = [
        'periodo' => 'date',
        'coste_usd' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
