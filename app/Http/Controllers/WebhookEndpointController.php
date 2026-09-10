<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\CompanyIntegration;
use App\Models\Instance;
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
            'lineasDelErp'  => $this->lineasDelErp(),
            'lineaElegida'  => auth()->user()->company->tieneLineaDelErpElegida(),
        ]);
    }

    /**
     * Los ajustes de envío del ERP: interruptores y plantillas por defecto.
     *
     * Se leen de Integra en cada visita y no se guardan aquí. Es lo que evita
     * que haya dos copias de la misma configuración y que nadie sepa cuál manda
     * — el problema con el que empezó todo este trabajo.
     */
    public function ajustesDeEnvio()
    {
        $cliente = $this->clienteDeIntegra();

        if (! $cliente) {
            return response()->json(['conectado' => false]);
        }

        $res = $cliente->ajustesDeEnvio();

        return response()->json($res['ok']
            ? ['conectado' => true] + $res['datos']
            : ['conectado' => true, 'error' => $this->avisoDeAjustes($res)]);
    }

    public function guardarAjustesDeEnvio(Request $request)
    {
        $validado = $request->validate([
            'envio_automatico_facturas' => 'nullable|boolean',
            'envio_automatico_recibos' => 'nullable|boolean',
            'plantilla_factura_id' => 'nullable|integer',
            'plantilla_tirilla_id' => 'nullable|integer',
            'plantilla_contrato_id' => 'nullable|integer',
        ]);

        $cliente = $this->clienteDeIntegra();

        if (! $cliente) {
            return response()->json(['message' => 'Integra no está conectado.'], 422);
        }

        $res = $cliente->guardarAjustesDeEnvio($validado);

        if (! $res['ok']) {
            return response()->json(['message' => $this->avisoDeAjustes($res)], 422);
        }

        return response()->json(['ok' => true] + $res['datos']);
    }

    /**
     * Qué dato va en cada `{{n}}` de la plantilla elegida.
     *
     * Elegir la plantilla y decir qué lleva dentro son la misma decisión, y
     * hasta ahora estaban en dos sistemas: la plantilla se elige aquí y sus
     * variables se editaban en Integra. Se resuelven las dos en el mismo sitio,
     * pero la parametrización se sigue guardando allí, que es de donde el cron
     * la lee.
     *
     * Al detalle del ERP se le añade el texto real de la plantilla en Meta: es
     * el que decide cuántas variables se envían, y si no coincide con el que
     * Integra tiene guardado, Meta rechaza el envío entero.
     */
    public function camposDePlantilla(int $plantilla)
    {
        $cliente = $this->clienteDeIntegra();

        if (! $cliente) {
            return response()->json(['message' => 'Integra no está conectado.'], 422);
        }

        $res = $cliente->camposDePlantilla($plantilla);

        if (! $res['ok']) {
            return response()->json(['message' => $this->avisoDeAjustes($res)], 422);
        }

        return response()->json($res['datos'] + ['meta' => $this->plantillaEnMeta($res['datos'])]);
    }

    public function guardarCamposDePlantilla(Request $request, int $plantilla)
    {
        $datos = $request->validate([
            'variables' => 'present|array',
            'variables.*' => 'nullable|string|max:1024',
        ]);

        $cliente = $this->clienteDeIntegra();

        if (! $cliente) {
            return response()->json(['message' => 'Integra no está conectado.'], 422);
        }

        $res = $cliente->guardarCamposDePlantilla($plantilla, $datos['variables']);

        if (! $res['ok']) {
            return response()->json(['message' => $this->avisoDeAjustes($res)], 422);
        }

        return response()->json($res['datos'] + ['meta' => $this->plantillaEnMeta($res['datos'])]);
    }

    /**
     * El cuerpo de esa misma plantilla tal y como está en Meta, buscada por
     * nombre e idioma en la línea por la que envía el ERP.
     *
     * Es la única cuenta de variables que manda: si la plantilla se editó en
     * Meta y ahora pide una más, Integra no se entera y el envío se cae entero
     * con «number of parameters does not match». Si no se puede leer el
     * catálogo se devuelve `null` y la pantalla se queda con lo que dice el
     * ERP: no saber no es lo mismo que saber que no está.
     *
     * @param  array<string, mixed>  $delErp
     * @return array<string, mixed>|null
     */
    private function plantillaEnMeta(array $delErp): ?array
    {
        $linea = auth()->user()->company->instanciaDelErp();

        if (! $linea || empty($linea->waba_id) || empty($linea->access_token)) {
            return null;
        }

        $res = app(\App\Services\MetaWhatsAppService::class)
            ->listTemplates($linea->waba_id, $linea->access_token, ['limit' => 200]);

        if (! ($res['success'] ?? false)) {
            return null;
        }

        $nombre = $delErp['title'] ?? '';
        $idioma = $delErp['language'] ?? '';

        $plantilla = collect($res['data']['data'] ?? [])
            ->first(fn ($p) => ($p['name'] ?? null) === $nombre
                && (($p['language'] ?? null) === $idioma || ! $idioma));

        if (! $plantilla) {
            return ['encontrada' => false, 'linea' => $linea->display_phone_number ?: $linea->name];
        }

        $cuerpo = collect($plantilla['components'] ?? [])
            ->first(fn ($c) => strtoupper($c['type'] ?? '') === 'BODY');

        $texto = $cuerpo['text'] ?? '';
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $texto, $coincidencias);

        return [
            'encontrada' => true,
            'linea' => $linea->display_phone_number ?: $linea->name,
            'estado' => $plantilla['status'] ?? null,
            'texto' => $texto,
            'huecos' => empty($coincidencias[1]) ? 0 : max(array_map('intval', $coincidencias[1])),
        ];
    }

    /** Lo que hay que hacer, no el código de error. */
    private function avisoDeAjustes(array $res): string
    {
        return match (true) {
            ($res['sin_permiso'] ?? false) => 'Tu conexión con Integra es anterior a esta función. '
                .'Vuelve a conectarla aquí mismo para poder ver y cambiar estos ajustes.',
            ($res['sin_endpoint'] ?? false) => 'Tu versión de Integra todavía no expone estos ajustes. '
                .'Actualízala y vuelve a intentarlo.',
            default => $res['error'] ?? 'No se pudieron leer los ajustes de Integra.',
        };
    }

    /** La conexión con Integra de esta empresa, si está conectada. */
    private function clienteDeIntegra(): ?\App\Services\IntegraClient
    {
        $integracion = CompanyIntegration::where('company_id', auth()->user()->company_id)
            ->whereIn('key', IntegrationProvider::find(IntegrationProvider::INTEGRA)['legacy_keys'] ?? [])
            ->get()
            ->first(fn (CompanyIntegration $i) => $i->isConnected());

        return $integracion?->client();
    }

    /**
     * Qué plantillas aprobadas tiene la línea de hoy y le faltan a la nueva.
     *
     * Los catálogos de plantillas viven en Meta y son **por WABA**: dos líneas
     * de la misma empresa no comparten ninguna. Mudarse a una línea sin su
     * catálogo es quedarse sin facturar, y se nota factura a factura.
     *
     * Si no se puede leer alguno de los dos catálogos no se bloquea nada: no
     * saber no es lo mismo que saber que falta, y dejar a alguien sin poder
     * cambiar de línea porque Meta no contestó sería peor.
     *
     * @return list<string>
     */
    private function plantillasQueFaltan(\App\Models\Company $company, int $nuevaId): array
    {
        $actual = $company->instanciaDelErp();
        $nueva = Instance::find($nuevaId);

        if (! $actual || ! $nueva || $actual->id === $nueva->id || $actual->waba_id === $nueva->waba_id) {
            return [];
        }

        $meta = app(\App\Services\MetaWhatsAppService::class);

        $aprobadasDe = function (Instance $linea) use ($meta): ?array {
            if (empty($linea->waba_id) || empty($linea->access_token)) {
                return null;
            }

            $res = $meta->listTemplates($linea->waba_id, $linea->access_token, ['limit' => 200]);

            if (! ($res['success'] ?? false)) {
                return null;
            }

            return collect($res['data']['data'] ?? [])
                ->where('status', 'APPROVED')
                ->map(fn ($p) => ($p['name'] ?? '').' ('.($p['language'] ?? '').')')
                ->all();
        };

        $enActual = $aprobadasDe($actual);
        $enNueva = $aprobadasDe($nueva);

        if ($enActual === null || $enNueva === null) {
            return [];
        }

        return array_values(array_diff($enActual, $enNueva));
    }

    /**
     * Por qué líneas está enviando el ERP, y desde cuándo no lo hace.
     *
     * El CRM e Integra 2.0 son dos bases de datos distintas, cada una con su
     * tabla `instances`, unidas por una sola cadena de texto: el
     * `phone_number_id`. Hasta ahora no había **ninguna pantalla** donde ver si
     * esa unión estaba viva: para saber si un ERP seguía enviando por una línea
     * había que contar mensajes con `incoming_invoice_id` en la base de datos.
     *
     * Sale de una marca que deja cada llamada del API (`api_last_seen_at`), no
     * de contar el millón de mensajes cada vez que alguien abre esta pantalla.
     *
     * @return list<array<string, mixed>>
     */
    private function lineasDelErp(): array
    {
        $company = auth()->user()->company;
        $elegida = $company->instanciaDelErp();

        return Instance::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('api_last_seen_at')
            ->get(['id', 'name', 'phone_number_id', 'display_phone_number', 'api_last_seen_at', 'api_last_seen_via'])
            ->map(fn ($instancia) => [
                'id' => $instancia->id,
                'nombre' => $instancia->name,
                'numero' => $instancia->display_phone_number,
                'phone_number_id' => $instancia->phone_number_id,
                'ultima_vez' => $instancia->api_last_seen_at?->toIso8601String(),
                'credencial' => $instancia->api_last_seen_via,
                'es_la_del_erp' => $elegida !== null && $instancia->id === $elegida->id,
            ])
            ->values()
            ->all();
    }

    /**
     * Elegir por qué línea envía el ERP sus facturas y recibos.
     *
     * Antes no se elegía: Integra 2.0 se quedaba con la que devolviera la base
     * de datos. Con una sola línea da igual; con dos, el ERP estaba usando la
     * que no era y nadie tenía dónde verlo ni dónde cambiarlo.
     */
    public function elegirLineaDelErp(Request $request)
    {
        $company = auth()->user()->company;

        $validado = $request->validate([
            'instance_id' => 'nullable|integer',
        ]);

        $instanceId = $validado['instance_id'] ?? null;

        // Una línea de otra empresa no se puede elegir, y una inactiva no
        // enviaría nada: las dos se rechazan aquí y no en el ERP, donde el
        // fallo llegaría días después y sin explicación.
        if ($instanceId !== null) {
            $existe = Instance::where('id', $instanceId)
                ->where('company_id', $company->id)
                ->where('active', true)
                ->exists();

            if (! $existe) {
                return back()->withErrors([
                    'instance_id' => 'Esa línea no es de tu empresa o no está activa.',
                ]);
            }
        }

        // Una línea sin las plantillas de la que envía hoy no puede facturar:
        // los catálogos de Meta son por WABA. El 10-sep-2026 cambiar de línea
        // sin comprobarlo dejó a Transinternet una noche entera con Meta
        // devolviendo «(#100) Invalid parameter» en cada factura, y nadie se
        // enteró hasta que el cliente lo dijo por WhatsApp a las 6 de la mañana.
        if ($instanceId !== null && ($faltan = $this->plantillasQueFaltan($company, $instanceId)) !== []) {
            return back()->withErrors([
                'instance_id' => 'Esa línea todavía no tiene aprobadas estas plantillas: '
                    .implode(', ', array_slice($faltan, 0, 5))
                    .(count($faltan) > 5 ? ' y '.(count($faltan) - 5).' más' : '')
                    .'. Cópialas desde Plantillas y espera a que Meta las apruebe: '
                    .'si cambias ahora, las facturas dejarán de salir.',
            ]);
        }

        $company->elegirInstanciaDelErp($instanceId);

        return back()->with('success', $instanceId
            ? 'Listo: el ERP enviará por esa línea a partir del próximo envío.'
            : 'Se quitó la elección: el ERP volverá a usar la primera línea activa.');
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

    /**
     * POST /api/webhooks/probe — ¿esa dirección acepta de verdad un evento?
     *
     * Se prueba ANTES de guardar porque el fallo típico no se ve al escribir:
     * la URL parece bien, el webhook se guarda, sale "activo" en verde, y sólo
     * meses después alguien descubre que todas las entregas devolvían 405
     * porque apuntaba a una página web. Mandar un evento de prueba en el
     * momento convierte eso en una frase antes de guardar nada.
     *
     * Va firmado igual que una entrega real, así que además sirve para que el
     * receptor compruebe su verificación de firma con algo inofensivo.
     */
    public function probe(Request $request)
    {
        $data = $request->validate(['url' => 'required|url|max:2048']);

        $payload = json_encode([
            'event' => 'webhook.probe',
            'data' => ['mensaje' => 'Prueba de conexión desde tu plataforma de WhatsApp.'],
            'sent_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Webhook-Event' => 'webhook.probe',
                // Sin webhook guardado todavía no hay secreto: la firma va con
                // uno de prueba para que el receptor vea llegar la cabecera,
                // aunque no le cuadre. La de verdad sale al guardar.
                'X-Webhook-Signature' => hash_hmac('sha256', $payload, 'probe'),
            ])->timeout(12)->withBody($payload, 'application/json')->post($data['url']);

            $code = $response->status();

            return response()->json([
                'ok' => $response->successful(),
                'status_code' => $code,
                'says' => $response->successful()
                    ? 'Tu servidor recibió el evento de prueba (HTTP ' . $code . ').'
                    : 'Tu servidor respondió HTTP ' . $code . ' y no aceptó el evento.',
                'fix' => $response->successful() ? null : WebhookEndpoint::hintForStatus($code),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'status_code' => null,
                'says' => 'No se pudo contactar esa dirección.',
                'fix' => WebhookEndpoint::hintForStatus(null),
            ]);
        }
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
