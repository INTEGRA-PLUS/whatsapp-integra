<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Log;

class WebhookDispatcher
{
    /**
     * Emit an event to every active endpoint of a company subscribed to it.
     * Each matching endpoint gets its own queued delivery job.
     */
    public static function emit(int $companyId, string $event, array $payload): void
    {
        try {
            $endpoints = WebhookEndpoint::forCompany($companyId)
                ->where('active', true)
                ->get()
                ->filter(fn (WebhookEndpoint $e) => $e->subscribesTo($event));

            if ($endpoints->isEmpty()) {
                return;
            }

            $body = [
                'event'      => $event,
                'company_id' => $companyId,
                'sent_at'    => now()->toIso8601String(),
                'data'       => $payload,
            ];

            foreach ($endpoints as $endpoint) {
                DeliverWebhook::dispatch($endpoint->id, $event, $body);
            }
        } catch (\Throwable $e) {
            // Never let webhook fan-out break the main request flow.
            Log::warning('WebhookDispatcher::emit failed', [
                'company_id' => $companyId,
                'event'      => $event,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a consistent conversation payload shared across events.
     */
    public static function conversationPayload(WhatsAppConversation $conversation, array $extra = []): array
    {
        $agent = $conversation->assignedAgent()->select('id', 'name')->first();

        return array_merge([
            'conversation_id' => $conversation->id,
            'instance_id'     => $conversation->instance_id,
            'wa_id'           => $conversation->wa_id,
            'phone_number'    => $conversation->phone_number,
            'name'            => $conversation->name,
            'status'          => $conversation->status,
            'assigned_agent'  => $agent ? ['id' => $agent->id, 'name' => $agent->name] : null,
        ], $extra);
    }
}
