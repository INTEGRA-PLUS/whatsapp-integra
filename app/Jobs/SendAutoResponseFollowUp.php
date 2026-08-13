<?php

namespace App\Jobs;

use App\Models\AutoResponse;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAutoResponseFollowUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public int $autoResponseId,
        public string $lastInboundWamid
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MetaWhatsAppService $metaService): void
    {
        $instance = Instance::find($this->instanceId);
        $conversation = WhatsAppConversation::find($this->conversationId);
        $rule = AutoResponse::find($this->autoResponseId);

        if (!$instance || !$conversation || !$rule || !$instance->active || !$rule->active) {
            return;
        }

        // Check if there has been any new inbound message since we scheduled this job
        $latestInbound = WhatsAppMessage::where('conversation_id', $this->conversationId)
            ->where('direction', 'inbound')
            ->orderBy('id', 'desc')
            ->first();

        if (!$latestInbound || $latestInbound->wamid !== $this->lastInboundWamid) {
            Log::channel('whatsapp')->info('⏭️ Follow-up cancelado: El usuario ya respondió o hay un mensaje más reciente.', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // El follow-up se programa a futuro, así que es el que más fácil cae
        // fuera de la ventana de 24h.
        if (!$conversation->isWindowOpen()) {
            Log::channel('whatsapp')->info('⏭️ Follow-up cancelado: ventana de 24h cerrada', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        $renderedMessage = $rule->renderMessage($conversation);

        $result = $metaService->sendMessage(
            $instance->phone_number_id,
            $conversation->recipientId(),
            $renderedMessage
        );

        if (!($result['success'] ?? false)) {
            Log::channel('whatsapp')->warning('⚠️ Follow-up no enviado', [
                'auto_response_id' => $rule->id,
                'conversation_id' => $conversation->id,
                'error' => $result['error'] ?? null,
            ]);
            return;
        }

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => $result['data']['messages'][0]['id'] ?? null,
            'type' => 'text',
            'content' => $renderedMessage,
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => ['auto_response_id' => $rule->id, 'follow_up' => true],
        ]);

        $conversation->update([
            'last_message' => $renderedMessage,
            'last_message_at' => now(),
        ]);

        $rule->increment('fires_count', 1, ['last_fired_at' => now()]);

        Log::channel('whatsapp')->info('🤖 Follow-up enviado (1h después)', [
            'auto_response_id' => $rule->id,
            'conversation_id' => $conversation->id,
        ]);
    }
}
