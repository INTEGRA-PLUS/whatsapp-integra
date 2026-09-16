<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Services\WhatsAppChatAiClient;
use App\Support\AiAssistantProfile;
use App\Support\AiPrompt;
use App\Support\TraspasoAUnAsesor;
use App\Support\DefaultAiMenusIntegration;
use App\Support\PlanDeLaEmpresa;
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
 *
 * Delante de ese freno hay ahora un candado distinto: el del plan. Son dos
 * preguntas que parecen una —«¿lo pagó?» y «¿está seguro?»— y juntarlas haría
 * que el día que una empresa contrate el complemento, la IA se encendiera sola
 * sin que nadie del equipo se entere. Por eso el secreto correcto NO se salta
 * el plan: `exigirPlanConIa()` va primero también en `unlock()`.
 */
class AiFlowSettingsController extends Controller
{
    /** GET /api/settings/ai-flow */
    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    /**
     * Corta si la empresa no tiene contratado el complemento de IA.
     *
     * 402 y no 403, igual que en `ExtensionController::install()`: no es un
     * problema de permisos sino de plan, y el frontend tiene que poder
     * distinguirlos para decir «contrátalo» en vez de «no tienes acceso», que
     * manda al admin a pelearse con sus roles para nada.
     *
     * Se pregunta por `PlanDeLaEmpresa::tieneIa()` y no por el nombre del plan:
     * el catálogo se está partiendo en un plan de CRM más un complemento de IA
     * aparte, y esa llamada va a seguir respondiendo lo mismo.
     */
    private function exigirPlanConIa(Company $company): void
    {
        if (! PlanDeLaEmpresa::de($company)->tieneIa()) {
            abort(402, 'El complemento de IA no está incluido en tu plan.');
        }
    }

    /**
     * Y para encender uno de los dos flujos caros, el complemento que los trae.
     *
     * `tieneIa()` no basta aquí: el complemento Esencial da el semáforo y el
     * resumen, que cuestan céntimos, pero no el chat ni los menús con IA — una
     * conversación de chat cuesta trece veces un análisis de semáforo. Sin esta
     * comprobación, quien contrata el barato enciende el caro desde esta misma
     * pantalla y la diferencia no la ve nadie hasta la factura del modelo.
     */
    private function exigirFlujo(Company $company, string $flujo, string $queEs): void
    {
        if (! PlanDeLaEmpresa::de($company)->permiteFlujoIa($flujo)) {
            abort(402, "{$queEs} no está incluido en tu complemento de IA.");
        }
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

        // Antes de mirar el secreto: tener el secreto no es haberlo contratado.
        $this->exigirPlanConIa($user->company);

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

        $this->exigirPlanConIa($company);

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
            // El perfil del asistente. Se valida aquí para que el admin vea el
            // error en su formulario; AiAssistantProfile lo vuelve a sanear al
            // guardar y al enviarlo, porque estas filas también se escriben
            // desde tinker y el texto acaba dentro de un prompt.
            'assistant' => 'sometimes|array',
            'assistant.nombre_asistente' => 'sometimes|nullable|string|max:' . AiAssistantProfile::MAX_NAME,
            'assistant.tratamiento' => ['sometimes', Rule::in(AiAssistantProfile::TREATMENTS)],
            'assistant.longitud' => ['sometimes', Rule::in(AiAssistantProfile::LONGITUDES)],
            'assistant.leer_documentos' => 'sometimes|boolean',
            'assistant.tono' => 'sometimes|nullable|string|max:' . AiAssistantProfile::MAX_TONE,
            'assistant.conocimiento' => 'sometimes|nullable|string|max:' . AiAssistantProfile::MAX_KNOWLEDGE,
            'assistant.limites' => 'sometimes|array|max:' . AiAssistantProfile::MAX_LIMITS,
            'assistant.limites.*' => 'string|max:' . AiAssistantProfile::MAX_LIMIT,
            // El prompt entrenable. El tope se valida aquí para que el admin vea
            // "te pasaste de largo" en su formulario en vez de que AiPrompt le
            // recorte el texto por detrás sin decir nada.
            'assistant.instrucciones' => 'sometimes|nullable|string|max:' . AiPrompt::MAX_INSTRUCTIONS,

            // A dónde va el chat cuando la IA se rinde. Los ids se comprueban
            // contra la empresa aquí para que el admin vea el error en su
            // formulario, y `TraspasoAUnAsesor` los vuelve a mirar al repartir:
            // un asesor puede darse de baja entre que se guarda y que llega el
            // siguiente chat.
            'traspaso' => 'sometimes|array',
            'traspaso.estrategia' => ['sometimes', Rule::in(TraspasoAUnAsesor::ESTRATEGIAS)],
            'traspaso.usuario_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $company->id)],
            'traspaso.equipo' => 'sometimes|array|max:' . TraspasoAUnAsesor::MAX_EQUIPO,
            'traspaso.equipo.*' => ['integer',
                Rule::exists('users', 'id')->where('company_id', $company->id)],
        ], [
            'traspaso.usuario_id.exists' => 'Ese asesor no es de tu empresa.',
            'traspaso.equipo.*.exists' => 'Uno de los asesores del equipo no es de tu empresa.',
        ]);

        // Encender, sólo lo contratado. Apagar no se comprueba: a quien se le
        // acabe el complemento hay que dejarle apagar lo que dejó encendido.
        if ($data['menus_enabled'] ?? false) {
            $this->exigirFlujo($company, 'ai_menus', 'La IA en los menús');
        }

        if ($data['chat_enabled'] ?? false) {
            $this->exigirFlujo($company, 'ai_chat', 'La IA en los chats');
        }

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

        // Se fusiona con lo que ya había en vez de reemplazarlo: el panel manda
        // sólo el campo que el admin acaba de tocar, y guardar el patch a secas
        // le borraría el conocimiento por cambiar el tratamiento.
        if (array_key_exists('assistant', $data)) {
            AiAssistantProfile::save(
                $company->id,
                array_replace(AiAssistantProfile::settings($company->id), $data['assistant'])
            );
        }

        if (isset($data['traspaso'])) {
            TraspasoAUnAsesor::guardar($company, $data['traspaso']);
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

        $plan = PlanDeLaEmpresa::de($company);

        return [
            'unlocked' => $company->aiFlowUnlocked(),
            'unlocked_at' => $company->ai_flow_unlocked_at,
            // Qué trae el complemento contratado. La pantalla lo usa para
            // desactivar el interruptor que no se ha pagado y decir por qué, en
            // vez de dejar que lo pulse y se coma un 402.
            'complemento' => [
                'nombre' => $plan->nombreIa(),
                'chat' => $plan->permiteFlujoIa('ai_chat'),
                'menus' => $plan->permiteFlujoIa('ai_menus'),
            ],
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
            'assistant' => AiAssistantProfile::settings($company->id) + [
                'empresa' => (string) ($company->name ?? ''),
                // Cómo va a presentarse con lo que hay guardado, para que el
                // admin no tenga que escribirle a su propio WhatsApp para
                // saberlo.
                'presentacion' => AiAssistantProfile::presentation($company->id),
                'treatments' => AiAssistantProfile::TREATMENTS,
                'longitudes' => AiAssistantProfile::LONGITUDES,
                'limits' => [
                    'nombre_asistente' => AiAssistantProfile::MAX_NAME,
                    'tono' => AiAssistantProfile::MAX_TONE,
                    'conocimiento' => AiAssistantProfile::MAX_KNOWLEDGE,
                    'limites' => AiAssistantProfile::MAX_LIMITS,
                    'limite' => AiAssistantProfile::MAX_LIMIT,
                    'instrucciones' => AiPrompt::MAX_INSTRUCTIONS,
                ],
            ],
            // A dónde va el chat cuando la IA se rinde o el cliente pide un
            // humano. Antes esto no se elegía: iba siempre al menos cargado.
            'traspaso' => TraspasoAUnAsesor::de($company) + [
                'estrategias' => TraspasoAUnAsesor::ESTRATEGIAS,
                'max_equipo' => TraspasoAUnAsesor::MAX_EQUIPO,
                // Quién puede recibir un chat: los mismos que usa el reparto,
                // no todos los usuarios. Enseñar a alguien que el reparto
                // nunca va a elegir es prometer algo que no pasa.
                'asesores' => $this->asesores($company),
            ],

            // Los dos prompts, para que el admin vea a qué le está sumando.
            //
            // El base baja entero y de sólo lectura: es de la plataforma y lo
            // comparten todas las empresas. Enseñarlo no es un adorno —es lo que
            // evita que un admin escriba en su campo cinco reglas que el base ya
            // trae, o una que lo contradice y que nunca va a ganar.
            'prompt' => [
                'base' => AiPrompt::BASE,
                'reglas' => AiPrompt::RULES,
                // Con `puede_ejecutar` en false: es el de chats, el que menos
                // promete. Enseñar el de menús haría creer que el asistente
                // siempre tiene herramientas, y en los chats no las tiene.
                'compuesto' => AiPrompt::compose($company->id, false),
            ],
        ];
    }

    /**
     * Los que de verdad pueden recibir un chat.
     *
     * Mismo criterio que `AgentAssignmentService`: activos, de la empresa y con
     * rol de atención. Listar a todos los usuarios dejaría elegir de destino a
     * alguien que el reparto nunca va a escoger, y el admin no entendería por
     * qué sus chats siguen yendo a otro.
     *
     * @return list<array{id: int, name: string}>
     */
    private function asesores(Company $company): array
    {
        setPermissionsTeamId($company->id);

        return User::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $u) => $u->hasRole(['admin', 'agent']))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /** La fila de la IA de menús, creándola si faltara. */
    private function menusRow($company): CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $company->id)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->first() ?? DefaultAiMenusIntegration::createFor($company);
    }
}
