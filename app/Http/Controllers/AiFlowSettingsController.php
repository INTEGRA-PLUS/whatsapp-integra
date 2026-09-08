<?php

namespace App\Http\Controllers;

use App\Models\CompanyIntegration;
use App\Services\WhatsAppChatAiClient;
use App\Support\DefaultAiMenusIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * El apartado "Flujo IA" de Configuración.
 *
 * Encender la IA pone a un modelo a hablar con clientes reales, así que no basta
 * con tener permiso en el panel: hay que escribir un secreto que sólo tiene el
 * equipo. Es un freno deliberado, no una credencial —no da acceso a nada, sólo
 * abre el apartado— y por eso se guarda en el `.env` y no en la base.
 *
 * El desbloqueo es por empresa y una sola vez: a partir de ahí el admin
 * enciende, apaga y ajusta permisos sin volver a escribirlo. Bloquear de nuevo
 * es lo que devuelve la puerta a su sitio si alguien se arrepiente.
 */
class AiFlowSettingsController extends Controller
{
    /** GET /api/settings/ai-flow */
    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    /**
     * POST /api/settings/ai-flow/unlock
     *
     * La ruta va con throttle: el secreto es corto y escribible a mano, así que
     * sin freno se puede probar a ciegas. Los intentos fallidos se registran
     * con el usuario, que es lo que permite notarlo.
     */
    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['secret' => 'required|string|max:200']);
        $expected = (string) config('services.ai_activation.secret');
        $user = $request->user();

        // Sin secreto configurado no se desbloquea nada. Tratarlo como "todo
        // vale" dejaría el apartado abierto justo en las instalaciones donde
        // nadie lo configuró.
        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'message' => 'El servidor no tiene configurado el secreto de activación. Avisa al equipo técnico.',
            ], 422);
        }

        if (! hash_equals($expected, $data['secret'])) {
            Log::channel('whatsapp')->warning('⚠️ Secreto de activación de IA incorrecto', [
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['ok' => false, 'message' => 'El secreto no es correcto.'], 422);
        }

        $company = $user->company;

        // Quién y cuándo, no un booleano: si un día la IA dice algo que no
        // debía, lo primero que se pregunta es quién la encendió.
        $company->forceFill([
            'ai_flow_unlocked_at' => now(),
            'ai_flow_unlocked_by' => $user->id,
        ])->save();

        Log::channel('whatsapp')->info('🔓 Flujo IA desbloqueado', [
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        return response()->json(['ok' => true] + $this->state());
    }

    /**
     * PUT /api/settings/ai-flow
     *
     * Los dos interruptores y los permisos. Se acepta lo que venga y se ignora
     * lo que no: el panel puede mandar sólo lo que cambió.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company->aiFlowUnlocked()) {
            return response()->json([
                'ok' => false,
                'message' => 'El flujo de IA todavía no está desbloqueado.',
            ], 403);
        }

        $data = $request->validate([
            'menus_enabled' => 'sometimes|boolean',
            'chat_enabled' => 'sometimes|boolean',
            'permissions' => 'sometimes|array',
            'permissions.*' => ['string', Rule::in(CompanyIntegration::AI_PERMISSIONS)],
        ]);

        // Encender algo que la plataforma no tiene configurado dejaría al admin
        // con un interruptor en verde y sin IA: se avisa en vez de aceptarlo.
        if (($data['menus_enabled'] ?? false) && blank(config('services.ai_menus.webhook_url'))) {
            return response()->json([
                'ok' => false,
                'message' => 'Falta configurar el flujo de menús en el servidor. Avisa al equipo técnico.',
            ], 422);
        }

        if (($data['chat_enabled'] ?? false) && ! WhatsAppChatAiClient::configured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Falta configurar el flujo de chats en el servidor. Avisa al equipo técnico.',
            ], 422);
        }

        if (array_key_exists('menus_enabled', $data) || array_key_exists('permissions', $data)) {
            $menus = $this->menusRow($company);
            $menus->update(array_filter([
                'enabled' => $data['menus_enabled'] ?? null,
                'abilities' => isset($data['permissions'])
                    ? array_values(array_intersect(CompanyIntegration::AI_PERMISSIONS, $data['permissions']))
                    : null,
            ], fn ($v) => $v !== null));
        }

        if (array_key_exists('chat_enabled', $data)) {
            CompanyIntegration::updateOrCreate(
                ['company_id' => $company->id, 'key' => CompanyIntegration::KEY_AI_CHAT],
                ['enabled' => $data['chat_enabled']]
            );
        }

        Log::channel('whatsapp')->info('⚙️ Flujo IA reconfigurado', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'cambios' => array_keys($data),
        ]);

        return response()->json(['ok' => true] + $this->state());
    }

    /**
     * DELETE /api/settings/ai-flow/unlock — vuelve a cerrar el apartado.
     *
     * Apaga las dos IA al cerrar: dejarlas encendidas detrás de una puerta
     * cerrada sería lo peor de los dos mundos —siguen hablando con clientes y
     * nadie puede apagarlas desde el panel.
     */
    public function lock(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        CompanyIntegration::where('company_id', $company->id)
            ->whereIn('key', [CompanyIntegration::KEY_AI_MENUS, CompanyIntegration::KEY_AI_CHAT])
            ->update(['enabled' => false]);

        $company->forceFill([
            'ai_flow_unlocked_at' => null,
            'ai_flow_unlocked_by' => null,
        ])->save();

        Log::channel('whatsapp')->info('🔒 Flujo IA bloqueado de nuevo', [
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['ok' => true] + $this->state());
    }

    /** Lo que el panel necesita saber para pintarse. */
    private function state(): array
    {
        $user = auth()->user();
        $company = $user->company;
        $menus = $this->menusRow($company);
        $chat = CompanyIntegration::where('company_id', $company->id)
            ->where('key', CompanyIntegration::KEY_AI_CHAT)
            ->first();

        return [
            'unlocked' => $company->aiFlowUnlocked(),
            'unlocked_at' => $company->ai_flow_unlocked_at,
            // Para que el panel pueda decir "avisa al equipo técnico" en vez de
            // dejar al admin encendiendo un interruptor que no hace nada.
            'platform' => [
                'secret_configured' => filled(config('services.ai_activation.secret')),
                'menus_configured' => filled(config('services.ai_menus.webhook_url')),
                'chat_configured' => WhatsAppChatAiClient::configured(),
            ],
            'menus' => [
                'enabled' => (bool) $menus->enabled,
                'permissions' => $menus->aiPermissions(),
                'available' => CompanyIntegration::AI_PERMISSIONS,
            ],
            'chat' => [
                'enabled' => (bool) ($chat->enabled ?? false),
            ],
        ];
    }

    /** La fila de la IA de menús, creándola si faltara. */
    private function menusRow($company): CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $company->id)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->first() ?? DefaultAiMenusIntegration::createFor($company);
    }
}
