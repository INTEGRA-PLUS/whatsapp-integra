<?php

namespace App\Http\Controllers;

use App\Models\CompanyIntegration;
use App\Services\IntegraClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    /** Catálogo de integraciones disponibles (estático por ahora). */
    private function catalog(): array
    {
        return [
            CompanyIntegration::KEY_INVOICE_PAYMENTS => [
                'key'         => CompanyIntegration::KEY_INVOICE_PAYMENTS,
                'name'        => 'Pagos a facturas',
                'description' => 'Conecta con el software Integra para registrar pagos a facturas desde el chat.',
            ],
        ];
    }

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    /** Serializa una integración para el frontend (sin secretos). */
    private function present(string $key, ?CompanyIntegration $i): array
    {
        $meta = $this->catalog()[$key];

        return [
            'key'             => $key,
            'name'            => $meta['name'],
            'description'     => $meta['description'],
            'status'          => $i->status ?? 'disconnected',
            'connected'       => $i ? $i->isConnected() : false,
            'base_url'        => $i->base_url ?? null,
            'account'         => $i->account ?? null,
            'enabled'         => (bool) ($i->enabled ?? false),
            'trigger_type'    => $i->trigger_type ?? 'slash',
            'trigger_command' => $i->trigger_command ?? null,
            'last_error'      => $i->last_error ?? null,
            'connected_at'    => optional($i->connected_at ?? null)->toIso8601String(),
            'token_expired'   => $i ? $i->tokenExpired() : false,
        ];
    }

    private function find(string $key): ?CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $this->companyId())
            ->where('key', $key)
            ->first();
    }

    /** GET /api/integrations — lista el estado de todas las integraciones. */
    public function index()
    {
        $items = collect($this->catalog())->map(function ($meta, $key) {
            return $this->present($key, $this->find($key));
        })->values();

        return response()->json($items);
    }

    /**
     * GET /integrations/invoice-payments/connect — inicia el flujo OAuth con Integra (paso 1).
     *
     * La empresa indica la URL de SU entorno Integra (Integra es multi-tenant); este wpp
     * es de un solo tenant, así que el redirect_uri siempre es nuestra propia URL de callback.
     * Genera un `state` aleatorio, lo guarda en sesión y redirige el navegador a la vista
     * de autorización de Integra. Al terminar, Integra vuelve a {oauthCallback} con el code.
     */
    public function oauthStart(Request $request)
    {
        $baseUrl = rtrim((string) $request->query('base_url', ''), '/');

        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! Str::startsWith($baseUrl, ['http://', 'https://'])) {
            return redirect()->route('integrations.index', ['integra' => 'error'])
                ->with('error', 'La URL de tu entorno Integra no es válida. Debe incluir https://');
        }

        $state       = Str::random(40);
        $redirectUri = route('integrations.invoice-payments.callback');

        // Recordamos la URL de la empresa para precargarla en futuras reconexiones.
        CompanyIntegration::updateOrCreate(
            ['company_id' => $this->companyId(), 'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS],
            ['base_url' => $baseUrl]
        );

        // El redirect_uri debe ser idéntico en el canje: lo guardamos junto al state y la URL.
        $request->session()->put('integra_oauth', [
            'state'        => $state,
            'redirect_uri' => $redirectUri,
            'company_id'   => $this->companyId(),
            'base_url'     => $baseUrl,
        ]);

        return redirect()->away((new IntegraClient($baseUrl))->authorizeUrl($redirectUri, $state));
    }

    /**
     * GET /integrations/invoice-payments/callback — recibe el code y lo canjea por el token (paso 2).
     */
    public function oauthCallback(Request $request)
    {
        $key   = CompanyIntegration::KEY_INVOICE_PAYMENTS;
        $oauth = $request->session()->pull('integra_oauth');

        $fail = fn (string $msg) => redirect()
            ->route('integrations.index', ['integra' => 'error'])
            ->with('error', $msg);

        // Integra puede devolver un error en vez de un code (p. ej. el usuario canceló).
        if ($request->filled('error')) {
            return $fail($request->query('error_description') ?: 'Integra rechazó la autorización.');
        }

        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        // Validamos el state (CSRF) y que la sesión pertenezca a la misma empresa.
        if (! $oauth
            || ! hash_equals((string) ($oauth['state'] ?? ''), $state)
            || (int) ($oauth['company_id'] ?? 0) !== $this->companyId()) {
            return $fail('La sesión de autorización expiró o no es válida. Inténtalo de nuevo.');
        }
        if ($code === '') {
            return $fail('Integra no devolvió un código de autorización.');
        }

        $baseUrl = $oauth['base_url'] ?? null;
        if (! $baseUrl) {
            return $fail('La URL de tu entorno Integra no está disponible. Vuelve a conectar.');
        }

        try {
            $result = (new IntegraClient($baseUrl))->exchangeCode($code, $oauth['redirect_uri']);
        } catch (\RuntimeException $e) {
            $integration = $this->find($key);
            if ($integration) {
                $integration->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            }
            return $fail($e->getMessage());
        }

        CompanyIntegration::updateOrCreate(
            ['company_id' => $this->companyId(), 'key' => $key],
            [
                'status'           => 'connected',
                'base_url'         => $baseUrl,
                'access_token'     => $result['token'],
                'token_expires_at' => $result['expires_at'] ? Carbon::parse($result['expires_at']) : null,
                'account'          => $result['user'],
                'last_error'       => null,
                'connected_at'     => now(),
            ]
        );

        return redirect()->route('integrations.index', ['integra' => 'connected'])
            ->with('success', 'Conexión establecida con Integra.');
    }

    /** GET /api/integrations/{key}/status — verifica la conexión contra Integra. */
    public function status(string $key)
    {
        abort_unless(isset($this->catalog()[$key]), 404);

        $integration = $this->find($key);
        if (! $integration || ! $integration->access_token) {
            return response()->json($this->present($key, $integration));
        }

        $client = new IntegraClient($integration->base_url, $integration->access_token);

        try {
            $user = $client->me();
            $integration->update([
                'status'     => 'connected',
                'account'    => $user ?: $integration->account,
                'last_error' => null,
            ]);
        } catch (\RuntimeException $e) {
            $integration->update(['status' => 'error', 'last_error' => $e->getMessage()]);
        }

        return response()->json($this->present($key, $integration->fresh()));
    }

    /** POST /api/integrations/{key}/activate — define disparador y habilita en el chat. */
    public function activate(Request $request, string $key)
    {
        abort_unless(isset($this->catalog()[$key]), 404);

        $data = $request->validate([
            'enabled'         => 'required|boolean',
            'trigger_type'    => 'required|in:slash,at',
            'trigger_command' => 'required_if:enabled,true|nullable|alpha_dash|max:64',
        ]);

        $integration = $this->find($key);
        if (! $integration || ! $integration->isConnected()) {
            return response()->json(['message' => 'Primero debes conectar la integración.'], 422);
        }

        $integration->update([
            'enabled'         => $data['enabled'],
            'trigger_type'    => $data['trigger_type'],
            'trigger_command' => $data['trigger_command'] ? ltrim($data['trigger_command'], '/@') : null,
        ]);

        return response()->json($this->present($key, $integration->fresh()));
    }

    /** POST /api/integrations/{key}/disconnect — borra token y desactiva. */
    public function disconnect(string $key)
    {
        abort_unless(isset($this->catalog()[$key]), 404);

        $integration = $this->find($key);
        if ($integration) {
            // Revoca el token en Integra antes de borrarlo localmente (best-effort).
            if ($integration->access_token && $integration->base_url) {
                (new IntegraClient($integration->base_url, $integration->access_token))->logout();
            }
            $integration->update([
                'status'           => 'disconnected',
                'access_token'     => null,
                'token_expires_at' => null,
                'enabled'          => false,
                'last_error'       => null,
            ]);
        }

        return response()->json($this->present($key, $integration?->fresh()));
    }

    /** GET /api/integrations/invoice-payments/clients?search= — autocompletado de clientes. */
    public function searchClients(Request $request)
    {
        $integration = $this->connectedOrFail();

        $search = (string) $request->query('search', '');
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $client = new IntegraClient($integration->base_url, $integration->access_token);

        try {
            return response()->json(['data' => $client->searchClients($search)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/integrations/invoice-payments/pay — registra el pago en Integra. */
    public function pay(Request $request)
    {
        $integration = $this->connectedOrFail();

        $data = $request->validate([
            'cliente_id'     => 'nullable',
            'cliente_nombre' => 'required|string|max:255',
            'valor'          => 'required|numeric|min:0',
            'pago_total'     => 'required|boolean',
        ]);

        $client = new IntegraClient($integration->base_url, $integration->access_token);

        try {
            $result = $client->createPayment([
                'cliente_id'     => $data['cliente_id'],
                'cliente_nombre' => $data['cliente_nombre'],
                'valor'          => $data['valor'],
                'pago_total'     => $data['pago_total'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Pago registrado correctamente.', 'result' => $result]);
    }

    /** Devuelve la integración de pagos conectada o aborta con 422. */
    private function connectedOrFail(): CompanyIntegration
    {
        $integration = $this->find(CompanyIntegration::KEY_INVOICE_PAYMENTS);

        if (! $integration || ! $integration->isConnected() || ! $integration->enabled) {
            abort(response()->json(['message' => 'La integración de pagos no está activa.'], 422));
        }

        return $integration;
    }
}
