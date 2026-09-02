<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que el bot le preguntó al cliente y sigue esperando.
 *
 * Sólo existe mientras haya una pregunta en el aire: en cuanto la acción se
 * resuelve (o se agota la paciencia) la fila se borra y la conversación vuelve
 * a comportarse como cualquier otra.
 */
class WhatsAppBotFlow extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_bot_flows';

    /** Pasos posibles. El nombre dice qué se está esperando del cliente. */
    public const STEP_IDENTIFICATION = 'awaiting_identification';
    public const STEP_CONTRACT = 'awaiting_contract';
    public const STEP_REPORT = 'awaiting_report';

    /**
     * La pregunta la hizo la IA, no una opción del menú.
     *
     * Importa quién preguntó porque la respuesta tiene que volver al mismo
     * sitio: `WhatsAppMenuActionService` sabe retomar los flujos de las
     * opciones de Integra, pero no los de la IA —para él sería una acción
     * desconocida y contestaría con silencio, dejando al cliente hablando
     * solo—. Con esta marca, el mensaje vuelve al flujo de IA.
     */
    public const ACTION_AI = 'ia';

    /** Cuánto sigue en pie una pregunta sin responder. */
    public const MINUTES = 30;

    /**
     * Cuántas veces se le pide la cédula antes de rendirse. A la tercera el
     * cliente ya no está escribiendo mal el documento: es que no lo tiene o no
     * está en Integra, y seguir preguntando sólo lo enfada.
     */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'conversation_id',
        'menu_option_id',
        'action_type',
        'step',
        'context',
        'expires_at',
    ];

    protected $casts = [
        'context' => 'array',
        'expires_at' => 'datetime',
    ];

    public function option()
    {
        return $this->belongsTo(WhatsAppMenuOption::class, 'menu_option_id');
    }

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function get(string $key, $default = null)
    {
        return data_get($this->context ?? [], $key, $default);
    }

    /** El flujo abierto y vigente de una conversación, si lo hay. */
    public static function activeFor(int $conversationId): ?self
    {
        $flow = self::where('conversation_id', $conversationId)->first();

        if (!$flow) {
            return null;
        }

        if ($flow->isExpired()) {
            $flow->delete();
            return null;
        }

        return $flow;
    }

    /**
     * Deja constancia de la pregunta. Sustituye a cualquier flujo anterior: si
     * el cliente vuelve al menú y elige otra cosa, la pregunta vieja ya no
     * espera respuesta.
     */
    public static function open(int $conversationId, ?int $optionId, string $actionType, string $step, array $context = []): self
    {
        return self::updateOrCreate(
            ['conversation_id' => $conversationId],
            [
                'menu_option_id' => $optionId,
                'action_type' => $actionType,
                'step' => $step,
                'context' => $context,
                'expires_at' => now()->addMinutes(self::MINUTES),
            ]
        );
    }

    public static function close(int $conversationId): void
    {
        self::where('conversation_id', $conversationId)->delete();
    }
}
