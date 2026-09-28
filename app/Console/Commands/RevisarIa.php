<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\ResumenIaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Le pregunta cada mañana a la IA de la plataforma si sigue respondiendo.
 *
 * El 28-sep-2026 Ollama Cloud llevaba dos días rechazando todo —«your
 * subscription payment is past due»— y se supo porque un cliente dijo que el
 * resumen no servía. El resumen al menos mostraba un error; el semáforo de
 * emociones y los menús con IA fallaban en silencio, porque sus flujos de n8n
 * atrapan el error y se guardan como ejecuciones correctas.
 *
 * Avisa a los master y no a los admins de cada empresa: la cuenta de Ollama es
 * de la plataforma y sólo nosotros podemos pagarla. Y sólo en el cambio —cae o
 * se recupera—, igual que `whatsapp:health-check`: la misma alerta cada día es
 * la que se aprende a ignorar.
 */
class RevisarIa extends Command
{
    /** Dónde se recuerda cómo estaba en la última revisión. */
    public const ESTADO = 'salud:ia';

    protected $signature = 'ia:revisar {--quiet-notifications : No notifica; sólo registra el estado}';

    protected $description = 'Comprueba que la IA de la plataforma (n8n + Ollama) responde y avisa a los master si cae';

    public function handle(ResumenIaClient $ia): int
    {
        if (! ResumenIaClient::configured()) {
            $this->warn('El servicio de resumen no está configurado: no hay IA que revisar.');

            return self::SUCCESS;
        }

        $prueba = $ia->probar();
        $antes = Cache::get(self::ESTADO);
        $ahora = $prueba['ok'] ? 'ok' : 'caida';

        Cache::forever(self::ESTADO, $ahora);

        if ($prueba['ok']) {
            $this->info('✓ La IA responde.');

            if ($antes === 'caida') {
                Log::channel('whatsapp')->info('✅ La IA de la plataforma volvió a responder');
                $this->avisar('La IA volvió a responder', 'El resumen, el semáforo de emociones y los menús con IA vuelven a funcionar.');
            }

            return self::SUCCESS;
        }

        $this->error('✗ La IA no responde: '.($prueba['estado'] ? "HTTP {$prueba['estado']}. " : '').($prueba['detalle'] ?? ''));

        Log::channel('whatsapp')->error('🧠 La IA de la plataforma no responde', $prueba);

        if ($antes !== 'caida') {
            $pago = str_contains(mb_strtolower((string) $prueba['detalle']), 'past due');

            $this->avisar(
                'La IA de la plataforma no responde',
                ($pago
                    ? 'Ollama Cloud rechaza las peticiones porque el pago de la suscripción está vencido. '
                    : 'El resumen con IA no responde'.($prueba['estado'] ? " (HTTP {$prueba['estado']})" : '').'. ')
                .'Mientras siga así fallan el resumen de conversaciones, el semáforo de emociones y los menús con IA, '
                .'en todas las empresas. La causa más común es el pago de Ollama Cloud: revisa https://ollama.com/settings/billing '
                .'y, si está al día, las ejecuciones del flujo de resumen en n8n.'
            );
        }

        return self::SUCCESS;
    }

    private function avisar(string $titulo, string $texto): void
    {
        if ($this->option('quiet-notifications')) {
            return;
        }

        // Por la tabla y no por `isMaster()`: en un comando no hay equipo de
        // permisos fijado, y `hasRole()` devolvería que no.
        $masters = User::where('role', 'master')->where('active', true)->get();

        if ($masters->isNotEmpty()) {
            Notification::send($masters, new SystemNotification($titulo, $texto, 'Sistema'));
        }
    }
}
