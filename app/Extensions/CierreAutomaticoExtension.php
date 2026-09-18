<?php

namespace App\Extensions;

use App\Extensions\Contracts\HandlesInboundMessage;
use App\Extensions\Contracts\RunsOnSchedule;
use App\Jobs\ProcessWhatsAppMenu;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\AiDecision;
use App\Support\ConversationNotice;
use App\Support\MenuActionResult;
use App\Support\SeDespide;
use Illuminate\Support\Facades\Log;

/**
 * Cierra solas las conversaciones que ya terminaron.
 *
 * El agujero que tapa: una bandeja de WhatsApp se llena de hilos que acabaron
 * hace días y que nadie cerró, y entonces «9 abiertas» deja de significar nada
 * —ni para el equipo ni para el reparto por carga, que cuenta chats abiertos
 * para decidir a quién le toca el siguiente—.
 *
 * Hace tres cosas, en este orden:
 *
 * 1. Tras un rato sin mensajes, **pregunta** si hace falta algo más.
 * 2. Si el cliente dice que no —o se despide— **cierra**.
 * 3. Si no contesta a esa pregunta en otro rato, **cierra** igual.
 *
 * ## Lo que NO hace
 *
 * **No toca los chats que lleva una persona**, salvo que se le pida. Un asesor
 * a mitad de gestión no necesita que un robot le pregunte a su cliente si
 * quiere algo más, ni que le cierre el hilo mientras busca un dato. Se puede
 * cambiar, pero viene apagado.
 *
 * Y no envía por su cuenta: delega en `ProcessWhatsAppMenu`, el único sitio que
 * habla con Meta, para que la ventana de 24 h y el «¿lo tomó un agente?» se
 * vuelvan a comprobar en el momento del envío y no en el de la decisión.
 */
class CierreAutomaticoExtension extends Extension implements RunsOnSchedule, HandlesInboundMessage
{
    /**
     * Tope por pasada y empresa.
     *
     * Una bandeja con doscientos hilos viejos no puede convertirse en doscientos
     * mensajes de golpe la primera vez que alguien enciende esto: para el
     * cliente sería un aluvión y para Meta, un pico de envíos desde el mismo
     * número. Se reparte entre pasadas de cinco minutos.
     */
    private const MAX_POR_PASADA = 25;

    public function slug(): string
    {
        return 'cierre_automatico';
    }

    public function name(): string
    {
        return 'Cierre automático de conversaciones';
    }

    public function description(): string
    {
        return 'Pregunta si hace falta algo más y cierra las conversaciones que ya terminaron.';
    }

    public function detail(): string
    {
        return 'Cada cinco minutos revisa tus conversaciones abiertas. Cuando una lleva parada el '
            .'tiempo que indiques, le pregunta al cliente si necesita algo más. Si contesta que no '
            .'—o se despide— cierra el chat; si no contesta en el tiempo que indiques, lo cierra '
            .'también.'
            ."\n\n"
            .'Por defecto **no toca los chats que ya lleva un asesor**: alguien a mitad de gestión '
            .'no necesita que un robot le pregunte a su cliente si quiere algo más. Puedes '
            .'cambiarlo abajo.'
            ."\n\n"
            .'Un cliente que vuelva a escribir reabre su conversación como siempre, así que cerrar '
            .'no pierde nada: sólo deja la bandeja diciendo la verdad.';
    }

    public function icon(): string
    {
        return 'CheckCheck';
    }

    public function category(): string
    {
        return self::CATEGORIA_CONVERSACIONES;
    }

    public function permissions(): array
    {
        return [
            'Leer tus conversaciones abiertas y sus mensajes',
            'Enviar un mensaje al cliente para preguntar si necesita algo más',
            'Cerrar conversaciones',
        ];
    }

    public function hooks(): array
    {
        return ['Cada 5 minutos, en segundo plano', 'Cuando entra un mensaje del cliente'];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'minutos_para_preguntar',
                'type' => 'number',
                'label' => 'Preguntar tras (minutos sin mensajes)',
                'help' => 'Cuánto tiene que llevar parada una conversación para preguntarle al cliente si necesita algo más.',
                'default' => 30,
                'min' => 5,
                'max' => 1440,
            ],
            [
                'key' => 'pregunta',
                'type' => 'textarea',
                'label' => 'Qué se le pregunta',
                'help' => 'Puedes usar {name} para su nombre.',
                'default' => '¿Necesitas algo más, {name}? Si no me dices nada, cierro la conversación por ahora. 😊',
            ],
            [
                'key' => 'minutos_para_cerrar',
                'type' => 'number',
                'label' => 'Cerrar si no contesta en (minutos)',
                'help' => 'Cuánto se espera esa respuesta antes de cerrar el chat.',
                'default' => 30,
                'min' => 5,
                'max' => 1440,
            ],
            [
                'key' => 'mensaje_de_cierre',
                'type' => 'textarea',
                'label' => 'Qué se le dice al cerrar',
                'help' => 'Déjalo vacío para cerrar sin escribir nada.',
                'default' => 'Cierro esta conversación por ahora. Si necesitas algo más, escríbeme cuando quieras. 👋',
            ],
            [
                'key' => 'cerrar_si_se_despide',
                'type' => 'boolean',
                'label' => 'Cerrar cuando el cliente se despide',
                'help' => 'Con «no, gracias», «eso es todo» o «hasta luego» se cierra sin esperar. Una pregunta nunca cierra, por muchas gracias que lleve delante.',
                'default' => true,
            ],
            [
                'key' => 'incluir_chats_con_asesor',
                'type' => 'boolean',
                'label' => 'Incluir los chats que ya lleva un asesor',
                'help' => 'Apagado, sólo se ocupa de los que no tiene nadie. Enciéndelo si tu equipo se olvida de cerrar los suyos.',
                'default' => false,
            ],
        ];
    }

    // ─── El gancho programado ────────────────────────────────────────────────

    public function runScheduled(CompanyExtension $installed): void
    {
        $ajustes = $installed->settings();

        $instanceIds = Instance::where('company_id', $installed->company_id)->pluck('id');

        if ($instanceIds->isEmpty()) {
            return;
        }

        $this->cerrarLasQueNoContestaron($installed, $ajustes, $instanceIds);
        $this->preguntarALasParadas($installed, $ajustes, $instanceIds);
    }

    /**
     * Las que ya se preguntaron y siguen calladas.
     *
     * Va ANTES de preguntar, y no al revés: si en la misma pasada se preguntara
     * primero, una conversación recién preguntada entraría en la segunda mitad
     * con la cuenta a cero y no se cerraría nunca.
     */
    private function cerrarLasQueNoContestaron(CompanyExtension $installed, array $ajustes, $instanceIds): void
    {
        $espera = (int) ($ajustes['minutos_para_cerrar'] ?? 30);

        $candidatas = $this->abiertas($ajustes, $instanceIds)
            ->whereNotNull('cierre_preguntado_at')
            ->where('cierre_preguntado_at', '<', now()->subMinutes($espera))
            ->limit(self::MAX_POR_PASADA)
            ->get();

        foreach ($candidatas as $conversation) {
            // Segunda línea de defensa: la marca la limpia el gancho de entrada
            // en cuanto el cliente escribe, pero si ese gancho falla —o el
            // mensaje entra por otra vía— cerraríamos un chat que ya revivió.
            // Cerrar a alguien que acaba de contestar es de lo peor que puede
            // hacer esto, así que se comprueba dos veces.
            if ($this->contestoDespues($conversation)) {
                $conversation->update(['cierre_preguntado_at' => null]);

                continue;
            }

            $this->cerrar($conversation, $installed, $ajustes, 'no contestó a la pregunta de cierre');
        }
    }

    /** ¿Llegó algo del cliente después de preguntarle? */
    private function contestoDespues(WhatsAppConversation $conversation): bool
    {
        return WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('type', '!=', 'system')
            ->where('created_at', '>', $conversation->cierre_preguntado_at)
            ->exists();
    }

    /** Las paradas a las que todavía no se les ha preguntado nada. */
    private function preguntarALasParadas(CompanyExtension $installed, array $ajustes, $instanceIds): void
    {
        $espera = (int) ($ajustes['minutos_para_preguntar'] ?? 30);
        $pregunta = trim((string) ($ajustes['pregunta'] ?? ''));

        if ($pregunta === '') {
            return;
        }

        $candidatas = $this->abiertas($ajustes, $instanceIds)
            // Ya se le preguntó: le toca a la otra mitad decidir si se cierra.
            ->whereNull('cierre_preguntado_at')
            ->where('last_message_at', '<', now()->subMinutes($espera))
            ->limit(self::MAX_POR_PASADA)
            ->get();

        foreach ($candidatas as $conversation) {
            $this->enviar($conversation, strtr($pregunta, ['{name}' => $conversation->name ?? '']));

            // Se marca aunque el envío se caiga después: si no llegó, el
            // cliente tampoco va a contestar, y cerrar un hilo parado es
            // exactamente lo que hay que hacer con él.
            $conversation->update(['cierre_preguntado_at' => now()]);
        }
    }

    // ─── El gancho de entrada ────────────────────────────────────────────────

    public function onInboundMessage(
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        CompanyExtension $installed
    ): void {
        $ajustes = $installed->settings();

        // El cliente volvió: si había una pregunta en el aire, ya está
        // respondida por el mero hecho de escribir. Se limpia SIEMPRE, incluso
        // si el cierre por despedida está apagado, o el hilo se cerraría solo
        // por una pregunta que el cliente ya contestó.
        if ($conversation->cierre_preguntado_at !== null) {
            $conversation->update(['cierre_preguntado_at' => null]);
        }

        if (! ($ajustes['cerrar_si_se_despide'] ?? true)) {
            return;
        }

        if ($conversation->status !== 'open') {
            return;
        }

        if ($conversation->assigned_to !== null && ! ($ajustes['incluir_chats_con_asesor'] ?? false)) {
            return;
        }

        if (! SeDespide::loDice($message->content)) {
            return;
        }

        $this->cerrar($conversation, $installed, $ajustes, 'el cliente se despidió');
    }

    // ─── Lo común ────────────────────────────────────────────────────────────

    /** @return \Illuminate\Database\Eloquent\Builder */
    private function abiertas(array $ajustes, $instanceIds)
    {
        return WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            ->when(
                ! ($ajustes['incluir_chats_con_asesor'] ?? false),
                fn ($q) => $q->whereNull('assigned_to')
            )
            ->orderBy('last_message_at');
    }

    private function cerrar(
        WhatsAppConversation $conversation,
        CompanyExtension $installed,
        array $ajustes,
        string $motivo
    ): void {
        $despedida = trim((string) ($ajustes['mensaje_de_cierre'] ?? ''));

        if ($despedida !== '') {
            $this->enviar($conversation, strtr($despedida, ['{name}' => $conversation->name ?? '']));
        }

        $conversation->update([
            'status' => 'closed',
            'closed_at' => now(),
            'cierre_preguntado_at' => null,
            // `closed_by` se queda en null a propósito: no lo cerró nadie del
            // equipo, y ponerle un usuario haría creer que alguien lo revisó.
            'closed_by' => null,
        ]);

        ConversationNotice::record(
            $conversation,
            'Conversación cerrada automáticamente: '.$motivo,
            'cierre'
        );

        Log::channel('whatsapp')->info('🔒 Cierre automático', [
            'conversation_id' => $conversation->id,
            'company_id' => $installed->company_id,
            'motivo' => $motivo,
        ]);
    }

    /**
     * Manda un texto por el único sitio que habla con Meta.
     *
     * No se envía desde aquí: `ProcessWhatsAppMenu` vuelve a comprobar la
     * ventana de 24 h y si un agente tomó el chat, y esas dos cosas pueden haber
     * cambiado entre que la pasada decidió y le toca el turno a la cola.
     */
    private function enviar(WhatsAppConversation $conversation, string $texto): void
    {
        ProcessWhatsAppMenu::dispatch(
            $conversation->instance_id,
            $conversation->id,
            null,
            null,
            '',
            null,
            new AiDecision(MenuActionResult::reply($texto))
        );
    }
}
