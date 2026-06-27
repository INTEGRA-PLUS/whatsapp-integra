<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;

class WhatsAppSettingsController extends Controller
{
    public function __construct(protected MetaWhatsAppService $meta) {}

    public function instances()
    {
        $user = auth()->user();

        return response()->json(
            Instance::where('company_id', $user->company_id)
                ->where('active', true)
                ->whereNotNull('waba_id')
                ->whereNotNull('access_token')
                ->orderBy('name')
                ->get(['id', 'name', 'display_phone_number', 'waba_id', 'phone_number_id'])
        );
    }

    public function readiness(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $waba = $this->meta->getWaba($instance->waba_id, $instance->access_token);
        $phone = $instance->phone_number_id
            ? $this->meta->getPhoneNumber($instance->phone_number_id, $instance->access_token)
            : ['success' => false, 'error' => 'No phone_number_id'];
        $apps = $this->meta->listSubscribedApps($instance->waba_id, $instance->access_token);

        $wabaData = $waba['success'] ? ($waba['data'] ?? []) : [];
        $phoneData = $phone['success'] ? ($phone['data'] ?? []) : [];
        $appsData = $apps['success'] ? ($apps['data']['data'] ?? []) : [];

        return response()->json([
            'instance' => [
                'id' => $instance->id,
                'name' => $instance->name,
                'display_phone_number' => $instance->display_phone_number,
                'waba_id' => $instance->waba_id,
                'phone_number_id' => $instance->phone_number_id,
            ],
            'checks' => [
                'business_verification' => $this->buildCheck(
                    'business_verification',
                    'Verificación del negocio',
                    'Tu Business Manager debe estar verificado por Meta para crear plantillas sin límites.',
                    $waba['success'],
                    $wabaData['business_verification_status'] ?? null,
                    [
                        'verified' => 'ok',
                        'not_verified' => 'manual',
                        'pending' => 'pending',
                        'pending_need_more_info' => 'pending',
                        'pending_submission' => 'pending',
                        'expired' => 'manual',
                        'failed' => 'manual',
                        'rejected' => 'manual',
                    ],
                    'https://business.facebook.com/settings/security'
                ),
                'account_review' => $this->buildCheck(
                    'account_review',
                    'Revisión de la cuenta de WhatsApp',
                    'Meta debe aprobar tu WhatsApp Business Account.',
                    $waba['success'],
                    $wabaData['account_review_status'] ?? null,
                    [
                        'APPROVED' => 'ok',
                        'PENDING' => 'pending',
                        'DECLINED' => 'manual',
                    ],
                    'https://business.facebook.com/wa/manage/home'
                ),
                'display_name' => $this->buildCheck(
                    'display_name',
                    'Nombre comercial del número',
                    'El nombre que ven tus clientes en WhatsApp debe estar aprobado.',
                    $phone['success'],
                    $phoneData['name_status'] ?? null,
                    [
                        'APPROVED' => 'ok',
                        'AVAILABLE_WITHOUT_REVIEW' => 'ok',
                        'PENDING_REVIEW' => 'pending',
                        'DECLINED' => 'manual',
                        'EXPIRED' => 'manual',
                    ],
                    'https://business.facebook.com/wa/manage/phone-numbers',
                    ['verified_name' => $phoneData['verified_name'] ?? null]
                ),
                'phone_registration' => $this->buildPhoneRegistrationCheck($phone['success'], $phoneData),
                'webhook_subscription' => $this->buildWebhookCheck($apps['success'], $appsData),
                'insights' => $this->buildInsightsCheck($waba['success'], $wabaData),
            ],
            'raw' => [
                'waba' => $waba,
                'phone' => $phone,
                'subscribed_apps' => $apps,
            ],
        ]);
    }

    public function subscribeWebhook(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $result = $this->meta->subscribeApp($instance->waba_id, $instance->access_token);
        return $this->wrap($result, 'No se pudo suscribir la app al WABA.');
    }

    public function registerNumber(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'pin' => 'nullable|string|regex:/^\d{6}$/',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        // PIN de verificación de 2 pasos. Por defecto siempre 123456 (igual que
        // el flujo de integra2.0); el cliente no necesita enviarlo.
        $pin = $data['pin'] ?? '123456';

        $result = $this->meta->registerPhoneNumber($instance->phone_number_id, $instance->access_token, $pin);
        return $this->wrap($result, 'No se pudo registrar el número en Cloud API.');
    }

    public function requestCode(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'code_method' => 'required|in:SMS,VOICE',
            'language' => 'nullable|string|max:10',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        $result = $this->meta->requestCode(
            $instance->phone_number_id,
            $instance->access_token,
            $data['code_method'],
            $data['language'] ?? 'es'
        );
        return $this->wrap($result, 'No se pudo solicitar el código de verificación.');
    }

    public function verifyCode(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'code' => 'required|string|regex:/^\d{3}-?\d{3}$/',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        // Meta acepta el código con o sin guion; normalizamos a 6 dígitos puros.
        $code = preg_replace('/\D/', '', $data['code']);

        $result = $this->meta->verifyCode($instance->phone_number_id, $instance->access_token, $code);
        return $this->wrap($result, 'No se pudo verificar el código.');
    }

    public function enableInsights(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $result = $this->meta->enableInsights($instance->waba_id, $instance->access_token);
        return $this->wrap($result, 'No se pudo activar la analítica.');
    }

    public function getProfile(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        $result = $this->meta->getBusinessProfile($instance->phone_number_id, $instance->access_token);
        if (!$result['success']) {
            return response()->json([
                'message' => 'No se pudo obtener el perfil de negocio.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        $data = $result['data']['data'][0] ?? [];

        // Nombre para mostrar (display name) y su estado de revisión. Es de solo
        // lectura por la API: cambiarlo se hace en WhatsApp Manager y luego se
        // re-registra el número. Lo leemos del nodo del número telefónico.
        $phone = $this->meta->getPhoneNumber($instance->phone_number_id, $instance->access_token);
        $phoneData = ($phone['success'] ?? false) ? ($phone['data'] ?? []) : [];

        return response()->json([
            'profile' => [
                'about' => $data['about'] ?? '',
                'address' => $data['address'] ?? '',
                'description' => $data['description'] ?? '',
                'email' => $data['email'] ?? '',
                'profile_picture_url' => $data['profile_picture_url'] ?? null,
                'websites' => $data['websites'] ?? [],
                'vertical' => $data['vertical'] ?? '',
            ],
            'name' => [
                'display_name' => $phoneData['verified_name'] ?? null,
                'display_phone_number' => $phoneData['display_phone_number'] ?? ($instance->display_phone_number ?? null),
                'name_status' => $phoneData['name_status'] ?? null,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'about' => 'nullable|string|max:139',
            'address' => 'nullable|string|max:256',
            'description' => 'nullable|string|max:512',
            'email' => 'nullable|email|max:128',
            'websites' => 'nullable|array|max:2',
            'websites.*' => 'string|max:256',
            'vertical' => 'nullable|in:UNDEFINED,OTHER,AUTO,BEAUTY,APPAREL,EDU,ENTERTAIN,EVENT_PLAN,FINANCE,GROCERY,GOVT,HOTEL,HEALTH,NONPROFIT,PROF_SERVICES,RETAIL,TRAVEL,RESTAURANT,NOT_A_BIZ',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        // Sanitize empty optional fields (Meta rejects empty strings on some fields)
        $payload = array_filter($data, fn($v, $k) => $k !== 'instance_id' && $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
        if (isset($payload['websites'])) {
            $payload['websites'] = array_values(array_filter($payload['websites'], fn($w) => !empty($w)));
            if (empty($payload['websites'])) unset($payload['websites']);
        }

        $result = $this->meta->updateBusinessProfile($instance->phone_number_id, $instance->access_token, $payload);
        return $this->wrap($result, 'No se pudo actualizar el perfil.');
    }

    public function updateProfilePhoto(Request $request)
    {
        $request->validate([
            'instance_id' => 'nullable|integer',
            'photo' => 'required|file|image|max:5120',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        $debug = $this->meta->debugToken($instance->access_token);
        if (!$debug['success'] || empty($debug['data']['app_id'])) {
            return response()->json([
                'message' => 'No se pudo identificar la app del token para subir la foto.',
                'error' => $debug['error'] ?? null,
            ], 502);
        }
        $appId = $debug['data']['app_id'];

        $file = $request->file('photo');
        $upload = $this->meta->uploadProfilePhoto(
            $appId,
            $instance->access_token,
            $file->getRealPath(),
            $file->getMimeType()
        );
        if (!$upload['success']) {
            return response()->json([
                'message' => 'No se pudo subir la imagen a Meta.',
                'stage' => $upload['stage'] ?? null,
                'error' => $upload['error'] ?? null,
            ], 502);
        }

        $update = $this->meta->updateBusinessProfile(
            $instance->phone_number_id,
            $instance->access_token,
            ['profile_picture_handle' => $upload['handle']]
        );
        return $this->wrap($update, 'No se pudo aplicar la foto al perfil.');
    }

    public function phoneNumbers(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        $result = $this->meta->listWabaPhoneNumbers($instance->waba_id, $instance->access_token);
        if (!$result['success']) {
            return response()->json([
                'message' => 'No se pudieron obtener los números de la WABA.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        return response()->json([
            'phone_numbers' => $result['data']['data'] ?? [],
            'current_phone_number_id' => $instance->phone_number_id,
        ]);
    }

    /**
     * Estado de la configuración de llamadas de la instancia: si la función está
     * habilitada en el número (necesaria para entrantes) y si el negocio activó
     * las salientes (que tienen costo por minuto).
     */
    public function callingSettings(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        return response()->json([
            'success' => true,
            'calling' => [
                'enabled' => $instance->callingEnabled(),
                'outbound_enabled' => $instance->outboundCallsEnabled(),
            ],
            // Aviso de costo para mostrar junto al toggle de salientes.
            'cost_notice' => [
                'inbound' => 'Las llamadas entrantes son gratuitas.',
                'outbound' => 'Las llamadas salientes tienen costo por minuto (facturado por Meta en bloques de 6s, solo si el cliente contesta). La tarifa varía por país.',
            ],
        ]);
    }

    /**
     * Activa la función de llamadas en el número (POST a Meta). Hace falta una
     * sola vez por número para poder recibir/realizar llamadas.
     */
    public function enableCalling(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        $result = $this->meta->enableCalling($instance->phone_number_id, $instance->access_token);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'message' => 'No se pudo habilitar las llamadas en Meta.',
                'error' => $result['error'] ?? null,
            ], 502);
        }

        $instance->setCallingSettings(['enabled' => true]);
        $instance->save();

        return response()->json([
            'success' => true,
            'calling' => [
                'enabled' => true,
                'outbound_enabled' => $instance->outboundCallsEnabled(),
            ],
        ]);
    }

    /**
     * Activa/desactiva las llamadas SALIENTES para la instancia. Se guarda en
     * `meta` y actúa como compuerta antes de iniciar cualquier llamada saliente
     * (por el costo que implican).
     */
    public function updateCallingSettings(Request $request)
    {
        $data = $request->validate([
            'instance_id' => 'nullable|integer',
            'outbound_enabled' => 'required|boolean',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        // No se pueden activar salientes si la función de llamadas no está
        // habilitada en el número.
        if ($data['outbound_enabled'] && !$instance->callingEnabled()) {
            return response()->json([
                'message' => 'Primero debes habilitar las llamadas en el número antes de activar las salientes.',
            ], 422);
        }

        $instance->setCallingSettings(['outbound_enabled' => $data['outbound_enabled']]);
        $instance->save();

        return response()->json([
            'success' => true,
            'calling' => [
                'enabled' => $instance->callingEnabled(),
                'outbound_enabled' => $instance->outboundCallsEnabled(),
            ],
        ]);
    }

    protected function wrap(array $result, string $errorMessage)
    {
        if (!$result['success']) {
            return response()->json([
                'message' => $errorMessage,
                'error' => $result['error'] ?? null,
            ], 502);
        }

        return response()->json(['success' => true, 'data' => $result['data'] ?? null]);
    }

    protected function buildCheck(
        string $id,
        string $title,
        string $description,
        bool $reachable,
        ?string $rawStatus,
        array $statusMap,
        ?string $manualUrl = null,
        array $extra = []
    ): array {
        if (!$reachable) {
            return [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'state' => 'unknown',
                'raw_status' => null,
                'action_type' => 'manual',
                'manual_url' => $manualUrl,
                'extra' => $extra,
            ];
        }

        $state = $rawStatus ? ($statusMap[$rawStatus] ?? 'unknown') : 'unknown';

        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'state' => $state,
            'raw_status' => $rawStatus,
            'action_type' => 'manual',
            'manual_url' => $manualUrl,
            'extra' => $extra,
        ];
    }

    protected function buildPhoneRegistrationCheck(bool $reachable, array $phoneData): array
    {
        $code = $phoneData['code_verification_status'] ?? null;
        $status = $phoneData['status'] ?? null;
        $platform = $phoneData['platform_type'] ?? null;

        // Flujo Meta para registrar un número en Cloud API:
        // 1. NOT_VERIFIED       → pedir código por SMS/VOICE (request_code)
        // 2. CODE_REQUESTED     → ingresar el código recibido (verify_code)
        // 3. VERIFIED + ≠CONNECTED → registrar con PIN 2FA de 6 dígitos (/register)
        // 4. status=PENDING     → Meta está procesando; se puede reintentar /register
        // 5. status=CONNECTED   → operativo
        $state = 'unknown';
        $actionType = null;
        $description = 'Para enviar mensajes desde Cloud API el número debe estar registrado con un PIN de 6 dígitos y conectado.';

        if ($reachable) {
            if ($code === 'VERIFIED' && $status === 'CONNECTED') {
                $state = 'ok';
                $actionType = null;
            } elseif ($status === 'PENDING') {
                $state = 'pending';
                $actionType = 'register_number'; // permite reintentar si quedó atascado
                $description = 'WhatsApp está procesando el registro. Si tarda demasiado puedes reintentar el registro con el mismo PIN.';
            } elseif ($code === 'CODE_REQUESTED' || $code === 'EXPIRED_CODE') {
                $state = $code === 'EXPIRED_CODE' ? 'action' : 'pending';
                $actionType = 'verify_code';
                $description = $code === 'EXPIRED_CODE'
                    ? 'El código de verificación expiró. Solicita uno nuevo o ingresa el último que recibiste.'
                    : 'Ingresa el código de 6 dígitos que recibiste por SMS o llamada.';
            } elseif ($code === 'NOT_VERIFIED') {
                $state = 'action';
                $actionType = 'request_code';
                $description = 'Primero debes verificar el número: solicita un código por SMS o llamada.';
            } elseif ($code === 'VERIFIED') {
                $state = 'action';
                $actionType = 'register_number';
                $description = 'El número está verificado. Regístralo en Cloud API con un PIN de 6 dígitos.';
            } else {
                // status=DISCONNECTED/MIGRATED/BANNED/RESTRICTED/FLAGGED/RATE_LIMITED
                $state = 'action';
                $actionType = 'register_number';
                if (in_array($status, ['BANNED', 'RESTRICTED', 'FLAGGED'], true)) {
                    $description = 'WhatsApp aplicó restricciones a este número. Revisa el estado en Business Manager.';
                    $actionType = null; // mejor mandar a Meta
                }
            }
        }

        return [
            'id' => 'phone_registration',
            'title' => 'Registro del número en Cloud API',
            'description' => $description,
            'state' => $state,
            // raw_status muestra el estado operativo real (status), no solo si tuvo PIN verificado
            'raw_status' => $status ?? $code,
            'action_type' => $actionType,
            'manual_url' => 'https://business.facebook.com/wa/manage/phone-numbers',
            'extra' => [
                'status' => $status,
                'code_verification_status' => $code,
                'platform_type' => $platform,
                'quality_rating' => $phoneData['quality_rating'] ?? null,
                'throughput' => $phoneData['throughput']['level'] ?? null,
                'messaging_limit_tier' => $phoneData['messaging_limit_tier'] ?? null,
            ],
        ];
    }

    protected function buildWebhookCheck(bool $reachable, array $appsData): array
    {
        $subscribed = $reachable && count($appsData) > 0;

        return [
            'id' => 'webhook_subscription',
            'title' => 'Webhook suscrito a la WABA',
            'description' => 'Necesario para recibir aprobación/rechazo de plantillas y cambios de calidad del número.',
            'state' => !$reachable ? 'unknown' : ($subscribed ? 'ok' : 'action'),
            'raw_status' => $subscribed ? 'SUBSCRIBED' : 'NOT_SUBSCRIBED',
            'action_type' => 'subscribe_webhook',
            'extra' => [
                'apps' => array_map(fn($a) => [
                    'id' => $a['id'] ?? null,
                    'name' => $a['name'] ?? null,
                ], $appsData),
            ],
        ];
    }

    protected function buildInsightsCheck(bool $reachable, array $wabaData): array
    {
        $enabled = (bool) ($wabaData['is_enabled_for_insights'] ?? false);

        return [
            'id' => 'insights',
            'title' => 'Analítica de plantillas',
            'description' => 'Necesaria para ver métricas de envío, entrega, lectura y clics de tus plantillas.',
            'state' => !$reachable ? 'unknown' : ($enabled ? 'ok' : 'action'),
            'raw_status' => $enabled ? 'ENABLED' : 'DISABLED',
            'action_type' => 'enable_insights',
            'extra' => [],
        ];
    }

    protected function resolveInstance(Request $request)
    {
        $user = auth()->user();
        $instanceId = $request->input('instance_id');

        $query = Instance::where('company_id', $user->company_id)
            ->where('active', true)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token');

        $instance = $instanceId
            ? $query->where('id', $instanceId)->first()
            : $query->orderBy('id')->first();

        if (!$instance) {
            return response()->json([
                'message' => 'No hay una instancia activa con WABA configurado.',
            ], 422);
        }

        return $instance;
    }
}
