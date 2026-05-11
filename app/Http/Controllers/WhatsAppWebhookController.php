<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use App\Services\AutoResponseService;

class WhatsAppWebhookController extends Controller
{
    private $metaService;
    private $autoResponseService;

    public function __construct(MetaWhatsAppService $metaService, AutoResponseService $autoResponseService)
    {
        $this->metaService = $metaService;
        $this->autoResponseService = $autoResponseService;
    }

    public function verify(Request $request)
    {
        // Try both underscore and dot notation (standard vs Laravel conversion)
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        // Fallback: Manually parse REQUEST_URI if server config strips query string
        if (!$mode && $request->server('REQUEST_URI')) {
            $queryString = parse_url($request->server('REQUEST_URI'), PHP_URL_QUERY);
            if ($queryString) {
                parse_str($queryString, $queryParams);
                $mode = $queryParams['hub_mode'] ?? $queryParams['hub.mode'] ?? null;
                $token = $queryParams['hub_verify_token'] ?? $queryParams['hub.verify_token'] ?? null;
                $challenge = $queryParams['hub_challenge'] ?? $queryParams['hub.challenge'] ?? null;
            }
        }

        $verifyToken = config('services.meta.webhook_verify_token');
        
        if ($mode === 'subscribe' && $token === $verifyToken) {
            Log::channel('whatsapp')->info('✅ Webhook verificado exitosamente');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::channel('whatsapp')->warning('❌ Intento de verificación fallido', [
            'mode' => $mode,
            'token' => $token,
            'ip' => $request->ip()
        ]);

        return response('Forbidden', 403);
    }

    public function webhook(Request $request)
    {
        $data = $request->all();

        Log::channel('whatsapp')->info('📩 Webhook recibido de Meta', [
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);

        try {
            if (isset($data['entry'])) {
                foreach ($data['entry'] as $entry) {
                    foreach ($entry['changes'] as $change) {
                        if ($change['field'] === 'messages') {
                            $this->processChange($change['value']);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('❌ Error procesando webhook', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function processChange($value)
    {
        $metadata = $value['metadata'];
        $phoneNumberId = $metadata['phone_number_id'];

        Log::channel('whatsapp')->info('🔍 Identificando instancia', [
            'phone_number_id' => $phoneNumberId
        ]);

        $instance = Instance::where('phone_number_id', $phoneNumberId)
            ->where('active', true)
            ->first();

        if (!$instance) {
            Log::channel('whatsapp')->warning('⚠️ No se encontró instancia activa', [
                'phone_number_id' => $phoneNumberId
            ]);
            return;
        }

        Log::channel('whatsapp')->info('✅ Instancia identificada', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id
        ]);

        if (isset($value['messages'])) {
            foreach ($value['messages'] as $message) {
                $this->processInboundMessage($message, $instance, $value);
            }
        }

        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                $this->updateMessageStatus($status, $instance);
            }
        }
    }

    private function processInboundMessage($message, Instance $instance, $metadata)
    {
        $from = $message['from'];
        $wamid = $message['id'];
        $timestamp = $message['timestamp'];

        $contactName = 'Desconocido';
        if (isset($metadata['contacts']) && count($metadata['contacts']) > 0) {
            $contactName = $metadata['contacts'][0]['profile']['name'] ?? $from;
        }

        $conversation = WhatsAppConversation::firstOrCreate(
            [
                'instance_id' => $instance->id,
                'wa_id' => $from
            ],
            [
                'phone_number' => $from,
                'name' => $contactName,
                'status' => 'open',
                'last_message_at' => now()
            ]
        );

        $existingMessage = WhatsAppMessage::where('wamid', $wamid)->first();
        if ($existingMessage) {
            Log::channel('whatsapp')->info('ℹ️ Mensaje duplicado, ignorando', ['wamid' => $wamid]);
            return;
        }

        $messageData = [
            'conversation_id' => $conversation->id,
            'wamid' => $wamid,
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => \Carbon\Carbon::createFromTimestamp($timestamp)
        ];

        switch ($message['type']) {
            case 'text':
                $messageData['type'] = 'text';
                $messageData['content'] = $message['text']['body'];
                break;

            case 'image':
                $messageData['type'] = 'image';
                $messageData['media_id'] = $message['image']['id'];
                $messageData['media_mime_type'] = $message['image']['mime_type'];
                $messageData['content'] = $message['image']['caption'] ?? '';

                $mediaInfo = $this->metaService->downloadMedia($message['image']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                    $messageData['filename'] = $mediaInfo['filename'];
                }
                break;

            case 'document':
                $messageData['type'] = 'document';
                $messageData['media_id'] = $message['document']['id'];
                $messageData['media_mime_type'] = $message['document']['mime_type'];
                $messageData['filename'] = $message['document']['filename'] ?? 'document';

                $mediaInfo = $this->metaService->downloadMedia($message['document']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                }
                break;

            case 'audio':
                $messageData['type'] = 'audio';
                $messageData['media_id'] = $message['audio']['id'];
                $messageData['media_mime_type'] = $message['audio']['mime_type'];

                $mediaInfo = $this->metaService->downloadMedia($message['audio']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                    $messageData['filename'] = $mediaInfo['filename'];
                }
                break;

            case 'video':
                $messageData['type'] = 'video';
                $messageData['media_id'] = $message['video']['id'];
                $messageData['media_mime_type'] = $message['video']['mime_type'];
                $messageData['content'] = $message['video']['caption'] ?? '';

                $mediaInfo = $this->metaService->downloadMedia($message['video']['id'], $instance->access_token);
                if ($mediaInfo) {
                    $messageData['media_url'] = $mediaInfo['url'];
                    $messageData['filename'] = $mediaInfo['filename'];
                }
                break;

            default:
                $messageData['type'] = 'text';
                $messageData['content'] = "Tipo de mensaje no soportado: {$message['type']}";
        }

        $savedMessage = WhatsAppMessage::create($messageData);

        $conversation->update([
            'last_message' => $messageData['content'] ?? 'Media',
            'last_message_at' => now()
        ]);
        $conversation->incrementUnread();

        $this->metaService->markAsRead($instance->phone_number_id, $wamid);

        $this->autoResponseService->handleInbound($instance, $conversation, $messageData['content'] ?? '', $wamid);

        Log::channel('whatsapp')->info('✅ Mensaje procesado', [
            'instance_id' => $instance->id,
            'message_id' => $savedMessage->id
        ]);
    }

    private function updateMessageStatus($status, Instance $instance)
    {
        $wamid = $status['id'];
        $newStatus = $status['status'];

        $message = WhatsAppMessage::where('wamid', $wamid)->first();

        if (!$message) {
            return;
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'delivered') {
            $updateData['delivered_at'] = now();
        } elseif ($newStatus === 'read') {
            $updateData['read_at'] = now();
        } elseif ($newStatus === 'failed') {
            $errors = $status['errors'] ?? [];
            $primaryError = $errors[0] ?? [];
            $errorCode = $primaryError['code'] ?? null;
            $errorTitle = $primaryError['title'] ?? null;
            $errorMessage = $primaryError['message'] ?? 'Error desconocido';

            $updateData['error_message'] = $errorMessage;

            Log::channel('whatsapp')->warning('⚠️ Mensaje fallido', [
                'wamid' => $wamid,
                'recipient_id' => $status['recipient_id'] ?? null,
                'error_code' => $errorCode,
                'error_title' => $errorTitle,
                'error_message' => $errorMessage,
                'all_errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'full_status' => json_encode($status, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $message->update($updateData);

        Log::channel('whatsapp')->info('✅ Estado actualizado', [
            'wamid' => $wamid,
            'status' => $newStatus
        ]);
    }
}
