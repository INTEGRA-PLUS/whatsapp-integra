<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\WhatsAppConversation;
use App\Support\ConversationNotice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $contacts = Contact::where('company_id', $companyId)
            ->withCount('conversations')
            ->when($request->search, fn ($q, $search) => $q->search($search))
            ->orderBy('name')
            ->get();

        return Inertia::render('Contacts/Index', $this->sanitizeUtf8([
            'contacts' => $contacts->toArray(),
            'unregistered' => $this->unregisteredConversations($companyId)->toArray(),
            'optOutRequests' => $this->optOutRequests($companyId)->toArray(),
            'filters' => ['search' => $request->search ?? ''],
        ]));
    }

    /**
     * WhatsApp names / messages can contain malformed UTF-8 bytes that break
     * json_encode (Inertia/JsonResponse). Re-encode strings defensively.
     */
    private function sanitizeUtf8($input)
    {
        if (is_string($input)) {
            return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        }
        if (is_array($input)) {
            foreach ($input as &$value) {
                $value = $this->sanitizeUtf8($value);
            }
            unset($value);
        }
        return $input;
    }

    /**
     * Conversations whose phone number is not yet saved as a contact.
     * One row per distinct phone number (most recent conversation wins).
     */
    private function unregisteredConversations($companyId)
    {
        $instanceIds = \App\Models\Instance::where('company_id', $companyId)->pluck('id');

        if ($instanceIds->isEmpty()) {
            return collect();
        }

        $existingPhones = Contact::where('company_id', $companyId)->pluck('phone_number');

        return WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->whereNull('contact_id')
            ->when($existingPhones->isNotEmpty(), fn ($q) => $q->whereNotIn('phone_number', $existingPhones))
            ->orderByDesc('last_message_at')
            ->get(['id', 'name', 'phone_number', 'wa_id', 'last_message', 'last_message_at'])
            ->unique('phone_number')
            ->values();
    }

    public function list(Request $request)
    {
        $companyId = auth()->user()->company_id;

        return response()->json($this->sanitizeUtf8(
            Contact::where('company_id', $companyId)
                ->when($request->search, fn ($q, $search) => $q->search($search))
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'phone_number', 'email'])
                ->toArray()
        ));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('contacts')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'phone_numbers' => 'nullable|array',
            'phone_numbers.*' => 'string|max:50',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);

        $validated['company_id'] = $companyId;
        $validated['phone_numbers'] = $this->cleanExtraNumbers($validated['phone_numbers'] ?? [], $validated['phone_number']);

        $contact = Contact::create($validated);
        $contact->loadCount('conversations');

        return response()->json($this->sanitizeUtf8($contact->toArray()), 201);
    }

    public function update(Request $request, Contact $contact)
    {
        $this->authorizeOwnership($contact);
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('contacts')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($contact->id),
            ],
            'phone_numbers' => 'nullable|array',
            'phone_numbers.*' => 'string|max:50',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);

        if (array_key_exists('phone_numbers', $validated)) {
            $primary = $validated['phone_number'] ?? $contact->phone_number;
            $validated['phone_numbers'] = $this->cleanExtraNumbers($validated['phone_numbers'] ?? [], $primary);
        }

        $contact->update($validated);
        $contact->loadCount('conversations');

        return response()->json($this->sanitizeUtf8($contact->toArray()));
    }

    /**
     * Normalize the secondary numbers list: trim, drop empties/duplicates and
     * remove the primary number (it lives in `phone_number`).
     */
    private function cleanExtraNumbers(array $numbers, ?string $primary): array
    {
        return collect($numbers)
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->reject(fn ($n) => $n === $primary)
            ->unique()
            ->values()
            ->all();
    }

    public function destroy(Contact $contact)
    {
        $this->authorizeOwnership($contact);
        $contact->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Clientes que escribieron pidiendo no recibir campañas y nadie ha resuelto
     * todavía.
     *
     * La petición la detecta el webhook, pero **no** la aplica: en Colombia
     * «baja» es también dar de baja el servicio, y confundir las dos cosas
     * significaría dejar de avisarle de su factura a quien nunca lo pidió. Aquí
     * es donde una persona decide, con el mensaje delante.
     */
    private function optOutRequests(int $companyId)
    {
        $instanceIds = \App\Models\Instance::where('company_id', $companyId)->pluck('id');

        if ($instanceIds->isEmpty()) {
            return collect();
        }

        return WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->whereNotNull('opt_out_requested_at')
            ->orderByDesc('opt_out_requested_at')
            ->limit(100)
            ->get(['id', 'name', 'phone_number', 'last_message', 'opt_out_requested_at'])
            ->map(fn ($c) => [
                'conversation_id' => $c->id,
                'name' => $c->name,
                'phone_number' => $c->phone_number,
                'message' => $c->last_message,
                'requested_at' => $c->opt_out_requested_at?->toIso8601String(),
            ]);
    }

    /**
     * Resuelve una petición de baja: aplicarla o descartarla.
     *
     * Aplicarla crea el contacto si el número no tenía ficha —lo normal en quien
     * escribe una sola vez— porque la baja se guarda en el contacto, que es lo
     * que mira la campaña al armar la lista.
     */
    public function resolveOptOutRequest(Request $request, $conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $aplicar = $request->boolean('apply');
        $contact = null;

        if ($aplicar) {
            $phone = WhatsAppConversation::normalizeRecipient((string) $conversation->phone_number);

            $contact = Contact::firstOrNew([
                'company_id' => $user->company_id,
                'phone_number' => $phone,
            ]);

            $contact->name = $contact->name ?: ($conversation->name ?: $phone);
            $contact->source = $contact->source ?: 'opt_out';
            $contact->opted_out_at = now();
            $contact->opt_out_source = 'client';
            $contact->opted_out_by = $user->id;
            $contact->save();

            ConversationNotice::record(
                $conversation,
                "{$user->name} confirmó la baja de campañas de este cliente."
            );
        }

        $conversation->forceFill(['opt_out_requested_at' => null])->save();

        // La ficha viaja de vuelta: la pantalla marca la fila sin recargar, y sin
        // esto el agente pulsaba "Excluir" y no veía cambiar nada.
        return response()->json([
            'success' => true,
            'applied' => $aplicar,
            'contact' => $contact,
        ]);
    }

    /**
     * Da de baja (o vuelve a dar de alta) a un contacto para las campañas.
     *
     * Es la baja de los envíos masivos, no del servicio: al cliente se le sigue
     * respondiendo en el chat y le siguen llegando los avisos que dispara el
     * ERP. Sin este interruptor no había dónde anotar un «no me manden más
     * publicidad», y la campaña siguiente volvía a escribirle.
     */
    public function toggleOptOut(Request $request, Contact $contact)
    {
        $this->authorizeOwnership($contact);

        $baja = $request->boolean('opted_out');

        $contact->update([
            'opted_out_at' => $baja ? now() : null,
            'opt_out_source' => $baja ? ($request->input('source', 'manual')) : null,
            'opted_out_by' => $baja ? auth()->id() : null,
        ]);

        return response()->json([
            'success' => true,
            'opted_out_at' => $contact->opted_out_at?->toIso8601String(),
        ]);
    }

    /**
     * Associate a conversation's phone number with an existing contact, or
     * create a brand new contact from the conversation and link it.
     */
    public function attachConversation(Request $request, $conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $validated = $request->validate([
            'contact_id' => 'nullable|exists:contacts,id',
            'name' => 'required_without:contact_id|string|max:255',
            'phone_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ]);

        if (!empty($validated['contact_id'])) {
            $contact = Contact::where('id', $validated['contact_id'])
                ->where('company_id', $user->company_id)
                ->firstOrFail();

            // Sync this conversation's number into the existing contact so both
            // numbers live under a single record. Un cliente que oculta su
            // número no aporta ninguno: se vincula igual, sin tocar la ficha.
            if ($conversation->hasPhone()) {
                $contact->addNumber($conversation->phone_number);
            }
        } else {
            $phone = $validated['phone_number'] ?: ($conversation->hasPhone() ? $conversation->phone_number : null);

            // La agenda se indexa por número —unique(company_id, phone_number)—
            // así que una ficha sin él chocaría con la del siguiente cliente que
            // oculte el suyo, y no casaría con ningún abonado de Integra.
            if (!$phone) {
                return response()->json([
                    'message' => 'Este cliente oculta su número de WhatsApp. Vincúlalo a un contacto existente en vez de crear uno nuevo.',
                ], 422);
            }

            $contact = Contact::firstOrCreate(
                ['company_id' => $user->company_id, 'phone_number' => $phone],
                [
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                ]
            );

            // If it already existed but came in without a name, fill it in.
            if (!$contact->wasRecentlyCreated && empty($contact->name)) {
                $contact->update(['name' => $validated['name']]);
            }
        }

        // Al vincular, el nombre del contacto manda sobre el nombre de perfil de
        // WhatsApp: así la lista y la cabecera (que muestran `name`) reflejan el
        // contacto inmediatamente y tras refrescos/polling.
        $conversation->update([
            'contact_id' => $contact->id,
            'name' => $contact->name ?: $conversation->name,
        ]);
        $contact->loadCount('conversations');

        return response()->json($this->sanitizeUtf8([
            'success' => true,
            'contact' => $contact->toArray(),
        ]));
    }

    private function authorizeOwnership(Contact $contact): void
    {
        if ($contact->company_id !== auth()->user()->company_id) {
            abort(403);
        }
    }
}
