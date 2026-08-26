<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MetaWhatsAppService
{
    protected $baseUri;
    protected $accessToken;
    protected $apiVersion;
    protected $callingBaseUri;

    public function __construct()
    {
        $this->apiVersion = config('services.meta.api_version', 'v21.0');
        $this->baseUri = "https://graph.facebook.com/{$this->apiVersion}";

        $callingVersion = config('services.meta.calling_api_version', 'v23.0');
        $this->callingBaseUri = "https://graph.facebook.com/{$callingVersion}";
    }

    public function sendMessage(string $phoneNumberId, string $to, string $message, ?string $contextWamid = null)
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => true,
                'body' => $message
            ]
        ];

        // Responder a un mensaje concreto (cita estilo WhatsApp).
        if ($contextWamid) {
            $payload['context'] = ['message_id' => $contextWamid];
        }

        return $this->sendRequest($phoneNumberId, $payload);
    }

    /**
     * Envía un menú interactivo: botones de respuesta o lista de opciones.
     *
     * El bloque `interactive` llega ya armado desde WhatsAppMenuService, que es
     * quien conoce los límites de cada formato. Aquí sólo se envuelve en el
     * sobre del mensaje, igual que el texto o la plantilla.
     */
    public function sendInteractive(string $phoneNumberId, string $to, array $interactive, ?string $contextWamid = null)
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
        ];

        if ($contextWamid) {
            $payload['context'] = ['message_id' => $contextWamid];
        }

        return $this->sendRequest($phoneNumberId, $payload);
    }

    /**
     * Reacciona con un emoji a un mensaje del chat.
     *
     * Meta trata la reacción como un mensaje más (`type: reaction`), así que
     * sale por el mismo endpoint que el texto. Un `emoji` vacío es la forma
     * documentada de quitar la reacción anterior.
     */
    public function sendReaction(string $phoneNumberId, string $to, string $targetWamid, string $emoji = '')
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'reaction',
            'reaction' => [
                'message_id' => $targetWamid,
                'emoji' => $emoji,
            ],
        ]);
    }

    public function sendImage(string $phoneNumberId, string $to, string $imageUrl, string $caption = '', ?string $contextWamid = null)
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'image',
            'image' => [
                'link' => $imageUrl,
                'caption' => $caption
            ]
        ];

        if ($contextWamid) {
            $payload['context'] = ['message_id' => $contextWamid];
        }

        return $this->sendRequest($phoneNumberId, $payload);
    }

    public function sendAudio(string $phoneNumberId, string $to, string $audioUrl)
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'audio',
            'audio' => [
                'link' => $audioUrl
            ]
        ]);
    }

    public function sendTemplate(string $phoneNumberId, string $to, string $templateName, string $languageCode = 'es', array $components = [])
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode
                ],
                'components' => $components
            ]
        ]);
    }

    public function sendDocument(
        string $phoneNumberId,
        string $to,
        string $documentUrl,
        string $filename = '',
        string $caption = '',
        ?string $contextWamid = null
    ) {
        $document = [
            'link' => $documentUrl,
            'filename' => $filename,
        ];

        // Meta rechaza el caption vacío en documentos, así que solo va si existe.
        if ($caption !== '') {
            $document['caption'] = $caption;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ];

        if ($contextWamid) {
            $payload['context'] = ['message_id' => $contextWamid];
        }

        return $this->sendRequest($phoneNumberId, $payload);
    }

    /**
     * Sube un archivo a Meta (POST /{phone_number_id}/media) y devuelve el media_id.
     * Ese id se referencia en header.parameters[].{image|video|document}.id al enviar
     * una plantilla con encabezado multimedia. Meta aloja el archivo (~30 días).
     */
    public function uploadMedia(string $phoneNumberId, string $filePath, string $mimeType): array
    {
        try {
            $instance = \App\Models\Instance::where('phone_number_id', $phoneNumberId)->first();
            $accessToken = $instance ? $instance->access_token : null;

            if (!$accessToken) {
                return ['success' => false, 'error' => 'Access token not found'];
            }

            $url = "{$this->baseUri}/{$phoneNumberId}/media";

            $response = Http::withToken($accessToken)
                ->timeout(60)
                ->attach('file', file_get_contents($filePath), basename($filePath), ['Content-Type' => $mimeType])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]);

            if ($response->successful() && $response->json('id')) {
                return ['success' => true, 'id' => $response->json('id')];
            }

            Log::error('WhatsApp uploadMedia error', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp uploadMedia exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function markAsRead(string $phoneNumberId, string $messageId)
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId
        ]);
    }

    /* ===================== Calling API ===================== */

    /**
     * Inicia una llamada saliente (business-initiated). Requiere que el usuario
     * haya concedido permiso de llamada previamente. Envía nuestra oferta SDP.
     */
    public function connectCall(string $phoneNumberId, string $to, string $sdpOffer)
    {
        return $this->sendCallRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'action' => 'connect',
            'session' => [
                'sdp_type' => 'offer',
                'sdp' => $sdpOffer,
            ],
        ]);
    }

    /**
     * Acepta provisionalmente una llamada entrante (timbrando) para indicarle a
     * Meta que la estamos procesando antes de conectar el audio.
     */
    public function preAcceptCall(string $phoneNumberId, string $callId)
    {
        return $this->sendCallRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'call_id' => $callId,
            'action' => 'pre_accept',
        ]);
    }

    /**
     * Acepta una llamada entrante respondiendo con nuestra SDP answer, lo que
     * abre el canal de audio.
     */
    public function acceptCall(string $phoneNumberId, string $callId, string $sdpAnswer)
    {
        return $this->sendCallRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'call_id' => $callId,
            'action' => 'accept',
            'session' => [
                'sdp_type' => 'answer',
                'sdp' => $sdpAnswer,
            ],
        ]);
    }

    /**
     * Rechaza una llamada entrante sin contestarla.
     */
    public function rejectCall(string $phoneNumberId, string $callId)
    {
        return $this->sendCallRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'call_id' => $callId,
            'action' => 'reject',
        ]);
    }

    /**
     * Cuelga / finaliza una llamada en curso (entrante o saliente).
     */
    public function terminateCall(string $phoneNumberId, string $callId)
    {
        return $this->sendCallRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'call_id' => $callId,
            'action' => 'terminate',
        ]);
    }

    /**
     * Envía una solicitud de permiso de llamada al usuario (mensaje interactivo
     * "call_permission_request"). El usuario debe aceptar antes de que el negocio
     * pueda iniciar llamadas salientes. La respuesta llega por webhook.
     */
    public function requestCallPermission(string $phoneNumberId, string $to, string $bodyText = '¿Nos permites llamarte por WhatsApp?')
    {
        try {
            $instance = \App\Models\Instance::where('phone_number_id', $phoneNumberId)->first();
            $accessToken = $instance ? $instance->access_token : null;

            if (!$accessToken) {
                return ['success' => false, 'error' => 'Access token not found'];
            }

            // La solicitud de permiso es un mensaje, pero usa la versión de Graph
            // API de llamadas porque el tipo interactivo es reciente.
            $url = "{$this->callingBaseUri}/{$phoneNumberId}/messages";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post($url, $this->withRecipient([
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'call_permission_request',
                        'body' => ['text' => $bodyText],
                        'action' => ['name' => 'call_permission_request'],
                    ],
                ]));

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::channel('whatsapp')->error('WhatsApp Request Call Permission Error', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('WhatsApp Request Call Permission Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Activa la función de llamadas en un número (POST /{phone_number_id}/settings).
     * Debe hacerse una sola vez por número antes de poder enviar/recibir llamadas.
     */
    public function enableCalling(string $phoneNumberId, string $accessToken)
    {
        try {
            $url = "{$this->callingBaseUri}/{$phoneNumberId}/settings";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post($url, [
                    'calling' => [
                        'status' => 'ENABLED',
                    ],
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::channel('whatsapp')->error('WhatsApp Enable Calling Error', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('WhatsApp Enable Calling Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lee las suscripciones de webhook a nivel de APP (GET /{app_id}/subscriptions).
     * Es donde viven los campos suscritos (messages, calls, ...); requiere app
     * access token (app_id|app_secret), un token de usuario/sistema no sirve.
     */
    public function getAppSubscriptions(string $appId, string $appSecret)
    {
        try {
            $response = Http::withToken("{$appId}|{$appSecret}")
                ->timeout(30)
                ->get("{$this->callingBaseUri}/{$appId}/subscriptions");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::channel('whatsapp')->error('WhatsApp Get App Subscriptions Error', [
                'app_id' => $appId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('WhatsApp Get App Subscriptions Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Actualiza la suscripción de webhook de la app para whatsapp_business_account
     * (POST /{app_id}/subscriptions). `fields` REEMPLAZA la lista completa del
     * objeto, así que el caller debe mandar los campos existentes + los nuevos.
     * Meta re-verifica el callback (GET con hub.challenge) en el momento del POST.
     */
    public function updateAppSubscription(string $appId, string $appSecret, string $callbackUrl, string $verifyToken, array $fields)
    {
        try {
            $response = Http::withToken("{$appId}|{$appSecret}")
                ->asForm()
                ->timeout(30)
                ->post("{$this->callingBaseUri}/{$appId}/subscriptions", [
                    'object' => 'whatsapp_business_account',
                    'callback_url' => $callbackUrl,
                    'verify_token' => $verifyToken,
                    'fields' => implode(',', $fields),
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::channel('whatsapp')->error('WhatsApp Update App Subscription Error', [
                'app_id' => $appId,
                'fields' => $fields,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('WhatsApp Update App Subscription Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * POST a /{phone_number_id}/calls usando la versión de Graph API dedicada a
     * llamadas. Comparte el patrón de autenticación/manejo de errores de sendRequest.
     */
    protected function sendCallRequest(string $phoneNumberId, array $data)
    {
        try {
            $instance = \App\Models\Instance::where('phone_number_id', $phoneNumberId)->first();
            $accessToken = $instance ? $instance->access_token : null;

            if (!$accessToken) {
                Log::error('WhatsApp Calling Error: Access token not found', ['phone_number_id' => $phoneNumberId]);
                return ['success' => false, 'error' => 'Access token not found'];
            }

            $url = "{$this->callingBaseUri}/{$phoneNumberId}/calls";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post($url, $data);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::channel('whatsapp')->error('WhatsApp Calling API Error', [
                'url' => $url,
                'action' => $data['action'] ?? null,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('WhatsApp Calling API Exception', [
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function downloadMedia(string $mediaId, string $accessToken)
    {
        try {
            $url = "{$this->baseUri}/{$mediaId}";
            $response = Http::withToken($accessToken)->get($url);

            if (!$response->successful()) {
                Log::error('Error getting media URL', [
                    'media_id' => $mediaId,
                    'response' => $response->json()
                ]);
                return null;
            }

            $mediaData = $response->json();
            $mediaUrl = $mediaData['url'];
            $mimeType = $mediaData['mime_type'];

            $mediaResponse = Http::withToken($accessToken)->get($mediaUrl);

            if (!$mediaResponse->successful()) {
                return null;
            }

            $extension = $this->getExtensionFromMime($mimeType);
            $filename = uniqid('wa_') . '_' . time() . '.' . $extension;
            $path = "whatsapp/media/{$filename}";

            Storage::disk('s3_media')->put($path, $mediaResponse->body(), 'public');

            return [
                'filename' => $filename,
                'path' => $path,
                'url' => Storage::disk('s3_media')->url($path),
                'mime_type' => $mimeType,
                'size' => strlen($mediaResponse->body())
            ];

        } catch (\Exception $e) {
            Log::error('Exception downloading media', [
                'media_id' => $mediaId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getExtensionFromMime(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/amr' => 'amr',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
        ];

        return $mimeMap[$mimeType] ?? 'bin';
    }

    /**
     * Destinatario en la forma que espera Meta.
     *
     * A un cliente que oculta su número se le responde con su BSUID, y Meta lo
     * exige en `recipient`, no en `to` (comprobado contra la API real: con sólo
     * `recipient` deja de pedir `to`). Se resuelve aquí, en el único punto por
     * el que pasan todos los envíos, para no repetirlo en cada tipo de mensaje.
     */
    protected function withRecipient(array $data): array
    {
        $to = $data['to'] ?? null;

        if ($to !== null && \App\Models\WhatsAppConversation::isBsuid($to)) {
            unset($data['to']);
            $data['recipient'] = $to;
        }

        return $data;
    }

    protected function sendRequest(string $phoneNumberId, array $data)
    {
        $data = $this->withRecipient($data);

        try {
            $instance = \App\Models\Instance::where('phone_number_id', $phoneNumberId)->first();
            $accessToken = $instance ? $instance->access_token : null;
            
            if (!$accessToken) {
                Log::error('WhatsApp API Error: Access token not found', ['phone_number_id' => $phoneNumberId]);
                return ['success' => false, 'error' => 'Access token not found'];
            }

            $url = "{$this->baseUri}/{$phoneNumberId}/messages";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post($url, $data);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            Log::error('WhatsApp API Error', [
                'url' => $url,
                'data' => $data,
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return [
                'success' => false,
                'error' => $response->json()
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp API Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function listTemplates(string $wabaId, string $accessToken, array $params = [])
    {
        $defaults = [
            'fields' => 'id,name,language,status,category,components,quality_score,previous_category,rejected_reason',
            'limit' => 100,
        ];
        $query = array_merge($defaults, $params);

        return $this->graphGet("/{$wabaId}/message_templates", $accessToken, $query);
    }

    public function enableInsights(string $wabaId, string $accessToken)
    {
        try {
            $url = "{$this->baseUri}/{$wabaId}";

            $response = Http::withToken($accessToken)
                ->asForm()
                ->timeout(30)
                ->post($url, ['is_enabled_for_insights' => 'true']);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Enable Insights Error', [
                'waba_id' => $wabaId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Enable Insights Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getWaba(string $wabaId, string $accessToken)
    {
        return $this->graphGet("/{$wabaId}", $accessToken, [
            'fields' => 'id,name,currency,timezone_id,is_enabled_for_insights,account_review_status,business_verification_status,message_template_namespace',
        ]);
    }

    public function getPhoneNumber(string $phoneNumberId, string $accessToken)
    {
        return $this->graphGet("/{$phoneNumberId}", $accessToken, [
            'fields' => 'id,display_phone_number,verified_name,name_status,code_verification_status,status,quality_rating,platform_type,throughput,messaging_limit_tier',
        ]);
    }

    public function listSubscribedApps(string $wabaId, string $accessToken)
    {
        return $this->graphGet("/{$wabaId}/subscribed_apps", $accessToken, []);
    }

    public function subscribeApp(string $wabaId, string $accessToken)
    {
        try {
            $url = "{$this->baseUri}/{$wabaId}/subscribed_apps";
            // Meta exige Content-Type: application/json en este endpoint aunque
            // el body vaya vacío; sin esto devuelve "Unsupported post request".
            $response = Http::withHeaders([
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ])
                ->timeout(30)
                ->post($url);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Subscribe App Error', [
                'waba_id' => $wabaId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Subscribe App Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getBusinessProfile(string $phoneNumberId, string $accessToken)
    {
        return $this->graphGet("/{$phoneNumberId}/whatsapp_business_profile", $accessToken, [
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
        ]);
    }

    public function updateBusinessProfile(string $phoneNumberId, string $accessToken, array $payload)
    {
        try {
            $url = "{$this->baseUri}/{$phoneNumberId}/whatsapp_business_profile";
            $payload['messaging_product'] = 'whatsapp';

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post($url, $payload);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Update Profile Error', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Update Profile Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function uploadProfilePhoto(string $appId, string $accessToken, string $filePath, string $mimeType): array
    {
        return $this->uploadResumable($appId, $accessToken, $filePath, $mimeType);
    }

    /**
     * Subida reanudable (resumable upload) a la app de Meta. Devuelve el handle "h"
     * que se usa como example.header_handle al crear plantillas con encabezado
     * multimedia (IMAGE/VIDEO/DOCUMENT) o como profile_picture_handle.
     */
    public function uploadResumable(string $appId, string $accessToken, string $filePath, string $mimeType): array
    {
        try {
            $fileSize = filesize($filePath);

            $initUrl = "{$this->baseUri}/{$appId}/uploads";
            $init = Http::withToken($accessToken)->timeout(30)->post($initUrl, [
                'file_length' => $fileSize,
                'file_type' => $mimeType,
                'access_token' => $accessToken,
            ]);

            if (!$init->successful()) {
                return ['success' => false, 'stage' => 'init', 'error' => $init->json()];
            }
            $uploadId = $init->json('id');
            if (!$uploadId) {
                return ['success' => false, 'stage' => 'init', 'error' => 'No upload id returned'];
            }

            $uploadUrl = "{$this->baseUri}/{$uploadId}";
            $upload = Http::withHeaders([
                'Authorization' => "OAuth {$accessToken}",
                'file_offset' => '0',
                'Content-Type' => $mimeType,
            ])
                ->withBody(file_get_contents($filePath), $mimeType)
                ->timeout(60)
                ->post($uploadUrl);

            if (!$upload->successful()) {
                return ['success' => false, 'stage' => 'upload', 'error' => $upload->json()];
            }

            $handle = $upload->json('h');
            if (!$handle) {
                return ['success' => false, 'stage' => 'upload', 'error' => 'No handle returned'];
            }

            return ['success' => true, 'handle' => $handle];
        } catch (\Exception $e) {
            Log::error('WhatsApp Profile Photo Upload Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function debugToken(string $accessToken): array
    {
        try {
            $response = Http::get("{$this->baseUri}/debug_token", [
                'input_token' => $accessToken,
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data')];
            }
            return ['success' => false, 'error' => $response->json()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function listWabaPhoneNumbers(string $wabaId, string $accessToken)
    {
        return $this->graphGet("/{$wabaId}/phone_numbers", $accessToken, [
            'fields' => 'id,display_phone_number,verified_name,name_status,code_verification_status,quality_rating,platform_type,throughput,messaging_limit_tier,status',
        ]);
    }

    public function conversationAnalytics(string $wabaId, string $accessToken, array $params)
    {
        $query = [
            'start' => $params['start'],
            'end' => $params['end'],
            'granularity' => $params['granularity'] ?? 'DAILY',
        ];
        if (!empty($params['dimensions'])) {
            $query['dimensions'] = json_encode($params['dimensions']);
        }
        if (!empty($params['phone_numbers'])) {
            $query['phone_numbers'] = json_encode($params['phone_numbers']);
        }

        return $this->graphGet("/{$wabaId}", $accessToken, [
            'fields' => 'conversation_analytics.start(' . $params['start'] . ').end(' . $params['end']
                . ').granularity(' . ($params['granularity'] ?? 'DAILY') . ')'
                . (!empty($params['dimensions']) ? '.dimensions([' . implode(',', array_map(fn($d) => '"' . $d . '"', $params['dimensions'])) . '])' : '')
                . '',
        ]);
    }

    public function registerPhoneNumber(string $phoneNumberId, string $accessToken, string $pin)
    {
        try {
            $url = "{$this->baseUri}/{$phoneNumberId}/register";
            // Meta requiere JSON en /register; con form-urlencoded falla con
            // "(#100) Param messaging_product is not part of the spec".
            $response = Http::withToken($accessToken)
                ->asJson()
                ->timeout(30)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'pin' => $pin,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Register Phone Error', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Register Phone Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function requestCode(string $phoneNumberId, string $accessToken, string $codeMethod, string $language = 'es')
    {
        try {
            $url = "{$this->baseUri}/{$phoneNumberId}/request_code";
            $response = Http::withToken($accessToken)
                ->asForm()
                ->timeout(30)
                ->post($url, [
                    'code_method' => $codeMethod,
                    'language' => $language,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Request Code Error', [
                'phone_number_id' => $phoneNumberId,
                'code_method' => $codeMethod,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Request Code Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function verifyCode(string $phoneNumberId, string $accessToken, string $code)
    {
        try {
            $url = "{$this->baseUri}/{$phoneNumberId}/verify_code";
            $response = Http::withToken($accessToken)
                ->asForm()
                ->timeout(30)
                ->post($url, [
                    'code' => $code,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Verify Code Error', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Verify Code Exception', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function templateAnalytics(string $wabaId, string $accessToken, array $params)
    {
        $query = [
            'start' => $params['start'],
            'end' => $params['end'],
            'granularity' => $params['granularity'] ?? 'DAILY',
            'metric_types' => json_encode($params['metric_types'] ?? ['SENT', 'DELIVERED', 'READ', 'CLICKED']),
            'template_ids' => json_encode(array_values(array_map('strval', $params['template_ids'] ?? []))),
        ];

        if (!empty($params['product_type'])) {
            $query['product_type'] = $params['product_type'];
        }

        return $this->graphGet("/{$wabaId}/template_analytics", $accessToken, $query);
    }

    public function createTemplate(string $wabaId, string $accessToken, array $payload)
    {
        try {
            $url = "{$this->baseUri}/{$wabaId}/message_templates";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->asJson()
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('WhatsApp Template Created', [
                    'waba_id' => $wabaId,
                    'template_name' => $payload['name'] ?? null,
                    'template_language' => $payload['language'] ?? null,
                    'meta_response' => $response->json(),
                ]);
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Template Create Error', [
                'url' => $url,
                'waba_id' => $wabaId,
                'payload' => $payload,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];

        } catch (\Exception $e) {
            Log::error('WhatsApp Template Create Exception', [
                'waba_id' => $wabaId,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getTemplate(string $templateId, string $accessToken, array $params = [])
    {
        $defaults = [
            'fields' => 'id,name,language,status,category,components,quality_score,previous_category,rejected_reason,library_template_name',
        ];
        $query = array_merge($defaults, $params);

        return $this->graphGet("/{$templateId}", $accessToken, $query);
    }

    protected function graphGet(string $path, string $accessToken, array $query = [])
    {
        try {
            $url = "{$this->baseUri}{$path}";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->get($url, $query);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Graph GET Error', [
                'url' => $url,
                'query' => $query,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];

        } catch (\Exception $e) {
            Log::error('WhatsApp Graph GET Exception', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lee META_APP_SECRETS y lo separa en la lista de secretos y el mapa por app.
     *
     * Cada entrada puede venir como "secreto" suelto o como "app_id:secreto".
     * La forma con app_id es la que permite saber cuál secreto corresponde a la
     * app de una instancia concreta (necesario para armar el app access token
     * "app_id|app_secret"); la suelta sólo sirve para validar firmas entrantes.
     *
     * @return array{secrets: list<string>, by_app_id: array<string, string>}
     */
    private function parseConfiguredAppSecrets(): array
    {
        $configured = config('services.meta.webhook_app_secrets');

        if (!is_string($configured) || trim($configured) === '') {
            return ['secrets' => [], 'by_app_id' => []];
        }

        $secrets = [];
        $byAppId = [];

        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, ':')) {
                [$appId, $secret] = explode(':', $entry, 2);
                $appId = trim($appId);
                $secret = trim($secret);

                if ($appId === '' || $secret === '') {
                    continue;
                }

                $byAppId[$appId] = $secret;
                $secrets[] = $secret;

                continue;
            }

            $secrets[] = $entry;
        }

        return ['secrets' => array_values(array_unique($secrets)), 'by_app_id' => $byAppId];
    }

    /**
     * Secretos de app aceptados al validar la firma de los webhooks entrantes.
     *
     * @return list<string>
     */
    private function webhookAppSecrets(): array
    {
        return $this->parseConfiguredAppSecrets()['secrets'];
    }

    /**
     * Secreto de la app indicada, para armar el app access token "app_id|app_secret".
     *
     * Con varias apps entregando al mismo sitio, usar un META_APP_SECRET único
     * hacía que las llamadas a nivel de app fallaran para las instancias de la
     * otra app: el par app_id|app_secret no cuadraba y Meta devolvía error.
     */
    public function appSecretForAppId(?string $appId): ?string
    {
        $parsed = $this->parseConfiguredAppSecrets();

        if ($appId !== null && isset($parsed['by_app_id'][$appId])) {
            return $parsed['by_app_id'][$appId];
        }

        // Compatibilidad con la configuración de una sola app.
        $single = config('services.meta.app_secret');

        if (is_string($single) && trim($single) !== '') {
            return trim($single);
        }

        // Última opción: si sólo hay un secreto configurado, es el que toca.
        return count($parsed['secrets']) === 1 ? $parsed['secrets'][0] : null;
    }

    /**
     * Valida la cabecera X-Hub-Signature-256 contra el cuerpo crudo de la petición.
     *
     * No hay camino permisivo: sin secretos configurados no se puede probar que el
     * payload venga de Meta, así que se rechaza. El endpoint es público y, como
     * Tech Provider, recibe eventos de WABAs de terceros; aceptar payloads sin
     * firmar dejaría que cualquiera inyecte mensajes en las conversaciones.
     *
     * Se prueba contra todos los secretos configurados porque varias apps de Meta
     * entregan al mismo callback y cada una firma con el suyo. Aceptar cualquiera
     * de ellos es el comportamiento correcto: todos son secretos nuestros.
     */
    public function validateWebhookSignature(string $payload, ?string $signature): bool
    {
        $secrets = $this->webhookAppSecrets();

        if ($secrets === []) {
            Log::channel('whatsapp')->error('❌ Sin META_APP_SECRETS/META_APP_SECRET configurado: no se puede validar la firma del webhook');
            return false;
        }

        if (empty($signature) || !str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $valid = false;

        foreach ($secrets as $secret) {
            // Sin cortes tempranos: se recorren todos los secretos siempre para
            // no filtrar por tiempo de respuesta cuál de ellos fue el que casó.
            $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
            $valid = hash_equals($expectedSignature, $signature) || $valid;
        }

        return $valid;
    }
}
