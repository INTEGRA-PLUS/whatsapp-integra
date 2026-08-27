<?php

namespace App\Http\Controllers;

use App\Jobs\SyncContactsFromIntegra;
use App\Models\CompanyIntegration;
use App\Services\IntegraClient;
use Illuminate\Http\Request;
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
                'description' => 'Conecta con el software Integra para registrar pagos desde el chat y para las opciones de autoservicio de los menús de WhatsApp (facturas, radicados, estado del servicio).',
            ],
            CompanyIntegration::KEY_CONTACTS_SYNC => [
                'key'         => CompanyIntegration::KEY_CONTACTS_SYNC,
                'name'        => 'Contactos',
                'description' => 'Sincroniza el maestro de clientes de Integra con tus contactos de WhatsApp.',
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
            'last_synced_at'  => optional($i->last_synced_at ?? null)->toIso8601String(),
            'sync_status'     => $i->sync_status ?? null,
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
     * Verificación de conexión que cada integración necesita: pega a un
     * endpoint liviano cuyo scope el token debería tener. "Pagos a facturas"
     * valida contra /pagos/catalogos; "Contactos" no requiere ese scope, así
     * que valida contra /contactos (página de 1) en su lugar.
     *
     * @throws \RuntimeException
     */
    private function pingForKey(string $key, IntegraClient $client): void
    {
        match ($key) {
            CompanyIntegration::KEY_CONTACTS_SYNC => $client->listContacts(['por_pagina' => 1]),
            default => $client->testConnection(),
        };
    }

    /**
     * POST /api/integrations/{key}/connect — conecta con el entorno Integra 2.0.
     *
     * La empresa indica la URL de SU entorno (Integra es multi-tenant) y se
     * autentica de una de dos formas:
     *  - email + password de su usuario de Integra (camino normal): el wizard
     *    canjea las credenciales por un token itg_ recién emitido vía
     *    POST /api/v1/tokens (la contraseña solo se usa aquí, nunca se guarda);
     *  - un token itg_ pegado a mano (`php artisan api:token` en el servidor de
     *    Integra), para entornos sin el endpoint de emisión.
     * En ambos casos se valida la conexión con una llamada real (que además
     * resuelve el prefijo /software) y el token se persiste cifrado (cast
     * `encrypted` del modelo); nunca vuelve al frontend. Cada integración
     * (`{key}`, ej. invoice_payments o contacts_sync) guarda su propia fila:
     * conectar una no conecta automáticamente la otra, aunque apunten al
     * mismo entorno/usuario de Integra.
     */
    public function connect(Request $request, string $key)
    {
        abort_unless(isset($this->catalog()[$key]), 404);

        $data = $request->validate([
            'base_url' => 'required|string|max:255',
            'token'    => 'nullable|string|max:255|required_without:email',
            'email'    => 'nullable|string|email|max:255|required_without:token',
            'password' => 'nullable|string|max:255|required_with:email',
        ]);

        $inputUrl = trim($data['base_url']);
        if (! filter_var($inputUrl, FILTER_VALIDATE_URL) || ! Str::startsWith($inputUrl, ['http://', 'https://'])) {
            return response()->json(['message' => 'La URL de tu entorno Integra no es válida. Debe incluir https://'], 422);
        }

        try {
            if (! empty($data['token'])) {
                $client = IntegraClient::probeBase($inputUrl, $data['token'], fn (IntegraClient $c) => $this->pingForKey($key, $c));
                $plainToken = $data['token'];
            } else {
                [$client, $plainToken] = IntegraClient::connectWithLogin($inputUrl, $data['email'], (string) $data['password']);
                // El token recién emitido trae los scopes de esta integración;
                // esta llamada valida además que la API responda con él.
                $this->pingForKey($key, $client);
            }
        } catch (\RuntimeException $e) {
            // Recordamos la URL para precargarla en reintentos, pero sin token ni estado conectado.
            CompanyIntegration::updateOrCreate(
                ['company_id' => $this->companyId(), 'key' => $key],
                ['base_url' => rtrim($inputUrl, '/'), 'status' => 'error', 'last_error' => $e->getMessage()]
            );

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $integration = CompanyIntegration::updateOrCreate(
            ['company_id' => $this->companyId(), 'key' => $key],
            [
                'status'           => 'connected',
                'base_url'         => $client->baseUrl(), // base resuelta (con /software si aplica)
                'access_token'     => $plainToken,
                'token_expires_at' => null, // los tokens de API de Integra no expiran
                'account'          => ['api' => 'integra2-v1'],
                'last_error'       => null,
                'connected_at'     => now(),
            ]
        );

        return response()->json($this->present($key, $integration->fresh()));
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
            $this->pingForKey($key, $client);
            $integration->update([
                'status'     => 'connected',
                'last_error' => null,
            ]);
        } catch (\RuntimeException $e) {
            $integration->update(['status' => 'error', 'last_error' => $e->getMessage()]);
        }

        return response()->json($this->present($key, $integration->fresh()));
    }

    /**
     * POST /api/integrations/{key}/sync — dispara en background la sincronización
     * masiva del maestro de clientes de Integra hacia la tabla `contacts`
     * (solo aplica a la integración "Contactos"). Marca `sync_status` como
     * `running` de una vez para que un doble clic no despache dos jobs.
     */
    public function syncContacts(string $key)
    {
        abort_unless($key === CompanyIntegration::KEY_CONTACTS_SYNC, 404);

        $integration = $this->find($key);
        if (! $integration || ! $integration->isConnected()) {
            return response()->json(['message' => 'Primero debes conectar la integración.'], 422);
        }

        if (($integration->sync_status['state'] ?? null) === 'running') {
            return response()->json(['message' => 'Ya hay una sincronización en curso.'], 422);
        }

        $integration->update([
            'sync_status' => [
                'state'       => 'running',
                'page'        => 0,
                'total_pages' => null,
                'processed'   => 0,
                'created'     => 0,
                'matched'     => 0,
                'error'       => null,
                'started_at'  => now()->toIso8601String(),
                'finished_at' => null,
            ],
        ]);

        SyncContactsFromIntegra::dispatch($integration->id);

        return response()->json($this->present($key, $integration->fresh()));
    }

    /** GET /api/integrations/{key}/sync-status — progreso de la sincronización de contactos (para polling). */
    public function syncStatus(string $key)
    {
        abort_unless($key === CompanyIntegration::KEY_CONTACTS_SYNC, 404);

        $integration = $this->find($key);

        return response()->json([
            'sync_status'    => $integration->sync_status ?? null,
            'last_synced_at' => optional($integration->last_synced_at ?? null)->toIso8601String(),
        ]);
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
            // El token maestro es estático: no hay nada que revocar en Integra,
            // basta con borrarlo de nuestro lado.
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

    /**
     * GET /api/integrations/invoice-payments/clients?search= — autocompletado de clientes.
     *
     * Pasa por /api/v1/contactos/buscar de Integra 2.0, que matchea un solo
     * criterio contra nit, nombre/apellidos, celular, teléfonos y email a la
     * vez. Los números de WhatsApp llegan con indicativo (57300...): se
     * normalizan a los últimos 10 dígitos, que es como Integra guarda los
     * celulares. Cada resultado trae el total que debe el cliente.
     *
     * Si el entorno Integra aún no tiene desplegado /contactos/buscar, degrada
     * a buscar por NIT exacto vía facturas/pendientes (que sí existe desde v1).
     */
    public function searchClients(Request $request)
    {
        $integration = $this->connectedOrFail();

        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $client = new IntegraClient($integration->base_url, $integration->access_token);

        $digits  = preg_replace('/\D+/', '', $search);
        $isDigits = $digits !== '' && $digits === preg_replace('/[\s\-\+\.\(\)]+/', '', $search);

        // Teléfono con indicativo → últimos 10 dígitos; lo demás va tal cual.
        $q = ($isDigits && strlen($digits) > 10) ? substr($digits, -10) : ($isDigits ? $digits : $search);

        try {
            $res = $client->searchContacts($q, 8);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === IntegraClient::CODE_ENDPOINT_MISSING && $isDigits) {
                // Entorno sin /contactos/buscar: al menos resolvemos NIT exacto vía facturas.
                return $this->searchClientsViaInvoices($client, $digits);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => array_map([$this, 'presentClient'], $res['data'])]);
    }

    /** Fallback para entornos Integra sin GET /contactos/buscar: NIT exacto vía facturas/pendientes. */
    private function searchClientsViaInvoices(IntegraClient $client, string $nit)
    {
        try {
            $res = $client->pendingInvoices(['nit' => $nit]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $cliente = $res['cliente'];
        if ($cliente) {
            $cliente['total_por_pagar'] = $res['total_por_pagar'];
            $cliente['facturas_pendientes'] = $res['facturas'];
        }

        return response()->json(['data' => $cliente ? [$this->presentClient($cliente)] : []]);
    }

    /**
     * Normaliza el contacto de Integra a lo que usa el modal del chat.
     *
     * Soporta las dos formas del payload de /contactos/buscar: la plana
     * original (nombre/celular/telefono1 en la raíz) y la segmentada de
     * Integra ≥ jul-2026 (nombre_completo en la raíz + segmento `contacto`
     * con los datos personales y `resumen` con los totales).
     */
    private function presentClient(array $c): array
    {
        $contacto = is_array($c['contacto'] ?? null) ? $c['contacto'] : [];
        $resumen  = is_array($c['resumen'] ?? null) ? $c['resumen'] : [];

        $porPagar = $c['total_por_pagar'] ?? $resumen['total_por_pagar'] ?? null;

        return [
            'id'              => $c['id'] ?? null,
            'nit'             => $c['identificacion'] ?? $contacto['identificacion'] ?? null,
            'nombre'          => $c['nombre_completo'] ?? $c['nombre'] ?? $contacto['nombre'] ?? '',
            'celular'         => $c['celular'] ?? $contacto['celular'] ?? null,
            'telefono'        => $c['telefono1'] ?? $contacto['telefono1'] ?? null,
            'total_por_pagar' => $porPagar !== null ? (float) $porPagar : null,
            'facturas_count'  => isset($c['facturas_pendientes'])
                ? count($c['facturas_pendientes'])
                : ($resumen['facturas_pendientes'] ?? null),
        ];
    }

    /**
     * GET /api/integrations/invoice-payments/invoices — facturas pendientes del cliente.
     *
     * Pasa por /api/v1/facturas/pendientes de Integra 2.0: facturas abiertas con
     * saldo, con montos, ítems y contratos vinculados. Acepta `cliente_id` o `nit`.
     */
    public function invoices(Request $request)
    {
        $integration = $this->connectedOrFail();

        $data = $request->validate([
            'cliente_id' => 'nullable|integer',
            'nit'        => 'nullable|string|max:32',
        ]);

        $params = array_filter([
            'cliente_id' => $data['cliente_id'] ?? null,
            'nit'        => $data['nit'] ?? null,
        ]);

        if (empty($params)) {
            return response()->json(['message' => 'Indica el cliente (cliente_id o nit).'], 422);
        }

        // Si llegan ambos, cliente_id gana (array_filter conserva el orden de las llaves).
        $params = array_slice($params, 0, 1);

        $client = new IntegraClient($integration->base_url, $integration->access_token);

        try {
            return response()->json($client->pendingInvoices($params));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/integrations/invoice-payments/catalogs — catálogos para registrar
     * un pago (cuentas/bancos, métodos y formas de pago del entorno Integra).
     */
    public function catalogs()
    {
        $integration = $this->connectedOrFail();

        $client = new IntegraClient($integration->base_url, $integration->access_token);

        try {
            return response()->json($client->paymentCatalogs());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/integrations/invoice-payments/pay — registra el pago de una
     * factura en Integra 2.0 (POST /api/v1/facturas/{id}/pagos). Integra genera
     * el recibo de caja, el movimiento contable, cierra la factura si se cubre
     * el saldo y reactiva el servicio si corresponde.
     */
    public function pay(Request $request)
    {
        $integration = $this->connectedOrFail();

        $data = $request->validate([
            'factura_id'    => 'required|integer',
            'cuenta'        => 'required|integer',
            'metodo_pago'   => 'required|integer',
            'monto'         => 'required|numeric|min:1',
            'observaciones' => 'nullable|string|max:255',
        ]);

        $client = new IntegraClient($integration->base_url, $integration->access_token);

        $agente = auth()->user()->name ?? 'agente';

        try {
            $result = $client->registerPayment((int) $data['factura_id'], [
                'cuenta'        => $data['cuenta'],
                'metodo_pago'   => $data['metodo_pago'],
                'monto'         => $data['monto'],
                'observaciones' => $data['observaciones'] ?? "Pago registrado desde WhatsApp ({$agente})",
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
