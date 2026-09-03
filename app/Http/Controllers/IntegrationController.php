<?php

namespace App\Http\Controllers;

use App\Jobs\SyncContactsFromIntegra;
use App\Models\CompanyIntegration;
use App\Services\Integra;
use App\Services\IntegraClient;
use App\Support\IntegrationProvider;
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
            'emit_electronic_invoice' => (bool) ($i->emit_electronic_invoice ?? false),
            // true / false / null ("no sabemos"): la UI trata cada caso distinto.
            'can_emit_electronic'     => $i?->grantsEmission(),
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
     * `encrypted` del modelo); nunca vuelve al frontend.
     *
     * Conectar una función conecta TODAS las del mismo proveedor: es un solo
     * entorno y un solo token, así que pedir la URL dos veces sólo servía para
     * escribirla mal una de las dos y para que el segundo token revocara al
     * primero. Ver IntegrationProvider.
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
                // Un token ajeno no dice qué autoriza: null es "no sabemos".
                $abilities = null;
            } else {
                [$client, $plainToken, $abilities] = IntegraClient::connectWithLogin($inputUrl, $data['email'], (string) $data['password']);
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

        $connection = [
            'status'           => 'connected',
            'base_url'         => $client->baseUrl(), // base resuelta (con /software si aplica)
            'access_token'     => $plainToken,
            'token_expires_at' => null, // los tokens de API de Integra no expiran
            'account'          => ['api' => 'integra2-v1'],
            'abilities'        => $abilities,
            'last_error'       => null,
            'connected_at'     => now(),
        ];

        // La conexión es del PROVEEDOR, no de la tarjeta: la misma URL y el
        // mismo token se guardan en todas las funciones que ese proveedor
        // habilita. Antes cada una emitía el suyo contra el mismo servidor y,
        // como Integra desactiva los anteriores al emitir uno con el mismo
        // nombre, conectar la segunda dejaba muerta la primera — con las dos
        // en verde en pantalla. Lo que NO se toca es lo propio de cada función
        // (si está activada en el chat y con qué comando).
        foreach (IntegrationProvider::siblingKeys($key) as $sibling) {
            CompanyIntegration::updateOrCreate(
                ['company_id' => $this->companyId(), 'key' => $sibling],
                $connection
            );
        }

        return response()->json($this->present($key, $this->find($key)));
    }

    /** GET /api/integrations/{key}/status — verifica la conexión contra Integra. */
    public function status(string $key)
    {
        abort_unless(isset($this->catalog()[$key]), 404);

        $integration = $this->find($key);
        if (! $integration || ! $integration->access_token) {
            return response()->json($this->present($key, $integration));
        }

        $client = $integration->client();

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
            'emit_electronic_invoice' => 'sometimes|boolean',
        ]);

        $integration = $this->find($key);
        if (! $integration || ! $integration->isConnected()) {
            return response()->json(['message' => 'Primero debes conectar la integración.'], 422);
        }

        // Emitir a la DIAN necesita un scope aparte del de registrar pagos, y
        // a un token sin él Integra no le responde 403: le responde "no_aplica"
        // y registra el pago igual. Encender el interruptor sobre un token así
        // no produce ningún error visible — produce una casilla marcada que no
        // hace nada. Se corta aquí, donde todavía hay a quién decírselo.
        if (($data['emit_electronic_invoice'] ?? false) && $integration->grantsEmission() === false) {
            return response()->json([
                'message' => 'El token de esta empresa no autoriza emitir facturas a la DIAN. Reconecta la integración para pedir uno nuevo con ese permiso.',
            ], 422);
        }

        $integration->update([
            'enabled'         => $data['enabled'],
            'trigger_type'    => $data['trigger_type'],
            'trigger_command' => $data['trigger_command'] ? ltrim($data['trigger_command'], '/@') : null,
            'emit_electronic_invoice' => $data['emit_electronic_invoice'] ?? $integration->emit_electronic_invoice,
        ]);

        return response()->json($this->present($key, $integration->fresh()));
    }

    /** POST /api/integrations/{key}/disconnect — borra token y desactiva. */
    public function disconnect(string $key)
    {
        abort_unless(isset($this->catalog()[$key]), 404);

        // Se desconecta el proveedor entero, por lo mismo que se conecta
        // entero: es un solo token. Dejar la otra tarjeta en verde con el
        // token que se acaba de borrar sólo produce una pantalla que miente.
        CompanyIntegration::where('company_id', $this->companyId())
            ->whereIn('key', IntegrationProvider::siblingKeys($key))
            ->get()
            ->each(fn (CompanyIntegration $i) => $i->update([
                // El token maestro es estático: no hay nada que revocar en
                // Integra, basta con borrarlo de nuestro lado.
                'status'           => 'disconnected',
                'access_token'     => null,
                'token_expires_at' => null,
                'abilities'        => null,
                'enabled'          => false,
                // La emisión electrónica cuelga del token que se acaba de
                // borrar: dejarla encendida haría que la siguiente conexión
                // —quizá con un token sin ese permiso— heredara en silencio
                // una casilla marcada que nadie volvió a decidir.
                'emit_electronic_invoice' => false,
                'last_error'       => null,
            ]));

        return response()->json($this->present($key, $this->find($key)));
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
        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $digits  = preg_replace('/\D+/', '', $search);
        $isDigits = $digits !== '' && $digits === preg_replace('/[\s\-\+\.\(\)]+/', '', $search);

        // Teléfono con indicativo → últimos 10 dígitos; lo demás va tal cual.
        $q = ($isDigits && strlen($digits) > 10) ? substr($digits, -10) : ($isDigits ? $digits : $search);

        return $this->payments(function (IntegraClient $client) use ($q, $digits, $isDigits) {
            try {
                $res = $client->searchContacts($q, 8);
            } catch (\RuntimeException $e) {
                if ($e->getCode() === IntegraClient::CODE_ENDPOINT_MISSING && $isDigits) {
                    // Entorno sin /contactos/buscar: al menos resolvemos NIT exacto vía facturas.
                    return ['data' => $this->searchClientsViaInvoices($client, $digits)];
                }

                throw $e;
            }

            return ['data' => array_map([$this, 'presentClient'], $res['data'])];
        });
    }

    /**
     * Fallback para entornos Integra sin GET /contactos/buscar: NIT exacto vía
     * facturas/pendientes. Deja subir la excepción: la traduce quien envuelve.
     *
     * @return array<int, array>
     */
    private function searchClientsViaInvoices(IntegraClient $client, string $nit): array
    {
        $res = $client->pendingInvoices(['nit' => $nit]);

        $cliente = $res['cliente'];
        if ($cliente) {
            $cliente['total_por_pagar'] = $res['total_por_pagar'];
            $cliente['facturas_pendientes'] = $res['facturas'];
        }

        return $cliente ? [$this->presentClient($cliente)] : [];
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

        return $this->payments(fn (IntegraClient $client) => $client->pendingInvoices($params));
    }

    /**
     * GET /api/integrations/invoice-payments/catalogs — catálogos para registrar
     * un pago (cuentas/bancos, métodos y formas de pago del entorno Integra).
     */
    public function catalogs()
    {
        return $this->payments(fn (IntegraClient $client) => $client->paymentCatalogs());
    }

    /**
     * POST /api/integrations/invoice-payments/pay — registra el pago de una
     * factura en Integra 2.0 (POST /api/v1/facturas/{id}/pagos). Integra genera
     * el recibo de caja, el movimiento contable, cierra la factura si se cubre
     * el saldo y reactiva el servicio si corresponde.
     *
     * Si la empresa activó "emitir electrónica al pagar" en la tarjeta de la
     * integración, el pago viaja además con `emitir_electronica`, y es Integra
     * quien convierte la factura estándar en electrónica y la emite a la DIAN.
     * El agente no decide esto desde el chat: es política de la empresa.
     *
     * El parámetro sólo se manda cuando está encendido. Un pago es una
     * operación fiscal y no vale la pena arriesgar los que ya funcionan
     * mandándole al ERP una llave que todavía podría no esperar.
     */
    public function pay(Request $request)
    {
        $data = $request->validate([
            'factura_id'    => 'required|integer',
            'cuenta'        => 'required|integer',
            'metodo_pago'   => 'required|integer',
            'monto'         => 'required|numeric|min:1',
            'observaciones' => 'nullable|string|max:255',
        ]);

        $agente = auth()->user()->name ?? 'agente';
        $emitir = (bool) ($this->find(CompanyIntegration::KEY_INVOICE_PAYMENTS)?->emit_electronic_invoice);

        return $this->payments(fn (IntegraClient $client) => [
            'message' => 'Pago registrado correctamente.',
            'result'  => $client->registerPayment((int) $data['factura_id'], array_merge([
                'cuenta'        => $data['cuenta'],
                'metodo_pago'   => $data['metodo_pago'],
                'monto'         => $data['monto'],
                'observaciones' => $data['observaciones'] ?? "Pago registrado desde WhatsApp ({$agente})",
            ], $emitir ? ['emitir_electronica' => true] : [])),
        ]);
    }

    /**
     * Consulta Integra desde el flujo de pagos del chat.
     *
     * Es el único consumidor con un interruptor propio —el disparador que el
     * admin activa en la tarjeta— así que no basta con que haya conexión: hay
     * que respetar también ese interruptor. Todo lo demás (resolver el cliente,
     * traducir el fallo a un 422 con mensaje) lo pone Integra::run.
     *
     * @param callable(IntegraClient): mixed $query
     */
    private function payments(callable $query): \Illuminate\Http\JsonResponse
    {
        $integration = $this->find(CompanyIntegration::KEY_INVOICE_PAYMENTS);

        if (! $integration || ! $integration->isConnected() || ! $integration->enabled) {
            return response()->json(['message' => 'La integración de pagos no está activa.'], 422);
        }

        return Integra::run($integration->client(), $query);
    }
}
