<?php

namespace App\Services;

use App\Models\AutoResponse;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;

class AutoResponseService
{
    private MetaWhatsAppService $metaService;

    public function __construct(MetaWhatsAppService $metaService)
    {
        $this->metaService = $metaService;
    }

    public function handleInbound(Instance $instance, WhatsAppConversation $conversation, string $incomingText): void
    {
        if (trim($incomingText) === '') {
            return;
        }

        $rule = AutoResponse::active()
            ->where('company_id', $instance->company_id)
            ->where(function ($q) use ($instance) {
                $q->whereNull('instance_id')->orWhere('instance_id', $instance->id);
            })
            ->orderByRaw('instance_id IS NULL')
            ->orderBy('created_at', 'desc')
            ->get()
            ->first(fn (AutoResponse $r) => $r->matches($incomingText));

        if (!$rule) {
            return;
        }

        $result = $this->metaService->sendMessage(
            $instance->phone_number_id,
            $conversation->phone_number,
            $rule->response_message
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
            'content' => $rule->response_message,
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message' => $rule->response_message,
            'last_message_at' => now(),
        ]);

        Log::channel('whatsapp')->info('🤖 Auto-respuesta enviada', [
            'auto_response_id' => $rule->id,
            'conversation_id' => $conversation->id,
        ]);
    }
}
