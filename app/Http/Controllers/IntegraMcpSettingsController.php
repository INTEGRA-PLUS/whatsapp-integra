<?php

namespace App\Http\Controllers;

use App\Models\CompanyIntegration;
use App\Services\Mcp\IntegraMcpClient;
use App\Services\Mcp\IntegraMcpPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * El panel donde se pega la credencial del asistente de Integra (MCP).
 *
 * Es a mano y no un asistente de conexión como el de Integra v1, y no por
 * pereza: el token se emite con `php artisan mcp:token --perfil=…` **dentro del
 * contenedor de cada cliente** y el servidor lo enseña una sola vez —en su base
 * sólo queda el hash—. No hay ningún endpoint que podamos llamar para que nos
 * lo dé, así que alguien lo copia y lo pega aquí. Que la guía lo diga y que
 * este formulario lo pida son la misma cosa.
 *
 * El token entra, se cifra (cast `encrypted` del modelo) y no vuelve a salir:
 * ninguna respuesta de este controlador lo incluye.
 */
class IntegraMcpSettingsController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function row(): ?CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $this->companyId())
            ->where('key', CompanyIntegration::KEY_MCP_INTEGRA)
            ->first();
    }

    /** GET /api/integrations/mcp */
    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    /**
     * POST /api/integrations/mcp
     *
     * Se prueba la credencial ANTES de guardarla. Guardar primero y verificar
     * después deja a la empresa con una fila en verde y un modelo que no puede
     * consultar nada, y el síntoma —"la IA no sabe mi factura"— no apunta a
     * ningún sitio.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_url' => 'required|string|max:255',
            'token' => 'required|string|max:500',
            'perfil' => ['required', Rule::in(CompanyIntegration::MCP_PROFILES)],
        ]);

        $url = $this->normalizeUrl($data['base_url']);

        if ($url === null) {
            return response()->json([
                'ok' => false,
                'message' => 'La URL no es válida. Debe empezar por https:// e incluir el dominio de tu Integra.',
            ], 422);
        }

        $probe = (new IntegraMcpClient($url, $data['token']))->probe();

        if (! $probe['ok']) {
            return response()->json(['ok' => false, 'message' => $probe['message']], 422);
        }

        CompanyIntegration::updateOrCreate(
            ['company_id' => $this->companyId(), 'key' => CompanyIntegration::KEY_MCP_INTEGRA],
            [
                'base_url' => $url,
                'access_token' => $data['token'],
                'settings' => ['perfil' => $data['perfil']],
                'status' => 'connected',
                'connected_at' => now(),
                'last_error' => null,
                // Lo que contestó el servidor, para poder enseñar en el panel
                // con qué se conectó sin volver a preguntárselo.
                'account' => [
                    'tools' => $probe['tools'],
                    'server' => $probe['server'],
                    'version' => $probe['version'],
                ],
            ]
        );

        Log::channel('whatsapp')->info('🔌 MCP de Integra conectado', [
            'company_id' => $this->companyId(),
            'user_id' => auth()->id(),
            'perfil' => $data['perfil'],
            'herramientas' => $probe['tools'],
        ]);

        return response()->json(['ok' => true] + $this->state());
    }

    /**
     * POST /api/integrations/mcp/verify — vuelve a probar lo que hay guardado.
     *
     * El token puede revocarse desde el servidor del cliente sin que nadie nos
     * avise, y entonces la IA deja de saber cosas sin un solo error visible en
     * el panel. Esto es el botón que lo convierte en una respuesta.
     */
    public function verify(): JsonResponse
    {
        $row = $this->row();
        $client = IntegraMcpClient::for($this->companyId());

        if (! $row || ! $client) {
            return response()->json(['ok' => false, 'message' => 'Todavía no hay ninguna credencial guardada.'], 422);
        }

        $probe = $client->probe();

        $row->update([
            'status' => $probe['ok'] ? 'connected' : 'error',
            'last_error' => $probe['message'],
            'account' => $probe['ok']
                ? ['tools' => $probe['tools'], 'server' => $probe['server'], 'version' => $probe['version']]
                : $row->account,
        ]);

        return response()->json(['ok' => $probe['ok'], 'message' => $probe['message']] + $this->state());
    }

    /** DELETE /api/integrations/mcp */
    public function destroy(): JsonResponse
    {
        $this->row()?->delete();

        Log::channel('whatsapp')->info('🔌 MCP de Integra desconectado', [
            'company_id' => $this->companyId(),
            'user_id' => auth()->id(),
        ]);

        return response()->json(['ok' => true] + $this->state());
    }

    /**
     * Acepta el dominio pelado o la URL completa y devuelve la del MCP.
     *
     * La gente copia lo que tiene en la barra del navegador, que es el dominio
     * de su Integra. Exigirle que recuerde el sufijo `/software/mcp` sólo
     * produce conexiones fallidas que parecen un token malo.
     */
    private function normalizeUrl(string $input): ?string
    {
        $url = rtrim(trim($input), '/');

        if (! Str::startsWith($url, ['http://', 'https://']) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        foreach (['/software/mcp', '/mcp'] as $suffix) {
            if (Str::endsWith($url, $suffix)) {
                $url = substr($url, 0, -strlen($suffix));
                break;
            }
        }

        return rtrim($url, '/').'/software/mcp';
    }

    /** Lo que el panel necesita para pintarse. Nunca incluye el token. */
    private function state(): array
    {
        $row = $this->row();

        $profile = data_get($row?->settings, 'perfil');

        return [
            'connected' => (bool) ($row && $row->base_url && $row->status === 'connected'),
            'base_url' => $row->base_url ?? null,
            'perfil' => $profile,
            'perfiles' => CompanyIntegration::MCP_PROFILES,
            // Hoy los 43 tokens emitidos son de perfil `lectura`. La UI lo
            // avisa en vez de dejar que el admin encienda "Radicar" y descubra
            // por un cliente enfadado que no se abrió ningún radicado.
            'writes' => IntegraMcpPolicy::profileWrites($profile),
            'tools' => data_get($row?->account, 'tools'),
            'server' => data_get($row?->account, 'server'),
            'status' => $row->status ?? 'disconnected',
            'last_error' => $row->last_error ?? null,
            'connected_at' => optional($row?->connected_at)->toIso8601String(),
            // Si el puente está encendido en ESTE servidor. Sin la llave la
            // credencial se guarda y verifica bien, y aun así la IA no la usa
            // nunca (WhatsAppAiClient::mcp() devuelve null): el panel tiene que
            // decirlo, porque en verde y sin esto no hay nada que investigar.
            'plataforma' => filled(config('services.mcp_integra.key'))
                && filled(config('services.mcp_integra.public_url')),
        ];
    }
}
