<?php

namespace App\Support;

use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Events\ConversationEvent;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Quien contesta se queda con el chat.
 *
 * Una conversación sin dueño la puede responder cualquiera del equipo, y eso
 * está bien hasta que alguien contesta: a partir de ahí el chat **tiene** dueño
 * aunque la ficha siga diciendo «sin asignar». El resultado es el de siempre:
 * dos asesores escribiéndole al mismo cliente porque los dos la vieron libre,
 * y una conversación que no sale en la bandeja de nadie.
 *
 * Hasta ahora había que acordarse de pulsar «Atenderla yo» **antes** de
 * escribir. Nadie se acuerda de eso, y un botón que hay que recordar es un
 * botón que no existe.
 *
 * ## Lo que NO hace
 *
 * **No se la quita a nadie.** Si ya tiene dueño, escribir no lo cambia: un
 * supervisor que entra a aclarar algo no debería robarle el chat a quien lo
 * lleva. Lo mismo que hace «Atenderla yo», que tampoco permite robar.
 *
 * **No se dispara con el bot ni con la IA.** El reclamo necesita una persona, y
 * quien se reconoce por `sent_by`: los envíos automáticos no lo traen. Un chat
 * asignado al bot sería un chat que además se calla, porque el bot no le habla
 * encima a un asesor asignado.
 *
 * **Tampoco con una nota interna.** Una nota no es hablarle al cliente, y a
 * menudo la escribe quien pasa a comentar algo y no quien va a atender.
 */
class QuienAtiende
{
    /**
     * Le asigna la conversación a quien acaba de escribir, si no tenía dueño.
     *
     * Devuelve `true` sólo cuando el reclamo cambió algo, para que quien llama
     * no avise dos veces.
     */
    public static function seQuedaConElChat(WhatsAppConversation $conversation, ?User $user): bool
    {
        if ($user === null || $conversation->assigned_to !== null) {
            return false;
        }

        // Condicional y en una sola consulta: dos asesores que contestan a la
        // vez entran los dos aquí con `assigned_to` a null leído de antes, y sin
        // esto el segundo pisaría al primero. Es el mismo reclamo atómico de
        // «Atenderla yo».
        $reclamada = WhatsAppConversation::where('id', $conversation->id)
            ->whereNull('assigned_to')
            ->update(['assigned_to' => $user->id]);

        if (! $reclamada) {
            return false;
        }

        Log::channel('whatsapp')->info('🙋 Contestó y se queda con el chat', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.assigned',
            WebhookDispatcher::conversationPayload($conversation, ['assigned_to' => $user->id])
        );

        // `$conversation` se leyó antes del reclamo, así que todavía tiene
        // `assigned_to` a null: sin refrescar, el resto del equipo la seguiría
        // viendo libre y con el botón de «Atenderla yo» puesto.
        Realtime::push(ConversationEvent::updated($conversation->refresh(), 'assigned'));

        return true;
    }
}
