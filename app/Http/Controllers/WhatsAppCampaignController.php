<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\Tag;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppCampaignSegment;
use App\Models\WhatsAppConversation;
use App\Services\CampaignTemplateBuilder;
use App\Services\MetaWhatsAppService;
use App\Services\TemplateParameterGuard;
use App\Services\WhatsAppFailureTranslator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Campañas: un mismo aviso a mucha gente, con una plantilla aprobada.
 *
 * La regla que lo condiciona todo es de WhatsApp, no nuestra: fuera de las 24h
 * siguientes al último mensaje del cliente sólo se entrega una plantilla
 * aprobada. Una campaña va, por definición, a quien no acaba de escribir, así
 * que aquí la plantilla no es una opción: es el único camino que llega.
 */
class WhatsAppCampaignController extends Controller
{
    public function __construct(
        private MetaWhatsAppService $metaService,
        private CampaignTemplateBuilder $builder,
        private WhatsAppFailureTranslator $translator,
    ) {}

    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $campaigns = WhatsAppCampaign::where('company_id', $user->company_id)
            ->with('instance:id,name,display_phone_number')
            ->orderBy('created_at', 'desc')
            ->get();

        // Los estados de todos los destinatarios en una sola consulta: pedirlos
        // campaña por campaña convertía la lista en una ristra de queries.
        $conteos = WhatsAppCampaignRecipient::whereIn('campaign_id', $campaigns->pluck('id'))
            ->selectRaw('campaign_id, status, COUNT(*) as total')
            ->groupBy('campaign_id', 'status')
            ->get()
            ->groupBy('campaign_id')
            ->map(fn ($filas) => $filas->pluck('total', 'status'));

        $campaigns = $campaigns->map(
            fn (WhatsAppCampaign $campaign) => $this->summary($campaign, false, $conteos->get($campaign->id))
        );

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'instances' => $this->instances($user->company_id),
        ]);
    }

    /**
     * El asistente. La instancia llega ya elegida: quien crea una campaña casi
     * siempre tiene una sola línea, y obligarle a seleccionarla era un paso que
     * no decidía nada.
     */
    public function create()
    {
        $user = auth()->user();
        $instances = $this->instances($user->company_id);

        return Inertia::render('Campaigns/Create', [
            'instances' => $instances,
            'defaultInstanceId' => $instances->first()['id'] ?? null,
            'segments' => WhatsAppCampaignSegment::where('company_id', $user->company_id)
                ->orderBy('name')
                ->get(['id', 'name', 'source', 'filters']),
            'tags' => Tag::where('company_id', $user->company_id)->orderBy('name')->get(['id', 'name', 'color']),
            'fields' => $this->variableFields(),
        ]);
    }

    public function show($id)
    {
        $user = auth()->user();

        $campaign = WhatsAppCampaign::where('id', $id)
            ->where('company_id', $user->company_id)
            ->with('instance:id,name,display_phone_number', 'creator:id,name')
            ->firstOrFail();

        return Inertia::render('Campaigns/Show', [
            'campaign' => $this->summary($campaign, true),
            'recipients' => $this->recipientRows($campaign),
        ]);
    }

    /**
     * Progreso en vivo mientras la campaña está enviando, sin recargar la página.
     */
    public function progress($id)
    {
        $user = auth()->user();

        $campaign = WhatsAppCampaign::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        return response()->json([
            'campaign' => $this->summary($campaign, true),
            'recipients' => $this->recipientRows($campaign),
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'instance_id' => 'required|integer|exists:instances,id',
            'template_name' => 'required|string|max:512',
            'template_language' => 'required|string|max:16',
            'template_components' => 'required|array',
            'variable_map' => 'nullable|array',
            'header_media_id' => 'nullable|string|max:255',
            'header_media_url' => 'nullable|string|max:1024',
            'header_filename' => 'nullable|string|max:255',
            'rate_per_minute' => 'nullable|integer|min:1|max:600',
            'contact_ids' => 'nullable|array',
            'contact_ids.*' => 'integer',
            'conversation_ids' => 'nullable|array',
            'conversation_ids.*' => 'integer',
            'manual_recipients' => 'nullable|array',
            'manual_recipients.*.phone' => 'required_with:manual_recipients|string|max:32',
            'manual_recipients.*.name' => 'nullable|string|max:120',
            'manual_recipients.*.variables' => 'nullable|array',
            'launch_now' => 'boolean',
            'schedule_type' => 'nullable|in:manual,recurring',
            'schedule_days' => 'required_if:schedule_type,recurring|array|min:1',
            'schedule_days.*' => 'in:mon,tue,wed,thu,fri,sat,sun',
            'schedule_time' => 'required_if:schedule_type,recurring|date_format:H:i',
        ]);

        $instance = Instance::where('id', $data['instance_id'])
            ->where('company_id', $user->company_id)
            ->where('active', true)
            ->firstOrFail();

        $recipients = $this->resolveRecipients($data, $user->company_id);

        if (count($recipients) === 0) {
            return back()->withErrors(['recipients' => 'No se encontraron destinatarios válidos.']);
        }

        if (collect($recipients)->every(fn ($r) => !empty($r['opted_out']))) {
            return back()->withErrors([
                'recipients' => 'Todos los destinatarios seleccionados pidieron no recibir campañas.',
            ]);
        }

        // Misma puerta que usan el chat y el ERP: si el encabezado o el número de
        // datos no cuadran con la plantilla aprobada, se dice ahora y no después
        // de cientos de envíos silenciosamente rechazados.
        $draft = new WhatsAppCampaign([
            'template_name' => $data['template_name'],
            'template_language' => $data['template_language'],
            'template_components' => $data['template_components'],
            'variable_map' => $data['variable_map'] ?? [],
            'header_media_id' => $data['header_media_id'] ?? null,
            'header_filename' => $data['header_filename'] ?? null,
        ]);

        $guard = app(TemplateParameterGuard::class)->check(
            $instance,
            $data['template_name'],
            $data['template_language'],
            $this->builder->components($draft, null)
        );

        if (!$guard['ok']) {
            return back()->withErrors(['template_name' => $guard['error']]);
        }

        $scheduleType = $data['schedule_type'] ?? 'manual';
        $isRecurring = $scheduleType === 'recurring';
        $launch = !$isRecurring && (bool) ($data['launch_now'] ?? false);

        $campaign = DB::transaction(function () use ($data, $recipients, $user, $launch, $isRecurring, $scheduleType) {
            $campaign = WhatsAppCampaign::create([
                'company_id' => $user->company_id,
                'instance_id' => $data['instance_id'],
                'created_by' => $user->id,
                'name' => $data['name'],
                'message' => null,
                'message_type' => 'template',
                'template_name' => $data['template_name'],
                'template_language' => $data['template_language'],
                'template_components' => $data['template_components'],
                'variable_map' => $data['variable_map'] ?? [],
                'header_media_id' => $data['header_media_id'] ?? null,
                'header_media_url' => $data['header_media_url'] ?? null,
                'header_filename' => $data['header_filename'] ?? null,
                'rate_per_minute' => $data['rate_per_minute'] ?? 60,
                'status' => $launch ? 'queued' : 'draft',
                'schedule_type' => $scheduleType,
                'schedule_days' => $isRecurring ? array_values($data['schedule_days']) : null,
                'schedule_time' => $isRecurring ? ($data['schedule_time'] . ':00') : null,
                'schedule_timezone' => $isRecurring ? config('app.timezone') : null,
                'total_recipients' => count($recipients),
            ]);

            if ($isRecurring) {
                $campaign->next_run_at = $campaign->computeNextRun();
                $campaign->save();
            }

            $now = now();
            $rows = array_map(fn ($r) => [
                'campaign_id' => $campaign->id,
                'contact_id' => $r['contact_id'],
                'conversation_id' => $r['conversation_id'],
                'phone_number' => $r['phone_number'],
                'name' => $r['name'],
                'variables' => json_encode($r['variables'], JSON_UNESCAPED_UNICODE),
                'status' => empty($r['opted_out']) ? 'pending' : 'skipped',
                'error_message' => empty($r['opted_out'])
                    ? null
                    : 'Este contacto pidió no recibir campañas.',
                'created_at' => $now,
                'updated_at' => $now,
            ], $recipients);

            foreach (array_chunk($rows, 500) as $chunk) {
                WhatsAppCampaignRecipient::insert($chunk);
            }

            return $campaign;
        });

        if ($launch) {
            ProcessWhatsAppCampaign::dispatch($campaign->id);
        }

        $msg = $launch
            ? 'Campaña encolada para envío'
            : ($isRecurring ? 'Campaña programada' : 'Campaña creada como borrador');

        return redirect()->route('campaigns.show', $campaign->id)->with('success', $msg);
    }

    public function send(Request $request, $id)
    {
        $campaign = $this->ownCampaign($id);

        if (!$campaign->isLaunchable()) {
            return back()->withErrors(['campaign' => $campaign->usesTemplate()
                ? 'La campaña no se puede enviar en su estado actual.'
                : 'Esta campaña se creó con texto libre. WhatsApp solo entrega envíos masivos como plantilla aprobada: '
                    . 'créala de nuevo eligiendo una plantilla.']);
        }

        // Relanzar tras un fallo vuelve a poner en cola solo lo que no llegó.
        $campaign->recipients()
            ->where('status', 'failed')
            ->update(['status' => 'pending', 'error_message' => null, 'error_code' => null, 'error_details' => null]);

        $campaign->update(['status' => 'queued', 'paused_at' => null, 'cancelled_at' => null]);
        ProcessWhatsAppCampaign::dispatch($campaign->id);

        return back()->with('success', 'Campaña encolada para envío');
    }

    /**
     * Pausar no cancela: los destinatarios que aún no salieron se quedan
     * pendientes y el envío sigue donde lo dejó al reanudar.
     */
    public function pause($id)
    {
        $campaign = $this->ownCampaign($id);

        if (!in_array($campaign->status, ['queued', 'sending'], true)) {
            return back()->withErrors(['campaign' => 'Solo se puede pausar una campaña que esté enviando.']);
        }

        $campaign->update(['status' => 'paused', 'paused_at' => now()]);

        return back()->with('success', 'Campaña pausada. Los envíos que faltan quedan en espera.');
    }

    public function resume($id)
    {
        $campaign = $this->ownCampaign($id);

        if ($campaign->status !== 'paused') {
            return back()->withErrors(['campaign' => 'Esta campaña no está pausada.']);
        }

        $campaign->update(['status' => 'queued', 'paused_at' => null]);
        ProcessWhatsAppCampaign::dispatch($campaign->id);

        return back()->with('success', 'Campaña reanudada');
    }

    public function cancel($id)
    {
        $campaign = $this->ownCampaign($id);

        if (in_array($campaign->status, ['completed', 'cancelled'], true)) {
            return back()->withErrors(['campaign' => 'Esta campaña ya terminó.']);
        }

        $campaign->update(['status' => 'cancelled', 'cancelled_at' => now(), 'completed_at' => now()]);
        $campaign->recipients()->where('status', 'pending')->update([
            'status' => 'skipped',
            'error_message' => 'La campaña se canceló antes de llegar a este destinatario.',
        ]);
        $campaign->refreshCounters();

        return back()->with('success', 'Campaña cancelada');
    }

    public function retryFailed($id)
    {
        $campaign = $this->ownCampaign($id);

        $pending = $campaign->recipients()->where('status', 'failed')->count();

        if ($pending === 0) {
            return back()->withErrors(['campaign' => 'No hay envíos fallidos que reintentar.']);
        }

        $campaign->recipients()->where('status', 'failed')->update([
            'status' => 'pending',
            'error_message' => null,
            'error_code' => null,
            'error_details' => null,
            'wamid' => null,
        ]);

        $campaign->update(['status' => 'queued', 'paused_at' => null, 'cancelled_at' => null, 'completed_at' => null]);
        ProcessWhatsAppCampaign::dispatch($campaign->id);

        return back()->with('success', "Se reintentarán {$pending} envíos");
    }

    public function destroy($id)
    {
        $campaign = $this->ownCampaign($id);

        if (in_array($campaign->status, ['queued', 'sending'], true)) {
            return back()->withErrors(['campaign' => 'No se puede eliminar una campaña en envío. Pausa o cancela primero.']);
        }

        $campaign->delete();

        return redirect()->route('campaigns.index')->with('success', 'Campaña eliminada');
    }

    /**
     * Descarga del resultado, para cruzarlo con el CRM sin pedirle nada a nadie.
     */
    public function export($id)
    {
        $campaign = $this->ownCampaign($id);

        $filename = 'campana-' . $campaign->id . '-' . now()->format('Ymd-Hi') . '.csv';

        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Nombre', 'Teléfono', 'Estado', 'Motivo', 'Enviado', 'Entregado', 'Leído']);

            $campaign->recipients()->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->name,
                        $row->phone_number,
                        $row->status,
                        $row->error_message,
                        optional($row->sent_at)->format('Y-m-d H:i'),
                        optional($row->delivered_at)->format('Y-m-d H:i'),
                        optional($row->read_at)->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Plantillas aprobadas de la línea, que es lo único que se puede enviar.
     */
    public function templates(Request $request)
    {
        $request->validate(['instance_id' => 'required|integer']);

        $instance = Instance::where('id', $request->integer('instance_id'))
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        if (empty($instance->waba_id) || empty($instance->access_token)) {
            return response()->json(['templates' => [], 'error' => 'La línea no tiene WhatsApp Business conectado.']);
        }

        $result = $this->metaService->listTemplates($instance->waba_id, $instance->access_token, ['limit' => 200]);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'templates' => [],
                'error' => 'No se pudo leer el catálogo de plantillas de WhatsApp. Inténtalo de nuevo en un minuto.',
            ]);
        }

        $templates = collect($result['data']['data'] ?? [])
            ->where('status', 'APPROVED')
            ->values();

        return response()->json(['templates' => $templates]);
    }

    /**
     * Sube el archivo del encabezado. El endpoint del chat cuelga de una
     * conversación y una campaña todavía no tiene ninguna.
     */
    public function uploadTemplateMedia(Request $request)
    {
        $request->validate([
            'instance_id' => 'required|integer',
            'file' => 'required|file|max:102400',
        ]);

        $instance = Instance::where('id', $request->integer('instance_id'))
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        if (!$instance->isMetaConfigured()) {
            return response()->json(['success' => false, 'error' => 'La línea no está configurada.'], 400);
        }

        $file = $request->file('file');
        $result = $this->metaService->uploadMedia($instance->phone_number_id, $file->getRealPath(), $file->getMimeType());

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => 'WhatsApp no aceptó el archivo. Revisa el formato y el tamaño.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'media_id' => $result['id'],
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    /**
     * Buscador de destinatarios. Dos fuentes con la misma forma de salida:
     * conversaciones (gente que ya escribió) y contactos del CRM (gente que no,
     * que es justo a quien sirve una plantilla).
     */
    public function searchContacts(Request $request)
    {
        $request->validate([
            'instance_id' => 'required|integer',
            'source' => 'nullable|in:conversations,contacts',
            'q' => 'nullable|string|max:120',
            'tag_ids' => 'nullable|array',
            'page' => 'nullable|integer|min:1',
        ]);

        $user = auth()->user();

        Instance::where('id', $request->integer('instance_id'))
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $term = trim((string) $request->input('q', ''));
        $source = $request->input('source', 'conversations');
        $page = max(1, $request->integer('page') ?: 1);

        if ($source === 'contacts') {
            $query = Contact::where('company_id', $user->company_id)
                ->when($term !== '', fn ($q) => $q->search($term));

            $total = $query->count();
            $rows = $query->orderBy('name')
                ->forPage($page, 25)
                ->get(['id', 'name', 'phone_number', 'identificacion'])
                ->map(fn ($c) => [
                    'key' => 'contact:' . $c->id,
                    'contact_id' => $c->id,
                    'conversation_id' => null,
                    'name' => $c->name,
                    'phone_number' => $c->phone_number,
                    'detail' => $c->identificacion,
                ]);
        } else {
            $query = WhatsAppConversation::where('instance_id', $request->integer('instance_id'))
                ->when($term !== '', fn ($q) => $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('phone_number', 'like', "%{$term}%")
                        ->orWhere('wa_id', 'like', "%{$term}%");
                }))
                ->when($request->filled('tag_ids'), fn ($q) => $q->whereHas(
                    'tags',
                    fn ($t) => $t->whereIn('tags.id', $request->input('tag_ids'))
                ));

            $total = $query->count();
            $rows = $query->orderByRaw('COALESCE(last_message_at, created_at) desc')
                ->forPage($page, 25)
                ->get(['id', 'name', 'phone_number', 'wa_id', 'last_message_at'])
                ->map(fn ($c) => [
                    'key' => 'conversation:' . $c->id,
                    'contact_id' => null,
                    'conversation_id' => $c->id,
                    'name' => $c->name,
                    'phone_number' => $c->phone_number ?: $c->wa_id,
                    'detail' => optional($c->last_message_at)->diffForHumans(),
                ]);
        }

        return response()->json([
            'contacts' => $rows,
            'total' => $total,
            'page' => $page,
            'has_more' => $total > $page * 25,
        ]);
    }

    /**
     * Todos los destinatarios que cumplen un criterio, sin paginar: es lo que
     * hay detrás de "seleccionar los N resultados" y de aplicar un segmento.
     */
    public function resolveSelection(Request $request)
    {
        $request->validate([
            'instance_id' => 'required|integer',
            'source' => 'nullable|in:conversations,contacts',
            'q' => 'nullable|string|max:120',
            'tag_ids' => 'nullable|array',
        ]);

        $user = auth()->user();

        Instance::where('id', $request->integer('instance_id'))
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $term = trim((string) $request->input('q', ''));

        if ($request->input('source', 'conversations') === 'contacts') {
            $rows = Contact::where('company_id', $user->company_id)
                ->when($term !== '', fn ($q) => $q->search($term))
                ->limit(5000)
                ->get(['id', 'name', 'phone_number', 'identificacion'])
                ->map(fn ($c) => [
                    'key' => 'contact:' . $c->id,
                    'contact_id' => $c->id,
                    'conversation_id' => null,
                    'name' => $c->name,
                    'phone_number' => $c->phone_number,
                    'detail' => $c->identificacion,
                ]);
        } else {
            $rows = WhatsAppConversation::where('instance_id', $request->integer('instance_id'))
                ->when($term !== '', fn ($q) => $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('phone_number', 'like', "%{$term}%")
                        ->orWhere('wa_id', 'like', "%{$term}%");
                }))
                ->when($request->filled('tag_ids'), fn ($q) => $q->whereHas(
                    'tags',
                    fn ($t) => $t->whereIn('tags.id', $request->input('tag_ids'))
                ))
                ->limit(5000)
                ->get(['id', 'name', 'phone_number', 'wa_id'])
                ->map(fn ($c) => [
                    'key' => 'conversation:' . $c->id,
                    'contact_id' => null,
                    'conversation_id' => $c->id,
                    'name' => $c->name,
                    'phone_number' => $c->phone_number ?: $c->wa_id,
                    'detail' => null,
                ]);
        }

        return response()->json(['contacts' => $rows->values(), 'total' => $rows->count()]);
    }

    public function storeSegment(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'source' => 'required|in:conversations,contacts',
            'filters' => 'nullable|array',
        ]);

        $segment = WhatsAppCampaignSegment::create([
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
            'name' => $data['name'],
            'source' => $data['source'],
            'filters' => $data['filters'] ?? [],
        ]);

        return response()->json(['segment' => $segment]);
    }

    public function destroySegment($id)
    {
        WhatsAppCampaignSegment::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail()
            ->delete();

        return response()->json(['success' => true]);
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function ownCampaign($id): WhatsAppCampaign
    {
        return WhatsAppCampaign::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();
    }

    private function instances(int $companyId)
    {
        return Instance::where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'display_phone_number', 'waba_id', 'phone_number_id'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'display_phone_number' => $i->display_phone_number,
                'ready' => !empty($i->waba_id) && !empty($i->phone_number_id),
            ]);
    }

    /** Campos del destinatario que se pueden insertar en una variable. */
    private function variableFields(): array
    {
        return [
            ['key' => 'name', 'label' => 'Nombre del destinatario'],
            ['key' => 'phone', 'label' => 'Teléfono'],
            ['key' => 'identificacion', 'label' => 'Identificación'],
        ];
    }

    private function summary(WhatsAppCampaign $campaign, bool $detailed = false, $counts = null): array
    {
        $counts = $counts ?? $campaign->recipients()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $data = [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'message_type' => $campaign->message_type,
            'template_name' => $campaign->template_name,
            'template_language' => $campaign->template_language,
            'uses_template' => $campaign->usesTemplate(),
            'instance' => $campaign->instance ? [
                'id' => $campaign->instance->id,
                'name' => $campaign->instance->name,
                'display_phone_number' => $campaign->instance->display_phone_number,
            ] : null,
            'schedule_type' => $campaign->schedule_type,
            'schedule_days' => $campaign->schedule_days,
            'schedule_time' => $campaign->schedule_time,
            'next_run_at' => optional($campaign->next_run_at)->toIso8601String(),
            'total_recipients' => (int) $campaign->total_recipients,
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['sending'] ?? 0),
                'sent' => (int) ($counts['sent'] ?? 0),
                'delivered' => (int) ($counts['delivered'] ?? 0),
                'read' => (int) ($counts['read'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'skipped' => (int) ($counts['skipped'] ?? 0),
            ],
            'created_at' => optional($campaign->created_at)->toIso8601String(),
            'started_at' => optional($campaign->started_at)->toIso8601String(),
            'completed_at' => optional($campaign->completed_at)->toIso8601String(),
            'can_launch' => $campaign->isLaunchable(),
        ];

        if ($detailed) {
            $data['template_components'] = $campaign->template_components;
            $data['variable_map'] = $campaign->variable_map;
            $data['header_media_url'] = $campaign->header_media_url;
            $data['rate_per_minute'] = (int) $campaign->rate_per_minute;
            $data['created_by'] = $campaign->creator?->name;
            $data['preview'] = $this->builder->preview($campaign, $campaign->recipients()->orderBy('id')->first());
        }

        return $data;
    }

    private function recipientRows(WhatsAppCampaign $campaign): array
    {
        return $campaign->recipients()
            ->orderBy('id')
            ->get()
            ->map(fn (WhatsAppCampaignRecipient $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'phone_number' => $r->phone_number,
                'status' => $r->status,
                'conversation_id' => $r->conversation_id,
                'sent_at' => optional($r->sent_at)->toIso8601String(),
                'delivered_at' => optional($r->delivered_at)->toIso8601String(),
                'read_at' => optional($r->read_at)->toIso8601String(),
                'attempts' => (int) $r->attempts,
                // El motivo, en el mismo castellano que la pantalla de mensajes
                // no entregados: quien lee esto no quiere el código de Meta.
                'reason' => $r->status === 'failed'
                    ? $this->translator->titleFor($r->error_code, $r->error_message)
                    : null,
                'reason_detail' => $r->error_message,
            ])
            ->all();
    }

    /**
     * Los destinatarios finales, sin repetidos y con el teléfono en la forma que
     * entiende WhatsApp. Se usa `normalizeRecipient()` y no un `preg_replace` a
     * secas porque un BSUID (CO.14026…) es un destinatario válido y quitarle las
     * letras lo convierte en un número inventado.
     */
    private function resolveRecipients(array $data, int $companyId): array
    {
        $seen = [];
        $out = [];

        // Quien pidió no recibir campañas no entra. No se descarta en silencio:
        // se guarda como omitido para que en el detalle se vea cuánta gente se
        // quedó fuera y por qué, en vez de un número de destinatarios que no
        // cuadra con lo que se seleccionó.
        $bajas = Contact::where('company_id', $companyId)
            ->optedOut()
            ->pluck('phone_number')
            ->map(fn ($p) => WhatsAppConversation::normalizeRecipient((string) $p))
            ->filter()
            ->flip();

        $push = function (?string $raw, ?string $name, ?int $contactId, ?int $conversationId, array $variables = []) use (&$seen, &$out, $bajas) {
            $phone = WhatsAppConversation::normalizeRecipient((string) $raw);

            if ($phone === '' || isset($seen[$phone])) {
                return;
            }

            if (!WhatsAppConversation::isBsuid($phone) && strlen($phone) < 7) {
                return;
            }

            $seen[$phone] = true;
            $out[] = [
                'phone_number' => $phone,
                'name' => $name ?: null,
                'contact_id' => $contactId,
                'conversation_id' => $conversationId,
                'variables' => $variables,
                'opted_out' => $bajas->has($phone),
            ];
        };

        if (!empty($data['conversation_ids'])) {
            WhatsAppConversation::whereIn('whatsapp_conversations.id', $data['conversation_ids'])
                ->where('whatsapp_conversations.instance_id', $data['instance_id'])
                ->join('instances', 'instances.id', '=', 'whatsapp_conversations.instance_id')
                ->where('instances.company_id', $companyId)
                ->get([
                    'whatsapp_conversations.id',
                    'whatsapp_conversations.name',
                    'whatsapp_conversations.phone_number',
                    'whatsapp_conversations.wa_id',
                    'whatsapp_conversations.bsuid',
                ])
                ->each(fn ($c) => $push($c->phone_number ?: ($c->bsuid ?: $c->wa_id), $c->name, null, $c->id));
        }

        if (!empty($data['contact_ids'])) {
            Contact::whereIn('id', $data['contact_ids'])
                ->where('company_id', $companyId)
                ->get(['id', 'name', 'phone_number', 'identificacion'])
                ->each(fn ($c) => $push($c->phone_number, $c->name, $c->id, null, [
                    'identificacion' => (string) $c->identificacion,
                ]));
        }

        foreach ($data['manual_recipients'] ?? [] as $entry) {
            $name = isset($entry['name']) ? trim((string) $entry['name']) : '';
            $push($entry['phone'] ?? '', $name !== '' ? $name : null, null, null, $entry['variables'] ?? []);
        }

        return $out;
    }
}
