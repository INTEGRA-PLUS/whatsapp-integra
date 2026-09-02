<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Models\Instance;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Services\CampaignTemplateService;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WhatsAppCampaignController extends Controller
{
    public function __construct(
        protected CampaignTemplateService $templates,
        protected MetaWhatsAppService $meta,
    ) {
    }

    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $campaigns = WhatsAppCampaign::where('company_id', $user->company_id)
            ->with('instance:id,name,display_phone_number')
            ->withCount('recipients')
            ->orderBy('created_at', 'desc')
            ->get();

        $instances = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'display_phone_number', 'waba_id']);

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'instances' => $instances,
        ]);
    }

    public function show($id)
    {
        $user = auth()->user();

        $campaign = WhatsAppCampaign::where('id', $id)
            ->where('company_id', $user->company_id)
            ->with('instance:id,name,display_phone_number')
            ->firstOrFail();

        $recipients = $campaign->recipients()
            ->orderBy('id')
            ->get(['id', 'phone_number', 'name', 'status', 'wamid', 'message_id', 'error_message', 'sent_at']);

        return Inertia::render('Campaigns/Show', [
            'campaign' => $campaign,
            'recipients' => $recipients,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'message_type' => 'nullable|in:text,template',
            'message' => 'required_unless:message_type,template|nullable|string|max:4096',
            'template_name' => 'required_if:message_type,template|nullable|string|max:512',
            'template_language' => 'required_if:message_type,template|nullable|string|max:16',
            'template_payload' => 'nullable|array',
            'template_payload.body_vars' => 'nullable|array|max:20',
            'template_payload.body_vars.*' => 'nullable|string|max:1024',
            'template_payload.header' => 'nullable|array',
            'template_payload.header.format' => 'nullable|in:IMAGE,VIDEO,DOCUMENT,LOCATION',
            'template_payload.header.path' => 'nullable|string|max:512',
            'template_payload.header.filename' => 'nullable|string|max:255',
            'template_payload.header.mime_type' => 'nullable|string|max:128',
            'template_payload.header.lat' => 'nullable|numeric|between:-90,90',
            'template_payload.header.lng' => 'nullable|numeric|between:-180,180',
            'template_payload.header.name' => 'nullable|string|max:120',
            'template_payload.header.address' => 'nullable|string|max:255',
            'instance_id' => 'required|integer|exists:instances,id',
            'contact_ids' => 'nullable|array',
            'contact_ids.*' => 'integer',
            'manual_recipients' => 'nullable|array',
            'manual_recipients.*.phone' => 'required_with:manual_recipients|string|max:32',
            'manual_recipients.*.name' => 'nullable|string|max:120',
            'launch_now' => 'boolean',
            'schedule_type' => 'nullable|in:manual,recurring',
            'schedule_days' => 'required_if:schedule_type,recurring|array|min:1',
            'schedule_days.*' => 'in:mon,tue,wed,thu,fri,sat,sun',
            'schedule_time' => 'required_if:schedule_type,recurring|date_format:H:i',
        ]);

        if (empty($data['contact_ids'] ?? []) && empty($data['manual_recipients'] ?? [])) {
            return back()->withErrors(['contact_ids' => 'Selecciona al menos un destinatario.']);
        }

        $user = auth()->user();

        $instance = Instance::where('id', $data['instance_id'])
            ->where('company_id', $user->company_id)
            ->where('active', true)
            ->firstOrFail();

        $isTemplate = ($data['message_type'] ?? 'text') === 'template';
        $templatePayload = null;

        if ($isTemplate) {
            $templatePayload = $this->prepareTemplatePayload(
                $instance,
                $data['template_name'],
                $data['template_language'],
                $data['template_payload'] ?? [],
                $user->company_id
            );

            if (!is_array($templatePayload)) {
                return $templatePayload; // RedirectResponse con los errores
            }
        }

        $recipients = $this->resolveRecipients(
            $data['contact_ids'] ?? [],
            $data['manual_recipients'] ?? [],
            $data['instance_id'],
            $user->company_id
        );

        if (count($recipients) === 0) {
            return back()->withErrors(['contact_ids' => 'No se encontraron destinatarios válidos.']);
        }

        $scheduleType = $data['schedule_type'] ?? 'manual';
        $isRecurring = $scheduleType === 'recurring';
        $launch = !$isRecurring && (bool) ($data['launch_now'] ?? false);

        $campaign = DB::transaction(function () use ($data, $recipients, $user, $launch, $isRecurring, $scheduleType, $isTemplate, $templatePayload) {
            $campaign = WhatsAppCampaign::create([
                'company_id' => $user->company_id,
                'instance_id' => $data['instance_id'],
                'created_by' => $user->id,
                'name' => $data['name'],
                // En una campaña de plantilla el texto libre no viaja a WhatsApp:
                // se guarda el cuerpo aprobado para que las listas y el detalle
                // muestren algo legible sin ir a consultar Meta.
                'message' => $isTemplate ? ($templatePayload['body_text'] ?? '') : $data['message'],
                'message_type' => $isTemplate ? 'template' : 'text',
                'template_name' => $isTemplate ? $data['template_name'] : null,
                'template_language' => $isTemplate ? $data['template_language'] : null,
                'template_payload' => $templatePayload,
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
                'phone_number' => $r['phone_number'],
                'name' => $r['name'],
                'status' => 'pending',
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

        return redirect()->route('campaigns.index')->with('success', $msg);
    }

    public function send(Request $request, $id)
    {
        $user = auth()->user();

        $campaign = WhatsAppCampaign::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        if (!$campaign->isLaunchable()) {
            return back()->withErrors(['campaign' => 'La campaña no se puede enviar en su estado actual.']);
        }

        if ($campaign->status === 'failed') {
            $campaign->recipients()
                ->where('status', 'failed')
                ->update(['status' => 'pending', 'error_message' => null]);
            $campaign->update(['failed_count' => 0]);
        }

        $campaign->update(['status' => 'queued']);
        ProcessWhatsAppCampaign::dispatch($campaign->id);

        return redirect()->route('campaigns.index')
            ->with('success', 'Campaña encolada para envío');
    }

    public function destroy($id)
    {
        $user = auth()->user();

        $campaign = WhatsAppCampaign::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        if (in_array($campaign->status, ['queued', 'sending'], true)) {
            return back()->withErrors(['campaign' => 'No se puede eliminar una campaña en envío. Espera a que finalice.']);
        }

        $campaign->delete();

        return redirect()->route('campaigns.index')->with('success', 'Campaña eliminada');
    }

    public function searchContacts(Request $request)
    {
        $request->validate([
            'instance_id' => 'required|integer',
            'q' => 'nullable|string|max:120',
        ]);

        $user = auth()->user();

        Instance::where('id', $request->integer('instance_id'))
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $query = WhatsAppConversation::where('instance_id', $request->integer('instance_id'));

        $term = trim((string) $request->input('q', ''));
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('phone_number', 'like', "%{$term}%")
                  ->orWhere('wa_id', 'like', "%{$term}%");
            });
        }

        $contacts = $query->orderByRaw('COALESCE(last_message_at, created_at) desc')
            ->limit(20)
            ->get(['id', 'name', 'phone_number', 'wa_id']);

        return response()->json(['contacts' => $contacts]);
    }

    /**
     * Plantillas aprobadas del WABA de la instancia, para elegir en el modal de
     * campaña. Mismo criterio que el chat: se piden todas y el frontend se queda
     * con las APPROVED.
     */
    public function templates(Request $request)
    {
        $request->validate(['instance_id' => 'required|integer']);

        $user = auth()->user();

        $instance = Instance::where('id', $request->integer('instance_id'))
            ->where('company_id', $user->company_id)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token')
            ->first();

        if (!$instance) {
            return response()->json([
                'data' => [],
                'message' => 'Esta instancia no tiene WhatsApp Business configurado, así que no tiene plantillas.',
            ]);
        }

        $result = $this->meta->listTemplates($instance->waba_id, $instance->access_token, ['limit' => 200]);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'data' => [],
                'message' => 'No se pudieron cargar las plantillas desde WhatsApp.',
            ]);
        }

        return response()->json(['data' => $result['data']['data'] ?? []]);
    }

    /**
     * Guarda el archivo del encabezado multimedia en nuestro bucket. A Meta no
     * se sube aquí: el media_id caduca a los 30 días y una campaña recurrente
     * seguiría enviándose meses después, así que el job lo sube en cada corrida
     * partiendo de esta copia.
     */
    public function uploadTemplateMedia(Request $request)
    {
        $request->validate([
            'instance_id' => 'required|integer',
            'format' => 'required|in:IMAGE,VIDEO,DOCUMENT',
            'file' => 'required|file|max:102400',
        ]);

        $user = auth()->user();

        Instance::where('id', $request->integer('instance_id'))
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $file = $request->file('file');
        $mime = $file->getMimeType();

        // Los formatos que Meta acepta en cada tipo de encabezado. Se validan aquí
        // y no solo en el navegador: un archivo que Meta no reconoce es el origen
        // del "Format mismatch, expected IMAGE, received UNKNOWN".
        $allowed = [
            'IMAGE' => ['image/jpeg', 'image/png'],
            'VIDEO' => ['video/mp4', 'video/3gpp'],
            'DOCUMENT' => ['application/pdf'],
        ][$request->input('format')];

        if (!in_array($mime, $allowed, true)) {
            return response()->json([
                'success' => false,
                'error' => 'WhatsApp no acepta ese formato en este encabezado. Permitidos: ' . implode(', ', $allowed) . '.',
            ], 422);
        }

        $path = sprintf(
            '%s/%d/%s.%s',
            CampaignTemplateService::MEDIA_DIR,
            $user->company_id,
            Str::uuid(),
            $file->getClientOriginalExtension() ?: 'bin'
        );

        Storage::disk('s3_media')->put($path, file_get_contents($file->getRealPath()), 'public');

        return response()->json([
            'success' => true,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
        ]);
    }

    /**
     * Contrasta lo que llenó el usuario contra la plantilla real del WABA y
     * devuelve el payload listo para guardar, o un redirect con los errores.
     *
     * Validar aquí y no al enviar es lo que evita que un dato mal puesto queme
     * la lista completa: los errores de plantilla de Meta son permanentes y se
     * repiten en cada destinatario.
     */
    private function prepareTemplatePayload(Instance $instance, string $name, string $language, array $payload, int $companyId)
    {
        $template = $this->templates->fetchTemplate($instance, $name, $language);

        if (!$template) {
            return back()->withErrors([
                'template_name' => 'La plantilla ya no existe en WhatsApp con ese nombre e idioma.',
            ]);
        }

        $header = $payload['header'] ?? null;

        // El `path` lo propone el navegador: hay que comprobar que sea un archivo
        // que subió esta misma empresa y no la ruta de otra.
        if (!empty($header['path'])) {
            $prefix = CampaignTemplateService::MEDIA_DIR . "/{$companyId}/";
            if (!str_starts_with($header['path'], $prefix)) {
                return back()->withErrors(['template_header' => 'El archivo del encabezado no es válido.']);
            }
        }

        $clean = [
            'body_vars' => array_values($payload['body_vars'] ?? []),
            'header' => $header,
            // Copia del cuerpo aprobado, para pintar la vista previa y el mensaje
            // del chat sin volver a consultar Meta en cada envío.
            'body_text' => $this->templates->bodyText($template),
        ];

        $errors = $this->templates->validateAgainstTemplate($template, $clean);

        if (count($errors) > 0) {
            return back()->withErrors($errors);
        }

        return $clean;
    }

    private function resolveRecipients(array $ids, array $manual, int $instanceId, int $companyId): array
    {
        $seen = [];
        $out = [];

        if (count($ids) > 0) {
            $contacts = WhatsAppConversation::whereIn('whatsapp_conversations.id', $ids)
                ->where('whatsapp_conversations.instance_id', $instanceId)
                ->join('instances', 'instances.id', '=', 'whatsapp_conversations.instance_id')
                ->where('instances.company_id', $companyId)
                ->get(['whatsapp_conversations.id', 'whatsapp_conversations.name', 'whatsapp_conversations.phone_number']);

            foreach ($contacts as $c) {
                $phone = preg_replace('/[^0-9]/', '', (string) $c->phone_number);
                if (strlen($phone) < 7 || isset($seen[$phone])) continue;
                $seen[$phone] = true;
                $out[] = ['phone_number' => $phone, 'name' => $c->name ?: null];
            }
        }

        foreach ($manual as $entry) {
            $phone = preg_replace('/[^0-9]/', '', (string) ($entry['phone'] ?? ''));
            if (strlen($phone) < 7 || isset($seen[$phone])) continue;
            $seen[$phone] = true;
            $name = isset($entry['name']) ? trim((string) $entry['name']) : '';
            $out[] = ['phone_number' => $phone, 'name' => $name !== '' ? $name : null];
        }

        return $out;
    }
}
