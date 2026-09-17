<?php

namespace App\Services;

use App\Jobs\ProcessWhatsAppAi;
use App\Jobs\ProcessWhatsAppChatAi;
use App\Jobs\ProcessWhatsAppMenu;
use App\Models\Instance;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMenuSession;
use App\Models\WhatsAppMessage;
use App\Support\AiAssistantProfile;
use App\Support\Documentos\DocumentoDelCliente;
use App\Support\Documentos\ImagenDelCliente;
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
        string $wamid,
        // Este mensaje reabrió un chat que estaba cerrado. Lo sabe el webhook y
        // sólo él: para cuando llegamos aquí la conversación ya está en «open» y
        // el rastro del cierre está borrado, así que no hay forma de deducirlo.
        bool $reabierta = false
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
        if ($flow = $this->awaitingAnswer($conversation, $messageData)) {
            $text = (string) ($messageData['content'] ?? '');

            // La respuesta vuelve a quien preguntó. Si preguntó la IA, mandarla
            // al servicio de acciones del menú sería silencio: para él "ia" es
            // una acción desconocida y el cliente se quedaría hablando solo.
            if ($flow->action_type === WhatsAppBotFlow::ACTION_AI) {
                $this->askAi($instance, $conversation, $text, true);
            } else {
                ProcessWhatsAppMenu::dispatch($instance->id, $conversation->id, null, null, $wamid, $text);
            }

            return true;
        }

        // 3. ¿Algún menú se dispara con este mensaje?
        $menu = $this->findTriggeredMenu($instance, $conversation, (string) ($messageData['content'] ?? ''), $wamid, $reabierta);

        if (!$menu) {
            // 4. Nadie reconoció el mensaje. Es el caso más común y el que peor
            // quedaba: el cliente que escribe "no me funciona el internet desde
            // ayer" no usa ninguna palabra clave, así que ningún menú se dispara
            // y su problema acaba en una respuesta automática genérica.
            //
            // Aquí es donde entra la IA: entiende la petición y ejecuta la
            // acción que corresponde. Va en último lugar a propósito —los
            // disparadores que el admin configuró mandan sobre ella— y sólo si
            // la empresa la encendió.
            return $this->handOverToAi($instance, $conversation, $messageData, $wamid);
        }

        if ($this->isInCooldown($menu, $conversation, $reabierta)) {
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
        string $wamid,
        bool $reabierta = false
    ): ?WhatsAppMenu {
        // El corte lo pone cada menú, así que se calcula por menú y no una
        // vez para todos: dos menús de la misma empresa pueden querer saludar
        // con ritmos distintos.
        $silencio = $this->horasDeSilencio($conversation, $wamid);

        return WhatsAppMenu::active()
            ->root()
            ->where('company_id', $instance->company_id)
            ->where(function ($q) use ($instance) {
                $q->whereNull('instance_id')->orWhere('instance_id', $instance->id);
            })
            ->has('options')
            ->with('options')
            ->get()
            // El orden vive en el modelo: la pantalla de menús lo necesita para
            // decir cuál responde, y dos copias de esta regla acabarían
            // diciendo cosas distintas.
            ->sort(WhatsAppMenu::ordenDeDisparo(...))
            ->values()
            ->first(fn (WhatsAppMenu $m) => $m->qualifies($text, [
                'is_first_inbound' => $m->tocaSaludar($silencio, $reabierta),
            ]));
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
     * El flujo abierto que este mensaje contesta, si lo hay.
     *
     * Sólo cuenta el texto: una foto o un audio no responden a "dime tu
     * cédula", y tratarlos como respuesta gastaría uno de los intentos del
     * cliente con algo que nunca íbamos a poder leer.
     *
     * Devuelve el flujo y no un booleano porque quien llama necesita saber
     * quién hizo la pregunta —una opción del menú o la IA— para devolverle la
     * respuesta al sitio correcto.
     */
    private function awaitingAnswer(WhatsAppConversation $conversation, array $messageData): ?WhatsAppBotFlow
    {
        if (trim((string) ($messageData['content'] ?? '')) === '') {
            return null;
        }

        return WhatsAppBotFlow::activeFor($conversation->id);
    }

    /**
     * Le pasa el mensaje a la IA, si la empresa la tiene lista.
     *
     * Texto, y **documentos si la empresa lo encendió**: un PDF, un Word o un
     * Excel se leen y su contenido viaja como parte del mensaje. Es barato
     * porque la tubería de extracción ya existe para los documentos que sube la
     * empresa.
     *
     * Un audio o una imagen siguen sin entrar: son otros modelos y otro coste.
     * Hacerse cargo de ellos para no contestar nada sería peor que dejarlos
     * seguir su camino — al menos así la respuesta automática o un agente los
     * atienden.
     *
     * @return bool true si la IA se hace cargo y nadie más debe responder.
     */
    private function handOverToAi(
        Instance $instance,
        WhatsAppConversation $conversation,
        array $messageData,
        string $wamid = ''
    ): bool {
        $text = trim((string) ($messageData['content'] ?? ''));

        // Un archivo que se sabe leer vale como mensaje. El texto de dentro NO
        // se saca aquí: esto corre dentro del webhook de Meta, que es
        // sincrónico y se reintenta si tarda. Aquí sólo se decide; leerlo es
        // cosa del job.
        $conDocumento = DocumentoDelCliente::esLegible($messageData)
            && AiAssistantProfile::leeDocumentos($instance->company_id);

        $conImagen = ImagenDelCliente::esLegible($messageData)
            && AiAssistantProfile::veImagenes($instance->company_id);

        if ($text === '' && ! $conDocumento && ! $conImagen) {
            return false;
        }

        // Con un archivo o una foto delante va el chat IA y no la de menús:
        // aquélla resuelve peticiones concretas contra Integra —una factura,
        // una falla— y un PDF o un comprobante no son ninguna de ésas.
        if ($conDocumento || $conImagen) {
            return $this->askChatAi($instance, $conversation, $text, $wamid, $messageData);
        }

        // La IA de los menús va primero: es la que sabe ejecutar acciones
        // contra Integra —consultar una factura, radicar una falla— y devuelve
        // una decisión que este sistema ya sabe ejecutar.
        if (WhatsAppAiClient::enabled($instance->company_id)) {
            $this->askAi($instance, $conversation, $text);

            Log::channel('whatsapp')->info('🤖 Mensaje sin menú que lo reconozca: va a la IA', [
                'conversation_id' => $conversation->id,
                'company_id' => $instance->company_id,
            ]);

            return true;
        }

        // Son dos funcionalidades distintas y una puede estar encendida sin la
        // otra: si la empresa no tiene la IA de menús, el chat IA atiende igual.
        return $this->askChatAi($instance, $conversation, $text, $wamid);
    }

    /**
     * Le pasa el mensaje al flujo de IA de los chats.
     *
     * Es un proceso aparte del de menús: aquél resuelve peticiones concretas
     * contra Integra y contesta en la misma llamada; éste conversa, y su
     * respuesta vuelve minutos después por el callback. Por eso no compiten
     * —se intenta primero el que puede resolver— y por eso no comparten job.
     */
    private function askChatAi(
        Instance $instance,
        WhatsAppConversation $conversation,
        string $text,
        string $wamid,
        array $documento = []
    ): bool {
        // Sin wamid no hay forma de casar la respuesta con la conversación
        // cuando el flujo la devuelva: preguntar sería tirar la respuesta.
        if ($wamid === '' || !WhatsAppChatAiClient::enabledFor($instance->company_id)) {
            return false;
        }

        ProcessWhatsAppChatAi::dispatch($instance->id, $conversation->id, $text, $wamid, $documento);

        Log::channel('whatsapp')->info('💬 Mensaje sin menú que lo reconozca: va al chat IA', [
            'conversation_id' => $conversation->id,
            'company_id' => $instance->company_id,
        ]);

        return true;
    }

    /**
     * Encola la pregunta al modelo con un margen para que el cliente termine.
     *
     * El retardo es el arreglo barato de un patrón muy común: "hola", "no
     * tengo internet", "desde ayer" en cinco segundos son tres mensajes. Sin
     * margen cada uno se lleva su inferencia; con él llegan los tres juntos y
     * el job los junta en una sola pregunta. El candado del propio job es el
     * que garantiza que no se solapen aunque el margen no alcance.
     */
    private function askAi(
        Instance $instance,
        WhatsAppConversation $conversation,
        string $text,
        bool $isFlowAnswer = false
    ): void {
        ProcessWhatsAppAi::dispatch($instance->id, $conversation->id, $text, $isFlowAnswer)
            ->delay(now()->addSeconds(ProcessWhatsAppAi::debounceSeconds()));
    }

    /**
     * ¿Toca saludar con el menú de bienvenida?
     *
     * Antes era literal: **el primer mensaje entrante de esa conversación,
     * contando desde siempre**. Un cliente que escribió una vez hace meses no
     * volvía a recibir el saludo jamás, y probarlo con el propio número era
     * imposible en cuanto lo habías usado una vez — la causa número uno de
     * «configuré el menú de bienvenida y no salta».
     *
     * Ahora también saluda a quien **vuelve después de un silencio largo**, que
     * es cuando una persona espera que la saluden otra vez. El corte son 24 h,
     * el mismo que usa Meta para la ventana de atención: si lleva un día sin
     * escribir, esto es una conversación nueva a todos los efectos.
     *
     * La marca de tiempo sale de `COALESCE(sent_at, created_at)` y no de
     * `created_at` a secas: cuando Meta reintenta durante días y suelta la cola
     * de golpe, `created_at` es de hoy y `sent_at` de hace tres, y mirar sólo el
     * primero saludaría a alguien a mitad de conversación.
     *
     * Devuelve `null` si es su primerísimo mensaje —no hay silencio que medir,
     * y ese caso saluda siempre—; si no, las horas que llevaba callado.
     */
    private function horasDeSilencio(WhatsAppConversation $conversation, string $wamid): ?float
    {
        $entrantes = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderBy('id')
            ->get(['id', 'wamid', 'sent_at', 'created_at']);

        $actual = $entrantes->firstWhere('wamid', $wamid);

        // Sin el mensaje delante no se puede afirmar que sea un saludo nuevo, y
        // ante la duda no se saluda: reenviar el menú a mitad de conversación es
        // peor que no mandarlo.
        if ($actual === null) {
            return 0.0;
        }

        $anterior = $entrantes->filter(fn ($m) => $m->id < $actual->id)->last();

        // Su primerísimo mensaje: el caso de siempre, y saluda pase lo que pase.
        if ($anterior === null) {
            return null;
        }

        $antes = $anterior->sent_at ?? $anterior->created_at;
        $ahora = $actual->sent_at ?? $actual->created_at;

        if ($antes === null || $ahora === null) {
            return 0.0;
        }

        return (float) $antes->diffInHours($ahora, absolute: true);
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

    private function isInCooldown(
        WhatsAppMenu $menu,
        WhatsAppConversation $conversation,
        bool $reabierta = false
    ): bool {
        // Una conversación que se cerró y el cliente reabre escribiendo empieza
        // de cero: la espera existe para no repetir el menú si escribe tres
        // veces seguidas, no para castigar a quien vuelve al rato.
        //
        // Sin esto, el saludo de la reapertura se anunciaba y no salía —«el
        // menú se disparó pero está en cooldown»— y quedaba imposible de
        // probar: cerrar y reabrir es justo lo que uno hace para probarlo.
        if ($reabierta) {
            return false;
        }

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
