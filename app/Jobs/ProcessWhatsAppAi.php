<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Services\WhatsAppAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
     * @param bool $isFlowAnswer El cliente está contestando a una pregunta que
     *                           hizo la IA. Cambia qué se le manda al flujo
     *                           (el paso pendiente y lo que ya sabíamos) y hace
     *                           que se descarte si esa pregunta ya caducó.
     */
    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public string $message,
        public bool $isFlowAnswer = false
    ) {}

    public function handle(WhatsAppAiClient $ai): void
    {
        $instance = Instance::find($this->instanceId);
        $conversation = WhatsAppConversation::find($this->conversationId);

        if (! $instance || ! $conversation || ! $instance->active) {
            return;
        }

        // Se comprueba antes de gastar la inferencia; ProcessWhatsAppMenu lo
        // vuelve a comprobar después, por si cambia mientras el modelo piensa.
        if ($conversation->assigned_to !== null || $conversation->status === 'closed') {
            return;
        }

        if (! $conversation->isWindowOpen()) {
            Log::channel('whatsapp')->info('⏭️ IA omitida: ventana de 24h cerrada', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        $flow = $this->isFlowAnswer ? WhatsAppBotFlow::activeFor($conversation->id) : null;

        // La pregunta caducó entre el webhook y la cola. Contestar ahora sería
        // retomar una conversación que el cliente ya dio por perdida.
        if ($this->isFlowAnswer && ! $flow) {
            return;
        }

        $decision = $ai->ask($instance, $conversation, $this->message, $flow);

        // La IA no se hizo cargo (apagada, sin turnos, flujo caído). No se
        // contesta nada: el mensaje ya quedó guardado y un agente lo verá en su
        // bandeja, que es preferible a improvisar una respuesta aquí.
        if ($decision === null) {
            Log::channel('whatsapp')->info('ℹ️ La IA no resolvió el mensaje; queda para un agente', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        Log::channel('whatsapp')->info('🤖 IA resolvió el mensaje', [
            'conversation_id' => $conversation->id,
            'intencion' => data_get($decision['meta'], 'intencion'),
            'confianza' => data_get($decision['meta'], 'confianza'),
            'redactor' => data_get($decision['meta'], 'redactor'),
            'degradacion' => data_get($decision['meta'], 'degradacion'),
            'ms' => data_get($decision['meta'], 'uso.planificador.ms'),
        ]);

        ProcessWhatsAppMenu::dispatch(
            $instance->id,
            $conversation->id,
            null,
            null,
            '',
            null,
            $decision['result'],
            $decision['note']
        );
    }
}
