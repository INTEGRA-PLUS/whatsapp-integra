<?php

namespace App\Jobs;

use App\Models\BusinessHour;
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

class ProcessOutOfHours implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $instanceId,
        public int $conversationId
    ) {}

    /**
     * @return bool True si se envió un mensaje de fuera de horario.
     */
    public function handle(MetaWhatsAppService $metaService): bool
    {
        $instance = Instance::find($this->instanceId);
        $conversation = WhatsAppConversation::find($this->conversationId);

        if (!$instance || !$conversation || !$instance->active) {
            return false;
        }

        if ($conversation->assigned_to !== null || $conversation->status === 'closed') {
            return false;
        }

        $rule = BusinessHour::active()
            ->where('company_id', $instance->company_id)
            ->where(function ($q) use ($instance) {
                $q->whereNull('instance_id')->orWhere('instance_id', $instance->id);
            })
            ->orderByRaw('instance_id IS NULL')
            ->get()
            ->first(fn (BusinessHour $r) => !$r->isWithinHours());

        if (!$rule) {
            return false;
        }

        if ($this->isInCooldown($rule, $conversation)) {
            Log::channel('whatsapp')->info('⏭️ Fuera de horario omitido: regla en cooldown', [
                'business_hour_id' => $rule->id,
                'conversation_id' => $conversation->id,
                'cooldown_minutes' => $rule->cooldown_minutes,
            ]);
            return true;
        }

        $renderedMessage = $rule->renderMessage($conversation);

        $result = $metaService->sendMessage(
            $instance->phone_number_id,
            $conversation->phone_number,
            $renderedMessage
        );

        if (!($result['success'] ?? false)) {
            Log::channel('whatsapp')->warning('⚠️ Mensaje fuera de horario no enviado', [
                'business_hour_id' => $rule->id,
                'conversation_id' => $conversation->id,
                'error' => $result['error'] ?? null,
            ]);
            return true;
        }

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => $result['data']['messages'][0]['id'] ?? null,
            'type' => 'text',
            'content' => $renderedMessage,
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => ['business_hour_id' => $rule->id],
        ]);

        $conversation->update([
            'last_message' => $renderedMessage,
            'last_message_at' => now(),
        ]);

        $rule->increment('fires_count', 1, ['last_fired_at' => now()]);

        Log::channel('whatsapp')->info('🕒 Mensaje fuera de horario enviado', [
            'business_hour_id' => $rule->id,
            'conversation_id' => $conversation->id,
        ]);

        return true;
    }

    private function isInCooldown(BusinessHour $rule, WhatsAppConversation $conversation): bool
    {
        $cooldownMinutes = $rule->cooldown_minutes ?? 0;

        if ($cooldownMinutes <= 0) {
            return false;
        }

        $threshold = now()->subMinutes($cooldownMinutes);

        return WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('sent_at', '>=', $threshold)
            ->where('metadata->business_hour_id', $rule->id)
            ->exists();
    }
}
