<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:run-scheduled';
    protected $description = 'Dispatch recurring WhatsApp campaigns whose next_run_at has elapsed';

    public function handle(): int
    {
        $now = now();

        $due = WhatsAppCampaign::query()
            ->where('schedule_type', 'recurring')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->whereNotIn('status', ['queued', 'sending'])
            ->get();

        if ($due->isEmpty()) {
            $this->info('No hay campañas programadas pendientes.');
            return self::SUCCESS;
        }

        foreach ($due as $campaign) {
            try {
                DB::transaction(function () use ($campaign) {
                    WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)
                        ->update(['status' => 'pending', 'wamid' => null, 'message_id' => null, 'error_message' => null, 'sent_at' => null, 'updated_at' => now()]);

                    $campaign->update([
                        'status' => 'queued',
                        'sent_count' => 0,
                        'failed_count' => 0,
                        'started_at' => now(),
                        'completed_at' => null,
                        'last_run_at' => now(),
                        'next_run_at' => $campaign->computeNextRun(now()->addMinute()),
                    ]);
                });

                ProcessWhatsAppCampaign::dispatch($campaign->id);
                $this->info("Campaña #{$campaign->id} encolada.");
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('Error encolando campaña recurrente', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Campaña #{$campaign->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
