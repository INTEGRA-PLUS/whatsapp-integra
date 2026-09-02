<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reparte una campaña: encola un envío por destinatario, escalonados en el
 * tiempo.
 *
 * Este job ya no habla con Meta. Antes lo hacía en bucle dentro de un único
 * job, con un `usleep()` entre llamadas: media hora de campaña era media hora
 * de worker bloqueado, un timeout dejaba la mitad sin enviar y no había forma
 * de pausar. Ahora sólo calcula cuándo le toca a cada uno —Meta responde 429 si
 * se le riega, de ahí `rate_per_minute`— y deja el trabajo real a
 * {@see SendCampaignMessage}, que sí sabe reintentarse solo.
 */
class ProcessWhatsAppCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public int $campaignId, public int $delayMs = 0)
    {
    }

    public function handle(): void
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

        // Una campaña de texto libre no puede salir: fuera de la ventana de 24h
        // WhatsApp no la entrega, y una campaña va justo a quien no acaba de
        // escribir. Se para aquí, con el motivo escrito, en vez de gastar cientos
        // de llamadas que Meta acepta y luego rechaza en silencio.
        if (!$campaign->usesTemplate()) {
            $campaign->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            $campaign->recipients()->where('status', 'pending')->update([
                'status' => 'failed',
                'error_message' => 'Esta campaña se creó con texto libre. WhatsApp solo entrega mensajes masivos '
                    . 'como plantilla aprobada: vuelve a crearla eligiendo una plantilla.',
            ]);

            $campaign->refreshCounters();
            return;
        }

        if ($campaign->paused_at || $campaign->cancelled_at) {
            return;
        }

        $campaign->update([
            'status' => 'sending',
            'started_at' => $campaign->started_at ?? now(),
            'completed_at' => null,
        ]);

        $rate = max(1, (int) ($campaign->rate_per_minute ?: 60));
        $position = 0;
        $encolados = 0;

        WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(200, function ($recipients) use ($rate, &$position, &$encolados) {
                foreach ($recipients as $recipient) {
                    SendCampaignMessage::dispatch($recipient->id)
                        ->delay(now()->addSeconds((int) floor($position * 60 / $rate)));

                    $position++;
                    $encolados++;
                }
            });

        Log::channel('whatsapp')->info('Campaña repartida', [
            'campaign_id' => $campaign->id,
            'destinatarios' => $encolados,
            'ritmo_por_minuto' => $rate,
        ]);

        // Nadie a quien enviar: la campaña ya está terminada.
        if ($encolados === 0) {
            $campaign->refreshCounters();
            $campaign->update([
                'status' => $campaign->sent_count === 0 && $campaign->failed_count > 0 ? 'failed' : 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
