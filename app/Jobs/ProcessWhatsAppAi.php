<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppAiClient;
use App\Services\WhatsAppChatAiClient;
use App\Support\AiDecision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Le pregunta al flujo de IA qué hacer con un mensaje y manda a ejecutar la
 * respuesta.
 *
 * Va en cola y no en el webhook porque la inferencia local tarda segundos y el
 * webhook de Meta es sincrónico: si tarda, Meta reintenta y el cliente acaba
 * con la misma respuesta dos veces.
 *
 * Este job **no envía nada**. Cuando la IA decide, delega en
 * ProcessWhatsAppMenu, que es el único sitio que habla con Meta, registra la
 * burbuja, abre el flujo pendiente y asigna al asesor. Dos saltos de cola en
 * vez de uno, y a cambio las comprobaciones (agente asignado, hilo cerrado,
 * ventana de 24 h) se reevalúan DESPUÉS de la espera del modelo, que es cuando
 * de verdad importan: en esos segundos un agente pudo haber tomado el chat.
 */
class ProcessWhatsAppAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Una sola pasada, nunca reintentos.
     *
     * Reintentar aquí no es gratis: la inferencia es un POST al flujo, y el
     * flujo crea radicados en Integra y pide cobros. Un segundo intento no
     * repite una lectura, repite esos efectos —hasta tres radicados por una
     * misma avería—, y no hay forma de saber desde aquí si el primero llegó a
     * ejecutarse antes de que el worker lo matara. Cuando falla, el mensaje
     * queda guardado y un agente lo ve en su bandeja, que es lo que ya hace
     * cuando la IA no se hace cargo.
     */
    public int $tries = 1;

    /**
     * Cuánto puede durar el job, que es casi enteramente la espera del modelo.
     *
     * Sale del mismo sitio que el timeout del cliente HTTP más margen para lo
     * que hay alrededor. Declararlo importa: sin él manda el `--timeout` del
     * worker (90 s), la mitad de lo que el propio flujo se permite tardar, así
     * que un modelo grande moría siempre a mitad de inferencia.
     *
     * Tiene que ser MENOR que el `retry_after` de la conexión de colas (ver
     * config/queue.php) o la cola libera el job para otro worker mientras el
     * primero sigue trabajando.
     */
    public int $timeout;

    /**
     * @param bool $isFlowAnswer El cliente está contestando a una pregunta que
     *                           hizo la IA. Cambia qué se le manda al flujo
     *                           (el paso pendiente y lo que ya sabíamos) y hace
     *                           que se descarte si esa pregunta ya caducó.
     * @param ?int $afterMessageId Sólo lo pone este mismo job al reencolarse:
     *                           el último mensaje que ya se atendió, para que
     *                           el relevo no vuelva a contestar lo mismo.
     */
    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public string $message,
        public bool $isFlowAnswer = false,
        public ?int $afterMessageId = null
    ) {
        $this->timeout = (int) config('services.ai_menus.timeout', 180) + 30;
    }

    public function handle(WhatsAppAiClient $ai): void
    {
        // Un cliente escribe "hola", "no tengo internet" y "desde ayer" en
        // cinco segundos: son tres jobs, y sin candado son tres inferencias en
        // paralelo y tres respuestas. El contador de turnos no protege de esto
        // —cuenta mensajes ya enviados, y a esa altura no hay ninguno—, así que
        // el candado es lo único que lo impide.
        //
        // Con TTL y no indefinido: si el worker muere a mitad de inferencia, el
        // `finally` no llega a correr y un candado eterno dejaría el hilo sin
        // IA para siempre.
        $lock = Cache::lock($this->lockKey(), $this->timeout + 30);

        if (! $lock->get()) {
            Log::channel('whatsapp')->info('⏭️ IA omitida: ya hay una inferencia en curso para este hilo', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        try {
            $handledUpTo = $this->answer($ai);
        } finally {
            $lock->release();
        }

        // El candado ya está suelto: si el cliente siguió escribiendo mientras
        // el modelo pensaba, esos mensajes se descartaron al no poder tomarlo.
        // Sin este relevo se quedarían sin contestar, que es peor que la
        // respuesta duplicada que el candado vino a evitar.
        $this->relayIfClientKeptWriting($handledUpTo);
    }

    /**
     * La pasada de verdad. Devuelve hasta qué mensaje del cliente se atendió.
     */
    private function answer(WhatsAppAiClient $ai): ?int
    {
        $instance = Instance::find($this->instanceId);
        $conversation = WhatsAppConversation::find($this->conversationId);

        if (! $instance || ! $conversation || ! $instance->active) {
            return null;
        }

        // Se comprueba antes de gastar la inferencia; ProcessWhatsAppMenu lo
        // vuelve a comprobar después, por si cambia mientras el modelo piensa.
        if ($conversation->assigned_to !== null || $conversation->status === 'closed') {
            return null;
        }

        if (! $conversation->isWindowOpen()) {
            Log::channel('whatsapp')->info('⏭️ IA omitida: ventana de 24h cerrada', [
                'conversation_id' => $conversation->id,
            ]);
            return null;
        }

        $flow = $this->isFlowAnswer ? WhatsAppBotFlow::activeFor($conversation->id) : null;

        // La pregunta caducó entre el webhook y la cola. Contestar ahora sería
        // retomar una conversación que el cliente ya dio por perdida.
        if ($this->isFlowAnswer && ! $flow) {
            return null;
        }

        [$message, $handledUpTo] = $this->pending($conversation);

        // El cliente no tiene nada sin contestar. Pasa cuando el relevo y el
        // job del webhook apuntan al mismo mensaje y uno de los dos llega
        // tarde: sin esto, el segundo lo contestaría por segunda vez, que es
        // justo lo que el candado vino a evitar.
        if ($message === null) {
            Log::channel('whatsapp')->info('⏭️ IA omitida: no hay nada del cliente sin contestar', [
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        $decision = $ai->ask($instance, $conversation, $message, $flow);

        // La IA no se hizo cargo (apagada, sin turnos, flujo caído). No se
        // contesta nada: el mensaje ya quedó guardado y un agente lo verá en su
        // bandeja, que es preferible a improvisar una respuesta aquí.
        if ($decision === null) {
            Log::channel('whatsapp')->info('ℹ️ La IA no resolvió el mensaje', [
                'conversation_id' => $conversation->id,
            ]);

            // El chat IA es otra funcionalidad, no un reintento de ésta: la de
            // menús resuelve peticiones concretas y dice "no me hago cargo"
            // cuando el cliente no está pidiendo ninguna. Justo ahí es donde
            // conversar tiene sentido. Si tampoco está, el mensaje queda para
            // un agente, como antes.
            $this->handOverToChatAi($instance, $conversation, $message, $handledUpTo);

            return $handledUpTo;
        }

        // Quién es el cliente se guarda aquí y no en el job que envía: es un
        // hecho que el flujo averiguó, y vale igual aunque un agente tome el
        // chat antes de que salga la respuesta —de hecho ahí vale más, porque
        // el agente abre el hilo sabiendo con quién habla.
        if ($decision->identifiedClient()) {
            $conversation->rememberIntegraClient(
                $decision->client['id'] ?? null,
                $decision->client['identificacion'] ?? null,
                $decision->client['nombre'] ?? null
            );
        }

        Log::channel('whatsapp')->info('🤖 IA resolvió el mensaje', [
            'conversation_id' => $conversation->id,
            'intencion' => data_get($decision->meta, 'intencion'),
            'confianza' => data_get($decision->meta, 'confianza'),
            'redactor' => data_get($decision->meta, 'redactor'),
            'degradacion' => data_get($decision->meta, 'degradacion'),
            'evento' => $decision->event,
            'ms' => data_get($decision->meta, 'uso.planificador.ms'),
        ]);

        ProcessWhatsAppMenu::dispatch(
            $instance->id,
            $conversation->id,
            null,
            null,
            '',
            null,
            $decision
        );

        return $handledUpTo;
    }

    /**
     * Todo lo que el cliente lleva escrito sin respuesta, en un solo texto.
     *
     * El mensaje que disparó el job no basta: mientras esperaba en la cola —y
     * durante el pequeño retardo que se le pone a propósito para dejar que el
     * cliente termine de escribir— pueden haber llegado dos o tres más. Con
     * sólo el primero, la IA contestaría a "hola" e ignoraría el "no tengo
     * internet" que venía detrás.
     *
     * @return array{0: ?string, 1: ?int} el texto y el id del último mensaje
     *         incluido. El texto es null cuando no hay nada sin contestar.
     */
    private function pending(WhatsAppConversation $conversation): array
    {
        // Desde la última vez que el bot o un agente dijo algo: lo anterior ya
        // tuvo respuesta. `afterMessageId` sólo aparece en el relevo, donde la
        // respuesta todavía no ha salido y el corte lo marca lo ya atendido.
        $floor = max(
            (int) WhatsAppMessage::where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')
                ->max('id'),
            (int) $this->afterMessageId
        );

        $rows = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->whereIn('type', ['text', 'interactive'])
            ->when($floor > 0, fn ($q) => $q->where('id', '>', $floor))
            ->orderByDesc('id')
            ->limit(self::COALESCE)
            ->get(['id', 'content'])
            ->reverse()
            ->values();

        $texts = $rows
            ->map(fn (WhatsAppMessage $m) => trim((string) $m->content))
            ->filter()
            ->all();

        if ($texts === []) {
            // Ya hubo una respuesta en este hilo y no queda nada del cliente
            // después: no hay nada que preguntar.
            if ($floor > 0) {
                return [null, null];
            }

            // Hilo sin ninguna respuesta todavía y sin filas que juntar: se
            // contesta al mensaje con el que se despachó. Es el seguro por si
            // alguna vez se despachara antes de guardar la fila.
            return [$this->message, null];
        }

        return [mb_substr(implode("\n", $texts), 0, self::MAX_CHARS), $rows->last()?->id];
    }

    /**
     * Reencola si el cliente escribió mientras el modelo pensaba.
     *
     * Vuelve por la IA y no por el camino normal de menús a propósito: el
     * cliente está a media conversación con ella, y mandarle ahora un menú
     * porque su mensaje contenía una palabra clave sería cortarle la frase. El
     * bucle lo cierra el flujo, que deja de hacerse cargo pasados los turnos.
     */
    private function relayIfClientKeptWriting(?int $handledUpTo): void
    {
        if ($handledUpTo === null) {
            return;
        }

        $newer = WhatsAppMessage::where('conversation_id', $this->conversationId)
            ->where('direction', 'inbound')
            ->whereIn('type', ['text', 'interactive'])
            ->where('id', '>', $handledUpTo)
            ->orderByDesc('id')
            ->first(['id', 'content']);

        if (! $newer || trim((string) $newer->content) === '') {
            return;
        }

        Log::channel('whatsapp')->info('↩️ El cliente siguió escribiendo durante la inferencia; se reencola', [
            'conversation_id' => $this->conversationId,
            'desde_mensaje' => $handledUpTo,
        ]);

        self::dispatch(
            $this->instanceId,
            $this->conversationId,
            (string) $newer->content,
            // La pregunta que hubiera pendiente ya la contestó la pasada
            // anterior: esto es un mensaje nuevo, no la respuesta a nada.
            false,
            $handledUpTo
        )->delay(now()->addSeconds(self::debounceSeconds()));
    }

    /**
     * Le pasa el turno al flujo de chats cuando la IA de menús no se hizo cargo.
     *
     * Necesita el wamid del último mensaje del cliente porque el flujo de chats
     * devuelve su respuesta por un callback que llega desnudo: ese id es lo
     * único que permite saber a qué conversación pertenece.
     */
    private function handOverToChatAi(
        Instance $instance,
        WhatsAppConversation $conversation,
        string $message,
        ?int $upTo
    ): void {
        if (! WhatsAppChatAiClient::enabledFor($instance->company_id)) {
            return;
        }

        $wamid = (string) ($upTo !== null
            ? WhatsAppMessage::where('id', $upTo)->value('wamid')
            : WhatsAppMessage::where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->orderByDesc('id')
                ->value('wamid'));

        if ($wamid === '') {
            return;
        }

        Log::channel('whatsapp')->info('💬 La IA de menús no se hizo cargo: pasa al chat IA', [
            'conversation_id' => $conversation->id,
        ]);

        ProcessWhatsAppChatAi::dispatch($instance->id, $conversation->id, $message, $wamid);
    }

    private function lockKey(): string
    {
        return 'whatsapp-ai:conversation:' . $this->conversationId;
    }

    /**
     * Cuánto se espera antes de preguntarle al modelo.
     *
     * Es el margen para que el cliente termine de escribir. Sin él, cada
     * mensaje suelto de una misma frase se lleva su propia inferencia.
     */
    public static function debounceSeconds(): int
    {
        return max(0, (int) config('services.ai_menus.debounce', 6));
    }

    /** Cuántos mensajes seguidos del cliente se juntan en una sola pregunta. */
    private const COALESCE = 5;

    /** Tope del texto que se junta. El flujo recorta en 2000 de todos modos. */
    private const MAX_CHARS = 2000;
}
