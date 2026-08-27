<?php

namespace App\Jobs;

use App\Events\ConversationEvent;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMenuSession;
use App\Models\WhatsAppMessage;
use App\Services\AgentAssignmentService;
use App\Services\MetaWhatsAppService;
use App\Services\WhatsAppMenuActionService;
use App\Services\WhatsAppMenuService;
use App\Support\MenuActionResult;
use App\Support\Realtime;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ejecuta el menú interactivo: manda el menú, resuelve la opción que el cliente
 * eligió, o retoma la conversación que el bot dejó a medias esperando un dato.
 *
 * Las tres cosas viven en el mismo job porque una lleva a la otra: elegir una
 * opción puede ser abrir un submenú —que es exactamente enviar un menú— y
 * también puede ser preguntarle al cliente su cédula, cuya respuesta vuelve a
 * caer aquí para terminar la misma acción.
 */
class ProcessWhatsAppMenu implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param ?string $flowInput Texto con el que el cliente contesta a una
     *                           pregunta del bot ("dime tu cédula"). Cuando
     *                           llega, el job retoma el flujo en curso en vez
     *                           de mandar un menú o ejecutar una opción.
     */
    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public ?int $menuId,
        public ?int $optionId,
        public string $inboundWamid,
        public ?string $flowInput = null
    ) {}

    public function handle(
        MetaWhatsAppService $meta,
        WhatsAppMenuService $menus,
        WhatsAppMenuActionService $actions,
        AgentAssignmentService $assignment
    ): void
    {
        $instance = Instance::find($this->instanceId);
        $conversation = WhatsAppConversation::find($this->conversationId);

        if (!$instance || !$conversation || !$instance->active) {
            return;
        }

        // Entre que el webhook decidió y la cola llegó aquí, un agente pudo
        // haber tomado el chat. El bot no le pisa el turno.
        if ($conversation->assigned_to !== null || $conversation->status === 'closed') {
            Log::channel('whatsapp')->info('⏭️ Menú omitido: el chat ya lo atiende alguien', [
                'conversation_id' => $conversation->id,
                'assigned_to' => $conversation->assigned_to,
            ]);
            return;
        }

        // Igual que las respuestas automáticas: si Meta entregó el webhook con
        // días de retraso, mandar fuera de la ventana de 24h sólo produce un
        // fallido "Re-engagement" que nadie lee.
        if (!$conversation->isWindowOpen()) {
            Log::channel('whatsapp')->info('⏭️ Menú omitido: ventana de 24h cerrada', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        if ($this->flowInput !== null) {
            $this->continueFlow($instance, $conversation, $meta, $actions, $assignment);
            return;
        }

        if ($this->optionId !== null) {
            $this->executeOption($instance, $conversation, $meta, $menus, $actions, $assignment);
            return;
        }

        $menu = WhatsAppMenu::with('options')->find($this->menuId);

        if ($menu && $menu->active) {
            $this->sendMenu($instance, $conversation, $menu, $meta, $menus);
        }
    }

    /** Lo que ocurre cuando el cliente elige una opción. */
    private function executeOption(
        Instance $instance,
        WhatsAppConversation $conversation,
        MetaWhatsAppService $meta,
        WhatsAppMenuService $menus,
        WhatsAppMenuActionService $actions,
        AgentAssignmentService $assignment
    ): void {
        $option = WhatsAppMenuOption::with('menu')->find($this->optionId);

        if (!$option) {
            return;
        }

        // Elegir en el menú cancela cualquier pregunta anterior del bot: el
        // cliente cambió de idea y ya no va a contestar a lo de antes.
        WhatsAppBotFlow::close($conversation->id);

        match (true) {
            $option->action_type === 'submenu' => $this->openSubmenu($instance, $conversation, $option, $meta, $menus),
            $option->action_type === 'handoff' => $this->handoff($instance, $conversation, $option, $meta, $assignment),
            $option->action_type === WhatsAppMenuOption::ACTION_NONE => $this->acknowledgeWithoutAction($conversation, $option),
            $option->usesIntegra() => $this->runIntegraAction($instance, $conversation, $option, $meta, $actions, $assignment),
            $option->isPending() => $this->replyWithPendingNotice($instance, $conversation, $option, $meta),
            default => $this->replyWithText($instance, $conversation, $option, $meta),
        };
    }

    /**
     * Una acción que consulta Integra (facturas, pago, radicado, estado).
     * El servicio decide qué contestar; aquí sólo se envía y se apunta lo que
     * quede pendiente.
     */
    private function runIntegraAction(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppMenuOption $option,
        MetaWhatsAppService $meta,
        WhatsAppMenuActionService $actions,
        AgentAssignmentService $assignment
    ): void {
        $this->applyResult(
            $instance,
            $conversation,
            $option,
            $actions->execute($instance, $conversation, $option),
            $meta,
            $assignment
        );
    }

    /**
     * El cliente contestó a la pregunta del bot (su cédula, cuál contrato, la
     * descripción de la falla).
     */
    private function continueFlow(
        Instance $instance,
        WhatsAppConversation $conversation,
        MetaWhatsAppService $meta,
        WhatsAppMenuActionService $actions,
        AgentAssignmentService $assignment
    ): void {
        $flow = WhatsAppBotFlow::activeFor($conversation->id);

        if (!$flow) {
            return;
        }

        $flow->load('option.menu');

        $this->applyResult(
            $instance,
            $conversation,
            $flow->option,
            $actions->resume($instance, $conversation, $flow, (string) $this->flowInput),
            $meta,
            $assignment,
            $flow->action_type
        );
    }

    /**
     * Lleva a cabo lo que decidió el servicio de acciones: contestar, quedarse
     * esperando un dato o pasarle el chat a una persona.
     */
    private function applyResult(
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        MenuActionResult $result,
        MetaWhatsAppService $meta,
        AgentAssignmentService $assignment,
        ?string $fallbackAction = null
    ): void {
        // El menú queda consumido en cuanto se ejecuta una opción: dejar la
        // sesión abierta haría que el siguiente "1" del cliente —que ahora es
        // la respuesta a nuestra pregunta— reejecutara la opción.
        WhatsAppMenuSession::close($conversation->id);

        if ($result->keepsWaiting()) {
            WhatsAppBotFlow::open(
                $conversation->id,
                $option?->id,
                (string) ($result->context['action'] ?? $option?->action_type ?? $fallbackAction),
                (string) $result->step,
                $result->context
            );
        } else {
            WhatsAppBotFlow::close($conversation->id);
        }

        if ($result->text !== '') {
            $this->deliverText($instance, $conversation, $meta, $result->text, array_filter([
                'menu_id' => $option?->menu_id,
                'menu_option_id' => $option?->id,
                'action_type' => $option?->action_type ?? $fallbackAction,
                'awaiting' => $result->step,
            ]));
        }

        if ($result->handoff) {
            // El bot se rindió: el reparto va al asesor más descargado aunque
            // la opción no fuera un handoff, porque dejarlo en la bandeja
            // general es exactamente el silencio que se quería evitar.
            $this->claimAgent(
                $instance,
                $conversation,
                $option,
                WhatsAppMenuOption::ASSIGN_LEAST_BUSY,
                'El bot no pudo resolver la solicitud del cliente y derivó el chat',
                $assignment
            );
        }
    }

    /**
     * La opción es de una integración que todavía no existe (consultar factura,
     * pagar en línea…). Se contesta el aviso en vez de callar: el cliente ya
     * tocó, y el silencio es indistinguible de un sistema roto.
     *
     * Cuando la integración llegue, este método deja de atender ese tipo y el
     * menú del cliente no cambia: el id de la opción sigue siendo el mismo.
     */
    private function replyWithPendingNotice(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppMenuOption $option,
        MetaWhatsAppService $meta
    ): void {
        WhatsAppMenuSession::close($conversation->id);

        $text = $option->menu?->render($option->pendingReply(), $conversation) ?? $option->pendingReply();

        Log::channel('whatsapp')->info('🚧 Menú: opción sin integración todavía', [
            'conversation_id' => $conversation->id,
            'menu_option_id' => $option->id,
            'action_type' => $option->action_type,
        ]);

        if (trim($text) === '') {
            return;
        }

        $this->deliverText($instance, $conversation, $meta, $text, [
            'menu_id' => $option->menu_id,
            'menu_option_id' => $option->id,
            'pending_action' => $option->action_type,
        ]);
    }

    /**
     * Opción marcada como "sin acción": queda registrada la elección y no se
     * manda nada. Sirve para armar el menú antes de decidir qué hará cada
     * opción, y por eso el formulario avisa de que el cliente no recibe nada.
     */
    private function acknowledgeWithoutAction(
        WhatsAppConversation $conversation,
        WhatsAppMenuOption $option
    ): void {
        WhatsAppMenuSession::close($conversation->id);

        Log::channel('whatsapp')->info('🤐 Menú: opción sin acción configurada', [
            'conversation_id' => $conversation->id,
            'menu_option_id' => $option->id,
            'title' => $option->title,
        ]);
    }

    private function replyWithText(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppMenuOption $option,
        MetaWhatsAppService $meta
    ): void {
        // La opción queda consumida pase lo que pase con el envío: dejar la
        // sesión abierta haría que el siguiente "1" del cliente reejecutara esto.
        WhatsAppMenuSession::close($conversation->id);

        $text = $option->menu?->render($option->reply_text, $conversation) ?? (string) $option->reply_text;

        if (trim($text) === '') {
            return;
        }

        $this->deliverText($instance, $conversation, $meta, $text, [
            'menu_id' => $option->menu_id,
            'menu_option_id' => $option->id,
        ]);
    }

    private function openSubmenu(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppMenuOption $option,
        MetaWhatsAppService $meta,
        WhatsAppMenuService $menus
    ): void {
        $target = WhatsAppMenu::with('options')->find($option->target_menu_id);

        // El submenú pudo borrarse o quedarse sin opciones después de
        // configurarlo. Antes que dejar al cliente sin respuesta, se le contesta
        // con el texto de la opción si lo tiene.
        if (!$target || !$target->active || $target->options->isEmpty()) {
            Log::channel('whatsapp')->warning('⚠️ Submenú no disponible', [
                'menu_option_id' => $option->id,
                'target_menu_id' => $option->target_menu_id,
                'conversation_id' => $conversation->id,
            ]);
            $this->replyWithText($instance, $conversation, $option, $meta);
            return;
        }

        $this->sendMenu($instance, $conversation, $target, $meta, $menus);
    }

    /**
     * Pasa el hilo a una persona.
     *
     * Asignarlo es además lo que calla al bot: tanto este job como
     * ProcessAutoResponse se saltan las conversaciones con agente asignado, así
     * que el cliente deja de recibir menús en cuanto pide un asesor.
     */
    private function handoff(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppMenuOption $option,
        MetaWhatsAppService $meta,
        AgentAssignmentService $assignment
    ): void {
        WhatsAppMenuSession::close($conversation->id);

        $agent = $this->claimAgent(
            $instance,
            $conversation,
            $option,
            $option->assignStrategy(),
            'El cliente pidió hablar con un asesor desde el menú',
            $assignment
        );

        $text = $option->menu?->render($option->reply_text, $conversation) ?? (string) $option->reply_text;

        if (trim($text) !== '') {
            $this->deliverText($instance, $conversation, $meta, $text, [
                'menu_id' => $option->menu_id,
                'menu_option_id' => $option->id,
                'assigned_to' => $agent?->id,
            ]);
        }

        Log::channel('whatsapp')->info('🙋 Menú: conversación derivada a un asesor', [
            'conversation_id' => $conversation->id,
            'menu_option_id' => $option->id,
            'strategy' => $option->assignStrategy(),
            'assigned_to' => $agent?->id,
        ]);
    }

    /**
     * Pone el chat en manos de una persona y deja constancia de por qué.
     *
     * La nota de sistema se escribe siempre, esté o no asignado: es lo único
     * que le dice al asesor que abre el chat que el cliente venía del menú y
     * qué pidió. Sin ella, un hilo derivado es indistinguible de uno cualquiera.
     *
     * La asignación es condicional a que el chat siga libre: si un agente se
     * autoasignó mientras el mensaje estaba en la cola, quitárselo para dárselo
     * a otro dejaría a dos personas creyendo que el chat es suyo.
     */
    private function claimAgent(
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        string $strategy,
        string $note,
        AgentAssignmentService $assignment
    ): ?User {
        $agent = match ($strategy) {
            WhatsAppMenuOption::ASSIGN_FIXED => $option?->assignee,
            WhatsAppMenuOption::ASSIGN_LEAST_BUSY => $assignment->leastBusy($instance->company_id),
            default => null,
        };

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type' => 'system',
            'content' => $agent ? $note . ' → ' . $agent->name : $note,
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now(),
            'metadata' => array_filter([
                'menu_id' => $option?->menu_id,
                'menu_option_id' => $option?->id,
                'assign_strategy' => $strategy,
                'assigned_to' => $agent?->id,
            ]),
        ]);

        if ($agent) {
            $claimed = WhatsAppConversation::where('id', $conversation->id)
                ->whereNull('assigned_to')
                ->update(['assigned_to' => $agent->id]);

            if ($claimed) {
                $conversation->refresh();
                Realtime::push(ConversationEvent::updated($conversation, 'assigned'));
            } else {
                $agent = null;
            }
        }

        WebhookDispatcher::emit(
            $instance->company_id,
            'advisor.requested',
            WebhookDispatcher::conversationPayload($conversation, [
                'motivo' => $note,
                'assign_strategy' => $strategy,
                'menu_option_id' => $option?->id,
            ])
        );

        return $agent;
    }

    private function sendMenu(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppMenu $menu,
        MetaWhatsAppService $meta,
        WhatsAppMenuService $menus
    ): void {
        if ($menu->options->isEmpty()) {
            return;
        }

        $result = $meta->sendInteractive(
            $instance->phone_number_id,
            $conversation->recipientId(),
            $menus->buildPayload($menu, $conversation)
        );

        if (!($result['success'] ?? false)) {
            Log::channel('whatsapp')->warning('⚠️ Menú no enviado', [
                'menu_id' => $menu->id,
                'conversation_id' => $conversation->id,
                'error' => $result['error'] ?? null,
            ]);
            return;
        }

        // La sesión se abre nada más confirmar el envío, antes de registrar nada
        // en el chat: el cliente ya tiene el menú en el móvil y puede contestar
        // "1" en cualquier momento. Si esto fuera después y el registro de la
        // burbuja fallara, el sistema no reconocería la respuesta de un menú que
        // el cliente sí está viendo.
        WhatsAppMenuSession::open($conversation->id, $menu);

        $menu->increment('fires_count', 1, ['last_fired_at' => now()]);

        // El agente no ve el menú como lo ve el cliente, así que en el chat se
        // guarda su versión en texto: el cuerpo y las opciones numeradas.
        $summary = $menus->summarize($menu, $conversation);

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => $result['data']['messages'][0]['id'] ?? null,
            'type' => 'text',
            'content' => $summary,
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => [
                'menu_id' => $menu->id,
                'menu_format' => $menu->format(),
            ],
        ]);

        $conversation->update([
            'last_message' => $summary,
            'last_message_at' => now(),
        ]);

        $this->broadcastMessage($message, $instance);

        Log::channel('whatsapp')->info('📋 Menú interactivo enviado', [
            'menu_id' => $menu->id,
            'conversation_id' => $conversation->id,
            'format' => $menu->format(),
        ]);
    }

    /** Texto suelto enviado por el menú (respuesta de una opción). */
    private function deliverText(
        Instance $instance,
        WhatsAppConversation $conversation,
        MetaWhatsAppService $meta,
        string $text,
        array $metadata
    ): void {
        $result = $meta->sendMessage(
            $instance->phone_number_id,
            $conversation->recipientId(),
            $text
        );

        if (!($result['success'] ?? false)) {
            Log::channel('whatsapp')->warning('⚠️ Respuesta de menú no enviada', $metadata + [
                'conversation_id' => $conversation->id,
                'error' => $result['error'] ?? null,
            ]);
            return;
        }

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => $result['data']['messages'][0]['id'] ?? null,
            'type' => 'text',
            'content' => $text,
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => $metadata,
        ]);

        $conversation->update([
            'last_message' => $text,
            'last_message_at' => now(),
        ]);

        $this->broadcastMessage($message, $instance);
    }

    /**
     * El chat abierto del agente debe ver lo que el bot contestó sin esperar al
     * siguiente poll. Si Reverb no responde el mensaje ya está guardado, así que
     * el fallo se registra y no tumba el job.
     */
    private function broadcastMessage(WhatsAppMessage $message, Instance $instance): void
    {
        try {
            broadcast(new \App\Events\WhatsAppMessageEvent($message->load('sender'), $instance->id, 'new'));
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo emitir el mensaje del menú en tiempo real', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
