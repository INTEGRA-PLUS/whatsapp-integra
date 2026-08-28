<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP para la API pública de Integra 2.0 (proyecto integra2.0).
 *
 * Documentación: {base}/software/api/docs (Swagger UI) y docs/openapi.yaml.
 * La API vive bajo https://{dominio}/software/api/v1 (el prefijo /software lo
 * pone Caddy en producción; en entornos locales puede no existir, por eso
 * probeBase() sondea ambas variantes al conectar).
 *
 * Autenticación: Bearer token por empresa (tenant), generado en el servidor
 * de Integra con `php artisan api:token {empresa} --abilities=...`. Scopes
 * usados por esta integración: contactos.leer, facturas.leer, pagos.leer,
 * pagos.registrar, radicados.leer, radicados.crear, contratos.leer. Un token
 * emitido sin abilities (el que sale del wizard con email+contraseña) los
 * tiene todos; uno pegado a mano puede quedarse corto y responder 403.
 *
 * Envelope de respuesta: { success, data, message?, meta? } — errores llegan
 * como { success: false, message } con HTTP 401/403/404/422/500.
 *
 * Endpoints usados:
 *   GET  /api/v1/contactos/buscar?q=&limite=          (contactos.leer)
 *        busca por nit, nombre/apellidos, celular, teléfonos y email; cada
 *        contacto trae total_por_pagar y sus facturas_pendientes resumidas
 *   GET  /api/v1/facturas/pendientes?nit=|cliente_id= (facturas.leer)
 *   GET  /api/v1/pagos/catalogos                      (pagos.leer)
 *   POST /api/v1/facturas/{factura}/pagos             (pagos.registrar)
 *        body: { cuenta, metodo_pago, monto, fecha?, observaciones?,
 *                comprobante_pago?, forma_pago? }
 *   GET  /api/v1/radicados/catalogos                  (radicados.leer)
 *   POST /api/v1/radicados                            (radicados.crear)
 *        body: { servicio, prioridad, cliente_id|identificacion, reporte?,
 *                contrato?, medio?, telefono?, ip?, mac_address? }
 *   GET  /api/v1/contratos/{nro}/estado               (contratos.leer)
 *        estado de internet/TV + facturas pendientes del contrato
 *   GET  /api/v1/contratos/{nro}/resumen              (contratos.leer)
 *        todo lo que el suscriptor puede preguntar de SU servicio, en una
 *        llamada; sin datos de infraestructura (IP, MAC, ONU, MikroTik)
 *   POST /api/v1/contratos/{nro}/prorroga             (contratos.prorroga)
 *        body: { factura_id, fecha, comentario?, identificacion? }
 */
class IntegraClient
{
    /**
     * Código de excepción para "la ruta no existe en este entorno" (deploy
     * viejo de Integra). Distinto del 404 de negocio (recurso no encontrado),
     * que conserva el código HTTP 404.
     */
    public const CODE_ENDPOINT_MISSING = 4404;

    protected string $baseUrl;
    protected ?string $token;

    public function __construct(string $baseUrl, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Resuelve la URL base real del API a partir de lo que escribió el usuario.
     * Acepta el dominio pelado, con /software, o incluso con /api/v1 pegado, y
     * sondea {base} y {base}/software hasta que el API responda con el token.
     *
     * @param ?callable $ping Verificación a ejecutar contra cada candidato (recibe el
     *                        cliente, debe lanzar RuntimeException si falla). Por
     *                        defecto pega a /pagos/catalogos (flujo de pagos); otras
     *                        integraciones (ej. contactos) pasan una verificación
     *                        propia para no exigir scopes que su token no tiene.
     * @return self cliente ya apuntando a la base que funcionó.
     * @throws \RuntimeException si ninguna variante responde (mensaje apto para UI).
     */
    public static function probeBase(string $inputUrl, string $token, ?callable $ping = null): self
    {
        $candidates = self::baseCandidates($inputUrl);
        $ping ??= fn (self $c) => $c->testConnection();

        $lastError = null;
        foreach ($candidates as $candidate) {
            $client = new self($candidate, $token);
            try {
                $ping($client);

                return $client;
            } catch (\RuntimeException $e) {
                // Token inválido o sin permisos: cambiar de prefijo no lo va a arreglar.
                if (in_array($e->getCode(), [401, 403], true)) {
                    throw $e;
                }
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('No se pudo conectar con el entorno Integra.');
    }

    /**
     * Conecta iniciando sesión con las credenciales de Integra: canjea
     * email + contraseña por un token itg_ recién emitido (POST /api/v1/tokens,
     * scopes del flujo de pagos) sondeando los mismos prefijos que probeBase.
     *
     * @return array{0: self, 1: string} cliente apuntando a la base que funcionó + token en claro.
     * @throws \RuntimeException con mensaje apto para UI (401 credenciales, 422 validación,
     *                           CODE_ENDPOINT_MISSING si el entorno no expone /tokens).
     */
    public static function connectWithLogin(string $inputUrl, string $email, string $password): array
    {
        $lastError = null;

        foreach (self::baseCandidates($inputUrl) as $candidate) {
            try {
                $res = Http::baseUrl($candidate)
                    ->acceptJson()
                    ->timeout(20)
                    ->post('/api/v1/tokens', [
                        'email'    => $email,
                        'password' => $password,
                        'nombre'   => 'wpp-integraciones',
                    ]);
            } catch (\Throwable $e) {
                Log::warning('Integra: error de red al emitir token', ['base' => $candidate, 'msg' => $e->getMessage()]);
                $lastError = new \RuntimeException('No se pudo contactar al servidor de Integra. Revisa la URL del entorno.', 0);
                continue;
            }

            $token = $res->json('data.token');
            if ($res->successful() && $res->json('success') === true && $token) {
                return [new self($candidate, $token), $token];
            }

            // Credenciales malas o validación: el envelope trae el mensaje y
            // cambiar de prefijo no lo va a arreglar.
            if (in_array($res->status(), [401, 422, 429], true) && $res->json('message')) {
                throw new \RuntimeException($res->json('message'), $res->status());
            }

            // 404 sin envelope = el entorno aún no tiene POST /api/v1/tokens.
            $lastError = $res->status() === 404
                ? new \RuntimeException('Este entorno Integra aún no permite iniciar sesión desde aquí (actualiza Integra o pega un token de API).', self::CODE_ENDPOINT_MISSING)
                : new \RuntimeException('Integra respondió con un error ('.$res->status().').', $res->status());
        }

        throw $lastError ?? new \RuntimeException('No se pudo conectar con el entorno Integra.');
    }

    /**
     * Variantes de URL base a sondear a partir de lo que escribió el usuario:
     * limpia sufijos que la gente copia de la barra del navegador y prueba
     * con y sin el prefijo /software que Caddy añade en producción.
     *
     * @return string[]
     */
    protected static function baseCandidates(string $inputUrl): array
    {
        $base = rtrim(trim($inputUrl), '/');
        foreach (['/api/v1', '/api/docs', '/api'] as $suffix) {
            if (str_ends_with($base, $suffix)) {
                $base = substr($base, 0, -strlen($suffix));
            }
        }
        $base = rtrim($base, '/');

        return str_ends_with($base, '/software')
            ? [$base]
            : [$base.'/software', $base];
    }

    protected function request(): PendingRequest
    {
        $req = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(20);

        if ($this->token) {
            $req = $req->withToken($this->token);
        }

        return $req;
    }

    /**
     * Ejecuta la petición y traduce los fallos comunes del envelope a
     * RuntimeException con mensajes aptos para la UI. El código de la
     * excepción conserva el HTTP status para que el caller pueda reaccionar.
     *
     * @throws \RuntimeException
     */
    protected function call(string $method, string $path, array $data = []): Response
    {
        try {
            $res = $method === 'post'
                ? $this->request()->post($path, $data)
                : $this->request()->get($path, $data);
        } catch (\Throwable $e) {
            Log::warning('Integra: error de red', ['path' => $path, 'msg' => $e->getMessage()]);
            throw new \RuntimeException('No se pudo contactar al servidor de Integra. Revisa la URL del entorno.', 0);
        }

        if ($res->successful()) {
            return $res;
        }

        $message = $res->json('message');

        if ($res->status() === 401) {
            throw new \RuntimeException('Integra rechazó el token de API. Verifica el token en la configuración de la integración.', 401);
        }
        if ($res->status() === 403) {
            throw new \RuntimeException($message ?? 'El token de API no tiene permisos para esta operación (revisa sus scopes).', 403);
        }
        if ($res->status() === 404) {
            // 404 de negocio (cliente/factura no encontrada) trae el envelope
            // {success:false, message}; un 404 sin envelope es ruta inexistente.
            if ($res->json('success') === false && $message) {
                throw new \RuntimeException($message, 404);
            }
            throw new \RuntimeException('El entorno Integra no expone este recurso ('.$path.'). Verifica la URL y que la API esté actualizada.', self::CODE_ENDPOINT_MISSING);
        }
        if ($res->status() === 422) {
            $errors = $res->json('errors');
            $detail = is_array($errors) ? implode(' ', array_map(fn ($v) => implode(' ', (array) $v), $errors)) : '';
            throw new \RuntimeException(trim(($message ?? 'Datos inválidos.').' '.$detail), 422);
        }

        throw new \RuntimeException($message ?? 'Integra respondió con un error ('.$res->status().').', $res->status());
    }

    /**
     * Prueba URL + token contra los catálogos de pago (endpoint liviano que
     * además valida el scope pagos.leer, necesario para el flujo del chat).
     *
     * @return array{ok: bool, cuentas: int, metodos_pago: int}
     * @throws \RuntimeException
     */
    public function testConnection(): array
    {
        $data = $this->call('get', '/api/v1/pagos/catalogos')->json('data') ?? [];

        return [
            'ok' => true,
            'cuentas' => count($data['cuentas'] ?? []),
            'metodos_pago' => count($data['metodos_pago'] ?? []),
        ];
    }

    /**
     * Búsqueda de contactos para el autocompletado del modal. El criterio `q`
     * matchea a la vez nit, nombre/apellidos, celular, teléfonos y email; cada
     * contacto llega con su total_por_pagar y facturas_pendientes resumidas.
     * "Sin coincidencias" (404 de negocio) se devuelve como lista vacía.
     *
     * @return array{data: array, total: int}
     * @throws \RuntimeException (code CODE_ENDPOINT_MISSING si el entorno aún no tiene /contactos/buscar)
     */
    public function searchContacts(string $q, int $limite = 8): array
    {
        try {
            $res = $this->call('get', '/api/v1/contactos/buscar', ['q' => $q, 'limite' => $limite]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                return ['data' => [], 'total' => 0];
            }
            throw $e;
        }

        return [
            'data' => $res->json('data') ?? [],
            'total' => (int) ($res->json('meta.total_contactos') ?? 0),
        ];
    }

    /**
     * Facturas pendientes (abiertas con saldo) de un cliente.
     *
     * @param array $params ['nit' => ...] o ['cliente_id' => ...]
     * @return array{found: bool, message: ?string, facturas: array, cliente: ?array, total_facturas: int, total_por_pagar: float}
     * @throws \RuntimeException
     */
    public function pendingInvoices(array $params): array
    {
        try {
            $res = $this->call('get', '/api/v1/facturas/pendientes', $params);
        } catch (\RuntimeException $e) {
            // 404 de negocio: cliente no encontrado en Integra.
            if ($e->getCode() === 404) {
                return [
                    'found' => false,
                    'message' => $e->getMessage(),
                    'facturas' => [],
                    'cliente' => null,
                    'total_facturas' => 0,
                    'total_por_pagar' => 0.0,
                ];
            }
            throw $e;
        }

        return [
            'found' => true,
            'message' => null,
            'facturas' => $res->json('data') ?? [],
            'cliente' => $res->json('meta.cliente'),
            'total_facturas' => (int) ($res->json('meta.total_facturas') ?? 0),
            'total_por_pagar' => (float) ($res->json('meta.total_por_pagar') ?? 0),
        ];
    }

    /**
     * Catálogos para registrar un pago: cuentas/bancos, métodos y formas de pago.
     *
     * @return array{cuentas: array, metodos_pago: array, formas_pago: array}
     * @throws \RuntimeException
     */
    public function paymentCatalogs(): array
    {
        $data = $this->call('get', '/api/v1/pagos/catalogos')->json('data') ?? [];

        return [
            'cuentas' => $data['cuentas'] ?? [],
            'metodos_pago' => $data['metodos_pago'] ?? [],
            'formas_pago' => $data['formas_pago'] ?? [],
        ];
    }

    /**
     * Listado paginado del maestro de clientes (GET /api/v1/contactos), usado
     * para la sincronización masiva de la integración "Contactos". A
     * diferencia de searchContacts() (autocompletado puntual), este barre
     * TODAS las páginas — soporta sincronización incremental vía
     * `actualizado_desde` y deliberadamente no pide `incluir` (mantiene el
     * tope de página en 100 en vez de 25; el resumen de contratos/facturas no
     * se persiste en el CRM en esta primera versión).
     *
     * @param array $params page, por_pagina, q, identificacion, estado, actualizado_desde
     * @return array{data: array, meta: array}
     * @throws \RuntimeException
     */
    public function listContacts(array $params = []): array
    {
        $res = $this->call('get', '/api/v1/contactos', array_filter(
            $params,
            fn ($v) => $v !== null && $v !== ''
        ));

        return [
            'data' => $res->json('data') ?? [],
            'meta' => $res->json('meta') ?? [],
        ];
    }

    /**
     * Registra un pago sobre una factura. Integra replica su flujo interno:
     * recibo de caja, movimiento contable, cierre de la factura si se cubre el
     * saldo y reactivación del servicio (Mikrotik/OLT) si corresponde.
     *
     * @param array $payload { cuenta, metodo_pago, monto, fecha?, observaciones?, comprobante_pago?, forma_pago? }
     * @return array { ingreso_id, recibo_caja, monto_aplicado, factura_estado, factura_por_pagar }
     * @throws \RuntimeException
     */
    public function registerPayment(int $facturaId, array $payload): array
    {
        $res = $this->call('post', "/api/v1/facturas/{$facturaId}/pagos", array_filter(
            $payload,
            fn ($v) => $v !== null && $v !== ''
        ));

        return $res->json('data') ?? [];
    }

    /**
     * Catálogos para crear un radicado: servicios (tipos de falla), técnicos,
     * prioridades y estatus del entorno Integra.
     *
     * Los usa el formulario del menú: el admin elige con qué servicio y qué
     * prioridad entra el radicado que abrirá el cliente desde WhatsApp.
     *
     * @return array{servicios: array, tecnicos: array, prioridades: array, estatus: array}
     * @throws \RuntimeException
     */
    public function radicadoCatalogs(): array
    {
        $data = $this->call('get', '/api/v1/radicados/catalogos')->json('data') ?? [];

        return [
            'servicios' => $data['servicios'] ?? [],
            'tecnicos' => $data['tecnicos'] ?? [],
            'prioridades' => $data['prioridades'] ?? [],
            'estatus' => $data['estatus'] ?? [],
        ];
    }

    /**
     * Crea un radicado de soporte. El cliente se identifica por `cliente_id` o
     * `identificacion`; nombre, teléfono y dirección se toman del contacto si
     * no se envían.
     *
     * @param array $payload { servicio, prioridad, cliente_id|identificacion, reporte?, contrato?, medio?, telefono?, ip?, mac_address? }
     * @return array { id, codigo, estatus, cliente_id, fecha }
     * @throws \RuntimeException
     */
    public function createRadicado(array $payload): array
    {
        $res = $this->call('post', '/api/v1/radicados', array_filter(
            $payload,
            fn ($v) => $v !== null && $v !== ''
        ));

        return $res->json('data') ?? [];
    }

    /**
     * Estado de internet y televisión de un contrato, con el contrato en sí y
     * sus facturas pendientes.
     *
     * Devuelve null cuando Integra no conoce el contrato (404 de negocio): que
     * el número esté mal no es un fallo de la integración, y el llamador
     * necesita distinguirlo de que la API esté caída.
     *
     * @param string $nro Número de contrato (contracts.nro), no el id.
     * @throws \RuntimeException
     */
    public function contractStatus(string $nro): ?array
    {
        try {
            $res = $this->call('get', '/api/v1/contratos/'.rawurlencode($nro).'/estado');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }

        return $res->json('data') ?? null;
    }

    /**
     * Todo lo que un suscriptor puede preguntar sobre SU servicio, en una sola
     * llamada: por qué está suspendido y cuánto cuesta reactivarlo, facturas,
     * saldo a favor, ciclo de facturación, promesa de pago, últimos pagos,
     * reportes de soporte abiertos, consumo, contrato firmado y clave WiFi.
     *
     * Es el hermano de contractStatus() pensado para leerlo el cliente final:
     * deliberadamente no trae IP, MAC, serial de la ONU ni MikroTik, así que es
     * el que debe usar el bot. contractStatus() se queda para el panel interno.
     *
     * `$identificacion` es obligatorio y no por capricho: los números de
     * contrato son secuenciales, de modo que sin comprobar la titularidad
     * cualquiera que pruebe el número siguiente leería la factura, la dirección
     * y la clave WiFi del vecino. Integra responde 404 —no 403— cuando el
     * documento no corresponde, para no confirmar siquiera que el contrato
     * existe; aquí eso llega como null, igual que un contrato inexistente, y
     * esa ambigüedad es la que protege al titular.
     *
     * @param string $nro Número de contrato (contracts.nro), no el id.
     * @param string $identificacion Cédula/NIT de quien dice ser el titular.
     * @param int $diasConsumo Días de detalle en `consumo.por_dia` (1–90).
     * @return array|null null si el contrato no existe o no es de esa persona.
     * @throws \RuntimeException
     */
    public function contractSummary(string $nro, string $identificacion, int $diasConsumo = 7): ?array
    {
        try {
            $res = $this->call('get', '/api/v1/contratos/'.rawurlencode($nro).'/resumen', [
                'identificacion' => $identificacion,
                'dias_consumo' => max(1, min(90, $diasConsumo)),
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }

        return $res->json('data') ?? null;
    }

    /**
     * Pide una prórroga (promesa de pago) sobre una factura del contrato.
     *
     * NO concede el plazo: deja una solicitud pendiente de que alguien del
     * proveedor la apruebe, en la misma bandeja que las de la app de clientes.
     * Un bot no puede regalar plazos por su cuenta, así que lo único honesto
     * que puede decirle al cliente es "queda radicada, te avisamos".
     *
     * Integra aplica sus propios topes —días de plazo, máximo de promesas al
     * año, y nada de una segunda solicitud si ya hay una sin atender— y los
     * rechaza con 422. Ese mensaje viene redactado y es el que hay que
     * mostrarle al cliente: explica por qué no se pudo, que es justo lo que
     * evita que insista.
     *
     * @param string $nro Número de contrato (no el id).
     * @param int $facturaId Factura pendiente, de resumen.facturacion.pendientes.items.
     * @param string $fecha Fecha propuesta de pago (Y-m-d), posterior a hoy.
     * @param string $identificacion Documento del titular; si no coincide, 404.
     * @return array{id?: int, tipo?: string, estado?: string, fecha_propuesta?: string, factura?: string}
     * @throws \RuntimeException 422 con el motivo listo para el cliente; 404 si
     *         el contrato no existe o no es de esa persona.
     */
    public function requestPaymentExtension(
        string $nro,
        int $facturaId,
        string $fecha,
        string $identificacion,
        ?string $comentario = null
    ): array {
        $res = $this->call('post', '/api/v1/contratos/'.rawurlencode($nro).'/prorroga', array_filter([
            'factura_id' => $facturaId,
            'fecha' => $fecha,
            'identificacion' => $identificacion,
            'comentario' => $comentario,
        ], fn ($v) => $v !== null && $v !== ''));

        return $res->json('data.solicitud') ?? [];
    }
}
