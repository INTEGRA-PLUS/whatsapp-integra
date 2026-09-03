<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWhatsAppMenu;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Services\WhatsAppAiClient;
use Illuminate\Console\Command;

/**
 * Prueba la IA de los menús sin WhatsApp y sin cola.
 *
 * Simula el mensaje de un cliente y recorre el camino real: arma el payload,
 * llama al flujo de n8n y traduce la respuesta. Muestra el texto EXACTO que
 * recibiría el cliente y por qué —qué intención entendió, con cuánta confianza,
 * cuánto tardó el modelo—, pero **no envía nada** salvo que se pida.
 *
 * Existe porque sin él la única forma de probar es escribirle al WhatsApp de
 * verdad: eso exige un worker de cola levantado, un número real y un cliente
 * real al otro lado recibiendo lo que salga.
 */
class ProbeMenuAi extends Command
{
    protected $signature = 'wa:ia-probar
        {mensaje : Lo que escribiría el cliente, entre comillas}
        {--empresa= : Id de la empresa. Por defecto, la única que tenga la IA encendida}
        {--conversacion= : Id de conversación a usar. Por defecto, la más reciente de la empresa}
        {--enviar : Envía de verdad por WhatsApp y aplica la acción (asigna asesor, abre flujo…)}';

    protected $description = 'Prueba la IA de los menús contra el flujo de n8n sin enviar nada al cliente';

    public function handle(WhatsAppAiClient $ai): int
    {
        if (blank(config('services.ai_menus.webhook_url'))) {
            $this->error('Falta AI_MENUS_WEBHOOK_URL en el .env.');

            return self::FAILURE;
        }

        $companyId = $this->resolveCompany();

        if ($companyId === null) {
            return self::FAILURE;
        }

        $conversation = $this->resolveConversation($companyId);

        if (! $conversation) {
            return self::FAILURE;
        }

        $instance = Instance::find($conversation->instance_id);
        $message = (string) $this->argument('mensaje');

        $this->newLine();
        $this->line('  <fg=gray>Empresa</>      ' . $companyId);
        $this->line('  <fg=gray>Conversación</> ' . $conversation->id . ' · ' . $conversation->phone_number
            . ' · ' . ($conversation->name ?: 'sin nombre'));
        $this->line('  <fg=gray>Mensaje</>      "' . $message . '"');
        $this->line('  <fg=gray>Flujo n8n</>    ' . config('services.ai_menus.webhook_url'));

        // Si hay una pregunta del bot en el aire, se manda igual que en
        // producción: el flujo necesita saberlo para no repetir la pregunta.
        $flow = WhatsAppBotFlow::activeFor($conversation->id);

        if ($flow) {
            $this->line('  <fg=gray>Pendiente</>    ' . $flow->action_type . ' / ' . $flow->step);
        }

        $this->newLine();
        $this->line('  <fg=yellow>Ojo:</> el flujo consulta el Integra de esta empresa de verdad. Si entiende');
        $this->line('  que hay que crear un radicado y el flujo tiene ese permiso, lo crea.');
        $this->line('  Para probar sin riesgo, usa mensajes de consulta ("cuánto debo").');
        $this->newLine();

        $inicio = microtime(true);
        $decision = $ai->ask($instance, $conversation, $message, $flow);
        $ms = (int) ((microtime(true) - $inicio) * 1000);

        if ($decision === null) {
            $this->error('  La IA no se hizo cargo. Revisa storage/logs con el canal whatsapp.');
            $this->line('  <fg=gray>Motivos típicos:</> la IA está apagada para esta empresa, se agotaron');
            $this->line('  los turnos, el flujo devolvió handled:false, o n8n no respondió.');

            return self::FAILURE;
        }

        $result = $decision['result'];
        $meta = $decision['meta'];

        $tipo = match (true) {
            $result->handoff => '<fg=yellow>DERIVA A UN ASESOR</>',
            $result->keepsWaiting() => '<fg=cyan>PREGUNTA Y ESPERA</> (' . $result->step . ')',
            default => '<fg=green>RESPONDE Y CIERRA</>',
        };

        $this->line('  ── Decisión ─────────────────────────────────');
        $this->line('  ' . $tipo);
        $this->newLine();
        $this->line('  <fg=gray>Intención</>    ' . ($meta['intencion'] ?? '?')
            . '   <fg=gray>Confianza</> ' . ($meta['confianza'] ?? '?'));
        $this->line('  <fg=gray>Modelo</>       ' . ($meta['modelo'] ?? '?')
            . '   <fg=gray>Redactor</> ' . ($meta['redactor'] ?? '?'));
        $this->line('  <fg=gray>Inferencia</>   ' . (data_get($meta, 'uso.planificador.ms') ?? '?') . ' ms'
            . '   <fg=gray>Total ida y vuelta</> ' . $ms . ' ms');

        if (filled($meta['degradacion'] ?? null)) {
            $this->line('  <fg=yellow>Degradación</>  ' . $meta['degradacion']);
        }

        if (filled($decision['note'] ?? null)) {
            $this->line('  <fg=gray>Nota asesor</>  ' . $decision['note']);
        }

        $this->newLine();
        $this->line('  ── Lo que recibiría el cliente ──────────────');
        $this->newLine();

        foreach (explode("\n", $result->text) as $linea) {
            $this->line('  <fg=white>' . $linea . '</>');
        }

        $this->newLine();

        if (! $this->option('enviar')) {
            $this->line('  <fg=gray>No se envió nada. Añade --enviar para mandarlo de verdad.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        if (! $conversation->isWindowOpen()) {
            $this->error('  No se envía: la ventana de 24 h está cerrada (el cliente no escribe desde hace más de un día).');

            return self::FAILURE;
        }

        // dispatchSync y no dispatch: el objetivo es ver el resultado ahora, y
        // en esta máquina no hay un worker de cola levantado.
        ProcessWhatsAppMenu::dispatchSync(
            $instance->id,
            $conversation->id,
            null,
            null,
            '',
            null,
            $result,
            $decision['note']
        );

        $this->info('  Enviado. Revisa la conversación en el panel.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function resolveCompany(): ?int
    {
        if ($this->option('empresa')) {
            return (int) $this->option('empresa');
        }

        $encendidas = CompanyIntegration::where('key', CompanyIntegration::KEY_AI_MENUS)
            ->where('enabled', true)
            ->pluck('company_id');

        if ($encendidas->isEmpty()) {
            $this->error('Ninguna empresa tiene la IA encendida. Enciéndela en Menús de WhatsApp.');

            return null;
        }

        if ($encendidas->count() > 1) {
            $this->error('Varias empresas tienen la IA encendida (' . $encendidas->implode(', ') . '). Indica --empresa=');

            return null;
        }

        return (int) $encendidas->first();
    }

    /**
     * Una conversación de esa empresa sobre la que probar.
     *
     * Se usa una real y no una inventada porque el flujo identifica al cliente
     * por su número de WhatsApp: con un teléfono ficticio, Integra no lo
     * encuentra y la prueba sólo ejercitaría el camino de "pedir el documento".
     */
    private function resolveConversation(int $companyId): ?WhatsAppConversation
    {
        if ($this->option('conversacion')) {
            $conversation = WhatsAppConversation::find((int) $this->option('conversacion'));

            if (! $conversation) {
                $this->error('No existe la conversación ' . $this->option('conversacion'));

                return null;
            }

            return $conversation;
        }

        $instanceIds = Instance::where('company_id', $companyId)->pluck('id');

        $conversation = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->orderByDesc('last_message_at')
            ->first();

        if (! $conversation) {
            $this->error('Esa empresa no tiene ninguna conversación de WhatsApp todavía.');
            $this->line('  <fg=gray>Escríbele al número desde un móvil y vuelve a intentarlo.</>');

            return null;
        }

        return $conversation;
    }
}
