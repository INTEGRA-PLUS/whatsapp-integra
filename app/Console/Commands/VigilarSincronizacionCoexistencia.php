<?php

namespace App\Console\Commands;

use App\Models\CoexistenceSync;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Vigila la ventana de 24 horas de las importaciones de coexistencia.
 *
 * La importación de contactos e historial se pide una vez y Meta la entrega por
 * webhooks a lo largo de los minutos siguientes. Si algo se rompe en medio —el
 * callback caído, un despliegue justo entonces, el cliente que apaga el
 * celular— la importación se queda a medias y **no hay segundo intento**:
 * recuperarla obliga a desconectar el número y rehacer el registro insertado
 * con el cliente delante.
 *
 * Sin esto, eso se descubre semanas después, cuando alguien pregunta por qué
 * ese cliente no tiene sus chats. Y para entonces ya no se puede arreglar sin
 * molestarlo.
 *
 * Avisa dos veces: a las cuatro horas de silencio, cuando todavía queda margen
 * de sobra para reaccionar, y al cerrarse la ventana, para dejar constancia de
 * que ese cliente necesita rehacer el proceso.
 */
class VigilarSincronizacionCoexistencia extends Command
{
    protected $signature = 'coexistencia:vigilar
        {--quiet-notifications : No notifica; sólo deja el rastro en el log}';

    protected $description = 'Avisa de las importaciones de coexistencia que se quedaron a medias antes de que expire su ventana';

    /** Horas de silencio tras las que ya conviene mirar qué pasó. */
    private const HORAS_SIN_AVANCE = 4;

    public function handle(): int
    {
        $pendientes = CoexistenceSync::with('instance')
            ->whereNotNull('requested_at')
            ->whereNull('completed_at')
            ->whereNotIn('status', [
                CoexistenceSync::COMPLETADA,
                CoexistenceSync::RECHAZADA,
                CoexistenceSync::FALLIDA,
            ])
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay importaciones a medias.');
            return self::SUCCESS;
        }

        foreach ($pendientes as $sync) {
            $horas = $sync->requested_at->diffInHours(now());

            if ($horas >= 24) {
                $this->cerrarVencida($sync);
                continue;
            }

            // Sólo se avisa una vez por importación: el aviso se marca dejando
            // el mensaje en `error_message`, que hasta aquí está vacío.
            if ($horas >= self::HORAS_SIN_AVANCE && $sync->error_message === null) {
                $this->avisarEstancada($sync, $horas);
            }
        }

        return self::SUCCESS;
    }

    /**
     * La ventana se cerró: ya no hay nada que esperar.
     *
     * Se marca como fallida para que la pantalla del cliente deje de prometer
     * una importación que no va a llegar, y para que quede el registro de que
     * ese número necesita rehacerse.
     */
    private function cerrarVencida(CoexistenceSync $sync): void
    {
        $sync->update([
            'status'        => CoexistenceSync::FALLIDA,
            'error_message' => 'La ventana de 24 horas se cerró con la importación incompleta. '
                . 'Para recuperar el historial hay que desconectar el número desde el celular y repetir la conexión.',
            'completed_at'  => now(),
        ]);

        $instancia = $sync->instance;

        Log::channel('whatsapp')->error('⏰ Ventana de importación de coexistencia vencida', [
            'instance_id' => $sync->instance_id,
            'progreso'    => $sync->porcentajeGlobal(),
            'mensajes'    => $sync->messages_imported,
        ]);

        $this->warn("Instancia {$sync->instance_id}: ventana vencida al {$sync->porcentajeGlobal()}%.");

        if ($this->option('quiet-notifications') || !$instancia) {
            return;
        }

        $this->notificar(
            $instancia->company_id,
            'Importación de WhatsApp incompleta',
            "La importación del historial de «{$instancia->name}» ({$instancia->display_phone_number}) "
                . "se quedó en {$sync->porcentajeGlobal()}% y su plazo expiró. "
                . 'Para recuperarlo hay que desconectar el número desde el celular y volver a conectarlo.'
        );
    }

    /** Todavía hay margen: alguien puede mirar qué está pasando. */
    private function avisarEstancada(CoexistenceSync $sync, int $horas): void
    {
        $sync->update([
            'error_message' => "Sin avance desde hace {$horas} horas.",
        ]);

        $instancia = $sync->instance;

        Log::channel('whatsapp')->warning('🐢 Importación de coexistencia sin avance', [
            'instance_id' => $sync->instance_id,
            'horas'       => $horas,
            'progreso'    => $sync->porcentajeGlobal(),
        ]);

        $this->warn("Instancia {$sync->instance_id}: {$horas}h sin avance, al {$sync->porcentajeGlobal()}%.");

        if ($this->option('quiet-notifications') || !$instancia) {
            return;
        }

        $restantes = 24 - $horas;

        $this->notificar(
            $instancia->company_id,
            'Importación de WhatsApp detenida',
            "La importación del historial de «{$instancia->name}» lleva {$horas} horas sin avanzar, "
                . "al {$sync->porcentajeGlobal()}%. Quedan {$restantes} horas de plazo. "
                . 'Pídele al cliente que abra la app de WhatsApp Business en su celular.'
        );
    }

    private function notificar(int $companyId, string $titulo, string $cuerpo): void
    {
        $admins = User::where('company_id', $companyId)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'superadmin']))
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new SystemNotification($titulo, $cuerpo, 'Sistema'));
    }
}
