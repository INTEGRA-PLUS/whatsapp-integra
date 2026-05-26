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
            'pin' => 'required|string|regex:/^\d{6}$/',
        ]);

        $instance = $this->resolveInstance($request);
        if (!$instance instanceof Instance) {
            return $instance;
        }

        if (!$instance->phone_number_id) {
            return response()->json(['message' => 'La instancia no tiene phone_number_id configurado.'], 422);
        }

        $result = $this->meta->registerPhoneNumber($instance->phone_number_id, $instance->access_token, $data['pin']);
        return $this->wrap($result, 'No se pudo registrar el número en Cloud API.');
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

        // Estados operativos del número en WhatsApp:
        // - CONNECTED: registrado y operativo
        // - PENDING: registro en curso (aún no se puede usar)
        // - DISCONNECTED / MIGRATED / BANNED / RESTRICTED / FLAGGED / RATE_LIMITED: requiere acción
        $state = 'unknown';
        if ($reachable) {
            if ($code === 'VERIFIED' && $status === 'CONNECTED') {
                $state = 'ok';
            } elseif ($status === 'PENDING' || $code === 'CODE_REQUESTED') {
                $state = 'pending';
            } elseif ($code === 'VERIFIED' && $status === null) {
                // Verificado pero sin status reportado (caso raro de Meta) — lo dejamos en pending
                $state = 'pending';
            } else {
                $state = 'action';
            }
        }

        return [
            'id' => 'phone_registration',
            'title' => 'Registro del número en Cloud API',
            'description' => 'Para enviar mensajes desde Cloud API el número debe estar registrado con un PIN de 6 dígitos y conectado.',
            'state' => $state,
            // raw_status muestra el estado operativo real (status), no solo si tuvo PIN verificado
            'raw_status' => $status ?? $code,
            'action_type' => 'register_number',
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
