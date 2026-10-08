<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\Mcp\IntegraMcpClient;
use App\Services\Mcp\IntegraMcpPolicy;
use App\Support\McpGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * El pass-through entre el flujo de n8n y el MCP de cada empresa.
 *
 * El servidor MCP no está aquí: está dentro de la instancia de Integra de cada
 * cliente, con sus 39 herramientas. Esto es **routing multiempresa**, y existe
 * por un problema muy concreto: cada empresa tiene su dominio y su token, las
 * credenciales de n8n son estáticas, y el nodo MCP Client es un sub-nodo donde
 * lo único que admite expresión es la URL.
 *
 * La alternativa era una credencial dinámica en n8n con el token de cada
 * empresa metido en el payload. Se puede —lo admite n8n—, pero exige un **valor
 * de reserva** para poder listar herramientas en tiempo de diseño, y ese valor
 * es el token de una empresa concreta: el día que la expresión no resuelva, n8n
 * no falla, usa la reserva, y la conversación de una empresa acaba consultando
 * el Integra de otra. Aquí el mismo fallo es un 401.
 *
 * Lo que NO hace: interpretar las herramientas. Reenvía el JSON-RPC tal cual y
 * devuelve la respuesta tal cual, de modo que una herramienta nueva en Integra
 * funciona sin tocar este repositorio. Lo único que mira es el nombre en
 * `tools/call`, para cruzarlo con los permisos que concedió la empresa.
 */
class IntegraMcpProxyController extends Controller
{
    /** Llamadas por conversación antes de frenar el bucle. */
    private const MAX_CALLS = 60;

    /** Ventana del tope, en segundos. */
    private const WINDOW = 600;

    /** POST /api/mcp/integra/{grant} */
    public function handle(Request $request, string $grant): JsonResponse
    {
        $key = (string) config('services.mcp_integra.key');

        // Sin llave configurada el pass-through no existe. Tratar "no
        // configurado" como "todo vale" dejaría abierta la puerta al Integra de
        // todos los clientes justo donde nadie lo configuró.
        if ($key === '') {
            return response()->json(['error' => 'No disponible.'], 404);
        }

        if (! hash_equals($key, (string) $request->header('X-Mcp-Key'))) {
            Log::channel('whatsapp')->warning('⚠️ MCP Integra: llave de plataforma incorrecta', ['ip' => $request->ip()]);

            return response()->json(['error' => 'No autorizado.'], 401);
        }

        $permit = McpGrant::open($grant);

        if (! $permit) {
            Log::channel('whatsapp')->warning('⚠️ MCP Integra: permiso inválido o caducado', ['ip' => $request->ip()]);

            return response()->json(['error' => 'El permiso de acceso no es válido o ha caducado.'], 401);
        }

        $message = $request->json()->all();

        if (! is_array($message) || ! isset($message['jsonrpc'])) {
            return response()->json($this->rpcError(null, -32600, 'El cuerpo no es un mensaje JSON-RPC 2.0.'), 400);
        }

        // Las notificaciones (mensajes sin `id`) no llevan respuesta. Se
        // reenvían igual —el servidor espera su `notifications/initialized`—
        // pero se contesta 202 sin cuerpo, que es lo que dice el transporte.
        $id = $message['id'] ?? null;

        $client = IntegraMcpClient::for($permit->companyId);

        if (! $client) {
            // Que la empresa no tenga MCP no es un error del flujo: es una
            // empresa que aún no lo configuró. Se dice con palabras que el
            // modelo pueda repetirle al cliente.
            return $this->toolFailure($id, 'Esta empresa todavía no tiene conectado su sistema Integra, '
                .'así que no puedo consultar datos suyos. Ofrécele pasar el chat a un asesor.');
        }

        if (($message['method'] ?? null) === 'tools/call') {
            if ($denial = $this->refuse($permit, $client, $message)) {
                return response()->json($denial);
            }
        }

        $response = $client->forward($message);

        if ($id === null) {
            return response()->json(null, 202);
        }

        if ($response['body'] === null) {
            return $this->toolFailure($id, 'Ahora mismo no puedo consultar el sistema de la empresa. '
                .'Dile al cliente que no has podido comprobarlo —no que no exista— y ofrécele un asesor.');
        }

        // La respuesta del servidor real se devuelve tal cual, con su status:
        // es su contrato con n8n, y reescribirlo aquí sería envejecer con el
        // que peor se actualice de los dos.
        return response()->json($response['body'], $response['status']);
    }

    /**
     * ¿Hay que negar esta llamada antes de que salga de aquí?
     *
     * Devuelve la respuesta JSON-RPC ya armada, o null para dejarla pasar.
     */
    private function refuse(McpGrant $permit, IntegraMcpClient $client, array $message): ?array
    {
        $id = $message['id'] ?? null;
        $tool = (string) data_get($message, 'params.name', '');

        // El freno al bucle. Al otro lado está el ERP de producción de un
        // cliente, y alguna herramienta (`contratos_diagnosticar`) llega hasta
        // un router: sin tope, una sola conversación puede tumbárselo.
        if (RateLimiter::tooManyAttempts($permit->throttleKey(), self::MAX_CALLS)) {
            Log::channel('whatsapp')->warning('⚠️ MCP Integra: tope de llamadas alcanzado', [
                'company_id' => $permit->companyId,
                'conversation_id' => $permit->conversationId,
                'herramienta' => mb_substr($tool, 0, 60),
            ]);

            return $this->toolError($id, 'Ya consulté demasiadas veces en esta conversación. '
                .'Resume lo que sepas y ofrécele un asesor al cliente.');
        }

        RateLimiter::hit($permit->throttleKey(), self::WINDOW);

        $catalog = $client->tools();

        if ($motivo = IntegraMcpPolicy::deny($permit, $tool, $catalog)) {
            Log::channel('whatsapp')->info('🚫 MCP Integra: herramienta no autorizada para la empresa', [
                'company_id' => $permit->companyId,
                'herramienta' => mb_substr($tool, 0, 60),
            ]);

            return $this->toolError($id, $motivo);
        }

        // Aviso, no bloqueo: el que decide es el servidor. Hoy los 43 tokens
        // emitidos son de perfil `lectura`, así que toda escritura va a fallar
        // allí; adelantarlo evita que el modelo le prometa al cliente un
        // radicado que nadie va a abrir y que el síntoma parezca un bug.
        if (IntegraMcpPolicy::writes($tool, $catalog) && ! $this->profileWrites($permit->companyId)) {
            return $this->toolError($id, 'El token de esta empresa es de sólo lectura, así que esta acción no se '
                .'puede ejecutar. Dile al cliente que lo registras con un asesor y deriva el chat.');
        }

        return null;
    }

    /** ¿El perfil del token guardado permite escribir? */
    private function profileWrites(int $companyId): bool
    {
        $settings = CompanyIntegration::where('company_id', $companyId)
            ->where('key', CompanyIntegration::KEY_MCP_INTEGRA)
            ->value('settings');

        return IntegraMcpPolicy::profileWrites(data_get($settings, 'perfil'));
    }

    /**
     * Un fallo que el modelo debe poder leer y contarle al cliente.
     *
     * Va como **resultado** con `isError`, no como error JSON-RPC: un error de
     * protocolo corta el turno del modelo, y aquí queremos lo contrario —que
     * sepa decir "no he podido comprobarlo" en vez de callarse o, peor,
     * concluir que el dato no existe.
     */
    private function toolError(mixed $id, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [['type' => 'text', 'text' => $message]],
                'isError' => true,
            ],
        ];
    }

    private function toolFailure(mixed $id, string $message): JsonResponse
    {
        return response()->json($this->toolError($id, $message));
    }

    private function rpcError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
