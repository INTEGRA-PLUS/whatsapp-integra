<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppChatAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Le entrega un mensaje al flujo de IA de los chats.
 *
 * Va en cola porque el gateway espera al modelo antes de responder, y quien lo
 * dispara es el webhook de Meta, que es sincrónico: esperar ahí acaba en un
 * reintento de Meta y en el mismo mensaje entrando dos veces.
 *
 * Este job no envía nada: delega en ProcessWhatsAppMenu, que es el único sitio
 * que habla con Meta. Así las comprobaciones —agente asignado, hilo cerrado,
 * ventana de 24 h— se vuelven a evaluar DESPUÉS de la espera del modelo, que es
 * cuando de verdad importan.
 *
 * Sin reintentos: el gateway deduplica por `message_id`, así que un segundo
 * intento no daría una segunda respuesta, y si el primero no llegó el mensaje
 * queda en la bandeja de un agente.
 */
class ProcessWhatsAppChatAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Casi todo es la espera del modelo. Sin declararlo manda el `--timeout`
     * del worker (90 s) y una inferencia larga moriría siempre a mitad.
     * Tiene que ser MENOR que el `retry_after` de la cola.
     */
    public int $timeout;

    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public string $message,
        public string $wamid
    ) {
        $this->timeout = (int) config('services.ai_chat.timeout', 180) + 30;
    }

    public function handle(WhatsAppChatAiClient $ai): void
    {
        $instance = Instance::find($this->instanceId);
        $conversation = WhatsAppConversation::find($this->conversationId);

        if (! $instance || ! $conversation || ! $instance->active) {
            return;
        }

        // Un agente pudo tomar el chat mientras esto esperaba en la cola.
        if ($conversation->assigned_to !== null || $conversation->status === 'closed') {
            return;
        }

        // La respuesta va a tardar todavía más que esto: si la ventana ya está
        // al límite, preguntar es gastar una inferencia que no se podrá enviar.
        if (! $conversation->isWindowOpen()) {
            Log::channel('whatsapp')->info('⏭️ Chat IA omitido: ventana de 24h cerrada', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $decision = $ai->ask($instance, $conversation, $this->message, $this->wamid);

        // El gateway no se hizo cargo (rechazó, no respondió, vino vacío). El
        // turno vuelve a la respuesta automática: el webhook se la saltó dando
        // por hecho que de este mensaje contestaba la IA, así que callarse aquí
        // deja al cliente sin nada.
        if ($decision === null) {
            $this->fallBackToAutoResponse($instance, $conversation);

            return;
        }

        ProcessWhatsAppMenu::dispatch(
            $instance->id,
            $conversation->id,
            null,
            null,
            '',
            null,
            $decision
        );
    }

    /**
     * Devuelve el turno a la respuesta automática cuando el chat IA no contestó.
     *
     * Es el mismo relevo que hace ProcessWhatsAppAi y por el mismo motivo: en
     * cuanto la IA está encendida, el webhook la da por ganadora y no dispara
     * la respuesta automática. Si después resulta que el flujo no contestó, sin
     * esto el interruptor de la IA acaba dejando muda a toda la empresa.
     *
     * ProcessAutoResponse revalida lo que pudo cambiar durante la espera del
     * modelo (agente asignado, hilo cerrado, ventana de 24 h, cooldown), así
     * que llegar tarde aquí no es un problema.
     */
    private function fallBackToAutoResponse(Instance $instance, WhatsAppConversation $conversation): void
    {
        // El texto sale de la base y no de `$this->message`: cuando el relevo
        // viene de la IA de menús, ahí llegan varios mensajes pegados y la
        // respuesta automática necesita uno solo para casar sus reglas.
        $row = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('wamid', $this->wamid)
            ->first();

        $content = trim((string) ($row->content ?? ''));

        if ($content === '') {
            return;
        }

        Log::channel('whatsapp')->info('↩️ El chat IA no se hizo cargo: vuelve a la respuesta automática', [
            'conversation_id' => $conversation->id,
            'message_id' => $row->id,
        ]);

        ProcessAutoResponse::dispatch($instance->id, $conversation->id, $content, $this->wamid);
    }
}
