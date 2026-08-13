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

class ProcessAutoResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public string $incomingText,
        public string $inboundWamid
    ) {}

    public function handle(MetaWhatsAppService $metaService): void
    {
        $instance = Instance::find($this->instanceId);
        $conversation = WhatsAppConversation::find($this->conversationId);

        if (!$instance || !$conversation || !$instance->active) {
            return;
        }

        if ($conversation->assigned_to !== null) {
            Log::channel('whatsapp')->info('⏭️ Auto-respuesta omitida: conversación asignada a un agente', [
                'conversation_id' => $conversation->id,
                'assigned_to' => $conversation->assigned_to,
            ]);
            return;
        }

        if ($conversation->status === 'closed') {
            Log::channel('whatsapp')->info('⏭️ Auto-respuesta omitida: conversación cerrada', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        $firstInbound = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderBy('id')
            ->first();
        $isFirstInbound = $firstInbound !== null && $firstInbound->wamid === $this->inboundWamid;

        // Tiempo de inactividad: minutos entre el mensaje anterior y el actual (para "reabrir").
        $currentMessage = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('wamid', $this->inboundWamid)
            ->first();
        $previousMessage = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->when($currentMessage, fn ($q) => $q->where('id', '<', $currentMessage->id))
            ->orderByDesc('id')
            ->first();
        $gapMinutes = $previousMessage
            ? (int) $previousMessage->created_at->diffInMinutes(now())
            : null;

        $context = [
            'is_first_inbound' => $isFirstInbound,
            'gap_minutes' => $gapMinutes,
        ];

        $rule = AutoResponse::active()
            ->where('company_id', $instance->company_id)
            ->where(function ($q) use ($instance) {
                $q->whereNull('instance_id')->orWhere('instance_id', $instance->id);
            })
            ->get()
            ->sort(fn (AutoResponse $a, AutoResponse $b) =>
                [$a->instance_id === null ? 1 : 0, $a->priority(), -$a->created_at->timestamp]
                <=>
                [$b->instance_id === null ? 1 : 0, $b->priority(), -$b->created_at->timestamp]
            )
            ->values()
            ->first(fn (AutoResponse $r) => $r->qualifies($this->incomingText, $context));

        if (!$rule) {
            return;
        }

        if ($this->isInCooldown($rule, $conversation)) {
            Log::channel('whatsapp')->info('⏭️ Auto-respuesta omitida: regla en cooldown', [
                'auto_response_id' => $rule->id,
                'conversation_id' => $conversation->id,
                'cooldown_minutes' => $rule->cooldown_minutes,
            ]);
            return;
        }

        // Normalmente la ventana está abierta: el cliente acaba de escribir. Pero
        // si Meta traía el webhook atascado y lo entrega días después, responder
        // en texto libre sólo genera un fallido "Re-engagement" que nadie lee.
        if (!$conversation->isWindowOpen()) {
            Log::channel('whatsapp')->info('⏭️ Auto-respuesta omitida: ventana de 24h cerrada', [
                'auto_response_id' => $rule->id,
                'conversation_id' => $conversation->id,
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
            Log::channel('whatsapp')->warning('⚠️ Auto-respuesta no enviada', [
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
            'metadata' => ['auto_response_id' => $rule->id],
        ]);

        $conversation->update([
            'last_message' => $renderedMessage,
            'last_message_at' => now(),
        ]);

        $rule->increment('fires_count', 1, ['last_fired_at' => now()]);

        Log::channel('whatsapp')->info('🤖 Auto-respuesta enviada', [
            'auto_response_id' => $rule->id,
            'conversation_id' => $conversation->id,
        ]);

        if (in_array('always', $rule->matchTypes(), true)) {
            SendAutoResponseFollowUp::dispatch(
                $instance->id,
                $conversation->id,
                $rule->id,
                $this->inboundWamid
            )->delay(now()->addHour());
        }
    }

    private function isInCooldown(AutoResponse $rule, WhatsAppConversation $conversation): bool
    {
        $cooldownMinutes = $rule->cooldown_minutes ?? 0;

        if ($cooldownMinutes <= 0) {
            return false;
        }

        $threshold = now()->subMinutes($cooldownMinutes);

        return WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('sent_at', '>=', $threshold)
            ->where('metadata->auto_response_id', $rule->id)
            ->exists();
    }
}
