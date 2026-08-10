<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Auditoría global de mensajes no entregados, solo para Master.
 *
 * Un agente ve el fallo de su propia burbuja en el chat, pero nadie tenía una
 * vista transversal: qué empresas están fallando, con qué código de Meta y con
 * qué frecuencia. Este módulo cruza todas las empresas, explica el motivo y
 * permite volver a poner el mensaje en la cola sin suplantar al usuario.
 */
class MessagesController extends Controller
{
    /** Buckets de "no entregado" que el filtro de estado sabe interpretar. */
    private const BUCKETS = ['all', 'failed', 'pending', 'sent'];

    public function __construct(private MetaWhatsAppService $metaService) {}

    public function index(Request $request)
    {
        $this->authorizeMaster();

        $filters = $this->resolveFilters($request);

        $messages = $this->buildQuery($filters)
            ->with([
                'conversation:id,instance_id,contact_id,phone_number,name,assigned_to',
                'conversation.instance:id,company_id,name,display_phone_number,phone_number_id,waba_id',
                'conversation.instance.company:id,name',
                'sender:id,name,email',
                'retriedBy:id,name',
            ])
            ->orderByRaw('COALESCE(whatsapp_messages.failed_at, whatsapp_messages.sent_at, whatsapp_messages.created_at) DESC')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (WhatsAppMessage $message) => $this->listPayload($message));

        return Inertia::render('Master/Messages/Index', [
            'messages'        => $messages,
            'stats'           => $this->stats($filters),
            'error_breakdown' => $this->errorBreakdown($filters),
            'companies'       => Company::when(
                    $filters['company_id'] && $this->scopedCompanyId(),
                    fn ($q) => $q->whereKey($filters['company_id'])
                )
                ->orderBy('name')
                ->get(['id', 'name']),
            // Confinado a una empresa: el selector de empresa no tiene sentido.
            'company_locked'  => $this->scopedCompanyId() !== null,
            'instances'       => Instance::when(
                    $filters['company_id'],
                    fn ($q, $companyId) => $q->where('company_id', $companyId)
                )
                ->orderBy('name')
                ->get(['id', 'name', 'company_id', 'display_phone_number']),
            'filters'         => $filters,
        ]);
    }

    /**
     * Detalle completo de un mensaje para el modal. Va por JSON en vez de
     * viajar en el listado porque `metadata` de una plantilla puede pesar más
     * que el resto de la fila.
     */
    public function show(Request $request, WhatsAppMessage $message)
    {
        $this->authorizeMaster();
        $this->authorizeMessageScope($message);

        $message->load([
            'conversation.instance.company',
            'conversation.contact',
            'conversation.assignedAgent:id,name,email',
            'sender:id,name,email',
            'retriedBy:id,name',
            'retryOf:id,status,created_at',
            'retries:id,retry_of_message_id,status,created_at,error_message,error_code',
        ]);

        $conversation = $message->conversation;
        $instance = $conversation?->instance;

        return response()->json([
            'message' => array_merge($this->listPayload($message), [
                'wamid'             => $message->wamid,
                'reply_to_wamid'    => $message->reply_to_wamid,
                'media_id'          => $message->resolvableMediaId(),
                'media_mime_type'   => $message->media_mime_type,
                'has_own_copy'      => ! empty($message->media_url),
                'metadata'          => $message->metadata,
                'delivered_at'      => $this->formatDate($message->delivered_at),
                'read_at'           => $this->formatDate($message->read_at),
                'updated_at'        => $this->formatDate($message->updated_at),
                'diagnosis'         => $this->diagnose($message, $instance),
                'retry_of'          => $message->retryOf ? [
                    'id'         => $message->retryOf->id,
                    'status'     => $message->retryOf->status,
                    'created_at' => $this->formatDate($message->retryOf->created_at),
                ] : null,
                'retries'           => $message->retries->map(fn ($retry) => [
                    'id'            => $retry->id,
                    'status'        => $retry->status,
                    'created_at'    => $this->formatDate($retry->created_at),
                    'error_message' => $retry->error_message,
                    'error_code'    => $retry->error_code,
                ])->values(),
                'conversation'      => $conversation ? [
                    'id'              => $conversation->id,
                    'phone_number'    => $conversation->phone_number,
                    'name'            => $conversation->name,
                    'status'          => $conversation->status,
                    'contact_name'    => $conversation->contact?->name,
                    'assigned_agent'  => $conversation->assignedAgent?->name,
                    'last_message_at' => $this->formatDate($conversation->last_message_at),
                    'window_open'     => $conversation->isWindowOpen(),
                ] : null,
                'instance'          => $instance ? [
                    'id'               => $instance->id,
                    'name'             => $instance->name,
                    'phone_number'     => $instance->display_phone_number,
                    'phone_number_id'  => $instance->phone_number_id,
                    'status'           => $instance->status,
                    'meta_configured'  => $instance->isMetaConfigured(),
                    'company_id'       => $instance->company_id,
                    'company_name'     => $instance->company?->name,
                ] : null,
            ]),
        ]);
    }

    /**
     * Vuelve a enviar el mensaje creando una copia nueva en estado "pending".
     *
     * No se reutiliza la fila original a propósito: cuando Meta ya aceptó el
     * mensaje y falló después, la fila tiene `wamid` y el job la descarta por
     * idempotencia. Clonar también deja el histórico intacto: se ve qué se
     * intentó, cuándo y con qué resultado.
     */
    public function retry(Request $request, WhatsAppMessage $message)
    {
        $this->authorizeMaster();
        $this->authorizeMessageScope($message);

        if ($message->direction !== 'outbound' || $message->type === 'note') {
            return back()->with('error', 'Solo se pueden reenviar mensajes salientes.');
        }

        if (! in_array($message->type, WhatsAppMessage::RETRYABLE_TYPES, true)) {
            return back()->with('error', "Los mensajes de tipo \"{$message->type}\" no se pueden reenviar.");
        }

        if (in_array($message->status, ['delivered', 'read'], true)) {
            return back()->with('error', 'Este mensaje ya fue entregado.');
        }

        $conversation = $message->conversation;
        $instance = $conversation?->instance;

        if (! $instance || ! $instance->isMetaConfigured()) {
            return back()->with('error', 'La instancia de esta empresa no tiene la configuración de Meta completa.');
        }

        // El job manda los adjuntos a Meta como enlace, así que sin `media_url`
        // no hay nada que enviar: se intenta recuperar la copia antes de rendirse.
        if (in_array($message->type, ['image', 'audio', 'document'], true) && empty($message->media_url)) {
            if (! $this->restoreMediaUrl($message, $instance)) {
                return back()->with('error', 'El adjunto ya no está disponible (los archivos de Meta caducan a los 30 días), así que no se puede reenviar.');
            }
        }

        $clone = WhatsAppMessage::create([
            'conversation_id'     => $message->conversation_id,
            'reply_to_wamid'      => $message->reply_to_wamid,
            'type'                => $message->type,
            'content'             => $message->content,
            'media_id'            => $message->media_id,
            'media_url'           => $message->media_url,
            'media_mime_type'     => $message->media_mime_type,
            'filename'            => $message->filename,
            'direction'           => 'outbound',
            'status'              => 'pending',
            'sent_by'             => $message->sent_by,
            'metadata'            => $message->metadata,
            'template_id'         => $message->template_id,
            'retry_of_message_id' => $message->retry_of_message_id ?: $message->id,
        ]);

        // El contador vive en el mensaje original, incluso si el reintento parte
        // de una copia previa: así "van 3 intentos" se lee de un solo sitio.
        $origin = $message->retryOf ?: $message;
        $origin->forceFill([
            'retry_count'     => $origin->retry_count + 1,
            'last_retried_at' => now(),
            'last_retried_by' => Auth::id(),
        ])->save();

        DeliverWhatsAppMessage::dispatch($clone->id);

        Log::info('Reintento de mensaje desde el panel Master', [
            'original_message_id' => $message->id,
            'new_message_id'      => $clone->id,
            'company_id'          => $instance->company_id,
            'master_user_id'      => Auth::id(),
        ]);

        return back()->with('success', "Mensaje #{$message->id} reencolado como #{$clone->id}. El resultado aparecerá en unos segundos.");
    }

    /**
     * Sirve el adjunto de cualquier empresa. `ChatController::downloadMedia`
     * exige que el mensaje sea de la empresa del usuario, condición que un
     * Master nunca cumple, de modo que aquí se autoriza por rol.
     */
    public function media(Request $request, WhatsAppMessage $message)
    {
        $this->authorizeMaster();
        $this->authorizeMessageScope($message);

        $instance = $message->conversation?->instance;

        if (! $instance) {
            return $this->mediaError($request, 'El mensaje no tiene una instancia asociada.');
        }

        if (empty($message->media_url) && ! $this->restoreMediaUrl($message, $instance)) {
            return $this->mediaError($request, 'Este mensaje no tiene un archivo adjunto disponible. Los adjuntos caducan 30 días después del envío.');
        }

        $response = Http::timeout(60)->get($message->media_url);

        if (! $response->successful()) {
            return $this->mediaError($request, 'El archivo ya no está disponible en el almacenamiento.');
        }

        $filename = $this->safeDownloadName(
            $message->resolvableFilename() ?: basename(parse_url($message->media_url, PHP_URL_PATH) ?: 'archivo')
        );

        return response($response->body(), 200, [
            'Content-Type' => $message->media_mime_type
                ?: ($response->header('Content-Type') ?: 'application/octet-stream'),
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment') . '; filename="' . $filename . '"',
            'Content-Length' => strlen($response->body()),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    // ── Consulta ─────────────────────────────────────────────────────────────

    private function resolveFilters(Request $request): array
    {
        $bucket = $request->input('bucket', 'all');
        if (! in_array($bucket, self::BUCKETS, true)) {
            $bucket = 'all';
        }

        $range = $request->input('range', 'month');
        $endDate = Carbon::now()->endOfDay();
        $startDate = match ($range) {
            'today' => Carbon::now()->startOfDay(),
            'week'  => Carbon::now()->subDays(7)->startOfDay(),
            'year'  => Carbon::now()->subYear()->startOfDay(),
            'all'   => Carbon::create(2020, 1, 1)->startOfDay(),
            default => Carbon::now()->subDays(30)->startOfDay(),
        };

        if ($range === 'custom') {
            $startDate = $request->filled('start_date')
                ? Carbon::parse($request->input('start_date'))->startOfDay()
                : Carbon::now()->subDays(30)->startOfDay();
            $endDate = $request->filled('end_date')
                ? Carbon::parse($request->input('end_date'))->endOfDay()
                : $endDate;
        }

        // Suplantando desde Master la empresa no se elige: es la que se está viendo.
        $scopedCompanyId = $this->scopedCompanyId();

        return [
            'bucket'      => $bucket,
            'company_id'  => $scopedCompanyId
                ?? ($request->filled('company_id') ? (int) $request->input('company_id') : null),
            'instance_id' => $request->filled('instance_id') ? (int) $request->input('instance_id') : null,
            'type'        => $request->input('type') ?: null,
            'error_code'  => $request->filled('error_code') ? (string) $request->input('error_code') : null,
            'search'      => trim((string) $request->input('search')) ?: null,
            'range'       => $range,
            'start_date'  => $startDate->format('Y-m-d'),
            'end_date'    => $endDate->format('Y-m-d'),
        ];
    }

    private function buildQuery(array $filters, ?string $bucketOverride = null)
    {
        $bucket = $bucketOverride ?? $filters['bucket'];

        $query = WhatsAppMessage::query()
            ->undelivered()
            ->whereBetween('whatsapp_messages.created_at', [
                Carbon::parse($filters['start_date'])->startOfDay(),
                Carbon::parse($filters['end_date'])->endOfDay(),
            ]);

        if ($bucket !== 'all') {
            $query->where('whatsapp_messages.status', $bucket);
        }

        if ($filters['type']) {
            $query->where('whatsapp_messages.type', $filters['type']);
        }

        if ($filters['error_code']) {
            $query->where('whatsapp_messages.error_code', $filters['error_code']);
        }

        // Los dos filtros se acumulan a propósito: cuando la vista está confinada
        // a una empresa, un instance_id de otra no puede saltarse el cerco.
        if ($filters['company_id']) {
            $query->whereHas(
                'conversation.instance',
                fn ($q) => $q->where('company_id', $filters['company_id'])
            );
        }

        if ($filters['instance_id']) {
            $query->whereHas('conversation', fn ($q) => $q->where('instance_id', $filters['instance_id']));
        }

        if ($filters['search']) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term, $filters) {
                $q->where('whatsapp_messages.content', 'like', $term)
                    ->orWhere('whatsapp_messages.error_message', 'like', $term)
                    ->orWhere('whatsapp_messages.filename', 'like', $term)
                    ->orWhere('whatsapp_messages.wamid', 'like', $term)
                    ->orWhereHas('conversation', function ($q2) use ($term) {
                        $q2->where('phone_number', 'like', $term)
                            ->orWhere('name', 'like', $term);
                    });

                if (ctype_digit($filters['search'])) {
                    $q->orWhere('whatsapp_messages.id', (int) $filters['search']);
                }
            });
        }

        return $query;
    }

    /**
     * Los contadores ignoran el bucket activo (son las pestañas) pero respetan
     * el resto de filtros, para que al acotar una empresa las cifras cuadren.
     */
    private function stats(array $filters): array
    {
        $countFor = fn (string $bucket) => (clone $this->buildQuery($filters, $bucket))->count();

        return [
            'failed'              => $countFor('failed'),
            'pending'             => $countFor('pending'),
            'sent_unconfirmed'    => $countFor('sent'),
            'total'               => $countFor('all'),
            'failed_last_24h'     => (clone $this->buildQuery($filters, 'failed'))
                ->where('whatsapp_messages.created_at', '>=', now()->subDay())
                ->count(),
            'companies_affected'  => (clone $this->buildQuery($filters, $filters['bucket']))
                ->join('whatsapp_conversations', 'whatsapp_conversations.id', '=', 'whatsapp_messages.conversation_id')
                ->join('instances', 'instances.id', '=', 'whatsapp_conversations.instance_id')
                ->distinct()
                ->count('instances.company_id'),
            'with_attachment'     => (clone $this->buildQuery($filters, $filters['bucket']))
                ->whereIn('whatsapp_messages.type', ['image', 'document', 'audio', 'video'])
                ->count(),
            'retried'             => (clone $this->buildQuery($filters, $filters['bucket']))
                ->where('whatsapp_messages.retry_count', '>', 0)
                ->count(),
        ];
    }

    /**
     * Motivos más frecuentes del recorte actual: es el atajo para pasar de
     * "hay 300 fallos" a "hay un solo problema repetido 300 veces".
     */
    private function errorBreakdown(array $filters): array
    {
        return $this->buildQuery($filters)
            ->selectRaw('whatsapp_messages.error_code, MIN(whatsapp_messages.error_message) as error_message, COUNT(*) as total')
            ->whereNotNull('whatsapp_messages.error_message')
            ->groupBy('whatsapp_messages.error_code')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'error_code'    => $row->error_code,
                'error_message' => $row->error_message,
                'total'         => (int) $row->total,
            ])
            ->all();
    }

    private function listPayload(WhatsAppMessage $message): array
    {
        $conversation = $message->conversation;
        $instance = $conversation?->instance;

        return [
            'id'              => $message->id,
            'type'            => $message->type,
            'status'          => $message->status,
            'content'         => $message->content,
            'filename'        => $message->resolvableFilename(),
            'media_available' => $message->isMediaAvailable(),
            'error_message'   => $message->error_message,
            'error_code'      => $message->error_code,
            'error_details'   => $message->error_details,
            'reason'          => $this->humanReason($message),
            'created_at'      => $this->formatDate($message->created_at),
            'sent_at'         => $this->formatDate($message->sent_at),
            'failed_at'       => $this->formatDate($message->failed_at),
            'failure_moment'  => $this->formatDate($message->failure_moment),
            'retry_count'     => (int) $message->retry_count,
            'last_retried_at' => $this->formatDate($message->last_retried_at),
            'last_retried_by' => $message->retriedBy?->name,
            'retryable'       => in_array($message->type, WhatsAppMessage::RETRYABLE_TYPES, true)
                && (bool) $instance?->isMetaConfigured(),
            'sender'          => $message->sender?->name,
            'company'         => [
                'id'   => $instance?->company_id,
                'name' => $instance?->company?->name,
            ],
            'instance'        => [
                'id'    => $instance?->id,
                'name'  => $instance?->name,
                'phone' => $instance?->display_phone_number,
            ],
            'recipient'       => [
                'conversation_id' => $conversation?->id,
                'phone_number'    => $conversation?->phone_number,
                'name'            => $conversation?->name,
            ],
        ];
    }

    /**
     * Traduce el estado técnico al motivo por el que el cliente no lo recibió.
     */
    private function humanReason(WhatsAppMessage $message): string
    {
        if ($message->status === 'failed') {
            return $message->error_message
                ?: 'Meta rechazó el mensaje sin devolver un motivo.';
        }

        if ($message->status === 'pending') {
            return 'Nunca salió de la cola de envío: el worker no lo procesó (cola detenida o job perdido).';
        }

        return 'Meta lo aceptó pero no ha confirmado la entrega: el destinatario puede tener el teléfono apagado, sin datos o el número no existe en WhatsApp.';
    }

    /**
     * Pistas accionables para el modal de detalle. Son heurísticas sobre el
     * estado actual del sistema, no datos guardados con el fallo.
     */
    private function diagnose(WhatsAppMessage $message, ?Instance $instance): array
    {
        $hints = [];

        if (! $instance) {
            $hints[] = 'La conversación no tiene instancia asociada; el mensaje no puede salir.';
        } elseif (! $instance->isMetaConfigured()) {
            $hints[] = 'La instancia no tiene phone_number_id o waba_id configurados.';
        }

        $conversation = $message->conversation;
        if ($conversation && ! $conversation->isWindowOpen() && $message->type !== 'template') {
            $hints[] = 'La ventana de 24 horas está cerrada: un mensaje libre volverá a fallar, hay que reabrir con una plantilla.';
        }

        if (in_array($message->type, ['image', 'audio', 'document'], true) && ! $message->isMediaAvailable()) {
            $hints[] = 'El adjunto no es recuperable (sin copia propia y sin media_id vigente), así que no se puede reenviar.';
        }

        if ((string) $message->error_code === '131026') {
            $hints[] = 'Código 131026: el número no está registrado en WhatsApp o no puede recibir mensajes.';
        }

        if ((string) $message->error_code === '131047') {
            $hints[] = 'Código 131047: pasaron más de 24 horas desde el último mensaje del cliente; se requiere plantilla.';
        }

        if ((string) $message->error_code === '470') {
            $hints[] = 'Código 470: la ventana de conversación expiró.';
        }

        if ($message->status === 'pending') {
            $hints[] = 'Revisa que el worker de colas esté corriendo (queue:work) antes de reintentar en lote.';
        }

        return $hints;
    }

    // ── Adjuntos ─────────────────────────────────────────────────────────────

    /**
     * Recupera de Meta el archivo del que solo queda el media_id y persiste la
     * copia, igual que hace el chat, para que la siguiente apertura sea local.
     */
    private function restoreMediaUrl(WhatsAppMessage $message, Instance $instance): bool
    {
        $mediaId = $message->resolvableMediaId();

        if (! $mediaId || empty($instance->access_token)) {
            return false;
        }

        $mediaInfo = $this->metaService->downloadMedia($mediaId, $instance->access_token);

        if (! $mediaInfo) {
            return false;
        }

        $message->forceFill([
            'media_url'       => $mediaInfo['url'],
            'media_id'        => $message->media_id ?: $mediaId,
            'media_mime_type' => $message->media_mime_type ?: $mediaInfo['mime_type'],
            'filename'        => $message->resolvableFilename() ?: $mediaInfo['filename'],
        ])->save();

        return true;
    }

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

    private function safeDownloadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name);
        $name = trim($name);

        return $name !== '' ? mb_substr($name, 0, 200) : 'archivo';
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    private function formatDate($date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d H:i:s') : null;
    }

    /**
     * El módulo es de Master, pero también se entra desde dentro de una empresa
     * mientras se la suplanta: ahí la sesión es de un admin y `isMaster()` da
     * false, aunque quien está al mando sigue siendo el Master original.
     */
    private function authorizeMaster(): void
    {
        $user = Auth::user();

        if ($user && $user->isMaster()) {
            return;
        }

        if ($user && $this->impersonatingMaster()) {
            return;
        }

        abort(403, 'Acceso denegado. Solo usuarios Master.');
    }

    /**
     * ¿La sesión actual es una suplantación iniciada por un Master de verdad?
     *
     * No basta con `from_master` en sesión: se comprueba que el usuario que la
     * inició tenga el rol. El rol "master" está scoped a su empresa, así que hay
     * que mover el team scope de Spatie y devolverlo a su sitio, porque el resto
     * del request (permisos compartidos con Inertia) depende de él.
     */
    private function impersonatingMaster(): bool
    {
        if (! session('from_master') || ! session('impersonated_by')) {
            return false;
        }

        $original = User::find(session('impersonated_by'));

        if (! $original) {
            return false;
        }

        setPermissionsTeamId($original->company_id);
        $isMaster = $original->isMaster();
        setPermissionsTeamId(Auth::user()->company_id);

        return $isMaster;
    }

    /**
     * Empresa a la que queda confinado el módulo. Un Master en su propia sesión
     * ve todas (null); suplantando ve solo la empresa en la que está metido, que
     * es lo que la pantalla dice estar mostrando.
     */
    private function scopedCompanyId(): ?int
    {
        $user = Auth::user();

        if ($user && $user->isMaster()) {
            return null;
        }

        return $user?->company_id;
    }

    /**
     * Impide que la vista confinada a una empresa alcance mensajes de otra por
     * el id de la URL.
     */
    private function authorizeMessageScope(WhatsAppMessage $message): void
    {
        $scopedCompanyId = $this->scopedCompanyId();

        if ($scopedCompanyId === null) {
            return;
        }

        if ($message->conversation?->instance?->company_id !== $scopedCompanyId) {
            abort(403, 'Este mensaje pertenece a otra empresa.');
        }
    }
}
