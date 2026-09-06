<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\MetaWhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Le pregunta a Meta, una vez al día, si cada instancia sigue viva.
 *
 * El 2026-09-05 aparecieron cinco empresas cuyo token o número ya no existían
 * del lado de Meta. La más antigua llevaba seis meses así. Las cinco se veían
 * "Activa" en verde en el panel, porque `active` es una casilla nuestra que
 * alguien marcó una vez, no una comprobación. Se descubrieron por casualidad,
 * revisando otra cosa.
 *
 * Esto convierte ese hallazgo en un aviso del mismo día.
 *
 * Se consulta el `phone_number_id` y no el WABA a propósito: es el objeto que
 * se usa para enviar, así que si responde, la instancia puede trabajar. Un
 * WABA legible con un número muerto seguiría sin poder mandar un mensaje.
 */
class CheckInstanceHealth extends Command
{
    protected $signature = 'whatsapp:health-check
        {--instance= : Revisa solo esta instancia (id)}
        {--quiet-notifications : No notifica; sólo actualiza el estado}';

    protected $description = 'Comprueba contra Meta que cada instancia siga viva y avisa cuando una se cae';

    public function __construct(private MetaWhatsAppService $meta)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $instances = Instance::with('company')
            ->where('active', true)
            ->when($this->option('instance'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')
            ->get();

        if ($instances->isEmpty()) {
            $this->warn('No hay instancias activas que revisar.');

            return self::SUCCESS;
        }

        $caidas = 0;
        $recuperadas = 0;

        foreach ($instances as $instance) {
            $anterior = $instance->health_status;
            [$estado, $error] = $this->revisar($instance);

            $instance->update([
                'health_status'     => $estado,
                'health_checked_at' => now(),
                'health_error'      => $error,
            ]);

            $etiqueta = $instance->company->name ?? "instancia #{$instance->id}";

            if ($estado === 'unreachable') {
                $this->line("  ✗ {$etiqueta}: {$error}");
            } else {
                $this->line("  ✓ {$etiqueta}");
            }

            // Sólo se avisa en el cambio de estado. Repetirlo cada día
            // convertiría la alerta en ruido, y el ruido es exactamente lo que
            // hizo que nadie mirara las cinco que ya estaban caídas.
            if ($estado === 'unreachable' && $anterior !== 'unreachable') {
                $caidas++;
                $this->avisar($instance, $error);
            }

            if ($estado === 'ok' && $anterior === 'unreachable') {
                $recuperadas++;
                Log::channel('whatsapp')->info('✅ Instancia recuperada', [
                    'instance_id' => $instance->id,
                    'company'     => $etiqueta,
                ]);
            }
        }

        $this->newLine();
        $this->info("Revisadas: {$instances->count()} · Caídas nuevas: {$caidas} · Recuperadas: {$recuperadas}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string|null} estado y motivo
     */
    private function revisar(Instance $instance): array
    {
        if (! $instance->access_token || ! $instance->phone_number_id) {
            return ['unreachable', 'La instancia no tiene token o phone_number_id configurado.'];
        }

        $res = $this->meta->getPhoneNumber($instance->phone_number_id, $instance->access_token);

        if ($res['success'] ?? false) {
            return ['ok', null];
        }

        $motivo = $res['error']['error']['message']
            ?? $res['error']['message']
            ?? (is_string($res['error'] ?? null) ? $res['error'] : 'Meta no reconoce el número o el token.');

        return ['unreachable', mb_substr($motivo, 0, 240)];
    }

    /**
     * Avisa a los admins de la empresa dueña, que son quienes pueden
     * reconectar. Mandarlo sólo al log repetiría el problema que esto arregla:
     * la información existía y nadie la miraba.
     */
    private function avisar(Instance $instance, ?string $error): void
    {
        Log::channel('whatsapp')->error('❌ Instancia caída contra Meta', [
            'instance_id'     => $instance->id,
            'company'         => $instance->company->name ?? null,
            'phone_number_id' => $instance->phone_number_id,
            'error'           => $error,
        ]);

        if ($this->option('quiet-notifications')) {
            return;
        }

        $admins = User::where('company_id', $instance->company_id)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new SystemNotification(
            'WhatsApp desconectado',
            "La conexión de «{$instance->name}» ({$instance->display_phone_number}) dejó de responder en Meta. "
                . 'No se están recibiendo ni enviando mensajes. Reconecta la cuenta desde Instancias.',
            'Sistema'
        ));
    }
}
