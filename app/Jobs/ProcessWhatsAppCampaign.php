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

        // El archivo del encabezado se vuelve a subir a Meta al empezar cada
        // corrida: el media_id caduca a los 30 días, así que una campaña
        // recurrente moriría al mes con un "Format mismatch" por destinatario.
        if (!$this->refreshHeaderMedia($campaign)) {
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

    /**
     * Renueva el media_id del encabezado desde nuestra copia del archivo.
     *
     * Devuelve false —y deja la campaña fallida— sólo si la plantilla exige un
     * archivo y no hay forma de conseguirlo: mejor pararlo entero que gastar la
     * lista entera en rechazos uno a uno.
     */
    private function refreshHeaderMedia(WhatsAppCampaign $campaign): bool
    {
        $formato = app(\App\Services\CampaignTemplateBuilder::class)->headerFormat($campaign);

        if (!in_array($formato, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            return true;
        }

        // Sin copia propia sólo queda el media_id original: sirve el primer mes.
        if (empty($campaign->header_media_path)) {
            if ($campaign->header_media_id) {
                return true;
            }

            $this->abortarSinEncabezado($campaign, 'La plantilla lleva un archivo en el encabezado y la campaña no tiene ninguno.');
            return false;
        }

        try {
            $disco = \Illuminate\Support\Facades\Storage::disk('s3_media');

            if (!$disco->exists($campaign->header_media_path)) {
                throw new \RuntimeException('la copia del archivo ya no está en el almacenamiento');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'camp_hdr_');
            file_put_contents($tmp, $disco->get($campaign->header_media_path));

            try {
                $subida = app(\App\Services\MetaWhatsAppService::class)->uploadMedia(
                    $campaign->instance->phone_number_id,
                    $tmp,
                    $campaign->header_media_mime ?: 'application/octet-stream'
                );
            } finally {
                @unlink($tmp);
            }

            if (!($subida['success'] ?? false)) {
                throw new \RuntimeException('WhatsApp no aceptó el archivo');
            }

            $campaign->forceFill(['header_media_id' => (string) $subida['id']])->save();

            return true;
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('No se pudo renovar el encabezado de la campaña', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            // Con un media_id previo se intenta igual: puede seguir vivo.
            if ($campaign->header_media_id) {
                return true;
            }

            $this->abortarSinEncabezado($campaign, 'No se pudo preparar el archivo del encabezado de la plantilla.');
            return false;
        }
    }

    private function abortarSinEncabezado(WhatsAppCampaign $campaign, string $motivo): void
    {
        $campaign->update(['status' => 'failed', 'completed_at' => now()]);
        $campaign->recipients()->where('status', 'pending')->update([
            'status' => 'failed',
            'error_message' => $motivo,
        ]);
        $campaign->refreshCounters();
    }
}
