<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWebhook;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WebhookEndpoint;
use App\Services\IntegraClient;
use App\Services\MetaWhatsAppService;
use App\Support\IntegrationProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class WebhookEndpointController extends Controller
{
    /**
     * Las plantillas que Meta pone sola en cada WABA nueva.
     *
     * `hello_world` aparece aprobada en la línea de siempre y **no** en una
     * WABA recién creada, así que la comparación la contaba como «te falta» y
     * bloqueaba el cambio de línea para siempre: no se puede copiar —Meta la
     * provisiona por su cuenta y sólo en algunas cuentas— y nadie la envía.
     * Descubierto el 10-sep-2026 esperando a que Transinternet pudiera mudarse.
     */
    private const PLANTILLAS_DE_MUESTRA = ['hello_world'];

    public function index()
    {
        return Inertia::render('Integrations/Index', [
            'webhooks' => $this->companyWebhooks()->get(),
            'eventCatalog' => config('webhooks.events', []),
            // El catálogo de proveedores conectables. Viaja desde el backend
            // para que añadir uno nuevo no exija tocar también el frontend.
            'providers' => IntegrationProvider::forDisplay(),
            'lineasDelErp' => $this->lineasDelErp(),
            'lineaElegida' => auth()->user()->company->tieneLineaDelErpElegida(),
            'credencialApagada' => $this->credencialApagada(),
        ]);
    }

    /**
     * La última línea por la que entró el ERP, si hoy está apagada.
     *
     * Elegir la línea aquí vale «aunque el software siga entrando con la
     * credencial de siempre»… mientras esa credencial sea de una instancia
     * activa. El API sólo acepta activas, así que si se apaga la instancia con
     * la que el ERP se autentica, cada factura recibe un 401 y el panel seguía
     * prometiendo que no había que tocar nada del otro lado.
     *
     * Pasó con Nac Technology el 23-sep-2026: al reconectar el número, Meta le
     * dio un `phone_number_id` y un WABA nuevos, la instancia vieja quedó
     * apagada doce segundos después de su último uso, y el ERP seguía
     * configurado con el identificador viejo. La lista de líneas sólo enseña
     * las activas, así que la credencial del ERP no aparecía en ninguna parte.
     *
     * @return array<string, mixed>|null
     */
    private function credencialApagada(): ?array
    {
        $ultima = Instance::where('company_id', auth()->user()->company_id)
            ->whereNotNull('api_last_seen_at')
            ->orderByDesc('api_last_seen_at')
            ->first(['id', 'name', 'active', 'phone_number_id', 'display_phone_number', 'api_last_seen_at', 'api_last_seen_via', 'updated_at']);

        if (! $ultima || $ultima->active) {
            return null;
        }

        return [
            'nombre' => $ultima->name,
            'numero' => $ultima->display_phone_number,
            'phone_number_id' => $ultima->phone_number_id,
            'ultima_vez' => $ultima->api_last_seen_at->toIso8601String(),
            'credencial' => $ultima->api_last_seen_via,
            // Aproximado: `updated_at` es el último cambio de la instancia, y
            // apagarla suele ser el último. Basta para saber desde cuándo.
            'apagada_desde' => $ultima->updated_at?->toIso8601String(),
        ];
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

        if (! $res['ok']) {
            return response()->json(['conectado' => true, 'error' => $this->avisoDeAjustes($res)]);
        }

        return response()->json(['conectado' => true] + $this->contrastadoConLaLinea($res['datos']));
    }

    public function guardarAjustesDeEnvio(Request $request)
    {
        $validado = $request->validate([
            'envio_automatico_facturas' => 'nullable|boolean',
            'envio_automatico_recibos' => 'nullable|boolean',
            'plantilla_factura_id' => 'nullable|integer',
            'plantilla_tirilla_id' => 'nullable|integer',
            'plantilla_contrato_id' => 'nullable|integer',
            // Elegir una plantilla aprobada en Meta que Integra aún no conoce:
            // se registra allí primero y se elige con el id que devuelva.
            'registrar' => 'nullable|array',
            'registrar.uso' => 'required_with:registrar|in:factura,tirilla,contrato',
            'registrar.nombre' => 'required_with:registrar|string|max:512',
            'registrar.idioma' => 'required_with:registrar|string|max:20',
        ]);

        $cliente = $this->clienteDeIntegra();

        if (! $cliente) {
            return response()->json(['message' => 'Integra no está conectado.'], 422);
        }

        $registrada = null;

        if (! empty($validado['registrar'])) {
            $pedida = $validado['registrar'];
            unset($validado['registrar']);

            // El contenido se toma de Meta, no del navegador: lo que se registra
            // en Integra tiene que ser exactamente lo que Meta aprobó, o el
            // número de variables no cuadra y Meta rechaza cada envío.
            $linea = auth()->user()->company->instanciaDelErp();
            $catalogo = $linea ? $this->catalogoAprobadoDe($linea) : null;

            if ($catalogo === null) {
                return response()->json(['message' => 'No se pudo leer el catálogo de Meta de la línea. Inténtalo en un minuto.'], 502);
            }

            $plantilla = collect($catalogo)->first(fn ($p) => ($p['name'] ?? '') === $pedida['nombre']
                && ($p['language'] ?? '') === $pedida['idioma']);

            if (! $plantilla) {
                return response()->json(['message' => "{$pedida['nombre']} ({$pedida['idioma']}) no está aprobada en la línea por la que envía Integra."], 422);
            }

            $alta = $cliente->registrarPlantilla($this->paraRegistrar($plantilla));

            if (! $alta['ok']) {
                return response()->json(['message' => $this->avisoDeAjustes($alta)], 422);
            }

            $registrada = (int) $alta['datos']['id'];
            $validado["plantilla_{$pedida['uso']}_id"] = $registrada;
        }

        $res = $cliente->guardarAjustesDeEnvio($validado);

        if (! $res['ok']) {
            return response()->json(['message' => $this->avisoDeAjustes($res)], 422);
        }

        // Guardar no devuelve la lista de disponibles; tras registrar una
        // plantilla nueva se vuelve a leer para que aparezca en el desplegable.
        $datos = $registrada ? (($cliente->ajustesDeEnvio()['datos'] ?? null) ?: $res['datos']) : $res['datos'];

        return response()->json(['ok' => true, 'registrada' => $registrada] + $this->contrastadoConLaLinea($datos));
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

        $res = app(MetaWhatsAppService::class)
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
            'nombre_visible' => $linea->meta['verified_name'] ?? $linea->name,
            'estado' => $plantilla['status'] ?? null,
            'texto' => $texto,
            'huecos' => empty($coincidencias[1]) ? 0 : max(array_map('intval', $coincidencias[1])),
            // Los componentes enteros, no sólo el cuerpo: el encabezado, el pie
            // y los botones son parte de lo que ve el cliente, y la vista
            // previa del CRM ya sabe pintarlos.
            'componentes' => array_values($plantilla['components'] ?? []),
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
    private function clienteDeIntegra(): ?IntegraClient
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
    private function plantillasQueFaltan(Company $company, int $nuevaId): array
    {
        $actual = $company->instanciaDelErp();
        $nueva = Instance::find($nuevaId);

        if (! $actual || ! $nueva || $actual->id === $nueva->id || $actual->waba_id === $nueva->waba_id) {
            return [];
        }

        $enActual = $this->aprobadasDe($actual);
        $enNueva = $this->aprobadasDe($nueva);

        if ($enActual === null || $enNueva === null) {
            return [];
        }

        $comoTexto = fn (array $lista) => array_map(fn ($p) => $p['nombre'].' ('.$p['idioma'].')', $lista);

        return array_values(array_diff($comoTexto($enActual), $comoTexto($enNueva)));
    }

    /**
     * Las plantillas que Meta tiene aprobadas en una línea.
     *
     * Devuelve `null` —y no una lista vacía— cuando no se pudo leer el
     * catálogo: quien llama tiene que poder distinguir «esta línea no tiene
     * ninguna» de «no sé qué tiene», porque de lo segundo no se deduce nada.
     *
     * @return list<array{nombre: string, idioma: string}>|null
     */
    private function aprobadasDe(Instance $linea): ?array
    {
        $catalogo = $this->catalogoAprobadoDe($linea);

        if ($catalogo === null) {
            return null;
        }

        return array_map(fn ($p) => ['nombre' => $p['name'] ?? '', 'idioma' => $p['language'] ?? ''], $catalogo);
    }

    /**
     * Las plantillas aprobadas de una línea, tal cual las devuelve Meta.
     *
     * @return list<array<string, mixed>>|null
     */
    private function catalogoAprobadoDe(Instance $linea): ?array
    {
        if (empty($linea->waba_id) || empty($linea->access_token)) {
            return null;
        }

        $res = app(MetaWhatsAppService::class)
            ->listTemplates($linea->waba_id, $linea->access_token, ['limit' => 200]);

        if (! ($res['success'] ?? false)) {
            return null;
        }

        return collect($res['data']['data'] ?? [])
            ->where('status', 'APPROVED')
            ->reject(fn ($p) => in_array($p['name'] ?? '', self::PLANTILLAS_DE_MUESTRA, true))
            ->values()
            ->all();
    }

    /**
     * Lo que Integra necesita para dar de alta una plantilla aprobada en Meta.
     *
     * @param  array<string, mixed>  $plantilla
     * @return array{nombre: string, idioma: string, categoria: string, con_documento: bool, encabezado: ?string, texto: string}
     */
    private function paraRegistrar(array $plantilla): array
    {
        $componentes = collect($plantilla['components'] ?? []);
        $encabezado = $componentes->first(fn ($c) => strtoupper($c['type'] ?? '') === 'HEADER');
        $formato = $encabezado ? strtoupper($encabezado['format'] ?? 'TEXT') : null;

        return [
            'nombre' => $plantilla['name'] ?? '',
            'idioma' => $plantilla['language'] ?? '',
            'categoria' => $plantilla['category'] ?? 'UTILITY',
            'con_documento' => $formato === 'DOCUMENT',
            'encabezado' => $formato,
            'texto' => $componentes->first(fn ($c) => strtoupper($c['type'] ?? '') === 'BODY')['text'] ?? '',
        ];
    }

    /**
     * Los ajustes del ERP, diciendo además cuáles de esas plantillas existen
     * de verdad en la línea por la que se envía.
     *
     * Las plantillas de los envíos automáticos se eligen en Integra, pero quien
     * las tiene aprobadas es el número de Meta, y **los catálogos son por
     * WABA**: la misma lista de Integra vale en una línea y no vale en la de al
     * lado. Al cambiar de línea la lista no cambia —sigue siendo la de
     * Integra—, lo que cambia es si lo elegido sigue existiendo, y eso no se
     * veía en ninguna parte: se descubría cuando la factura no salía.
     *
     * Si el catálogo no se puede leer no se marca nada. No saber no es saber
     * que falta, y pintar de rojo una plantilla que sí está sería peor que no
     * decir nada.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function contrastadoConLaLinea(array $datos): array
    {
        $linea = auth()->user()->company->instanciaDelErp();

        if (! $linea) {
            return $datos;
        }

        $datos['linea'] = [
            'id' => $linea->id,
            'nombre' => $linea->meta['verified_name'] ?? $linea->name,
            'numero' => $linea->display_phone_number,
        ];

        $catalogo = $this->catalogoAprobadoDe($linea);

        if ($catalogo === null || ! isset($datos['disponibles'])) {
            return $datos;
        }

        $aprobadas = array_map(fn ($p) => ['nombre' => $p['name'] ?? '', 'idioma' => $p['language'] ?? ''], $catalogo);

        $datos['disponibles'] = array_map(function ($plantilla) use ($aprobadas) {
            $nombre = $plantilla['title'] ?? '';
            $idioma = $plantilla['language'] ?? '';

            // Sin idioma en Integra se compara sólo por nombre: es como lo
            // resuelve `plantillaEnMeta()` y como lo acaba mandando el cron.
            $plantilla['en_la_linea'] = collect($aprobadas)->contains(
                fn ($a) => $a['nombre'] === $nombre && ($a['idioma'] === $idioma || $idioma === '')
            );

            return $plantilla;
        }, $datos['disponibles']);

        // Y al revés: lo que la línea tiene aprobado e Integra no conoce. El
        // desplegable sólo ofrecía lo registrado en Integra, así que una
        // plantilla aprobada en Meta con otro nombre —`facturacion` donde
        // Integra tenía `facturas`— no se podía elegir desde ninguna parte, y
        // la pantalla de Plantillas la enseñaba aprobada. Nac Technology,
        // 24-sep-2026. Se ofrecen aquí y se registran en Integra al elegirlas.
        $enIntegra = collect($datos['disponibles'])
            ->map(fn ($p) => ($p['title'] ?? '').'|'.($p['language'] ?? ''))
            ->all();

        $datos['solo_en_meta'] = collect($catalogo)
            ->reject(fn ($p) => in_array(($p['name'] ?? '').'|'.($p['language'] ?? ''), $enIntegra, true))
            ->map(fn ($p) => $this->paraRegistrar($p))
            ->sortBy('nombre')
            ->values()
            ->all();

        return $datos;
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
    /**
     * Cuántos días de silencio hacen sospechar de la línea elegida.
     *
     * Dos y no uno: hay empresas que facturan por ciclos y pasan un día entero
     * sin enviar nada. Avisar el primer día sería un aviso que se aprende a
     * ignorar.
     */
    private const DIAS_PARA_SOSPECHAR = 2;

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
                // Elegida pero muda. Es el síntoma de que el ERP sigue enviando
                // por otra: la elección se guardó aquí y allí no cambió nada.
                // Pasó con Transinternet —nueve días y 552 facturas por la línea
                // que no era— y no había forma de verlo si no se miraba la fecha
                // de al lado y se comparaba a ojo.
                'elegida_sin_usar' => $company->tieneLineaDelErpElegida()
                    && $elegida !== null
                    && $instancia->id === $elegida->id
                    && ($instancia->api_last_seen_at === null
                        || $instancia->api_last_seen_at->lt(now()->subDays(self::DIAS_PARA_SOSPECHAR))),
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
                return $this->rechazarLinea($request, 'Esa línea no es de tu empresa o no está activa.');
            }
        }

        // Una línea sin las plantillas de la que envía hoy no puede facturar:
        // los catálogos de Meta son por WABA. El 10-sep-2026 cambiar de línea
        // sin comprobarlo dejó a Transinternet una noche entera con Meta
        // devolviendo «(#100) Invalid parameter» en cada factura, y nadie se
        // enteró hasta que el cliente lo dijo por WhatsApp a las 6 de la mañana.
        if ($instanceId !== null && ($faltan = $this->plantillasQueFaltan($company, $instanceId)) !== []) {
            return $this->rechazarLinea(
                $request,
                'Esa línea todavía no tiene aprobadas estas plantillas: '
                    .implode(', ', array_slice($faltan, 0, 5))
                    .(count($faltan) > 5 ? ' y '.(count($faltan) - 5).' más' : '')
                    .'. Cópialas desde Plantillas y espera a que Meta las apruebe: '
                    .'si cambias ahora, las facturas dejarán de salir.',
                $faltan,
            );
        }

        $company->elegirInstanciaDelErp($instanceId);

        $aviso = $instanceId
            ? 'Listo: el ERP enviará por esa línea a partir del próximo envío.'
            : 'Se quitó la elección: el ERP volverá a usar la primera línea activa.';

        // La elección también se escribe en Integra. Guardarla sólo aquí servía
        // mientras el ERP entrara con la credencial de una línea viva; si esa
        // línea se apagaba, cada factura recibía un 401 y había que ir a pegar
        // la credencial nueva a mano (Nac Technology, 23-sep-2026).
        if ($instanceId !== null && ($sincronia = $this->sincronizarLineaEnIntegra($instanceId)) !== null) {
            $aviso .= ' '.$sincronia;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $aviso]);
        }

        return back()->with('success', $aviso);
    }

    /**
     * El «no» a un cambio de línea, dicho donde se pidió.
     *
     * Rechazarlo con `back()->withErrors()` obliga a recargar la página entera
     * para que el error llegue, y esa recarga devuelve al cliente a la galería
     * de complementos: pulsaba «Usar esta», salía de la pantalla, y tenía que
     * volver a entrar para leer por qué no había pasado nada. Pedido desde el
     * navegador con `Accept: application/json` se contesta aquí mismo, sin
     * moverse de sitio; la respuesta de Inertia se conserva para quien llegue
     * sin JavaScript y para los tests que la comprueban.
     *
     * @param  list<string>  $faltan
     */
    /**
     * Deja en Integra la línea elegida como la que envía. Devuelve la frase que
     * se suma al aviso, o null si no hay Integra conectado (no es un error: es
     * una empresa que no lo usa).
     */
    private function sincronizarLineaEnIntegra(int $instanceId): ?string
    {
        $cliente = $this->clienteDeIntegra();
        if (! $cliente) {
            return null;
        }

        $linea = Instance::where('id', $instanceId)
            ->where('company_id', auth()->user()->company_id)
            ->first(['id', 'name', 'phone_number_id', 'waba_id', 'display_phone_number']);

        $res = $cliente->usarLineaParaEnvios([
            'phone_number_id' => $linea->phone_number_id,
            'waba_id' => $linea->waba_id,
            'nombre' => $linea->name,
            'numero' => $linea->display_phone_number,
        ]);

        if ($res['ok']) {
            return 'Integra ya quedó configurado para enviar por ella.';
        }

        Log::warning('Integra: no se pudo cambiar la línea de envío', [
            'company_id' => auth()->user()->company_id,
            'instance_id' => $instanceId,
            'error' => $res['error'] ?? null,
        ]);

        return match (true) {
            $res['sin_permiso'] ?? false => 'Pero no se pudo cambiar en Integra: tu conexión es anterior a esta función. '
                .'Vuelve a conectarla en Integraciones.',
            $res['sin_endpoint'] ?? false => 'Pero tu versión de Integra todavía no permite cambiarla desde aquí: '
                .'actualízala o cambia la instancia activa allá.',
            default => 'Pero no se pudo cambiar en Integra; revisa la conexión en Integraciones.',
        };
    }

    private function rechazarLinea(Request $request, string $motivo, array $faltan = [])
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $motivo, 'faltan' => $faltan], 422);
        }

        return back()->withErrors(['instance_id' => $motivo]);
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
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => $validated['events'],
            'headers' => $validated['headers'] ?? null,
            'active' => $validated['active'] ?? true,
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
            'event' => 'webhook.test',
            'company_id' => $webhook->company_id,
            'sent_at' => now()->toIso8601String(),
            'data' => [
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
                    ? 'Tu servidor recibió el evento de prueba (HTTP '.$code.').'
                    : 'Tu servidor respondió HTTP '.$code.' y no aceptó el evento.',
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
            'name' => "$rule|string|max:100",
            'url' => "$rule|url|max:2048",
            'events' => "$rule|array|min:1",
            'events.*' => 'string|in:'.implode(',', $validEvents),
            'headers' => 'nullable|array',
            'active' => 'boolean',
        ]);
    }

    private function authorizeOwnership(WebhookEndpoint $webhook): void
    {
        if ($webhook->company_id !== auth()->user()->company_id) {
            abort(403);
        }
    }
}
