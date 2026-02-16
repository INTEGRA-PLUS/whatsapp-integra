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
        $this->accessToken = config('services.meta.access_token');
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

    public function downloadMedia(string $mediaId)
    {
        try {
            $url = "{$this->baseUri}/{$mediaId}";
            $response = Http::withToken($this->accessToken)->get($url);

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

            $mediaResponse = Http::withToken($this->accessToken)->get($mediaUrl);

            if (!$mediaResponse->successful()) {
                return null;
            }

            $extension = $this->getExtensionFromMime($mimeType);
            $filename = uniqid('wa_') . '_' . time() . '.' . $extension;
            $path = "whatsapp/media/{$filename}";

            Storage::disk('public_uploads')->put($path, $mediaResponse->body());

             // Fix permissions explicitly using the disk path
            $fullPath = Storage::disk('public_uploads')->path($path);
            if (file_exists($fullPath)) {
                chmod($fullPath, 0644);
            }

            return [
                'filename' => $filename,
                'path' => $path,
                // Force URL construction to match requirement
                'url' => rtrim(config('app.url'), '/') . "/{$path}",
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
            $url = "{$this->baseUri}/{$phoneNumberId}/messages";

            $response = Http::withToken($this->accessToken)
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
