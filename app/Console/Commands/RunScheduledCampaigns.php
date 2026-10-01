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

        // Primero, los envíos que un worker muerto dejó en "sending": mientras
        // estén ahí la campaña cuenta como "enviando" y la de abajo nunca la
        // volvería a lanzar. Va aquí porque esto corre cada minuto pase lo que
        // pase, haya o no campañas que lanzar.
        $rescatados = WhatsAppCampaign::rescatarEnviosAtascados();

        if ($rescatados > 0) {
            Log::channel('whatsapp')->warning('Destinatarios de campaña rescatados de "sending"', [
                'destinatarios' => $rescatados,
            ]);
        }

        // Ni pausadas ni canceladas: relanzarlas las ponía en "queued" con el
        // `paused_at`/`cancelled_at` puesto, ProcessWhatsAppCampaign se negaba a
        // repartirlas y se quedaban en cola para siempre —y fuera de este
        // listado, que excluye "queued"—. Una cancelada, además, volvía a
        // escribir a toda la lista.
        $due = WhatsAppCampaign::query()
            ->where('schedule_type', 'recurring')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->whereNotIn('status', ['queued', 'sending', 'paused', 'cancelled'])
            ->whereNull('paused_at')
            ->whereNull('cancelled_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No hay campañas programadas pendientes.');
            return self::SUCCESS;
        }

        foreach ($due as $campaign) {
            try {
                DB::transaction(function () use ($campaign) {
                    // Una recurrente vuelve a empezar de cero cada vez: se limpian
                    // también los acuses del envío anterior, que si no quedarían
                    // mezclados con los de esta vuelta.
                    //
                    // Salvo quien no quiere recibirla: "skipped" son los que
                    // estaban dados de baja al crear la campaña, y 131050 los que
                    // se dieron de baja de marketing después. Ponerlos en
                    // "pending" era volver a escribirles en cada vuelta.
                    WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)
                        ->where('status', '!=', 'skipped')
                        ->where(fn ($q) => $q->whereNull('error_code')->orWhere('error_code', '!=', '131050'))
                        ->update([
                            'status' => 'pending',
                            'wamid' => null,
                            'error_message' => null,
                            'error_code' => null,
                            'error_details' => null,
                            'sent_at' => null,
                            'delivered_at' => null,
                            'read_at' => null,
                            'message_id' => null,
                            'updated_at' => now(),
                        ]);

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
