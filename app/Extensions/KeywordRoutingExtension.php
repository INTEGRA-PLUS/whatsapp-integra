<?php

namespace App\Extensions;

use App\Extensions\Contracts\HandlesInboundMessage;
use App\Models\CompanyExtension;
use App\Models\KanbanColumn;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\ExtensionAlertNotification;
use App\Services\AgentAssignmentService;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * Clasifica y reparte los mensajes entrantes según lo que diga el cliente.
 *
 * "Garantía", "reclamo", "quiero comprar" y "cancelar" no son la misma
 * conversación y hoy caen en la misma bandeja indiferenciada, donde las ordena
 * la hora de llegada. Esto es el enrutado que ya se hace a ojo, escrito una vez.
 *
 * Cubre además la intención de compra —avisar al equipo cuando alguien dice que
 * quiere comprar— sin depender del flujo de IA de n8n, que es de otro equipo y
 * cuyas inferencias tardan minutos: un enrutado que llega tarde no enruta nada.
 *
 * Lo que NO hace: no le contesta al cliente. Responder es trabajo de los menús y
 * de las respuestas automáticas, y si esto también respondiera el cliente
 * recibiría dos cosas por el mismo mensaje.
 */
class KeywordRoutingExtension extends Extension implements HandlesInboundMessage
{
    public const ASIGNAR_NADIE = 'none';

    public const ASIGNAR_MENOS_CARGADO = 'least_busy';

    public function __construct(private AgentAssignmentService $assignment) {}

    public function slug(): string
    {
        return 'keyword_routing';
    }

    public function name(): string
    {
        return 'Enrutado por palabra clave';
    }

    public function description(): string
    {
        return 'Etiqueta, asigna y alerta según lo que escriba el cliente, en cuanto lo escribe.';
    }

    public function detail(): string
    {
        return 'Define reglas de "palabra clave → acción". Cuando llega un mensaje del cliente se '
            .'aplica la primera regla que coincida: puede etiquetar la conversación, asignarla a '
            .'un agente concreto o al que menos carga tenga, y avisar por la campana.'
            ."\n\n"
            .'Sólo mira mensajes de texto del cliente y sólo actúa una vez por mensaje (manda la '
            .'primera regla que coincide, de arriba abajo). No le responde nada al cliente y nunca '
            .'le quita un chat a un agente que ya lo tenía asignado.';
    }

    public function icon(): string
    {
        return 'Route';
    }

    public function category(): string
    {
        return self::CATEGORIA_AUTOMATIZACION;
    }

    public function permissions(): array
    {
        return [
            'Leer el texto de los mensajes entrantes',
            'Aplicar etiquetas a las conversaciones',
            'Asignar conversaciones a tus agentes',
            'Enviar notificaciones a tu equipo',
        ];
    }

    public function hooks(): array
    {
        return ['Al llegar un mensaje del cliente, antes de las respuestas automáticas'];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'rules',
                'type' => 'rules',
                'label' => 'Reglas',
                'help' => 'Se evalúan de arriba abajo y manda la primera que coincida.',
                'default' => [],
                // El editor de reglas necesita las dos listas a la vez: una
                // regla elige etiqueta y agente en la misma fila.
                'sources' => ['tags', 'agents'],
            ],
            [
                'key' => 'match_mode',
                'type' => 'select',
                'label' => 'Cómo coincidir',
                'help' => '«Palabra completa» evita que "compra" salte con "incomprable". '
                    .'No distingue mayúsculas ni tildes en ninguno de los dos modos.',
                'default' => 'word',
                'options' => [
                    ['value' => 'word', 'label' => 'Palabra completa'],
                    ['value' => 'contains', 'label' => 'En cualquier parte del texto'],
                ],
            ],
            [
                'key' => 'first_message_only',
                'type' => 'boolean',
                'label' => 'Sólo el primer mensaje de la conversación',
                'help' => 'Útil para enrutar al abrir el chat y no volver a tocarlo después.',
                'default' => false,
            ],
        ];
    }

    public function sanitizeSettings(array $input, int $companyId): array
    {
        return [
            'rules' => $this->sanearReglas($input['rules'] ?? [], $companyId),
            'match_mode' => ($input['match_mode'] ?? null) === 'contains' ? 'contains' : 'word',
            'first_message_only' => (bool) ($input['first_message_only'] ?? false),
        ];
    }

    public function onInboundMessage(
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        CompanyExtension $installed
    ): void {
        if ($message->type !== 'text' || trim((string) $message->content) === '') {
            return;
        }

        $settings = $installed->settings();
        $reglas = $settings['rules'] ?? [];

        if (empty($reglas)) {
            return;
        }

        if ($settings['first_message_only'] && ! $this->esPrimerMensaje($conversation, $message)) {
            return;
        }

        $texto = $this->normalizar((string) $message->content);
        $companyId = (int) $installed->company_id;

        foreach ($reglas as $regla) {
            if (! $this->coincide($texto, $regla['keywords'], $settings['match_mode'])) {
                continue;
            }

            $this->aplicar($regla, $conversation, $companyId);

            // La primera que coincide manda: encadenar todas las reglas haría
            // que un mensaje con dos palabras clave acabara asignado a dos
            // agentes distintos, y el segundo pisaría al primero sin que nadie
            // pudiera explicar por qué.
            return;
        }
    }

    private function aplicar(array $regla, WhatsAppConversation $conversation, int $companyId): void
    {
        if (! empty($regla['tag_id'])) {
            $this->etiquetar($conversation, (int) $regla['tag_id'], $companyId);
        }

        $this->asignar($regla, $conversation, $companyId);

        if (! empty($regla['notify'])) {
            $this->avisar($regla, $conversation, $companyId);
        }
    }

    /**
     * Un chat ya asignado no se toca.
     *
     * Reasignar por una palabra clave le quita de las manos al agente una
     * conversación que puede llevar diez minutos atendiendo, y el cliente acaba
     * contándole lo mismo a dos personas.
     */
    private function asignar(array $regla, WhatsAppConversation $conversation, int $companyId): void
    {
        $destino = $regla['assign'] ?? self::ASIGNAR_NADIE;

        if ($destino === self::ASIGNAR_NADIE || $conversation->assigned_to !== null) {
            return;
        }

        $agente = $destino === self::ASIGNAR_MENOS_CARGADO
            ? $this->assignment->leastBusy($companyId)
            : User::where('company_id', $companyId)
                ->where('id', $destino)
                ->where('active', true)
                ->first();

        if (! $agente) {
            return;
        }

        $conversation->update(['assigned_to' => $agente->id]);

        // El mismo evento que emite el chat al asignar a mano: para el ERP que
        // escucha los webhooks, una asignación es una asignación venga de donde
        // venga.
        WebhookDispatcher::emit(
            $companyId,
            'conversation.assigned',
            WebhookDispatcher::conversationPayload($conversation, ['assigned_to' => $agente->id])
        );
    }

    private function etiquetar(WhatsAppConversation $conversation, int $tagId, int $companyId): void
    {
        $tag = Tag::where('company_id', $companyId)->find($tagId);

        if (! $tag) {
            return;
        }

        $column = KanbanColumn::where('company_id', $companyId)->where('tag_id', $tag->id)->first();

        if ($column) {
            $conversation->update(['kanban_column_id' => $column->id]);
        }

        $conversation->tags()->syncWithoutDetaching([$tag->id]);

        WebhookDispatcher::emit(
            $companyId,
            'conversation.tag_added',
            WebhookDispatcher::conversationPayload($conversation, ['tag' => ['id' => $tag->id, 'name' => $tag->name]])
        );
    }

    private function avisar(array $regla, WhatsAppConversation $conversation, int $companyId): void
    {
        $conversation->refresh();

        // Al agente asignado si lo hay —incluido el que esta misma regla acaba
        // de asignar—, y a los administradores si el chat sigue huérfano.
        $destinatarios = $conversation->assigned_to
            ? User::where('company_id', $companyId)
                ->where('id', $conversation->assigned_to)
                ->where('active', true)
                ->get()
            : User::where('company_id', $companyId)
                ->where('role', 'admin')
                ->where('active', true)
                ->get();

        if ($destinatarios->isEmpty()) {
            return;
        }

        $quien = $conversation->name ?: $conversation->phone_number;
        $etiqueta = $regla['label'] ?: 'Palabra clave detectada';

        Notification::send($destinatarios, new ExtensionAlertNotification(
            $etiqueta,
            "«{$quien}» escribió algo que coincide con la regla «{$etiqueta}».",
            'Enrutado',
            $conversation
        ));
    }

    private function esPrimerMensaje(WhatsAppConversation $conversation, WhatsAppMessage $message): bool
    {
        $primero = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderBy('id')
            ->value('id');

        return $primero !== null && (int) $primero === (int) $message->id;
    }

    /**
     * @param  list<string>  $keywords
     */
    private function coincide(string $texto, array $keywords, string $modo): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = $this->normalizar($keyword);

            if ($keyword === '') {
                continue;
            }

            if ($modo === 'contains') {
                if (str_contains($texto, $keyword)) {
                    return true;
                }

                continue;
            }

            // \b no sirve aquí: la normalización ya dejó el texto en ASCII, pero
            // una clave de varias palabras ("quiero comprar") rodeada de \b
            // funciona; lo que falla es delimitar con espacios a mano, que no
            // reconoce la clave al principio o al final del mensaje.
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $texto) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas y sin tildes.
     *
     * Media Colombia escribe "garantia" sin tilde y la otra media "garantía":
     * comparar en crudo obligaría al admin a escribir las dos versiones de cada
     * palabra, y la que olvidara sería un cliente mal enrutado.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sanearReglas(mixed $reglas, int $companyId): array
    {
        if (! is_array($reglas)) {
            return [];
        }

        $agentesValidos = User::where('company_id', $companyId)
            ->where('active', true)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $limpias = [];

        foreach ($reglas as $regla) {
            if (! is_array($regla)) {
                continue;
            }

            $keywords = collect(is_array($regla['keywords'] ?? null)
                ? $regla['keywords']
                : explode(',', (string) ($regla['keywords'] ?? '')))
                ->map(fn ($k) => trim((string) $k))
                ->filter()
                ->unique()
                ->take(30)
                ->values()
                ->all();

            // Una regla sin palabras clave coincidiría con todo o con nada según
            // el modo; guardarla sólo sirve para confundir a quien la lea luego.
            if (empty($keywords)) {
                continue;
            }

            $destino = (string) ($regla['assign'] ?? self::ASIGNAR_NADIE);
            if ($destino !== self::ASIGNAR_MENOS_CARGADO && ! in_array($destino, $agentesValidos, true)) {
                $destino = self::ASIGNAR_NADIE;
            }

            $tagId = $regla['tag_id'] ?? null;

            $limpias[] = [
                'label' => mb_substr(trim((string) ($regla['label'] ?? '')), 0, 60) ?: 'Sin nombre',
                'keywords' => $keywords,
                'tag_id' => $tagId
                    ? Tag::where('company_id', $companyId)->where('id', $tagId)->value('id')
                    : null,
                'assign' => $destino,
                'notify' => (bool) ($regla['notify'] ?? false),
            ];

            if (count($limpias) >= 20) {
                break;
            }
        }

        return $limpias;
    }
}
