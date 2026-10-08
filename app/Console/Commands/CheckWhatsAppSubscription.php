<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Models\WhatsAppMessage;
use App\Services\EventosDePlantilla;
use App\Services\MetaWhatsAppService;
use Illuminate\Console\Command;

/**
 * Le pregunta a Meta si la WABA de cada instancia sigue suscrita al campo
 * `messages`, que es el que entrega los mensajes entrantes.
 *
 * El síntoma que diagnostica: el cliente escribe, en su teléfono el mensaje
 * sale entregado, pero en el CRM no aparece nada y el log del webhook no tiene
 * ni rastro de su número. Si Meta no envía el evento, no hay nada que guardar,
 * y desde el servidor es imposible distinguirlo de "nadie ha escrito".
 */
class CheckWhatsAppSubscription extends Command
{
    protected $signature = 'whatsapp:check-subscription
        {--instance= : Revisa solo esta instancia (id)}
        {--last=0 : Lista los últimos N entrantes con su remitente}';

    protected $description = 'Comprueba contra Meta que las instancias reciban mensajes entrantes';

    public function __construct(private MetaWhatsAppService $meta)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $instances = Instance::where('active', true)
            ->when($this->option('instance'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')
            ->get();

        if ($instances->isEmpty()) {
            $this->warn('No hay instancias activas que revisar.');
            return self::SUCCESS;
        }

        $problems = 0;

        foreach ($instances as $instance) {
            $this->newLine();
            $this->line("<options=bold>#{$instance->id} · {$instance->name}</> (empresa {$instance->company_id})");
            $this->line("  phone_number_id: " . ($instance->phone_number_id ?: '—'));
            $this->line("  waba_id:         " . ($instance->waba_id ?: '—'));

            $problems += $this->reportLastInbound($instance);

            $falta = collect(['waba_id' => $instance->waba_id, 'access_token' => $instance->access_token])
                ->filter(fn ($v) => empty($v))
                ->keys();

            if ($falta->isNotEmpty()) {
                $this->warn('  ⚠️  Falta ' . $falta->implode(' y ') . ': no se puede consultar a Meta.');
                $problems++;
                continue;
            }

            $problems += $this->reportSubscription($instance);
            $problems += $this->reportAppFields($instance);
        }

        $this->newLine();

        if ($problems === 0) {
            $this->info('Todo en orden: las instancias revisadas están suscritas y reciben entrantes.');
            return self::SUCCESS;
        }

        $this->warn("Se encontraron {$problems} problema(s). Revisa los avisos de arriba.");
        $this->newLine();
        $this->line('Si falta la suscripción, se re-suscribe desde Ajustes de WhatsApp → "Suscribir webhook",');
        $this->line('o con POST /{waba_id}/subscribed_apps usando el token de la instancia.');
        $this->line('Los campos de plantillas se añaden con el mismo botón (o al habilitar llamadas).');

        return self::SUCCESS;
    }

    /**
     * Cuándo entró el último mensaje del cliente. Una instancia que lleva días
     * sin recibir nada, teniendo chats activos, es la que hay que mirar.
     */
    private function reportLastInbound(Instance $instance): int
    {
        $last = WhatsAppMessage::whereHas(
            'conversation',
            fn ($q) => $q->where('instance_id', $instance->id)
        )->where('direction', 'inbound')->latest('created_at')->first();

        if (! $last) {
            $this->warn('  ⚠️  Nunca ha recibido un mensaje entrante.');
            return 1;
        }

        $hours = $last->created_at->diffInHours(now());
        $when = "{$last->created_at->format('Y-m-d H:i')} (hace " . $last->created_at->diffForHumans(null, true) . ')';

        $stale = $hours >= 24;

        $stale
            ? $this->warn("  ⚠️  Último entrante: {$when}")
            : $this->line("  Último entrante: {$when}");

        // La lista sirve igual (o más) cuando la instancia lleva rato sin
        // recibir: dice quién fue el último en escribir.
        $this->listRecentInbound($instance);

        return $stale ? 1 : 0;
    }

    /**
     * Quién escribió de verdad, con su número tal como está guardado.
     *
     * Resuelve la confusión de buscar por un número y no encontrar nada: si el
     * remitente real es otro (el cliente escribe desde una línea distinta, o el
     * número que se estaba buscando era el equivocado), aquí se ve enseguida.
     */
    private function listRecentInbound(Instance $instance): void
    {
        $limit = (int) $this->option('last');

        if ($limit <= 0) {
            return;
        }

        $messages = WhatsAppMessage::with('conversation:id,wa_id,name')
            ->whereHas('conversation', fn ($q) => $q->where('instance_id', $instance->id))
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->limit($limit)
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        $this->table(
            ['cuándo', 'de', 'nombre', 'conv', 'contenido'],
            $messages->map(fn ($m) => [
                $m->created_at->format('m-d H:i'),
                $m->conversation?->wa_id ?? '—',
                mb_substr((string) ($m->conversation?->name ?? ''), 0, 18),
                $m->conversation_id,
                mb_substr((string) ($m->content ?? "[{$m->type}]"), 0, 40),
            ])->all()
        );
    }

    /**
     * Apps suscritas a la WABA y con qué campos. Sin `messages` no llegan ni
     * los mensajes del cliente ni los acuses de entrega.
     */
    private function reportSubscription(Instance $instance): int
    {
        $result = $this->meta->listSubscribedApps($instance->waba_id, $instance->access_token);

        if (! ($result['success'] ?? false)) {
            $error = $result['error']['error']['message'] ?? json_encode($result['error'] ?? null);
            $this->error("  ❌ Meta no respondió: {$error}");
            return 1;
        }

        $apps = $result['data']['data'] ?? [];

        if (empty($apps)) {
            $this->error('  ❌ Ninguna app suscrita a esta WABA: Meta no enviará ningún webhook.');
            return 1;
        }

        $subscribedToMessages = false;

        foreach ($apps as $app) {
            $name = $app['whatsapp_business_api_data']['name'] ?? '(sin nombre)';
            $id = $app['whatsapp_business_api_data']['id'] ?? '—';

            // Meta no siempre devuelve la lista de campos en este endpoint; si no
            // viene, la suscripción existe pero no se puede afinar más desde aquí.
            $fields = $app['whatsapp_business_api_data']['field_names']
                ?? $app['field_names']
                ?? null;

            $this->line("  App suscrita: {$name} ({$id})");

            if ($fields === null) {
                $this->line('    campos: Meta no los reporta en este endpoint');
                $subscribedToMessages = true;
                continue;
            }

            $this->line('    campos: ' . implode(', ', (array) $fields));

            if (in_array('messages', (array) $fields, true)) {
                $subscribedToMessages = true;
            }
        }

        if (! $subscribedToMessages) {
            $this->error('  ❌ La WABA no está suscrita al campo "messages": no llegarán entrantes.');
            return 1;
        }

        return 0;
    }

    /** Campos de la app ya consultados en esta ejecución, por app_id. */
    private array $camposPorApp = [];

    /**
     * Los campos se suscriben a nivel de app, no de WABA. Una app con sólo
     * `messages` recibe los mensajes pero no se entera de que Meta pausó,
     * rechazó o recategorizó una plantilla: las campañas seguían enviando
     * una plantilla pausada y la pantalla de respaldo la daba por aprobada.
     */
    private function reportAppFields(Instance $instance): int
    {
        $debug = $this->meta->debugToken($instance->access_token);
        $appId = $debug['data']['app_id'] ?? null;

        if (! ($debug['success'] ?? false) || ! $appId) {
            $this->warn('  ⚠️  No se pudo identificar la app del token: no se revisan los campos de plantillas.');
            return 0;
        }

        if (! array_key_exists($appId, $this->camposPorApp)) {
            $secret = $this->meta->appSecretForAppId((string) $appId);
            $subs = $secret ? $this->meta->getAppSubscriptions((string) $appId, $secret) : null;

            $this->camposPorApp[$appId] = ($subs['success'] ?? false)
                ? collect($subs['data']['data'] ?? [])
                    ->first(fn ($s) => ($s['object'] ?? null) === 'whatsapp_business_account')['fields'] ?? []
                : null;
        }

        $campos = $this->camposPorApp[$appId];

        if ($campos === null) {
            $this->warn("  ⚠️  No se pudo leer la suscripción de la app {$appId} (¿falta su secreto en META_APP_SECRETS?).");
            return 0;
        }

        $nombres = collect($campos)->map(fn ($f) => is_array($f) ? ($f['name'] ?? null) : $f)->filter()->all();
        $faltan = array_values(array_diff(EventosDePlantilla::CAMPOS, $nombres));

        if ($faltan === []) {
            $this->line("  App {$appId}: recibe los avisos de plantillas.");
            return 0;
        }

        $this->error("  ❌ La app {$appId} no está suscrita a: " . implode(', ', $faltan)
            . '. Integra no se entera de pausas, rechazos ni cambios de categoría.');

        return 1;
    }
}
