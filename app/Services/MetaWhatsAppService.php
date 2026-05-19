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

    public function __construct()
    {
        $this->apiVersion = config('services.meta.api_version', 'v21.0');
        $this->baseUri = "https://graph.facebook.com/{$this->apiVersion}";
    }

    public function sendMessage(string $phoneNumberId, string $to, string $message)
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => true,
                'body' => $message
            ]
        ]);
    }

    public function sendImage(string $phoneNumberId, string $to, string $imageUrl, string $caption = '')
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'image',
            'image' => [
                'link' => $imageUrl,
                'caption' => $caption
            ]
        ]);
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

    public function sendDocument(string $phoneNumberId, string $to, string $documentUrl, string $filename = '')
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => [
                'link' => $documentUrl,
                'filename' => $filename
            ]
        ]);
    }

    public function markAsRead(string $phoneNumberId, string $messageId)
    {
        return $this->sendRequest($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId
        ]);
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

    protected function sendRequest(string $phoneNumberId, array $data)
    {
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

    public function createTemplate(string $wabaId, string $accessToken, array $payload)
    {
        try {
            $url = "{$this->baseUri}/{$wabaId}/message_templates";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post($url, $payload);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp Template Create Error', [
                'url' => $url,
                'payload' => $payload,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json()];

        } catch (\Exception $e) {
            Log::error('WhatsApp Template Create Exception', [
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

    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        $appSecret = config('services.meta.app_secret');
        
        if (empty($appSecret)) {
            return true;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);
        
        return hash_equals($expectedSignature, $signature);
    }
}
