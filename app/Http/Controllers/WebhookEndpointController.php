<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use App\Support\IntegrationProvider;
use Inertia\Inertia;

class WebhookEndpointController extends Controller
{
    public function index()
    {
        return Inertia::render('Integrations/Index', [
            'webhooks'      => $this->companyWebhooks()->get(),
            'eventCatalog'  => config('webhooks.events', []),
            // El catálogo de proveedores conectables. Viaja desde el backend
            // para que añadir uno nuevo no exija tocar también el frontend.
            'providers'     => IntegrationProvider::forDisplay(),
        ]);
    }

    public function list()
    {
        return response()->json($this->companyWebhooks()->get());
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $validated = $this->validatePayload($request);

        $webhook = WebhookEndpoint::create([
            'company_id' => $companyId,
            'name'       => $validated['name'],
            'url'        => $validated['url'],
            'events'     => $validated['events'],
            'headers'    => $validated['headers'] ?? null,
            'active'     => $validated['active'] ?? true,
            'created_by' => auth()->id(),
        ]);

        return response()->json($webhook, 201);
    }

    public function update(Request $request, WebhookEndpoint $webhook)
    {
        $this->authorizeOwnership($webhook);

        $validated = $this->validatePayload($request, partial: true);

        $webhook->update($validated);

        return response()->json($webhook);
    }

    public function destroy(WebhookEndpoint $webhook)
    {
        $this->authorizeOwnership($webhook);
        $webhook->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Fire a sample payload to the endpoint so the user can verify connectivity.
     */
    public function test(WebhookEndpoint $webhook)
    {
        $this->authorizeOwnership($webhook);

        DeliverWebhook::dispatch($webhook->id, 'webhook.test', [
            'event'      => 'webhook.test',
            'company_id' => $webhook->company_id,
            'sent_at'    => now()->toIso8601String(),
            'data'       => [
                'message' => 'Este es un evento de prueba desde tu plataforma WhatsApp.',
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evento de prueba encolado. Revisa el historial de entregas.',
        ]);
    }

    public function deliveries(WebhookEndpoint $webhook)
    {
        $this->authorizeOwnership($webhook);

        return response()->json(
            $webhook->deliveries()
                ->latest('id')
                ->limit(50)
                ->get(['id', 'event', 'status_code', 'success', 'error', 'attempts', 'delivered_at'])
        );
    }

    private function companyWebhooks()
    {
        return WebhookEndpoint::forCompany(auth()->user()->company_id)
            ->orderByDesc('id');
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';
        $validEvents = array_keys(config('webhooks.events', []));

        return $request->validate([
            'name'       => "$rule|string|max:100",
            'url'        => "$rule|url|max:2048",
            'events'     => "$rule|array|min:1",
            'events.*'   => 'string|in:' . implode(',', $validEvents),
            'headers'    => 'nullable|array',
            'active'     => 'boolean',
        ]);
    }

    private function authorizeOwnership(WebhookEndpoint $webhook): void
    {
        if ($webhook->company_id !== auth()->user()->company_id) {
            abort(403);
        }
    }
}
