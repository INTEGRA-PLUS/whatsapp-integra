<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 3;

    /**
     * @param array $body Full JSON body to POST (event, company_id, sent_at, data).
     */
    public function __construct(
        public int $endpointId,
        public string $event,
        public array $body
    ) {
    }

    /**
     * Exponential-ish backoff between retries (seconds).
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);

        if (!$endpoint || !$endpoint->active) {
            return;
        }

        $payload   = json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payload, $endpoint->secret);

        $headers = array_merge([
            'Content-Type'        => 'application/json',
            'X-Webhook-Event'     => $this->event,
            'X-Webhook-Signature' => $signature,
        ], $this->normalizeHeaders($endpoint->headers));

        $statusCode = null;
        $success    = false;
        $responseBody = null;
        $error      = null;

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->withBody($payload, 'application/json')
                ->post($endpoint->url);

            $statusCode   = $response->status();
            $success      = $response->successful();
            $responseBody = mb_substr((string) $response->body(), 0, 2000);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            Log::warning('DeliverWebhook failed', [
                'endpoint_id' => $endpoint->id,
                'event'       => $this->event,
                'error'       => $error,
            ]);
        }

        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event'               => $this->event,
            'payload'             => $this->body,
            'status_code'         => $statusCode,
            'success'             => $success,
            'response_body'       => $responseBody,
            'error'               => $error,
            'attempts'            => $this->attempts(),
            'delivered_at'        => now(),
            'created_at'          => now(),
        ]);

        // Trigger a retry (within $tries) on transport error or non-2xx response.
        if (!$success) {
            throw new \RuntimeException(
                "Webhook delivery failed (status: " . ($statusCode ?? 'n/a') . ")"
            );
        }
    }

    /**
     * Custom headers stored as [{key, value}] or an associative map.
     */
    private function normalizeHeaders(?array $headers): array
    {
        if (empty($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_array($value) && isset($value['key'])) {
                $normalized[$value['key']] = $value['value'] ?? '';
            } elseif (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
