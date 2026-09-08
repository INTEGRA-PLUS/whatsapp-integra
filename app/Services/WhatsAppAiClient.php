<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMessage;
use App\Support\AiDecision;
use App\Support\MenuActionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le pregunta al flujo de IA qué hacer con un mensaje.
 *
 * El razonamiento no vive aquí: vive en un flujo de n8n que entiende la
 * petición, consulta Integra y **arma el texto con las cifras ya formateadas**.
 * Este servicio sólo empaqueta el contexto, hace la llamada y traduce la
 * respuesta a un MenuActionResult, que es lo único que el resto del sistema
 * sabe ejecutar.
 *
 * Esa frontera es deliberada: el flujo decide y devuelve; enviar por WhatsApp,
 * registrar la burbuja, abrir el flujo pendiente y asignar el asesor lo sigue
 * haciendo ProcessWhatsAppMenu, que ya sabía hacerlo para las opciones del
 * menú. Así la IA no estrena un camino de envío propio que pudiera divergir.
 */
class WhatsAppAiClient
{
    /** Cuántos mensajes del hilo se le pasan como contexto. */
    private const HISTORY = 10;

    /**
     * Eventos de negocio que la IA puede pedir emitir.
     *
     * Es una lista blanca y no un paso a través porque al otro lado hay un
     * flujo que alguien puede editar sin tocar este código: un `evento` libre
     * dejaría que quien edita n8n dispare cualquier webhook de la empresa.
     * Son exactamente los mismos que emite el menú desde
     * WhatsAppMenuActionService, para que el sistema de la empresa no tenga
     * que distinguir de qué camino vino el cliente.
     */
    private const EVENTS = [
        'invoice.queried',
        'service.checked',
        'ticket.created',
        'payment.requested',
    ];

    /** La configuración de IA de una empresa, o null si no está lista. */
    public static function for(int $companyId): ?CompanyIntegration
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->first();

        return $integration && $integration->aiReady() ? $integration : null;
    }

    public static function enabled(int $companyId): bool
    {
        return filled(config('services.ai_menus.webhook_url')) && self::for($companyId) !== null;
    }

    /**
     * @return ?AiDecision null cuando la IA no puede hacerse cargo: quien llama
     *                    debe entonces dejar el mensaje seguir su curso normal.
     */
    public function ask(
        Instance $instance,
        WhatsAppConversation $conversation,
        string $message,
        ?WhatsAppBotFlow $flow = null
    ): ?AiDecision {
        $url = config('services.ai_menus.webhook_url');
        $integration = self::for($instance->company_id);

        if (blank($url) || ! $integration) {
            return null;
        }

        // El payload se arma FUERA del try a propósito. Dentro, cualquier error
        // nuestro al construirlo se registraría como "el flujo no respondió" y
        // mandaría a alguien a depurar n8n por un fallo que está en PHP.
        $payload = $this->payload($instance, $conversation, $message, $flow, $integration);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.ai_menus.timeout', 180))
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ El flujo de IA no respondió', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::channel('whatsapp')->warning('⚠️ El flujo de IA respondió con error', [
                'conversation_id' => $conversation->id,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        return $this->translate($response->json() ?? [], $conversation);
    }

    /**
     * Traduce la respuesta del flujo.
     *
     * Se valida en vez de confiar porque al otro lado hay un flujo que alguien
     * puede editar sin tocar este código: un `step` que no exista dejaría a la
     * conversación esperando una respuesta que nadie sabe retomar, y un
     * `evento` libre dispararía webhooks que la empresa no configuró.
     */
    private function translate(array $data, WhatsAppConversation $conversation): ?AiDecision
    {
        // El flujo dice "no me hago cargo" (la IA está apagada para esa
        // empresa, se agotaron los turnos, faltan datos en la petición).
        if (($data['handled'] ?? false) !== true) {
            Log::channel('whatsapp')->info('ℹ️ La IA no se hizo cargo del mensaje', [
                'conversation_id' => $conversation->id,
                'motivo' => data_get($data, 'meta.motivo'),
            ]);

            return null;
        }

        $text = trim((string) ($data['text'] ?? ''));
        $handoff = ($data['handoff'] ?? false) === true;
        $step = $data['step'] ?? null;
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $note = trim((string) ($data['nota_asesor'] ?? '')) ?: null;

        $pasos = [
            WhatsAppBotFlow::STEP_IDENTIFICATION,
            WhatsAppBotFlow::STEP_CONTRACT,
            WhatsAppBotFlow::STEP_REPORT,
        ];

        if ($step !== null && ! in_array($step, $pasos, true)) {
            Log::channel('whatsapp')->warning('⚠️ La IA devolvió un paso desconocido; se trata como respuesta cerrada', [
                'conversation_id' => $conversation->id,
                'step' => $step,
            ]);
            $step = null;
        }

        [$event, $eventData] = $this->event($data, $conversation);
        $client = $this->client($meta);

        // Sin texto no hay nada que mandar. Si además pedía derivar, se deriva
        // igual —el chat tiene que llegar a una persona—, y si no, la IA no
        // aporta nada y el mensaje sigue su curso normal.
        if ($text === '') {
            return $handoff
                ? new AiDecision(MenuActionResult::escalate(''), $note, $meta, $event, $eventData, $client)
                : null;
        }

        if ($handoff) {
            return new AiDecision(MenuActionResult::escalate($text), $note, $meta, $event, $eventData, $client);
        }

        if ($step !== null) {
            // La marca de que preguntó la IA viaja en el contexto: es lo que
            // hace que la respuesta del cliente vuelva a la IA y no al
            // servicio de acciones del menú, que no sabría retomarla.
            $context['action'] = WhatsAppBotFlow::ACTION_AI;

            return new AiDecision(
                MenuActionResult::ask($text, $step, $context), $note, $meta, $event, $eventData, $client
            );
        }

        return new AiDecision(MenuActionResult::reply($text), $note, $meta, $event, $eventData, $client);
    }

    /**
     * El evento de negocio que el flujo pide emitir.
     *
     * Es la mitad que faltaba de la promesa "la IA hace lo mismo que el menú":
     * el menú avisa a los sistemas de la empresa cada vez que resuelve algo, y
     * `payment.requested` en particular es quien genera el cobro. Sin esto, un
     * cliente que pagaba por el menú generaba cobro y el mismo cliente
     * atendido por la IA no.
     *
     * @return array{0: ?string, 1: array}
     */
    private function event(array $data, WhatsAppConversation $conversation): array
    {
        $event = $data['evento'] ?? null;

        if (blank($event)) {
            return [null, []];
        }

        if (! in_array($event, self::EVENTS, true)) {
            Log::channel('whatsapp')->warning('⚠️ La IA pidió emitir un evento desconocido; se descarta', [
                'conversation_id' => $conversation->id,
                'evento' => is_string($event) ? mb_substr($event, 0, 60) : gettype($event),
            ]);

            return [null, []];
        }

        return [$event, is_array($data['datos_evento'] ?? null) ? $data['datos_evento'] : []];
    }

    /**
     * A quién identificó el flujo en Integra, si a alguien.
     *
     * El flujo consulta Integra por su cuenta, así que muchas veces sabe quién
     * es el cliente antes que nosotros. Guardarlo evita que en el mensaje
     * siguiente —lo atienda la IA o el menú— se le vuelva a pedir la cédula a
     * quien ya la dio.
     */
    private function client(array $meta): array
    {
        $client = data_get($meta, 'cliente');

        if (! is_array($client)) {
            return [];
        }

        return array_filter([
            'id' => $client['id'] ?? null,
            'identificacion' => trim((string) ($client['identificacion'] ?? '')) ?: null,
            'nombre' => trim((string) ($client['nombre'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * El contexto que el flujo necesita. Todo lo que la IA podrá afirmar sale
     * de aquí, así que se manda explícito y acotado.
     */
    private function payload(
        Instance $instance,
        WhatsAppConversation $conversation,
        string $message,
        ?WhatsAppBotFlow $flow,
        CompanyIntegration $integration
    ): array {
        $integra = Integra::connection($instance->company_id);

        return [
            'empresa' => [
                'id' => $instance->company_id,
                'nombre' => $instance->company->name ?? '',
            ],
            // Lo que decide la empresa: el interruptor y hasta dónde llega la
            // IA. El modelo y el servidor de Ollama siguen siendo de la
            // plataforma y viven en el flujo de n8n —mandarlos desde aquí
            // sería mantener los mismos valores en dos sitios—, pero los
            // permisos no: "consultar mi factura" y "crear un radicado a mi
            // nombre" no son la misma decisión, y la toma cada empresa. El
            // flujo los cruza con los suyos, así que esto puede restringir
            // pero nunca conceder más de lo que la plataforma permite.
            'ia' => [
                'habilitada' => true,
                'permisos' => $integration->aiPermissionMap(),
            ],
            // El flujo consulta Integra por su cuenta con estas credenciales.
            // Si la empresa no lo tiene conectado se manda vacío y el flujo
            // deriva a un asesor, que es lo mismo que hace el menú.
            'integra' => [
                'base_url' => $integra?->base_url,
                'token' => $integra?->access_token,
            ],
            'conversacion' => [
                'id' => $conversation->id,
                'telefono' => $conversation->phone_number,
                'nombre' => $conversation->name,
                'turno' => $this->turn($conversation),
                'documento_conocido' => data_get($conversation->metadata ?? [], 'integra.identificacion'),
                'cliente_id' => data_get($conversation->metadata ?? [], 'integra.cliente_id'),
            ],
            'mensaje' => $message,
            'historial' => $this->history($conversation),
            // Los ajustes de las opciones del menú que la IA también necesita:
            // el enlace de pago y con qué servicio entra un radicado. Se toman
            // de las opciones que el admin ya configuró, para no pedir dos
            // veces lo mismo en dos sitios distintos.
            'opcion' => $this->menuDefaults($instance->company_id),
            'flujo' => [
                'step' => $flow?->step,
                'context' => $flow?->context ?? [],
            ],
        ];
    }

    /**
     * Cuántas veces ha contestado ya la IA en este hilo.
     *
     * Es el freno para que un cliente no se quede atrapado conversando con un
     * modelo: pasado el tope, el flujo deja de hacerse cargo. Se cuenta sobre
     * los mensajes salientes marcados por la IA en las últimas 24 h, que es la
     * ventana en la que la conversación sigue viva.
     */
    private function turn(WhatsAppConversation $conversation): int
    {
        return WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('metadata->action_type', WhatsAppBotFlow::ACTION_AI)
            ->where('sent_at', '>=', now()->subDay())
            ->count() + 1;
    }

    /** @return list<array{rol: string, texto: string}> */
    private function history(WhatsAppConversation $conversation): array
    {
        return WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereIn('type', ['text', 'interactive'])
            ->orderByDesc('id')
            ->limit(self::HISTORY)
            ->get(['direction', 'content'])
            ->reverse()
            ->map(fn (WhatsAppMessage $m) => [
                'rol' => $m->direction === 'outbound' ? 'bot' : 'cliente',
                'texto' => (string) $m->content,
            ])
            ->filter(fn (array $m) => trim($m['texto']) !== '')
            ->values()
            ->all();
    }

    /**
     * Ajustes de negocio ya configurados en el menú de la empresa.
     *
     * Se toma la primera opción de cada tipo que los tenga. No es exacto —una
     * empresa podría tener dos enlaces de pago distintos—, pero pedirle al
     * admin que los reconfigure para la IA sería garantizar que un día no
     * coincidan con los del menú.
     */
    private function menuDefaults(int $companyId): array
    {
        $options = WhatsAppMenuOption::whereHas('menu', fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('action_type', ['pagar_en_linea', 'reportar_falla'])
            ->orderBy('id')
            ->get();

        $pago = $options->first(fn (WhatsAppMenuOption $o) => filled($o->setting('payment_url')));
        $falla = $options->first(fn (WhatsAppMenuOption $o) => filled($o->setting('radicado_servicio')));

        return [
            'payment_url' => $pago?->setting('payment_url'),
            'radicado_servicio' => $falla?->setting('radicado_servicio'),
            'radicado_prioridad' => (int) ($falla?->setting('radicado_prioridad') ?? 2),
            'radicado_tecnico' => $falla?->setting('radicado_tecnico'),
        ];
    }
}
