<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\Instance;
use App\Models\KanbanColumn;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\User;
use App\Services\MetaWhatsAppService;
use App\Services\WebhookDispatcher;
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
            ->with(['assignedAgent:id,name', 'tags', 'contact:id,name,phone_number,email'])
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

        $conversation = WhatsAppConversation::firstOrCreate(
            ['instance_id' => $instance->id, 'wa_id' => $phone],
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

        $sessionOpen = $conversation->messages()
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        $conversation->markAsRead();

        return response()->json($this->sanitizeUtf8([
            'success'      => true,
            'conversation' => $conversation->load(['assignedAgent:id,name', 'tags']),
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
            return response()->json(['data' => []]);
        }

        $result = $this->metaService->listTemplates($instance->waba_id, $instance->access_token, [
            'status' => 'APPROVED',
            'limit'  => 200,
        ]);

        if (!($result['success'] ?? false)) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $result['data']['data'] ?? [],
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

        $result = $this->metaService->sendTemplate(
            $instance->phone_number_id,
            $conversation->phone_number,
            $templateName,
            $languageCode,
            $components
        );

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error'   => $this->metaError($result, 'Error al enviar la plantilla'),
                'meta'    => $result['error']['error'] ?? null,
            ], 500);
        }

        $preview = $request->preview ?: "[Plantilla: {$templateName}]";

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid'           => $result['data']['messages'][0]['id'] ?? null,
            'type'            => 'template',
            'content'         => $preview,
            'direction'       => 'outbound',
            'status'          => 'sent',
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

        WebhookDispatcher::emit(
            $user->company_id,
            'message.sent',
            WebhookDispatcher::conversationPayload($conversation, [
                'message' => [
                    'id'      => $message->id,
                    'wamid'   => $message->wamid,
                    'type'    => 'template',
                    'content' => $message->content,
                    'sent_by' => $user->id,
                ],
            ])
        );

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
                ->with(['assignedAgent:id,name', 'tags', 'contact:id,name,phone_number,email'])
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
                ->whereIn('status', ['delivered', 'read', 'failed'])
                ->select('id', 'wamid', 'status', 'delivered_at', 'read_at', 'error_message')
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

        return response()->json($this->sanitizeUtf8([
            'conversation' => $conversation,
            'messages' => $messages,
            'timestamp' => now()->toIso8601String()
        ]));
    }

    public function sendMessage(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:4096'
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

        // El cliente ve quién le escribe: anteponemos el nombre del agente en
        // negrita. En el CRM se guarda el texto limpio (la etiqueta del agente
        // se muestra aparte, sin duplicar).
        $outgoing = '*' . $user->name . ':*' . "\n" . $request->message;

        $result = $this->metaService->sendMessage(
            $instance->phone_number_id,
            $conversation->phone_number,
            $outgoing
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'text',
                'content' => $request->message,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_by' => $user->id,
                'sent_at' => now()
            ]);

            $conversation->update([
                'last_message' => $request->message,
                'last_message_at' => now()
            ]);

            WebhookDispatcher::emit(
                $user->company_id,
                'message.sent',
                WebhookDispatcher::conversationPayload($conversation, [
                    'message' => [
                        'id'      => $message->id,
                        'wamid'   => $message->wamid,
                        'type'    => 'text',
                        'content' => $message->content,
                        'sent_by' => $user->id,
                    ],
                ])
            );

            return response()->json($this->sanitizeUtf8([
                'success' => true,
                'message' => 'Mensaje enviado',
                'data' => $message->load('sender')
            ]));
        }

        return response()->json([
            'success' => false,
            'error' => $this->metaError($result, 'Error al enviar'),
            'meta'  => $result['error']['error'] ?? null,
        ], 500);
    }

    public function sendImage(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|max:5120',
            'caption' => 'nullable|string|max:1024'
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

        $path = $request->file('image')->storePublicly('whatsapp/media', 's3_media');
        $imageUrl = Storage::disk('s3_media')->url($path);

        $result = $this->metaService->sendImage(
            $instance->phone_number_id,
            $conversation->phone_number,
            $imageUrl,
            $request->caption ?? ''
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'image',
                'content' => $request->caption ?? '',
                'media_url' => $imageUrl,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_by' => $user->id,
                'sent_at' => now()
            ]);

            $conversation->update([
                'last_message' => $request->caption ?? 'Imagen',
                'last_message_at' => now()
            ]);

            WebhookDispatcher::emit(
                $user->company_id,
                'message.sent',
                WebhookDispatcher::conversationPayload($conversation, [
                    'message' => [
                        'id'      => $message->id,
                        'wamid'   => $message->wamid,
                        'type'    => 'image',
                        'content' => $message->content,
                        'media_url' => $message->media_url,
                        'sent_by' => $user->id,
                    ],
                ])
            );

            return response()->json($this->sanitizeUtf8([
                'success' => true,
                'message' => 'Imagen enviada',
                'data' => $message->load('sender')
            ]));
        }

        return response()->json([
            'success' => false,
            'error' => $this->metaError($result, 'Error al enviar imagen'),
            'meta'  => $result['error']['error'] ?? null,
        ], 500);
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

        $instance = $conversation->instance;

        $audioUrl = $this->storeAudioForMeta($request->file('audio'));

        $result = $this->metaService->sendAudio(
            $instance->phone_number_id,
            $conversation->phone_number,
            $audioUrl
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'audio',
                'media_url' => $audioUrl,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_by' => $user->id,
                'sent_at' => now()
            ]);

            $conversation->update([
                'last_message' => 'Audio',
                'last_message_at' => now()
            ]);

            WebhookDispatcher::emit(
                $user->company_id,
                'message.sent',
                WebhookDispatcher::conversationPayload($conversation, [
                    'message' => [
                        'id'      => $message->id,
                        'wamid'   => $message->wamid,
                        'type'    => 'audio',
                        'media_url' => $message->media_url,
                        'sent_by' => $user->id,
                    ],
                ])
            );

            return response()->json($this->sanitizeUtf8([
                'success' => true,
                'message' => 'Audio enviado',
                'data' => $message->load('sender')
            ]));
        }

        return response()->json([
            'success' => false,
            'error' => $this->metaError($result, 'Error al enviar audio'),
            'meta'  => $result['error']['error'] ?? null,
        ], 500);
    }

    public function close($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $conversation->update(['status' => 'closed']);

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.closed',
            WebhookDispatcher::conversationPayload($conversation, ['closed_by' => $user->id])
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversación cerrada',
            'status'  => 'closed',
        ]);
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
            WhatsAppConversation::whereIn('id', $ids)->update(['status' => 'closed']);

            WebhookDispatcher::emit(
                $user->company_id,
                'conversations.bulk_closed',
                ['closed_by' => $user->id, 'ids' => $ids->all(), 'count' => $ids->count()]
            );
        }

        return response()->json([
            'success'      => true,
            'closed_count' => $ids->count(),
            'ids'          => $ids->values(),
        ]);
    }

    /**
     * Elimina por completo una conversación y sus mensajes (cascade) de la
     * empresa del usuario. Acción destructiva e irreversible.
     */
    public function destroy($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.deleted',
            WebhookDispatcher::conversationPayload($conversation, ['deleted_by' => $user->id])
        );

        // Quita relaciones de etiquetas; los mensajes caen por FK onDelete cascade.
        $conversation->tags()->detach();
        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversación eliminada',
        ]);
    }

    public function reopen($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $conversation->update(['status' => 'open']);

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.reopened',
            WebhookDispatcher::conversationPayload($conversation, ['reopened_by' => $user->id])
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversación reabierta',
            'status'  => 'open',
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

        return response()->json([
            'success' => true,
            'message' => 'Conversación asignada',
            'assigned_agent' => $conversation->assignedAgent()->select('id', 'name')->first()
        ]);
    }

    /**
     * Store an internal note on a conversation. Notes are never sent to the
     * customer (no Meta call) and can @-mention other agents, who get an in-app
     * notification.
     */
    public function storeNote(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'content'    => 'required|string|max:4096',
            'mentions'   => 'nullable|array',
            'mentions.*' => 'integer',
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

        $note = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'note',
            'content'         => $request->content,
            'direction'       => 'internal',
            'is_internal'     => true,
            'mentions'        => $mentionIds ?: null,
            'status'          => 'sent',
            'sent_by'         => $user->id,
            'sent_at'         => now(),
        ]);

        // Surface the conversation in the updates() poll without polluting the
        // customer-facing last message preview.
        $conversation->touch();

        if ($mentionIds) {
            $recipients = User::whereIn('id', $mentionIds)
                ->where('id', '!=', $user->id)
                ->get();
            foreach ($recipients as $recipient) {
                $recipient->notify(new \App\Notifications\MentionNotification(
                    $note,
                    $conversation,
                    $user->name
                ));
            }
        }

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'data'    => $note->load('sender:id,name'),
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
