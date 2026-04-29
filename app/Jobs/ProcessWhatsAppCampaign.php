<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public int $campaignId, public int $delayMs = 250)
    {
    }

    public function handle(MetaWhatsAppService $metaService): void
    {
        $campaign = WhatsAppCampaign::with('instance')->find($this->campaignId);

        if (!$campaign || !$campaign->instance) {
            Log::channel('whatsapp')->warning('Campaña no encontrada para procesar', ['campaign_id' => $this->campaignId]);
            return;
        }

        if (!in_array($campaign->status, ['queued', 'sending'], true)) {
            Log::channel('whatsapp')->info('Campaña en estado no procesable', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);
            return;
        }

        $campaign->update([
            'status' => 'sending',
            'started_at' => $campaign->started_at ?? now(),
        ]);

        $instance = $campaign->instance;
        $delayUs = max(0, $this->delayMs) * 1000;

        WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(100, function ($recipients) use ($campaign, $instance, $metaService, $delayUs) {
                foreach ($recipients as $recipient) {
                    $this->sendOne($recipient, $campaign, $instance, $metaService);
                    if ($delayUs > 0) {
                        usleep($delayUs);
                    }
                }
            });

        $campaign->refresh();
        $hasPending = $campaign->recipients()->where('status', 'pending')->exists();
        $campaign->update([
            'status' => $hasPending ? 'failed' : 'completed',
            'completed_at' => now(),
        ]);
    }

    private function sendOne(WhatsAppCampaignRecipient $recipient, WhatsAppCampaign $campaign, $instance, MetaWhatsAppService $metaService): void
    {
        try {
            $result = $metaService->sendMessage(
                $instance->phone_number_id,
                $recipient->phone_number,
                $campaign->message
            );

            if ($result['success'] ?? false) {
                $recipient->update([
                    'status' => 'sent',
                    'wamid' => $result['data']['messages'][0]['id'] ?? null,
                    'sent_at' => now(),
                    'error_message' => null,
                ]);
                $campaign->increment('sent_count');
            } else {
                $recipient->update([
                    'status' => 'failed',
                    'error_message' => is_string($result['error'] ?? null)
                        ? $result['error']
                        : json_encode($result['error'] ?? 'Error desconocido', JSON_UNESCAPED_UNICODE),
                ]);
                $campaign->increment('failed_count');
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('Error enviando recipient de campaña', [
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
            $recipient->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $campaign->increment('failed_count');
        }
    }
}
