<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use Carbon\Carbon;

class MessageApiController extends Controller
{
    private $metaService;

    public function __construct(MetaWhatsAppService $metaService)
    {
        $this->metaService = $metaService;
    }

    private function validateInstance(Request $request)
    {
        $token = $request->header('X-Instance-Token');

        if (!$token) {
            return null;
        }

        return Instance::where('phone_number_id', $token)
            ->where('active', true)
            ->first();
    }

    public function sendMessage(Request $request)
    {
        $instance = $this->validateInstance($request);
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|string',
            'message' => 'required|string|max:4096'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $to = $request->to;
        $messageContent = $request->message;

        // Find or create conversation
        $conversation = WhatsAppConversation::firstOrCreate(
            [
                'instance_id' => $instance->id,
                'wa_id' => $to
            ],
            [
                'phone_number' => $to,
                'name' => $to, // Fallback to phone number
                'status' => 'open',
                'last_message_at' => now()
            ]
        );

        $result = $this->metaService->sendMessage(
            $instance->phone_number_id,
            $to,
            $messageContent
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'text',
                'content' => $messageContent,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_at' => now()
            ]);

            $conversation->update([
                'last_message' => $messageContent,
                'last_message_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message_id' => $message->id,
                'wamid' => $message->wamid
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error']['error']['message'] ?? 'Error al enviar a Meta'
        ], 500);
    }

    public function sendTemplate(Request $request)
    {
        $instance = $this->validateInstance($request);
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|string',
            'template_name' => 'required|string',
            'language_code' => 'nullable|string',
            'components' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $to = $request->to;
        $templateName = $request->template_name;
        $languageCode = $request->language_code ?? 'es';
        $components = $request->components ?? [];

        // Find or create conversation
        $conversation = WhatsAppConversation::firstOrCreate(
            [
                'instance_id' => $instance->id,
                'wa_id' => $to
            ],
            [
                'phone_number' => $to,
                'name' => $to,
                'status' => 'open',
                'last_message_at' => now()
            ]
        );

        $result = $this->metaService->sendTemplate(
            $instance->phone_number_id,
            $to,
            $templateName,
            $languageCode,
            $components
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'text', // Or 'template' if we want to be more specific, but 'text' is fine for UI
                'content' => "[Plantilla: $templateName]",
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => [
                    'template' => $templateName,
                    'language' => $languageCode,
                    'components' => $components
                ]
            ]);

            $conversation->update([
                'last_message' => "[Plantilla: $templateName]",
                'last_message_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message_id' => $message->id,
                'wamid' => $message->wamid
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error']['error']['message'] ?? 'Error al enviar plantilla a Meta'
        ], 500);
    }

    public function registerMessage(Request $request)
    {
        $instance = $this->validateInstance($request);
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|string',
            'wamid' => 'required|string|unique:whatsapp_messages,wamid',
            'content' => 'required|string',
            'type' => 'nullable|string', // text, image, document, template, etc.
            'status' => 'nullable|string', // sent, delivered, read, failed
            'direction' => 'nullable|string|in:inbound,outbound',
            'name' => 'nullable|string', // contact name
            'media_url' => 'nullable|string',
            'metadata' => 'nullable|array',
            'sent_at' => 'nullable' // ISO8601 timestamp
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $to = $request->to;
        $wamid = $request->wamid;
        $content = $request->content;
        $type = $request->type ?? 'text';
        $status = $request->status ?? 'sent';
        $direction = $request->direction ?? 'outbound';
        $sentAt = $request->sent_at ? Carbon::parse($request->sent_at) : now();

        // Find or create conversation
        $conversation = WhatsAppConversation::firstOrCreate(
            [
                'instance_id' => $instance->id,
                'wa_id' => $to
            ],
            [
                'phone_number' => $to,
                'name' => $request->name ?? $to,
                'status' => 'open',
                'last_message_at' => $sentAt
            ]
        );

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => $wamid,
            'type' => $type,
            'content' => $content,
            'media_url' => $request->media_url,
            'direction' => $direction,
            'status' => $status,
            'metadata' => $request->metadata,
            'sent_at' => $sentAt
        ]);

        $conversation->update([
            'last_message' => $content,
            'last_message_at' => $sentAt
        ]);

        return response()->json([
            'success' => true,
            'message_id' => $message->id,
            'wamid' => $message->wamid
        ]);
    }

    public function getConversations(Request $request)
    {
        $instance = $this->validateInstance($request);
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $perPage = $request->query('per_page', 20);
        
        $conversations = WhatsAppConversation::where('instance_id', $instance->id)
            ->orderBy('last_message_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $conversations->items(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total()
            ]
        ]);
    }

    public function getMessages(Request $request, $conversationId)
    {
        $instance = $this->validateInstance($request);
        if (!$instance) {
            return response()->json(['error' => 'Instancia no válida o token ausente'], 401);
        }

        $conversation = WhatsAppConversation::where('instance_id', $instance->id)
            ->findOrFail($conversationId);

        $perPage = $request->query('per_page', 50);

        $messages = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->orderBy('sent_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total()
            ]
        ]);
    }
}
