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

        $rule = AutoResponse::active()
            ->where('company_id', $instance->company_id)
            ->where(function ($q) use ($instance) {
                $q->whereNull('instance_id')->orWhere('instance_id', $instance->id);
            })
            ->orderByRaw('instance_id IS NULL')
            ->orderByRaw("CASE WHEN match_type = 'welcome' THEN 0 WHEN match_type = 'always' THEN 2 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get()
            ->first(function (AutoResponse $r) use ($isFirstInbound) {
                if ($r->match_type === 'welcome' && !$isFirstInbound) {
                    return false;
                }
                return $r->matches($this->incomingText);
            });

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

        $renderedMessage = $rule->renderMessage($conversation);

        $result = $metaService->sendMessage(
            $instance->phone_number_id,
            $conversation->phone_number,
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

        if ($rule->match_type === 'always') {
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
