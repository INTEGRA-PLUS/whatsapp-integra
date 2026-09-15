<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de color en el semáforo de una conversación.
 *
 * Sin `updated_at`: una fila de histórico no se edita. Si algo cambia, es otra
 * fila.
 *
 * Lleva `company_id` propio —a diferencia de los demás modelos colgados de una
 * conversación— porque el panel lo consulta agregado por empresa. Eso significa
 * que **toda consulta tiene que filtrar por él a mano**: aquí no hay ningún
 * scope global que lo haga por nadie.
 */
class SentimentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'conversation_sentiment_events';

    protected $fillable = [
        'company_id',
        'conversation_id',
        'nivel',
        'score',
        'origen',
        'assigned_to',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'score' => 'float',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Apunta un cambio de color. No hace nada si el color no cambió.
     *
     * Vive aquí y no en la extensión porque lo escriben dos sitios —la matriz y
     * el job de IA— y la condición de "sólo si cambió" tiene que ser la misma en
     * los dos: repetida a mano, el día que alguien toque una se separan y el
     * histórico empieza a contar cosas distintas según quién escribiera.
     */
    public static function registrar(
        WhatsAppConversation $conversation,
        int $companyId,
        string $nivel,
        ?float $score,
        string $origen,
        ?string $anterior
    ): void {
        if ($nivel === $anterior) {
            return;
        }

        self::create([
            'company_id' => $companyId,
            'conversation_id' => $conversation->id,
            'nivel' => $nivel,
            'score' => $score,
            'origen' => $origen,
            'assigned_to' => $conversation->assigned_to,
            'created_at' => now(),
        ]);
    }
}
