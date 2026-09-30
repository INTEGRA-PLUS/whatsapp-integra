<?php

namespace App\Services\Mcp;

use App\Models\CompanyIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del "asistente de Integra": el servidor MCP que ya corre dentro de la
 * instancia de cada cliente.
 *
 * Nosotros NO implementamos herramientas. Allí hay 39 —36 de consulta y 3 de
 * escritura— y este cliente sólo sabe empujar un mensaje JSON-RPC y devolver lo
 * que conteste. Todo lo que este archivo no sepa del protocolo es, a propósito,
 * cosa del servidor: así un método nuevo o una herramienta nueva funcionan sin
 * tocar este repositorio.
 *
 *   URL:  {dominio del cliente}/software/mcp
 *   Auth: Authorization: Bearer itg_…  (un token por empresa y por perfil)
 *
 * El token vive cifrado en `company_integrations` y no sale de Laravel. Ésa es
 * la razón de ser del pass-through: la alternativa era mandárselo a n8n en cada
 * mensaje y que quedara guardado en cada registro de ejecución.
 */
class IntegraMcpClient
{
    /**
     * Versión del protocolo con la que nos presentamos al sondear.
     *
     * Sólo la usa `probe()`. Las llamadas del flujo reenvían el `initialize`
     * que mande n8n tal cual: quien negocia la versión son ellos dos, y
     * meternos en medio sería envejecer con el que peor se actualice.
     */
    private const PROTOCOL = '2025-06-18';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    /** El cliente de una empresa, o null si no tiene el MCP configurado. */
    public static function for(int $companyId): ?self
    {
        $row = CompanyIntegration::where('company_id', $companyId)
            ->where('key', CompanyIntegration::KEY_MCP_INTEGRA)
            ->first();

        if (! $row || blank($row->base_url) || blank($row->access_token)) {
            return null;
        }

        return new self($row->base_url, $row->access_token);
    }

    /**
     * Reenvía un mensaje JSON-RPC tal cual y devuelve la respuesta tal cual.
     *
     * @return array{status: int, body: mixed}
     */
    public function forward(array $message): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($this->token)
                ->timeout((int) config('services.mcp_integra.timeout', 60))
                ->post($this->baseUrl, $message);
        } catch (\Throwable $e) {
            // Sin distinguir timeout de DNS: al modelo le da igual y al log le
            // llega el mensaje entero.
            Log::channel('whatsapp')->warning('⚠️ MCP Integra: el servidor no respondió', [
                'base_url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 504, 'body' => null];
        }

        return ['status' => $response->status(), 'body' => $response->json()];
    }

    /**
     * Qué herramientas expone este servidor, cacheado.
     *
     * Se usa para dos cosas: enseñar en el panel que la conexión sirve, y
     * clasificar las escrituras por su `readOnlyHint` (ver IntegraMcpPolicy).
     * Va cacheado porque lo segundo ocurre en cada `tools/call` y no puede
     * costar un viaje extra al ERP del cliente cada vez.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(bool $fresh = false): array
    {
        $key = 'mcp-integra:tools:'.sha1($this->baseUrl.'|'.$this->token);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(10), function () {
            $this->forward([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'whatsapp-integra-crm', 'version' => '1.0.0'],
                ],
            ]);

            $res = $this->forward(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

            $tools = data_get($res['body'], 'result.tools');

            return is_array($tools) ? $tools : [];
        });
    }

    /**
     * Prueba la credencial contra el servidor real.
     *
     * Lo que se comprueba es que el token abre y que hay herramientas al otro
     * lado. No se valida el perfil: el servidor no lo dice, y el único modo
     * honesto de saber si un token escribe sería intentar una escritura, que es
     * justo lo que no se puede hacer para probar una conexión.
     *
     * @return array{ok: bool, tools: int, server: ?string, version: ?string, message: ?string}
     */
    public function probe(): array
    {
        $res = $this->forward([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'whatsapp-integra-crm', 'version' => '1.0.0'],
            ],
        ]);

        if ($res['status'] === 401 || $res['status'] === 403) {
            return $this->failed('El servidor rechazó el token. Comprueba que lo pegaste completo y que no se ha revocado.');
        }

        if ($res['status'] === 404) {
            return $this->failed('En esa URL no hay un servidor MCP. Suele ser el dominio de tu Integra más /software/mcp.');
        }

        if ($res['status'] >= 400 || $res['body'] === null) {
            return $this->failed($res['status'] === 504
                ? 'No se pudo contactar con el servidor. Revisa la URL y que el dominio sea accesible desde aquí.'
                : 'El servidor respondió con un error ('.$res['status'].').');
        }

        if (data_get($res['body'], 'error')) {
            return $this->failed((string) data_get($res['body'], 'error.message', 'El servidor rechazó la conexión.'));
        }

        $tools = $this->tools(fresh: true);

        // Cero herramientas con el token aceptado es una conexión que no sirve
        // para nada, y se parece demasiado a una que funciona: se dice.
        if ($tools === []) {
            return $this->failed('El servidor aceptó el token pero no expone ninguna herramienta.');
        }

        return [
            'ok' => true,
            'tools' => count($tools),
            'server' => data_get($res['body'], 'result.serverInfo.name'),
            'version' => data_get($res['body'], 'result.serverInfo.version'),
            'message' => null,
        ];
    }

    /** @return array{ok: bool, tools: int, server: null, version: null, message: string} */
    private function failed(string $message): array
    {
        return ['ok' => false, 'tools' => 0, 'server' => null, 'version' => null, 'message' => $message];
    }
}
