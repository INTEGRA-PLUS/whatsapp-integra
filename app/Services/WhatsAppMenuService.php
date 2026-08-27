<?php

namespace App\Services;

use App\Jobs\ProcessWhatsAppMenu;
use App\Models\Instance;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMenuSession;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;

/**
 * Decide qué hacer con un mensaje entrante respecto a los menús interactivos.
 *
 * La decisión se toma aquí, en caliente dentro del webhook, y sólo el envío se
 * va a la cola: el webhook necesita saber YA si el menú se hace cargo del
 * mensaje, porque si no lo sabe despacharía además la respuesta automática y el
 * cliente recibiría dos contestaciones al mismo mensaje.
 */
class WhatsAppMenuService
{
    /**
     * @param array $messageData El mensaje ya normalizado por el webhook
     *                           (content + metadata), no el payload crudo.
     * @return bool true si el menú se hace cargo y nadie más debe responder.
     */
    public function handleInbound(
        Instance $instance,
        WhatsAppConversation $conversation,
        array $messageData,
        string $wamid
    ): bool {
        // Con un agente encima o el hilo cerrado, el bot se calla: nada peor que
        // un menú interrumpiendo una conversación que ya está atendiendo alguien.
        if ($conversation->assigned_to !== null || $conversation->status === 'closed') {
            return false;
        }

        // 1. ¿Es la respuesta a un menú que ya mandamos?
        if ($selection = $this->resolveSelection($conversation, $messageData)) {
            ProcessWhatsAppMenu::dispatch($instance->id, $conversation->id, null, $selection->id, $wamid);
            return true;
        }

        // Un toque sobre un menú nuestro que ya no existe se da por atendido de
        // todos modos. Reevaluar disparadores aquí reenviaría el menú en bucle:
        // el texto que llega al tocar es el título de la opción, y ese título
        // puede contener justamente la palabra clave que dispara el menú.
        if ($this->isOwnMenuReply($messageData)) {
            Log::channel('whatsapp')->info('ℹ️ Respuesta a una opción de menú que ya no existe', [
                'conversation_id' => $conversation->id,
                'payload_id' => $this->replyPayloadId($messageData),
            ]);
            return true;
        }

        // 2. ¿El bot le había preguntado algo y esto es la respuesta?
        //
        // Va antes de los disparadores a propósito: quien contesta "no tengo
        // internet desde ayer" a nuestra pregunta está describiendo su falla,
        // no pidiendo el menú, y reenviárselo aquí perdería lo que escribió.
        if ($this->isAwaitingAnswer($conversation, $messageData)) {
            ProcessWhatsAppMenu::dispatch(
                $instance->id,
                $conversation->id,
                null,
                null,
                $wamid,
                (string) ($messageData['content'] ?? '')
            );
            return true;
        }

        // 3. ¿Algún menú se dispara con este mensaje?
        $menu = $this->findTriggeredMenu($instance, $conversation, (string) ($messageData['content'] ?? ''), $wamid);

        if (!$menu) {
            return false;
        }

        if ($this->isInCooldown($menu, $conversation)) {
            Log::channel('whatsapp')->info('⏭️ Menú omitido: en cooldown', [
                'menu_id' => $menu->id,
                'conversation_id' => $conversation->id,
            ]);
            return false;
        }

        ProcessWhatsAppMenu::dispatch($instance->id, $conversation->id, $menu->id, null, $wamid);

        return true;
    }

    /**
     * Qué opción eligió el cliente, si es que eligió alguna.
     *
     * Dos caminos: el toque en el botón —que devuelve nuestro propio id— y el
     * cliente que escribe en vez de tocar. Este segundo caso es el que salva al
     * menú de ser inútil: mucha gente responde "1" o copia el título.
     */
    public function resolveSelection(WhatsAppConversation $conversation, array $messageData): ?WhatsAppMenuOption
    {
        if ($parsed = WhatsAppMenuOption::parsePayloadId($this->replyPayloadId($messageData))) {
            return WhatsAppMenuOption::with('menu')->find($parsed['option_id']);
        }

        return $this->resolveTypedSelection($conversation, (string) ($messageData['content'] ?? ''));
    }

    /**
     * El cliente escribió en vez de tocar. Sólo se interpreta mientras el menú
     * siga en pie: pasada la hora, "1" vuelve a ser un mensaje normal y no la
     * primera opción de un menú que el cliente ya ni recuerda.
     */
    private function resolveTypedSelection(WhatsAppConversation $conversation, string $text): ?WhatsAppMenuOption
    {
        $session = WhatsAppMenuSession::with('menu.options')
            ->where('conversation_id', $conversation->id)
            ->first();

        if (!$session) {
            return null;
        }

        if ($session->isExpired()) {
            WhatsAppMenuSession::close($conversation->id);
            return null;
        }

        $needle = WhatsAppMenu::normalizeForMatch($text);

        if ($needle === '' || !$session->menu) {
            return null;
        }

        $options = $session->menu->options;

        // "2", "2." o "2)" — la numeración que el cliente ve en el listado.
        if (preg_match('/^(\d{1,2})[\.\)]?$/', $needle, $m)) {
            $index = (int) $m[1] - 1;

            if ($index >= 0 && $index < $options->count()) {
                return $options[$index];
            }
        }

        // O el título copiado tal cual, con o sin el emoji que lo acompañe.
        foreach ($options as $option) {
            if (WhatsAppMenu::normalizeForMatch($option->title) === $needle) {
                return $option;
            }
        }

        return null;
    }

    /**
     * El menú que responde a este mensaje, o null.
     *
     * Sólo entran los menús raíz: un submenú se alcanza tocando la opción del
     * menú que lo contiene, nunca por su cuenta.
     */
    public function findTriggeredMenu(
        Instance $instance,
        WhatsAppConversation $conversation,
        string $text,
        string $wamid
    ): ?WhatsAppMenu {
        $context = ['is_first_inbound' => $this->isFirstInbound($conversation, $wamid)];

        return WhatsAppMenu::active()
            ->root()
            ->where('company_id', $instance->company_id)
            ->where(function ($q) use ($instance) {
                $q->whereNull('instance_id')->orWhere('instance_id', $instance->id);
            })
            ->has('options')
            ->with('options')
            ->get()
            // El menú atado a esta instancia gana al genérico; entre iguales,
            // la palabra clave gana a la bienvenida y el más reciente al resto.
            ->sort(fn (WhatsAppMenu $a, WhatsAppMenu $b) =>
                [$a->instance_id === null ? 1 : 0, $a->priority(), -$a->created_at->timestamp]
                <=>
                [$b->instance_id === null ? 1 : 0, $b->priority(), -$b->created_at->timestamp]
            )
            ->values()
            ->first(fn (WhatsAppMenu $m) => $m->qualifies($text, $context));
    }

    /**
     * Traduce un menú al bloque `interactive` de la Cloud API.
     *
     * El formato no lo elige el admin: hasta 3 opciones salen como botones y a
     * partir de 4 como lista, porque son los únicos dos tipos que Meta acepta y
     * cada uno tiene su propio tope. Los títulos se recortan aquí —20 caracteres
     * en botón, 24 en fila— para que un menú al que le añadieron una cuarta
     * opción no empiece a fallar con un 400 de Meta.
     */
    public function buildPayload(WhatsAppMenu $menu, WhatsAppConversation $conversation): array
    {
        $interactive = [
            'body' => ['text' => mb_substr($menu->renderBody($conversation), 0, WhatsAppMenu::MAX_BODY)],
        ];

        if (filled($menu->header_text)) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => mb_substr($menu->render($menu->header_text, $conversation), 0, WhatsAppMenu::MAX_HEADER),
            ];
        }

        if (filled($menu->footer_text)) {
            $interactive['footer'] = [
                'text' => mb_substr($menu->render($menu->footer_text, $conversation), 0, WhatsAppMenu::MAX_FOOTER),
            ];
        }

        $options = $menu->options->take(WhatsAppMenu::MAX_ROWS);

        if ($menu->format() === 'button') {
            $interactive['type'] = 'button';
            $interactive['action'] = [
                'buttons' => $options->map(fn (WhatsAppMenuOption $o) => [
                    'type' => 'reply',
                    'reply' => [
                        'id' => $o->payloadId(),
                        'title' => mb_substr($o->title, 0, WhatsAppMenu::MAX_BUTTON_TITLE),
                    ],
                ])->values()->all(),
            ];

            return $interactive;
        }

        $interactive['type'] = 'list';
        $interactive['action'] = [
            'button' => mb_substr($menu->list_button_text ?: 'Ver opciones', 0, WhatsAppMenu::MAX_BUTTON_TITLE),
            'sections' => [[
                'title' => 'Opciones',
                'rows' => $options->map(function (WhatsAppMenuOption $o) {
                    $row = [
                        'id' => $o->payloadId(),
                        'title' => mb_substr($o->title, 0, WhatsAppMenu::MAX_ROW_TITLE),
                    ];

                    if (filled($o->description)) {
                        $row['description'] = mb_substr($o->description, 0, WhatsAppMenu::MAX_ROW_DESCRIPTION);
                    }

                    return $row;
                })->values()->all(),
            ]],
        ];

        return $interactive;
    }

    /**
     * Resumen legible del menú para la burbuja del chat y para `last_message`.
     * En el panel del agente no se ve el menú tal cual lo ve el cliente, así que
     * sin esto la conversación mostraría una burbuja vacía.
     */
    public function summarize(WhatsAppMenu $menu, WhatsAppConversation $conversation): string
    {
        $lines = [$menu->renderBody($conversation)];

        foreach ($menu->options->values() as $i => $option) {
            $lines[] = ($i + 1) . '. ' . $option->title;
        }

        return implode("\n", $lines);
    }

    /**
     * ¿Hay una pregunta del bot esperando respuesta en esta conversación?
     *
     * Sólo cuenta el texto: una foto o un audio no responden a "dime tu
     * cédula", y tratarlos como respuesta gastaría uno de los intentos del
     * cliente con algo que nunca íbamos a poder leer.
     */
    private function isAwaitingAnswer(WhatsAppConversation $conversation, array $messageData): bool
    {
        if (trim((string) ($messageData['content'] ?? '')) === '') {
            return false;
        }

        return WhatsAppBotFlow::activeFor($conversation->id) !== null;
    }

    private function isFirstInbound(WhatsAppConversation $conversation, string $wamid): bool
    {
        $first = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderBy('id')
            ->first();

        return $first !== null && $first->wamid === $wamid;
    }

    /** ¿El mensaje entrante es el toque sobre un botón o fila de un menú nuestro? */
    private function isOwnMenuReply(array $messageData): bool
    {
        return WhatsAppMenuOption::parsePayloadId($this->replyPayloadId($messageData)) !== null;
    }

    /**
     * Id de la opción tocada. Botón y fila lo traen en claves distintas, y por
     * el mismo sitio llegan los botones de plantilla, que no son de este módulo.
     */
    private function replyPayloadId(array $messageData): ?string
    {
        $interactive = $messageData['metadata']['interactive'] ?? [];

        return $interactive['button_reply']['id']
            ?? $interactive['list_reply']['id']
            ?? $messageData['metadata']['button']['payload']
            ?? null;
    }

    private function isInCooldown(WhatsAppMenu $menu, WhatsAppConversation $conversation): bool
    {
        $minutes = $menu->cooldown_minutes ?? 0;

        if ($minutes <= 0) {
            return false;
        }

        return WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('sent_at', '>=', now()->subMinutes($minutes))
            ->where('metadata->menu_id', $menu->id)
            ->exists();
    }
}
