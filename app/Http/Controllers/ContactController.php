<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\WhatsAppConversation;
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
            // numbers live under a single record.
            $contact->addNumber($conversation->phone_number);
        } else {
            $phone = $validated['phone_number'] ?? $conversation->phone_number;

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

        $conversation->update(['contact_id' => $contact->id]);
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
