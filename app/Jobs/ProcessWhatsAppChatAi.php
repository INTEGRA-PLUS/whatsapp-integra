<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
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

        if ($decision === null) {
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
}
