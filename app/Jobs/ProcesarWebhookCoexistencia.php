<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\CoexistenceIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Procesa fuera de la petición los webhooks de coexistencia.
 *
 * No es una optimización: la documentación advierte que **un solo webhook de
 * `history` puede describir miles de mensajes**. Hacerlo dentro de la petición
 * de Meta agotaría el tiempo de respuesta, y entonces Meta reintenta el lote
 * entero — que es exactamente cómo se duplica un historial.
 *
 * El controlador responde 200 en cuanto encola; si algo revienta aquí, el
 * reintento es de la cola y la idempotencia por `wamid` lo hace inofensivo.
 */
class ProcesarWebhookCoexistencia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;

    public function __construct(
        public int $instanceId,
        public string $field,
        public array $value
    ) {
    }

    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function handle(CoexistenceIngestService $ingesta): void
    {
        $instance = Instance::find($this->instanceId);

        if (!$instance) {
            Log::channel('whatsapp')->warning('⚠️ Webhook de coexistencia sin instancia', [
                'instance_id' => $this->instanceId,
                'field'       => $this->field,
            ]);
            return;
        }

        match ($this->field) {
            'history'            => $ingesta->importarHistorial($instance, $this->value),
            'smb_app_state_sync' => $ingesta->sincronizarContactos($instance, $this->value),
            'smb_message_echoes' => $ingesta->reflejarEco($instance, $this->value),
            default => Log::channel('whatsapp')->warning('⚠️ Campo de coexistencia desconocido', [
                'field' => $this->field,
            ]),
        };
    }
}
