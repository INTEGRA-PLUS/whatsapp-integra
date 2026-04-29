<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Models\Instance;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WhatsAppCampaignController extends Controller
{
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
            ->get(['id', 'name', 'display_phone_number']);

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
            ->get(['id', 'phone_number', 'name', 'status', 'wamid', 'error_message', 'sent_at']);

        return Inertia::render('Campaigns/Show', [
            'campaign' => $campaign,
            'recipients' => $recipients,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'message' => 'required|string|max:4096',
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

        Instance::where('id', $data['instance_id'])
            ->where('company_id', $user->company_id)
            ->where('active', true)
            ->firstOrFail();

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

        $campaign = DB::transaction(function () use ($data, $recipients, $user, $launch, $isRecurring, $scheduleType) {
            $campaign = WhatsAppCampaign::create([
                'company_id' => $user->company_id,
                'instance_id' => $data['instance_id'],
                'created_by' => $user->id,
                'name' => $data['name'],
                'message' => $data['message'],
                'message_type' => 'text',
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
