<?php

namespace App\Support;

use App\Events\ConversationEvent;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
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
            ->update(['assigned_to' => $user->id, 'assigned_at' => now()]);

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

    /**
     * Y quien atendió suelta el chat cuando el cliente vuelve tiempo después.
     *
     * 2026-10-06: «no se está disparando ninguna respuesta automática». Desde
     * que contestar asigna el chat (2026-09-18) nada lo desasignaba: ni cerrar,
     * ni el cierre automático, ni que el cliente reabriera días después. El
     * menú y la respuesta automática no le hablan encima a un asignado, así
     * que con todo cliente que un asesor hubiera atendido alguna vez el bot se
     * callaba para siempre. Y casi todos los clientes acaban hablando con un
     * asesor.
     *
     * Se suelta en dos casos, siempre con el cliente escribiendo:
     *
     * - **Reabre un chat cerrado.** Cerrar es dar la atención por terminada;
     *   lo que llegue después es una atención nueva.
     * - **Nadie del equipo le escribe en 1 h** (`whatsapp.asignacion.
     *   soltar_tras_minutos`): ni el asesor asignado ni un administrador. Se
     *   cuenta desde lo último que escribió alguno de los dos o desde que se
     *   tomó el chat (`assigned_at`), lo más reciente. Lo que escribe el
     *   cliente no para el reloj: un cliente insistiendo sin respuesta es justo
     *   el caso en que el bot tiene que volver.
     *
     * Un aviso de WhatsApp (cambio de número, mensaje revocado) no suelta
     * nada: no es el cliente volviendo. Se llama ANTES de guardar el mensaje
     * entrante, porque el silencio se mide hasta el mensaje anterior.
     */
    public static function sueltaSiVuelveElCliente(WhatsAppConversation $conversation, bool $reabierta): bool
    {
        if ($conversation->assigned_to === null) {
            return false;
        }

        $motivo = $reabierta ? 'el cliente reabrió el chat' : self::sinRespuestaDelEquipo($conversation);

        if ($motivo === null) {
            return false;
        }

        $asesor = User::find($conversation->assigned_to);

        // Condicional, como el reclamo: si alguien la tomó en este instante, no
        // se le quita.
        $soltada = WhatsAppConversation::where('id', $conversation->id)
            ->where('assigned_to', $conversation->assigned_to)
            ->update(['assigned_to' => null, 'assigned_at' => null]);

        if (! $soltada) {
            return false;
        }

        $conversation->assigned_to = null;
        $conversation->assigned_at = null;
        $conversation->syncOriginalAttributes(['assigned_to', 'assigned_at']);

        // En el hilo, para que el asesor entienda por qué el chat ya no está en
        // su bandeja. `evento` es lo que mira el menú para saber que la
        // atención anterior terminó (WhatsAppMenuService::hablaUnaPersona).
        ConversationNotice::record(
            $conversation,
            ($asesor ? "{$asesor->name} deja de tener" : 'Se libera').' el chat: '.$motivo.'. El bot vuelve a atender.',
            'liberada',
        );

        Log::channel('whatsapp')->info('🔓 El cliente volvió y el chat se libera', [
            'conversation_id' => $conversation->id,
            'user_id' => $asesor?->id,
            'motivo' => $motivo,
        ]);

        return true;
    }

    /**
     * El motivo, si ni el asesor asignado ni un administrador le han escrito
     * al cliente en el plazo.
     */
    private static function sinRespuestaDelEquipo(WhatsAppConversation $conversation): ?string
    {
        $minutos = (int) config('whatsapp.asignacion.soltar_tras_minutos', 60);

        if ($minutos <= 0) {
            return null;
        }

        $limite = now()->subMinutes($minutos);

        // Acaba de tomarlo: todavía no ha tenido tiempo de escribir.
        if ($conversation->assigned_at && $conversation->assigned_at->gt($limite)) {
            return null;
        }

        // Solo mensajes al cliente: las notas internas van con `direction =
        // internal` y no son hablarle. La hora es la de Meta: cuando suelta una
        // cola atascada, `created_at` es de hoy y `sent_at` de hace días.
        $escribioHacePoco = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->whereIn('sent_by', self::quienesCuentan($conversation))
            ->whereRaw('COALESCE(sent_at, created_at) > ?', [$limite])
            ->exists();

        if ($escribioHacePoco) {
            return null;
        }

        $plazo = $minutos % 60 === 0 ? ($minutos / 60).' h' : "{$minutos} min";

        return "{$plazo} sin que el asesor ni un administrador le escriban al cliente";
    }

    /**
     * El asesor asignado y los administradores de la empresa.
     *
     * Los roles de Spatie están particionados por empresa y esto corre dentro
     * del webhook, sin usuario: hay que fijar el equipo para que hasRole()
     * encuentre algo, y devolverlo como estaba, porque un mismo lote de Meta
     * puede traer mensajes de varias empresas.
     *
     * @return array<int, int>
     */
    private static function quienesCuentan(WhatsAppConversation $conversation): array
    {
        $companyId = $conversation->instance?->company_id;
        $ids = [(int) $conversation->assigned_to];

        if ($companyId === null) {
            return $ids;
        }

        $equipoAnterior = getPermissionsTeamId();
        setPermissionsTeamId($companyId);

        try {
            $admins = User::where('company_id', $companyId)
                ->get()
                ->filter(fn (User $u) => $u->hasRole('admin'))
                ->pluck('id')
                ->all();
        } finally {
            setPermissionsTeamId($equipoAnterior);
        }

        return array_values(array_unique([...$ids, ...$admins]));
    }
}
