<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Events\ConversationEvent;
use App\Support\ConversationNotice;
use App\Support\Realtime;
use App\Models\Instance;
use App\Models\KanbanColumn;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\ConversationDeletionRequest;
use App\Models\User;
use App\Services\MetaWhatsAppService;
use App\Services\WebhookDispatcher;
use App\Jobs\DeliverWhatsAppMessage;
use App\Http\Controllers\KanbanController;
use Inertia\Inertia;

class ChatController extends Controller
{
    private $metaService;

    public function __construct(MetaWhatsAppService $metaService)
    {
        // $this->middleware('auth'); // Middleware is usually applied in routes in Laravel 11
        $this->metaService = $metaService;
    }

    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }
        
        $instances = Instance::where('company_id', $user->company_id)
            ->where('type', 'meta')
            ->where('active', true)
            ->get();

        return Inertia::render('Chat/Index', [
            'instances'    => $instances,
            'integrations' => $this->activeChatIntegrations($user->company_id),
        ]);
    }

    /**
     * Integraciones habilitadas que exponen un disparador en el composer del chat.
     */
    private function activeChatIntegrations(int $companyId): array
    {
        return \App\Models\CompanyIntegration::where('company_id', $companyId)
            ->where('enabled', true)
            ->where('status', 'connected')
            ->whereNotNull('trigger_command')
            ->get()
            ->map(fn ($i) => [
                'key'          => $i->key,
                'name'         => $i->key === \App\Models\CompanyIntegration::KEY_INVOICE_PAYMENTS ? 'Pagos a facturas' : $i->key,
                'trigger_type' => $i->trigger_type,
                'trigger'      => $i->triggerToken(),
            ])
            ->values()
            ->all();
    }

    public function kanban()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $columns = KanbanColumn::where('company_id', $user->company_id)
            ->whereNotNull('tag_id')
            ->orderBy('position')
            ->get();

        $instanceIds = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->pluck('id');

        $total = WhatsAppConversation::whereIn('instance_id', $instanceIds)->count();

        $instances = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->get(['id', 'name']);

        return Inertia::render('Chat/Kanban', [
            'columns'             => $columns,
            'total_conversations' => $total,
            'instances'           => $instances,
        ]);
    }

    public function conversations(Request $request)
    {
        $user = auth()->user();
        $instanceId = $request->instance_id;

        if (!$instanceId) {
            return response()->json(['error' => 'instance_id es requerido'], 400);
        }

        $instance = Instance::where('id', $instanceId)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $conversations = WhatsAppConversation::forInstance($instanceId)
            ->with(['assignedAgent:id,name', 'closedByUser:id,name', 'tags', 'contact:id,name,phone_number,email,notes'])
            ->when($request->search, function ($query, $search) {
                $query->search($search);
            })
            ->when($request->status, function ($query, $status) {
                // Acepta un estado ('closed') o una lista ('open,pending') para que la
                // paginación del backend coincida con el filtro de estado del cliente.
                $list = collect(is_array($status) ? $status : explode(',', (string) $status))
                    ->map(fn ($s) => trim($s))->filter()->values()->all();
                if (count($list) === 1) {
                    $query->where('status', $list[0]);
                } elseif (count($list) > 1) {
                    $query->whereIn('status', $list);
                }
            })
            ->when($request->tag_ids ?? $request->tag_id, function ($query, $tags) {
                $ids = collect(is_array($tags) ? $tags : explode(',', (string) $tags))
                    ->map(fn ($v) => (int) $v)->filter()->values()->all();
                if (!empty($ids)) {
                    // OR: conversaciones que tengan al menos una de las etiquetas seleccionadas.
                    $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $ids));
                }
            })
            ->when($request->assigned_to, function ($query, $assignedTo) {
                if ($assignedTo === 'unassigned') {
                    $query->whereNull('assigned_to');
                } else {
                    $query->where('assigned_to', $assignedTo);
                }
            })
            ->when($request->filter, function ($query, $filter) use ($user) {
                $this->applyFolderFilter($query, $filter, $user);
            })
            ->orderByDesc('last_message_at')
            ->paginate(50);

        return response()->json($this->sanitizeUtf8($conversations->toArray()));
    }

    /**
     * Carpetas contextuales del panel izquierdo del chat (estilo Chatwoot):
     *   - mentions:   conversaciones donde se mencionó al usuario en una nota interna.
     *   - unattended: conversaciones abiertas cuyo último mensaje (no interno) es del cliente,
     *                 es decir, esperan respuesta de un agente.
     */
    private function applyFolderFilter($query, string $filter, $user): void
    {
        if ($filter === 'mentions') {
            $query->whereHas('messages', function ($q) use ($user) {
                $q->where('is_internal', true)
                  ->whereJsonContains('mentions', (int) $user->id);
            });
        } elseif ($filter === 'unattended') {
            $query->where('status', 'open')
                ->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('whatsapp_messages as m')
                        ->whereColumn('m.conversation_id', 'whatsapp_conversations.id')
                        ->where('m.direction', 'inbound')
                        ->where('m.is_internal', false)
                        ->whereRaw('m.created_at = (
                            select max(created_at) from whatsapp_messages
                            where conversation_id = whatsapp_conversations.id and is_internal = 0
                        )');
                });
        }
    }

    /**
     * Conteos para el panel izquierdo del chat: todas, menciones y desatendidas
     * de la instancia seleccionada.
     */
    public function folders(Request $request)
    {
        $user = auth()->user();
        $instanceId = $request->instance_id;

        if (!$instanceId) {
            return response()->json(['error' => 'instance_id es requerido'], 400);
        }

        Instance::where('id', $instanceId)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $base = fn () => WhatsAppConversation::forInstance($instanceId);

        $all = $base()->count();

        $mentions = $base();
        $this->applyFolderFilter($mentions, 'mentions', $user);

        $unattended = $base();
        $this->applyFolderFilter($unattended, 'unattended', $user);

        // Conteo de conversaciones por etiqueta dentro de la instancia.
        $tagCounts = \Illuminate\Support\Facades\DB::table('whatsapp_conversation_tag as ct')
            ->join('whatsapp_conversations as c', 'c.id', '=', 'ct.whatsapp_conversation_id')
            ->where('c.instance_id', $instanceId)
            ->groupBy('ct.tag_id')
            ->selectRaw('ct.tag_id, count(*) as total')
            ->pluck('total', 'tag_id');

        return response()->json([
            'all'        => $all,
            'mentions'   => $mentions->count(),
            'unattended' => $unattended->count(),
            'tags'       => $tagCounts,
        ]);
    }

    /**
     * Inicia (o reutiliza) una conversación hacia un número arbitrario para poder
     * escribirle directamente. Devuelve la conversación, sus mensajes y si la
     * ventana de servicio de 24h está abierta (último mensaje entrante reciente),
     * que determina si se puede enviar texto libre o hace falta una plantilla.
     */
    public function startConversation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'instance_id'  => 'required',
            'phone_number' => 'required|string|max:30',
            'name'         => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $instance = Instance::where('id', $request->instance_id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        if (!$instance->isMetaConfigured()) {
            return response()->json(['success' => false, 'error' => 'Instancia no configurada'], 400);
        }

        // Normaliza: solo dígitos (sin +, espacios ni guiones).
        $phone = preg_replace('/\D/', '', $request->phone_number);
        if (strlen($phone) < 8) {
            return response()->json(['success' => false, 'error' => 'Número de teléfono inválido. Incluye el código de país.'], 422);
        }

        // Un celular colombiano sin el 57 (10 dígitos empezando por 3) crea un
        // hilo que Meta nunca va a reconocer: el webhook siempre trae el número
        // con indicativo, así que el chat se queda vacío para siempre y nadie
        // entiende por qué. Ningún indicativo real produce un número de 10
        // dígitos que empiece por 3, así que se puede rechazar sin ambigüedad.
        if (strlen($phone) === 10 && str_starts_with($phone, '3')) {
            return response()->json([
                'success' => false,
                'error' => "Falta el código de país. Escribe 57{$phone} en vez de {$phone}.",
            ], 422);
        }

        $conversation = WhatsAppConversation::resolveFor(
            $instance->id,
            $phone,
            [
                'phone_number'    => $phone,
                'name'            => $request->name ?: $phone,
                'status'          => 'open',
                'last_message_at' => now(),
            ]
        );

        // Si se reabrió una conversación cerrada, vuelve a marcarla abierta.
        if ($conversation->status !== 'open') {
            $conversation->update(['status' => 'open']);
        }

        // El hilo aparece al instante en la lista del resto del equipo, en vez
        // de esperar hasta 30s a que el poll lo descubra.
        Realtime::push(ConversationEvent::updated(
            $conversation,
            $conversation->wasRecentlyCreated ? 'created' : 'reopened',
        ));

        $sessionOpen = $conversation->isWindowOpen();

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        $conversation->markAsRead();

        $lastInboundWamid = $messages->where('direction', 'inbound')->whereNotNull('wamid')->last()?->wamid;
        if ($lastInboundWamid) {
            $this->metaService->markAsRead($instance->phone_number_id, $lastInboundWamid);
        }

        return response()->json($this->sanitizeUtf8([
            'success'      => true,
            'conversation' => $conversation->load(['assignedAgent:id,name', 'closedByUser:id,name', 'tags']),
            'messages'     => $messages,
            'session_open' => $sessionOpen,
            'timestamp'    => now()->toIso8601String(),
        ]));
    }

    /**
     * Plantillas aprobadas de la instancia, para que un agente pueda abrir un
     * chat nuevo. Endpoint propio del chat (solo sesión) para no exigir el
     * permiso de gestión de plantillas.
     */
    public function templates(Request $request)
    {
        $user = auth()->user();

        $instance = Instance::where('id', $request->instance_id)
            ->where('company_id', $user->company_id)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token')
            ->first();

        if (!$instance) {
            return response()->json(['data' => [], 'resume_template' => null]);
        }

        $resumeTemplate = $instance->resumeTemplateName() ? [
            'name' => $instance->resumeTemplateName(),
            'language' => $instance->resumeTemplateLanguage(),
        ] : null;

        // Sin filtro de status: el frontend filtra APPROVED para la lista de envío,
        // pero también necesita ver PENDING/REJECTED para saber si la plantilla de
        // reanudación del sistema ya fue creada y en qué estado está.
        $result = $this->metaService->listTemplates($instance->waba_id, $instance->access_token, [
            'limit'  => 200,
        ]);

        if (!($result['success'] ?? false)) {
            return response()->json(['data' => [], 'resume_template' => $resumeTemplate]);
        }

        return response()->json([
            'data' => $result['data']['data'] ?? [],
            'resume_template' => $resumeTemplate,
        ]);
    }

    /**
     * Crea (si aún no existe) la plantilla de reanudación de conversación del
     * catálogo por defecto de Integra CRM para la instancia, y la deja
     * configurada como plantilla de reinicio si la instancia no tenía ninguna.
     * Endpoint propio del chat (sin permiso de gestión de plantillas): solo
     * puede crear esta plantilla puntual, nunca una arbitraria.
     */
    public function ensureResumeTemplate(Request $request)
    {
        $user = auth()->user();
        $key = 'reanudar_conversacion_cliente';

        $instance = Instance::where('id', $request->instance_id)
            ->where('company_id', $user->company_id)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token')
            ->first();

        if (!$instance) {
            return response()->json(['success' => false, 'message' => 'No hay una instancia activa con WABA configurado.'], 422);
        }

        $entry = config("whatsapp_default_templates.{$key}");
        if (!$entry) {
            return response()->json(['success' => false, 'message' => 'Plantilla por defecto no configurada.'], 500);
        }

        $payload = [
            'name' => $key,
            'language' => $entry['language'],
            'category' => $entry['category'],
            'components' => $entry['components'],
        ];
        if (!empty($entry['parameter_format'])) {
            $payload['parameter_format'] = $entry['parameter_format'];
        }

        $result = $this->metaService->createTemplate($instance->waba_id, $instance->access_token, $payload);

        $alreadyExists = false;
        if (!$result['success']) {
            $inner = $result['error']['error'] ?? null;
            $code = is_array($inner) ? ($inner['code'] ?? null) : null;
            $subcode = is_array($inner) ? ($inner['error_subcode'] ?? null) : null;

            // Meta: nombre de plantilla duplicado (ya se había creado antes).
            if ((int) $code === 100 && (int) $subcode === 2388023) {
                $alreadyExists = true;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo crear la plantilla en WhatsApp. Intenta de nuevo más tarde.',
                ], 502);
            }
        }

        if (!$instance->resumeTemplateName()) {
            $instance->setResumeTemplate($key, $entry['language']);
            $instance->save();
        }

        return response()->json([
            'success' => true,
            'already_exists' => $alreadyExists,
        ]);
    }

    /**
     * Envía una plantilla aprobada de Meta a una conversación. Es la vía válida
     * para abrir una conversación fuera de la ventana de 24h.
     */
    public function sendTemplate(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'template_name' => 'required|string',
            'language_code' => 'nullable|string',
            'components'    => 'nullable|array',
            'preview'       => 'nullable|string',
            'template_id'   => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $instance = $conversation->instance;

        if (!$instance->isMetaConfigured()) {
            return response()->json(['success' => false, 'error' => 'Instancia no configurada'], 400);
        }

        $templateName = $request->template_name;
        $languageCode = $request->language_code ?: 'es';
        $components   = $request->components ?? [];

        $preview = $request->preview ?: "[Plantilla: {$templateName}]";

        // La llamada a Meta y la descarga de la copia S3 del header multimedia se
        // hacen en DeliverWhatsAppMessage (cola). Aquí solo persistimos "pending".
        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'template',
            'content'         => $preview,
            'direction'       => 'outbound',
            'status'          => 'pending',
            'sent_by'         => $user->id,
            'sent_at'         => now(),
            'metadata'        => [
                'template'   => $templateName,
                'language'   => $languageCode,
                'components' => $components,
            ],
            'template_id'     => $request->template_id,
        ]);

        $conversation->update([
            'last_message'    => $preview,
            'last_message_at' => now(),
        ]);

        DeliverWhatsAppMessage::dispatch($message->id);

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'data'    => $message->load('sender:id,name'),
        ]));
    }

    /**
     * Sube un archivo (imagen/video/documento) a Meta para usarlo como encabezado
     * multimedia al ENVIAR una plantilla. Lo sube a /{phone_number_id}/media y
     * devuelve el media_id; el frontend lo pasa como
     * header.parameters[].{image|video|document}.id en los componentes del envío.
     */
    public function uploadTemplateMedia(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:102400',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $instance = $conversation->instance;
        if (!$instance->isMetaConfigured()) {
            return response()->json(['success' => false, 'error' => 'Instancia no configurada'], 400);
        }

        $file = $request->file('file');
        $result = $this->metaService->uploadMedia(
            $instance->phone_number_id,
            $file->getRealPath(),
            $file->getMimeType()
        );

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $this->metaError($result, 'No se pudo subir el archivo a Meta.'),
                'meta'  => $result['error']['error'] ?? null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'media_id' => $result['id'],
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    public function updates(Request $request)
    {
        $user = auth()->user();
        $instanceId = $request->instance_id;

        if (!$instanceId) {
            return response()->json(['error' => 'instance_id es requerido'], 400);
        }

        // Normaliza `since` a la zona horaria de la app antes de comparar. El cliente
        // puede enviarlo en UTC ("...Z") o con offset ("...-05:00"); MySQL interpreta
        // el offset/Z y desfasa la comparación contra los timestamps (que se guardan
        // en hora local naive), provocando que `updated_at > since` falle y que las
        // conversaciones/mensajes nuevos nunca lleguen al polling. Carbon parsea el
        // instante real y lo lleva a la zona de la app para una comparación correcta.
        $sinceTs = null;
        if ($request->since) {
            try {
                $sinceTs = \Carbon\Carbon::parse($request->since)->setTimezone(config('app.timezone'));
            } catch (\Throwable $e) {
                $sinceTs = $request->since;
            }
        }

        $instance = Instance::where('id', $instanceId)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        // Sin `since` solo sembramos el timestamp del servidor (primer poll), sin
        // devolver toda la lista.
        $updatedConversations = [];
        if ($sinceTs) {
            $updatedConversations = WhatsAppConversation::forInstance($instanceId)
                ->with(['assignedAgent:id,name', 'closedByUser:id,name', 'tags', 'contact:id,name,phone_number,email,notes'])
                ->where('updated_at', '>', $sinceTs)
                ->when($request->filter, function ($query, $filter) use ($user) {
                    $this->applyFolderFilter($query, $filter, $user);
                })
                ->orderByDesc('last_message_at')
                ->get();
        }

        $newMessages = [];
        if ($request->conversation_id && $sinceTs) {
            $newMessages = WhatsAppMessage::where('conversation_id', $request->conversation_id)
                ->with('sender:id,name')
                ->where('created_at', '>', $sinceTs)
                ->orderBy('created_at', 'asc')
                ->get();
        }

        $updatedStatuses = [];
        if ($request->conversation_id && $sinceTs) {
            $updatedStatuses = WhatsAppMessage::where('conversation_id', $request->conversation_id)
                ->where('updated_at', '>', $sinceTs)
                // 'sent' incluido para propagar el paso pending → sent (con su
                // wamid real) que ahora hace DeliverWhatsAppMessage en la cola.
                ->whereIn('status', ['sent', 'delivered', 'read', 'failed'])
                ->select('id', 'wamid', 'status', 'delivered_at', 'read_at', 'error_message', 'error_code', 'error_details')
                ->get();
        }

        return response()->json($this->sanitizeUtf8([
            'conversations' => $updatedConversations,
            'new_messages' => $newMessages,
            'updated_statuses' => $updatedStatuses,
            'timestamp' => now()->toIso8601String()
        ]));
    }

    public function messages($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        $conversation->markAsRead();

        if ($conversation->instance->isMetaConfigured()) {
            $lastInboundWamid = $conversation->messages()
                ->where('direction', 'inbound')
                ->whereNotNull('wamid')
                ->orderBy('created_at', 'desc')
                ->value('wamid');

            if ($lastInboundWamid) {
                $this->metaService->markAsRead($conversation->instance->phone_number_id, $lastInboundWamid);
            }
        }

        return response()->json($this->sanitizeUtf8([
            'conversation' => $conversation,
            'messages' => $messages,
            'timestamp' => now()->toIso8601String()
        ]));
    }

    /**
     * Entrega el adjunto de un mensaje (documento, imagen, video o audio).
     *
     * Sirve el archivo a través de la app en vez de enlazar `media_url` directo
     * por dos motivos: el nombre original del archivo se conserva en la
     * descarga, y los mensajes que solo tienen `media_id` (plantillas enviadas
     * por la API externa, que nunca guardaron copia) se resuelven aquí contra
     * Meta la primera vez que alguien los abre.
     *
     * `?inline=1` lo muestra en el navegador (previsualizar PDF/imagen); sin el
     * parámetro se fuerza la descarga.
     */
    public function downloadMedia(Request $request, $messageId)
    {
        $user = auth()->user();

        $message = WhatsAppMessage::with('conversation.instance')->findOrFail($messageId);
        $instance = $message->conversation?->instance;

        if (! $instance || $instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $mediaUrl = $message->media_url;

        // Sin copia propia: la pedimos a Meta y la guardamos, para que la
        // siguiente apertura (y la de los demás agentes) ya no dependa de ella.
        if (! $mediaUrl) {
            $mediaId = $message->resolvableMediaId();

            if (! $mediaId || empty($instance->access_token)) {
                return $this->mediaError($request, 'Este mensaje no tiene un archivo adjunto disponible.');
            }

            $mediaInfo = $this->metaService->downloadMedia($mediaId, $instance->access_token);

            if (! $mediaInfo) {
                return $this->mediaError($request, 'No se pudo recuperar el archivo desde WhatsApp. Los adjuntos caducan 30 días después del envío.');
            }

            $message->forceFill([
                'media_url'       => $mediaInfo['url'],
                'media_id'        => $message->media_id ?: $mediaId,
                'media_mime_type' => $message->media_mime_type ?: $mediaInfo['mime_type'],
                'filename'        => $message->resolvableFilename() ?: $mediaInfo['filename'],
            ])->save();

            $mediaUrl = $mediaInfo['url'];
        }

        $response = Http::timeout(60)->get($mediaUrl);

        if (! $response->successful()) {
            return $this->mediaError($request, 'El archivo ya no está disponible en el almacenamiento.');
        }

        $filename = $this->safeDownloadName(
            $message->resolvableFilename() ?: basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'archivo')
        );

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($response->body(), 200, [
            'Content-Type' => $message->media_mime_type
                ?: ($response->header('Content-Type') ?: 'application/octet-stream'),
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            'Content-Length' => strlen($response->body()),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * El botón "Descargar" pide el archivo por XHR y muestra el mensaje en la
     * burbuja; el enlace "Ver" abre una pestaña, donde un JSON crudo no se lee.
     */
    private function mediaError(Request $request, string $message)
    {
        if ($request->expectsJson() || ! $request->boolean('inline')) {
            return response()->json(['error' => $message], 404);
        }

        return response(
            '<!doctype html><meta charset="utf-8"><title>Archivo no disponible</title>'
            . '<body style="font-family:system-ui,sans-serif;padding:2rem;color:#111b21">'
            . '<p>' . e($message) . '</p></body>',
            404,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    /**
     * Deja el nombre del adjunto en algo seguro para la cabecera
     * Content-Disposition: viene de un tercero (Meta o la API externa) y podría
     * traer comillas, rutas o saltos de línea.
     */
    private function safeDownloadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name);
        $name = trim($name);

        return $name !== '' ? mb_substr($name, 0, 200) : 'archivo';
    }

    public function sendMessage(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:4096',
            'reply_to_wamid' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $instance = $conversation->instance;

        if (!$instance->isMetaConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'Instancia no configurada'
            ], 400);
        }

        if (!$conversation->isWindowOpen()) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'error' => 'La ventana de 24h para responder libremente expiró. Envía primero una plantilla aprobada.',
            ], 422);
        }

        // En el CRM se guarda el texto limpio (la etiqueta del agente se muestra
        // aparte). El prefijo con el nombre en negrita se antepone al enviar a
        // Meta, dentro del job.
        $replyToWamid = $request->input('reply_to_wamid') ?: null;

        // Persistimos el mensaje como "pending" y respondemos de inmediato; la
        // llamada a Meta (hasta 60s) la hace DeliverWhatsAppMessage en la cola.
        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'reply_to_wamid' => $replyToWamid,
            'type' => 'text',
            'content' => $request->message,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $user->id,
            'sent_at' => now()
        ]);

        $conversation->update([
            'last_message' => $request->message,
            'last_message_at' => now()
        ]);

        DeliverWhatsAppMessage::dispatch($message->id);

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'message' => 'Mensaje encolado',
            'data' => $message->load('sender')
        ]));
    }

    public function sendImage(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|max:5120',
            'caption' => 'nullable|string|max:1024',
            'reply_to_wamid' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        if (!$conversation->isWindowOpen()) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'error' => 'La ventana de 24h para responder libremente expiró. Envía primero una plantilla aprobada.',
            ], 422);
        }

        // El disco s3_media está configurado con 'throw' => false: si el bucket
        // falla, storePublicly() devuelve false en vez de lanzar. Sin esta guarda
        // se guardaba un mensaje con media_url roto y el envío moría después.
        $path = $request->file('image')->storePublicly('whatsapp/media', 's3_media');

        if (!$path) {
            Log::channel('whatsapp')->error('❌ No se pudo subir la imagen al almacenamiento', [
                'conversation_id' => $conversation->id,
                'original_name' => $request->file('image')->getClientOriginalName(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'No se pudo guardar la imagen en el almacenamiento. Intenta de nuevo.',
            ], 500);
        }

        $imageUrl = Storage::disk('s3_media')->url($path);

        $replyToWamid = $request->input('reply_to_wamid') ?: null;

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'reply_to_wamid' => $replyToWamid,
            'type' => 'image',
            'content' => $request->caption ?? '',
            'media_url' => $imageUrl,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $user->id,
            'sent_at' => now()
        ]);

        $conversation->update([
            'last_message' => $request->caption ?? 'Imagen',
            'last_message_at' => now()
        ]);

        DeliverWhatsAppMessage::dispatch($message->id);

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'message' => 'Imagen encolada',
            'data' => $message->load('sender')
        ]));
    }

    /**
     * Envía un documento (PDF, Office, ZIP, etc.) al cliente. El archivo se guarda
     * en nuestro bucket y Meta lo descarga por link.
     *
     * Límite: 30 MB. WhatsApp admite hasta 100 MB, pero nginx (client_max_body_size
     * 32M) y PHP (post_max_size 32M) cortan antes.
     */
    public function sendDocument(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'document' => [
                'required',
                'file',
                'max:30720',
                // Se guarda en un bucket público: nada que un servidor pueda
                // ejecutar ni SVG (script embebido). Las imágenes se permiten
                // porque HEIC/WEBP solo viajan bien como documento.
                'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,rtf,odt,ods,odp,zip,rar,7z,xml,json,jpg,jpeg,png,gif,bmp,webp,heic,heif',
            ],
            'caption' => 'nullable|string|max:1024',
            'reply_to_wamid' => 'nullable|string|max:500',
        ], [
            'document.extensions' => 'Ese tipo de archivo no está permitido.',
            'document.max' => 'El archivo supera el límite de 30 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        if (!$conversation->isWindowOpen()) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'error' => 'La ventana de 24h para responder libremente expiró. Envía primero una plantilla aprobada.',
            ], 422);
        }

        $file = $request->file('document');
        $filename = $file->getClientOriginalName() ?: 'documento';

        $path = $file->storePublicly('whatsapp/media', 's3_media');

        if (!$path) {
            Log::channel('whatsapp')->error('❌ No se pudo subir el documento al almacenamiento', [
                'conversation_id' => $conversation->id,
                'original_name' => $filename,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'No se pudo guardar el archivo en el almacenamiento. Intenta de nuevo.',
            ], 500);
        }

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'reply_to_wamid' => $request->input('reply_to_wamid') ?: null,
            'type' => 'document',
            'content' => $request->input('caption') ?? '',
            'media_url' => Storage::disk('s3_media')->url($path),
            'media_mime_type' => $file->getClientMimeType(),
            'filename' => $filename,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message' => '📄 ' . $filename,
            'last_message_at' => now(),
        ]);

        DeliverWhatsAppMessage::dispatch($message->id);

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'message' => 'Documento encolado',
            'data' => $message->load('sender'),
        ]));
    }

    public function sendAudio(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            // El navegador puede grabar webm (Chrome) u ogg (Firefox); aceptamos
            // ambos y los formatos que admite WhatsApp.
            'audio' => 'required|file|mimes:ogg,oga,webm,m4a,mp4,mp3,wav,aac|max:16384',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        if (!$conversation->isWindowOpen()) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'error' => 'La ventana de 24h para responder libremente expiró. Envía primero una plantilla aprobada.',
            ], 422);
        }

        $audioUrl = $this->storeAudioForMeta($request->file('audio'));

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type' => 'audio',
            'media_url' => $audioUrl,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $user->id,
            'sent_at' => now()
        ]);

        $conversation->update([
            'last_message' => 'Audio',
            'last_message_at' => now()
        ]);

        DeliverWhatsAppMessage::dispatch($message->id);

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'message' => 'Audio encolado',
            'data' => $message->load('sender')
        ]));
    }

    public function close($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        // Cerrar lo ya cerrado no es un hito nuevo: sin esta guarda, un doble
        // clic o dos agentes pulsando "Cerrar" a la vez apilaban pastillas
        // repetidas en el hilo, y encima se perdía quién lo cerró de verdad.
        if ($conversation->status === 'closed') {
            return response()->json([
                'success' => true,
                'message' => 'La conversación ya estaba cerrada',
                'status'  => 'closed',
                'closed_by_user' => $conversation->load('closedByUser:id,name')->closedByUser,
                'closed_at' => $conversation->closed_at?->toIso8601String(),
                'notice'    => null,
            ]);
        }

        $conversation->update([
            'status'    => 'closed',
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);

        $notice = $this->recordConversationNotice($conversation, "Conversación cerrada por {$user->name}");
        $this->notifyAdminsOfClosure($user, $conversation);

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.closed',
            WebhookDispatcher::conversationPayload($conversation, ['closed_by' => $user->id])
        );

        Realtime::push(ConversationEvent::updated($conversation, 'closed'));

        return response()->json([
            'success' => true,
            'message' => 'Conversación cerrada',
            'status'  => 'closed',
            'closed_by_user' => $conversation->load('closedByUser:id,name')->closedByUser,
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            // El hilo abierto lo pinta al instante, sin esperar al poll.
            'notice'    => $notice,
        ]);
    }

    /**
     * Deja constancia en el propio hilo de que el chat se cerró o se reabrió.
     *
     * Se guarda como mensaje `system`, que el chat pinta como pastilla centrada
     * en vez de burbuja, así que el equipo ve el hito en su sitio cronológico
     * sin tener que abrir el panel de detalles.
     *
     * `direction` es 'internal' a propósito: con 'inbound' el aviso abriría
     * falsamente la ventana de 24h de Meta (isWindowOpen cuenta entrantes), y
     * con 'outbound' el panel de no entregados lo tomaría por un envío atascado
     * (scopeUndelivered mira los salientes sin confirmar). Nunca sale a
     * WhatsApp: el cliente no lo ve.
     */
    private function recordConversationNotice(WhatsAppConversation $conversation, string $text): ?WhatsAppMessage
    {
        return ConversationNotice::record($conversation, $text);
    }

    /**
     * Avisa a los administradores de la empresa de que se cerró una conversación.
     *
     * Cerrar un chat lo saca de la bandeja de todo el equipo, así que quien
     * supervisa necesita enterarse sin tener que revisar la lista de cerradas.
     *
     * Se avisa también a quien cerró, aunque ya lo sepa: muchas empresas tienen
     * un solo administrador y, si se le excluyera, sus propios cierres no
     * dejarían ningún aviso y la campana no serviría de registro.
     *
     * Es un efecto secundario del cierre: si falla, la conversación ya quedó
     * cerrada y no se debe romper la respuesta al agente.
     */
    private function notifyAdminsOfClosure($user, WhatsAppConversation $conversation, int $total = 1): void
    {
        try {
            // Los roles de Spatie están particionados por empresa; sin fijar el
            // equipo, hasRole('admin') no encuentra nada.
            setPermissionsTeamId($user->company_id);

            $admins = User::where('company_id', $user->company_id)
                ->where('active', true)
                ->get()
                ->filter(fn ($u) => $u->hasRole('admin'));

            foreach ($admins as $admin) {
                $admin->notify(new \App\Notifications\ConversationClosedNotification(
                    $conversation,
                    $user->name,
                    $total,
                    $user->id
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar el cierre de la conversación', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra en lote: o las conversaciones indicadas por `ids`, o TODAS las
     * abiertas (`scope=all`), opcionalmente acotadas a una instancia.
     */
    public function closeBulk(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'ids'         => 'nullable|array',
            'ids.*'       => 'integer',
            'scope'       => 'nullable|in:all',
            'instance_id' => 'nullable|integer',
        ]);

        $instanceIds = Instance::where('company_id', $user->company_id)->pluck('id');

        $query = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('status', '!=', 'closed');

        if (!empty($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        } elseif (($validated['scope'] ?? null) === 'all') {
            if (!empty($validated['instance_id'])) {
                $query->where('instance_id', $validated['instance_id']);
            }
        } else {
            return response()->json(['success' => false, 'error' => 'No se indicó qué cerrar'], 422);
        }

        $ids = $query->pluck('id');

        if ($ids->isNotEmpty()) {
            WhatsAppConversation::whereIn('id', $ids)->update([
                'status'    => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
            ]);

            // El aviso sí va en cada hilo —quien abra uno concreto tiene que ver
            // por qué está cerrado—, pero en un solo INSERT: cerrar "todas"
            // puede tocar cientos de conversaciones.
            $now = now();
            WhatsAppMessage::insert($ids->map(fn ($id) => [
                'conversation_id' => $id,
                'type'            => 'system',
                'content'         => "Conversación cerrada por {$user->name} (cierre masivo)",
                'direction'       => 'internal',
                'is_internal'     => false,
                'status'          => 'sent',
                'sent_at'         => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->all());

            // Una sola notificación por el lote, no una por conversación: se
            // manda con la primera como referencia y el total del cierre.
            $first = WhatsAppConversation::find($ids->first());
            if ($first) {
                $this->notifyAdminsOfClosure($user, $first, $ids->count());
            }

            WebhookDispatcher::emit(
                $user->company_id,
                'conversations.bulk_closed',
                ['closed_by' => $user->id, 'ids' => $ids->all(), 'count' => $ids->count()]
            );

            // Un evento por instancia (no por conversación): el canal es por
            // instancia y `scope=all` puede abarcar varias de la empresa.
            WhatsAppConversation::whereIn('id', $ids)
                ->select('id', 'instance_id')
                ->get()
                ->groupBy('instance_id')
                ->each(function ($rows, $instanceId) use ($user) {
                    Realtime::push(ConversationEvent::bulkClosed(
                        (int) $instanceId,
                        $rows->pluck('id')->all(),
                        $user->name,
                    ));
                });
        }

        return response()->json([
            'success'      => true,
            'closed_count' => $ids->count(),
            'ids'          => $ids->values(),
        ]);
    }

    /**
     * Elimina la conversación, o deja una petición si quien lo pide no puede.
     *
     * Borrar arrastra todos los mensajes y adjuntos y no se puede deshacer, así
     * que solo lo hace directamente quien tiene el permiso `chat.delete`. El
     * resto deja una petición que un aprobador resuelve.
     */
    public function destroy(Request $request, $conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        if (! $user->can('chat.delete')) {
            return $this->requestConversationDeletion($user, $conversation, $request->input('reason'));
        }

        return $this->deleteConversationNow($user, $conversation);
    }

    /**
     * Registra la petición y avisa a quienes pueden resolverla.
     *
     * Si ya hay una en curso no se crea otra: dos agentes pidiendo lo mismo
     * llenarían la campana de los aprobadores con la misma decisión repetida.
     */
    private function requestConversationDeletion($user, WhatsAppConversation $conversation, ?string $reason)
    {
        $existing = ConversationDeletionRequest::pending()
            ->where('conversation_id', $conversation->id)
            ->with('requester:id,name')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'status'  => 'pending',
                'message' => $existing->requested_by === $user->id
                    ? 'Ya pediste la eliminación de este chat. Está pendiente de aprobación.'
                    : "{$existing->requester?->name} ya pidió eliminar este chat. Está pendiente de aprobación.",
                'request' => $existing,
            ]);
        }

        $deletionRequest = ConversationDeletionRequest::create([
            'conversation_id' => $conversation->id,
            'company_id'      => $user->company_id,
            'requested_by'    => $user->id,
            'status'          => ConversationDeletionRequest::STATUS_PENDING,
            'reason'          => $reason ? mb_substr($reason, 0, 500) : null,
        ]);

        $notice = $this->recordConversationNotice(
            $conversation,
            "{$user->name} pidió eliminar esta conversación. Pendiente de aprobación."
        );

        $approvers = $this->notifyDeletionApprovers($user, $deletionRequest);

        return response()->json($this->sanitizeUtf8([
            'success'   => true,
            'status'    => 'pending',
            'approvers' => $approvers,
            // Sin nadie que pueda aprobarla la petición no avanzaría nunca, y
            // callarlo dejaría al agente esperando una respuesta que no llega.
            'message'   => $approvers > 0
                ? 'No tienes permiso para eliminar chats, así que se envió la petición a un administrador. La verás en tus notificaciones.'
                : 'Se registró la petición, pero nadie en tu empresa tiene el permiso para aprobarla. Pide que activen "Delete" en el módulo Chat de algún rol de administrador.',
            'request'   => $deletionRequest->load('requester:id,name'),
            'notice'    => $notice,
        ]));
    }

    /**
     * Usuarios de la empresa que pueden resolver una petición: los mismos que
     * podrían haber borrado el chat directamente.
     */
    private function deletionApprovers(int $companyId)
    {
        setPermissionsTeamId($companyId);

        return User::where('company_id', $companyId)
            ->where('active', true)
            ->get()
            ->filter(fn ($u) => $u->can('chat.delete'));
    }

    /**
     * Avisa de la petición y devuelve cuántos pueden resolverla.
     *
     * Quien la pidió también recibe el aviso, sin botones: sin él su campana se
     * queda vacía y no tiene forma de saber que la petición existe ni en qué
     * estado está.
     */
    private function notifyDeletionApprovers($user, ConversationDeletionRequest $deletionRequest): int
    {
        try {
            $deletionRequest->load(['requester:id,name', 'conversation']);
            $approvers = $this->deletionApprovers($user->company_id);

            foreach ($approvers as $approver) {
                $approver->notify(new \App\Notifications\ConversationDeletionRequestedNotification(
                    $deletionRequest,
                    true
                ));
            }

            // El solicitante nunca está entre los aprobadores (si pudiera
            // resolverla habría borrado directamente), así que no se duplica.
            $user->notify(new \App\Notifications\ConversationDeletionRequestedNotification(
                $deletionRequest,
                false
            ));

            if ($approvers->isEmpty()) {
                Log::warning('Petición de eliminación sin aprobadores posibles', [
                    'company_id' => $user->company_id,
                    'request_id' => $deletionRequest->id,
                ]);
            }

            return $approvers->count();
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar de la petición de eliminación', [
                'request_id' => $deletionRequest->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Borrado efectivo. Se llama desde destroy() con permiso, o al aprobar.
     */
    private function deleteConversationNow($user, WhatsAppConversation $conversation)
    {
        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.deleted',
            WebhookDispatcher::conversationPayload($conversation, ['deleted_by' => $user->id])
        );

        // El evento se arma ANTES del delete: después no queda de dónde sacar el
        // instance_id con el que se elige el canal.
        $event = ConversationEvent::deleted($conversation);

        // Quita relaciones de etiquetas; los mensajes caen por FK onDelete cascade.
        $conversation->tags()->detach();
        $conversation->delete();

        // Y se emite DESPUÉS: si el delete falla, nadie debe haber quitado la
        // conversación de su lista.
        Realtime::push($event);

        return response()->json([
            'success' => true,
            'message' => 'Conversación eliminada',
            'deleted' => true,
        ]);
    }

    /**
     * Peticiones de eliminación pendientes de la empresa, para quien puede
     * resolverlas.
     */
    public function deletionRequests()
    {
        $user = auth()->user();

        if (! $user->can('chat.delete')) {
            abort(403, 'No autorizado');
        }

        $requests = ConversationDeletionRequest::pending()
            ->where('company_id', $user->company_id)
            ->with(['requester:id,name', 'conversation:id,name,phone_number,instance_id'])
            ->latest()
            ->get();

        return response()->json($this->sanitizeUtf8(['requests' => $requests]));
    }

    /**
     * Aprueba la petición y borra la conversación, o la rechaza dejándola
     * intacta. En ambos casos se avisa a quien la pidió.
     */
    public function resolveDeletionRequest(Request $request, $requestId)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'note'   => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        if (! $user->can('chat.delete')) {
            abort(403, 'No tienes permiso para resolver peticiones de eliminación');
        }

        $deletionRequest = ConversationDeletionRequest::with(['conversation.instance', 'requester'])
            ->findOrFail($requestId);

        if ($deletionRequest->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        if (! $deletionRequest->isPending()) {
            return response()->json([
                'success' => false,
                'status'  => $deletionRequest->status,
                'error'   => 'Esta petición ya fue resuelta.',
            ], 422);
        }

        $approved = $request->action === 'approve';
        $conversation = $deletionRequest->conversation;

        // El nombre se guarda antes de borrar: después la conversación ya no
        // existe y el aviso al solicitante se quedaría sin a qué referirse.
        $contactName = $conversation?->name ?: ($conversation?->phone_number ?? 'la conversación');

        $deletionRequest->update([
            'status'      => $approved
                ? ConversationDeletionRequest::STATUS_APPROVED
                : ConversationDeletionRequest::STATUS_REJECTED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => $request->note ? mb_substr($request->note, 0, 500) : null,
        ]);

        if ($approved && $conversation) {
            $this->deleteConversationNow($user, $conversation);
        } elseif ($conversation) {
            $this->recordConversationNotice(
                $conversation,
                "{$user->name} rechazó la petición de eliminar esta conversación."
            );
        }

        $this->notifyDeletionRequester($deletionRequest, $contactName);

        return response()->json([
            'success' => true,
            'status'  => $deletionRequest->status,
            'deleted' => $approved,
            'message' => $approved
                ? 'Petición aprobada: la conversación fue eliminada.'
                : 'Petición rechazada: la conversación sigue disponible.',
            'conversation_id' => $deletionRequest->conversation_id,
        ]);
    }

    private function notifyDeletionRequester(ConversationDeletionRequest $deletionRequest, string $contactName): void
    {
        try {
            $requester = $deletionRequest->requester;

            // Puede no existir si el usuario se dio de baja entre la petición y
            // la resolución (requested_by es nullOnDelete).
            if (! $requester) {
                return;
            }

            $requester->notify(new \App\Notifications\ConversationDeletionResolvedNotification(
                $deletionRequest->load('reviewer:id,name'),
                $contactName
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar al solicitante de la eliminación', [
                'request_id' => $deletionRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reopen($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        // Misma guarda que en close(): reabrir un chat que ya está abierto no es
        // un hito, y repetirlo llenaba el hilo de pastillas iguales.
        if ($conversation->status !== 'closed') {
            return response()->json([
                'success' => true,
                'message' => 'La conversación ya estaba abierta',
                'status'  => 'open',
                'notice'  => null,
            ]);
        }

        // Al reabrir se limpia el rastro del cierre: si no, el panel seguiría
        // mostrando "cerrada por X" en un chat que está abierto.
        $conversation->update([
            'status'    => 'open',
            'closed_by' => null,
            'closed_at' => null,
        ]);

        // Sin este aviso el hilo mostraría dos "cerrada" seguidas sin nada en
        // medio cuando un chat se cierra, se reabre y se vuelve a cerrar.
        $notice = $this->recordConversationNotice($conversation, "Conversación reabierta por {$user->name}");

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.reopened',
            WebhookDispatcher::conversationPayload($conversation, ['reopened_by' => $user->id])
        );

        Realtime::push(ConversationEvent::updated($conversation, 'reopened'));

        return response()->json([
            'success' => true,
            'message' => 'Conversación reabierta',
            'status'  => 'open',
            'notice'  => $notice,
        ]);
    }

    public function assign(Request $request, $conversationId)
    {
        $user = auth()->user();
        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validated['user_id']) {
            $targetUser = User::where('id', $validated['user_id'])
                ->where('company_id', $user->company_id)
                ->firstOrFail();
        }

        $conversation->update([
            'assigned_to' => $validated['user_id'],
        ]);

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.assigned',
            WebhookDispatcher::conversationPayload($conversation, ['assigned_to' => $validated['user_id']])
        );

        Realtime::push(ConversationEvent::updated($conversation, 'assigned'));

        return response()->json([
            'success' => true,
            'message' => 'Conversación asignada',
            'assigned_agent' => $conversation->assignedAgent()->select('id', 'name')->first()
        ]);
    }

    /**
     * Permite a cualquier agente autoasignarse una conversación que no tiene nadie asignado.
     * A diferencia de assign(), no requiere el permiso chat.update y no permite tomar
     * una conversación ya asignada a otro agente (evita "robar" chats ajenos).
     */
    public function assignToMe($conversationId)
    {
        $user = auth()->user();
        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $claimed = WhatsAppConversation::where('id', $conversationId)
            ->whereNull('assigned_to')
            ->update(['assigned_to' => $user->id]);

        if (! $claimed) {
            return response()->json([
                'success' => false,
                'message' => 'Esta conversación ya fue asignada a otro agente',
            ], 409);
        }

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.assigned',
            WebhookDispatcher::conversationPayload($conversation, ['assigned_to' => $user->id])
        );

        // `$conversation` se leyó antes del claim atómico, así que todavía tiene
        // assigned_to = null; sin refrescar, el resto del equipo recibiría la
        // conversación como si siguiera libre y volvería a ofrecer "Asignarme".
        Realtime::push(ConversationEvent::updated($conversation->refresh(), 'assigned'));

        return response()->json([
            'success' => true,
            'message' => 'Conversación asignada',
            'assigned_agent' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /**
     * Store an internal note on a conversation. Notes are never sent to the
     * customer (no Meta call) and can @-mention other agents, who get an in-app
     * notification.
     *
     * La nota admite una imagen adjunta. Como nunca sale hacia Meta, no aplican
     * ni los formatos que exige la Cloud API ni la ventana de 24 h: basta con
     * dejar el archivo en nuestro bucket y guardar la URL.
     */
    public function storeNote(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            // Una nota vale con solo la imagen: exigir texto obligaría a
            // escribir un relleno para poder adjuntar una captura.
            'content'    => 'required_without:image|nullable|string|max:4096',
            'image'      => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            'mentions'   => 'nullable|array',
            'mentions.*' => 'integer',
        ], [
            'content.required_without' => 'Escribe la nota o adjunta una imagen.',
            'image.image' => 'El archivo adjunto debe ser una imagen.',
            'image.max'   => 'La imagen supera el límite de 5 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        // Keep only mentioned users that belong to the same company.
        $mentionIds = [];
        if (!empty($request->mentions)) {
            $mentionIds = User::whereIn('id', $request->mentions)
                ->where('company_id', $user->company_id)
                ->pluck('id')
                ->all();
        }

        $media = ['media_url' => null, 'media_mime_type' => null, 'filename' => null];

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            // El disco s3_media va con 'throw' => false: sin esta guarda la nota
            // se guardaría con una media_url rota y la imagen no cargaría nunca.
            $path = $file->storePublicly('whatsapp/notes', 's3_media');

            if (!$path) {
                Log::channel('whatsapp')->error('❌ No se pudo subir la imagen de la nota interna', [
                    'conversation_id' => $conversation->id,
                    'original_name' => $file->getClientOriginalName(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'No se pudo guardar la imagen en el almacenamiento. Intenta de nuevo.',
                ], 500);
            }

            $media = [
                'media_url'       => Storage::disk('s3_media')->url($path),
                'media_mime_type' => $file->getClientMimeType(),
                'filename'        => $file->getClientOriginalName() ?: 'imagen',
            ];
        }

        $note = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'note',
            'content'         => $request->input('content') ?? '',
            'direction'       => 'internal',
            'is_internal'     => true,
            'mentions'        => $mentionIds ?: null,
            'status'          => 'sent',
            'sent_by'         => $user->id,
            'sent_at'         => now(),
            ...$media,
        ]);

        // Surface the conversation in the updates() poll without polluting the
        // customer-facing last message preview.
        $conversation->touch();

        if ($mentionIds) {
            $recipients = User::whereIn('id', $mentionIds)
                ->where('id', '!=', $user->id)
                ->get();
            foreach ($recipients as $recipient) {
                // Avisar es un efecto secundario de la nota, que ya está
                // guardada: ni un Reverb caído (el aviso también va por
                // websocket) ni un destinatario raro deben tumbar la respuesta
                // ni impedir que se avise a los demás mencionados.
                try {
                    $recipient->notify(new \App\Notifications\MentionNotification(
                        $note,
                        $conversation,
                        $user->name
                    ));
                } catch (\Throwable $e) {
                    Log::warning('No se pudo notificar la mención', [
                        'conversation_id' => $conversation->id,
                        'user_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'data'    => $note->load('sender:id,name'),
        ]));
    }

    /**
     * Localiza un mensaje del chat comprobando que pertenece a la empresa del
     * usuario. Todas las acciones sobre un mensaje concreto (reaccionar,
     * reenviar, editar) empiezan por aquí.
     */
    private function findMessageForUser($messageId): WhatsAppMessage
    {
        $message = WhatsAppMessage::with('conversation.instance')->findOrFail($messageId);
        $instance = $message->conversation?->instance;

        if (! $instance || $instance->company_id !== auth()->user()->company_id) {
            abort(403, 'No autorizado');
        }

        return $message;
    }

    /**
     * Reacciona (o quita la reacción) con un emoji a un mensaje del chat.
     *
     * Nuestra reacción se guarda en `metadata.own_reaction` y no en
     * `metadata.reaction`, que es donde el webhook deja la del cliente: son dos
     * reacciones independientes sobre el mismo mensaje y la UI muestra ambas.
     */
    public function reactToMessage(Request $request, $messageId)
    {
        $validator = Validator::make($request->all(), [
            // Cadena vacía = quitar la reacción (así lo documenta Meta).
            'emoji' => 'present|string|max:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = $this->findMessageForUser($messageId);
        $conversation = $message->conversation;
        $instance = $conversation->instance;

        if ($message->is_internal || ! $message->wamid) {
            return response()->json([
                'success' => false,
                'error' => 'Solo se puede reaccionar a mensajes que ya están en WhatsApp.',
            ], 422);
        }

        if (! $instance->isMetaConfigured()) {
            return response()->json(['success' => false, 'error' => 'Instancia no configurada'], 400);
        }

        // Una reacción es un mensaje saliente más, así que también la bloquea la
        // ventana de 24h.
        if (! $conversation->isWindowOpen()) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'error' => 'La ventana de 24h expiró, así que no se puede reaccionar a este mensaje.',
            ], 422);
        }

        $emoji = (string) $request->input('emoji');

        $result = $this->metaService->sendReaction(
            $instance->phone_number_id,
            $conversation->phone_number,
            $message->wamid,
            $emoji
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $this->metaError($result, 'No se pudo enviar la reacción'),
            ], 422);
        }

        $metadata = $message->metadata ?? [];
        if ($emoji === '') {
            unset($metadata['own_reaction']);
        } else {
            $metadata['own_reaction'] = $emoji;
        }
        $message->metadata = $metadata;
        $message->save();

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'data' => $message->load('sender:id,name'),
        ]));
    }

    /**
     * Tipos que se pueden reenviar: son los que `DeliverWhatsAppMessage` sabe
     * poner en el aire a partir de la fila guardada. El resto (sticker, video,
     * ubicación, contactos, plantillas) llega por webhook o necesita un payload
     * que no siempre conservamos.
     */
    private const FORWARDABLE_TYPES = ['text', 'image', 'audio', 'document'];

    /**
     * Reenvía un mensaje a otra conversación de la misma empresa.
     *
     * No se llama a Meta aquí: se crea la fila "pending" en la conversación
     * destino y el job de entrega hace el envío, igual que un mensaje normal.
     */
    public function forwardMessage(Request $request, $messageId)
    {
        $validator = Validator::make($request->all(), [
            'conversation_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $message = $this->findMessageForUser($messageId);

        if (! in_array($message->type, self::FORWARDABLE_TYPES, true)) {
            return response()->json([
                'success' => false,
                'error' => "Los mensajes de tipo \"{$message->type}\" no se pueden reenviar.",
            ], 422);
        }

        if ($message->type !== 'text' && empty($message->media_url)) {
            return response()->json([
                'success' => false,
                'error' => 'El archivo de este mensaje ya no está disponible, así que no se puede reenviar.',
            ], 422);
        }

        $target = WhatsAppConversation::with('instance')->findOrFail($request->conversation_id);

        if ($target->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        if (! $target->instance->isMetaConfigured()) {
            return response()->json(['success' => false, 'error' => 'La instancia del chat destino no está configurada'], 400);
        }

        if (! $target->isWindowOpen()) {
            return response()->json([
                'success' => false,
                'code' => 'window_closed',
                'error' => 'La ventana de 24h del chat destino expiró. Ahí hay que enviar primero una plantilla aprobada.',
            ], 422);
        }

        $metadata = $message->metadata ?? [];
        // Rastro del origen: de qué mensaje y de qué chat salió este reenvío.
        $metadata['forwarded_from'] = [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'forwarded_by' => $user->id,
            'forwarded_at' => now()->toIso8601String(),
        ];

        $forwarded = WhatsAppMessage::create([
            'conversation_id' => $target->id,
            'type' => $message->type,
            'content' => $message->content,
            'media_url' => $message->media_url,
            'media_mime_type' => $message->media_mime_type,
            'filename' => $message->filename,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $user->id,
            'sent_at' => now(),
            'metadata' => $metadata,
        ]);

        $target->update([
            'last_message' => $message->content ?: '[' . $message->type . ']',
            'last_message_at' => now(),
        ]);

        DeliverWhatsAppMessage::dispatch($forwarded->id);

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'message' => 'Mensaje reenviado',
            'data' => $forwarded->load('sender:id,name'),
            'conversation_id' => $target->id,
        ]));
    }

    /**
     * Edita el texto de una nota interna.
     *
     * Solo notas internas: la Cloud API de Meta no expone ninguna forma de
     * editar un mensaje que ya salió a WhatsApp, así que cambiar aquí el texto
     * de un saliente dejaría el CRM diciendo una cosa y el teléfono del cliente
     * otra. Las notas nunca salieron, así que ahí sí se puede editar de verdad.
     */
    public function updateNote(Request $request, $messageId)
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $message = $this->findMessageForUser($messageId);

        if (! $message->is_internal) {
            return response()->json([
                'success' => false,
                'error' => 'Solo se pueden editar las notas internas: WhatsApp no permite modificar un mensaje ya enviado al cliente.',
            ], 422);
        }

        if ($message->sent_by !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => 'Solo el autor puede editar su nota.',
            ], 403);
        }

        $metadata = $message->metadata ?? [];
        // Se conserva el texto anterior para que quede claro qué decía la nota
        // antes de la corrección.
        $metadata['edit_history'][] = [
            'content' => $message->content,
            'edited_at' => now()->toIso8601String(),
        ];
        $metadata['edited_at'] = now()->toIso8601String();

        $message->update([
            'content' => $request->content,
            'metadata' => $metadata,
        ]);

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'data' => $message->load('sender:id,name'),
        ]));
    }

    /**
     * Extrae un mensaje de error legible de la respuesta de error de Meta,
     * incluyendo el detalle específico (error_data.details) que suele explicar
     * la causa real detrás de un "(#100) Invalid parameter".
     */
    private function metaError($result, string $fallback = 'Error al enviar'): string
    {
        $err = $result['error']['error'] ?? null;
        if (!is_array($err)) {
            return is_string($result['error'] ?? null) ? $result['error'] : $fallback;
        }

        $message = $err['message'] ?? $fallback;
        $details = $err['error_data']['details'] ?? null;

        if ($details && $details !== $message) {
            return "{$message} — {$details}";
        }
        return $message;
    }

    /**
     * Recursively sanitize array data to ensure valid UTF-8.
     *
     * @param mixed $input
     * @return mixed
     */
    /**
     * Guarda el audio para enviarlo a WhatsApp. WhatsApp solo acepta OGG/Opus
     * (entre otros), así que si el navegador grabó otra cosa (p. ej. WebM de
     * Chrome) y hay ffmpeg disponible, lo transcodificamos a OGG. Si no hay
     * ffmpeg, se sube tal cual (Firefox ya graba OGG nativo).
     */
    private function storeAudioForMeta($file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'webm');

        if ($ext !== 'ogg' && ($ffmpeg = $this->ffmpegPath())) {
            $src = $file->getRealPath();
            $out = tempnam(sys_get_temp_dir(), 'wa_aud_') . '.ogg';
            // OGG/Opus mono 48kHz: el formato de nota de voz que espera WhatsApp.
            $cmd = escapeshellarg($ffmpeg) . ' -y -i ' . escapeshellarg($src)
                . ' -vn -ac 1 -ar 48000 -c:a libopus -b:a 24k -application voip '
                . escapeshellarg($out) . ' 2>/dev/null';
            @exec($cmd, $output, $code);

            if ($code === 0 && is_file($out) && filesize($out) > 0) {
                $path = 'whatsapp/media/' . uniqid('aud_') . '.ogg';
                Storage::disk('s3_media')->put($path, file_get_contents($out), 'public');
                @unlink($out);
                return Storage::disk('s3_media')->url($path);
            }

            if (is_file($out)) {
                @unlink($out);
            }
        }

        $path = $file->storePublicly('whatsapp/media', 's3_media');
        return Storage::disk('s3_media')->url($path);
    }

    /** Ruta a ffmpeg si está instalado y exec disponible; null en caso contrario. */
    private function ffmpegPath(): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        // Permite forzar la ruta por config/env (FFMPEG_PATH).
        $configured = env('FFMPEG_PATH');
        if ($configured && is_executable($configured)) {
            return $configured;
        }

        if (function_exists('shell_exec')) {
            $path = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        // Rutas comunes (por si el PATH del proceso PHP no las incluye).
        foreach (['/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/usr/bin/ffmpeg'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function sanitizeUtf8($input)
    {
        if (is_string($input)) {
            return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        } elseif (is_object($input)) {
            // Convert objects to arrays for sanitization if they have toArray method
            if (method_exists($input, 'toArray')) {
                $input = $input->toArray();
            } else {
                $input = (array) $input;
            }
            foreach ($input as &$value) {
                $value = $this->sanitizeUtf8($value);
            }
            unset($value);
        } elseif (is_array($input)) {
            foreach ($input as &$value) {
                $value = $this->sanitizeUtf8($value);
            }
            unset($value);
        }
        return $input;
    }
}
