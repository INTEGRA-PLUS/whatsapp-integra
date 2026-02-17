<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;

class ChatController extends Controller
{
    private $metaService;

    public function __construct(MetaWhatsAppService $metaService)
    {
        // $this->middleware('auth'); // Middleware is usually applied in routes in Laravel 11
        $this->metaService = $metaService;
    }

    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }
        
        $instances = Instance::where('company_id', $user->company_id)
            ->where('type', 'meta')
            ->where('active', true)
            ->get();

        return view('chat.index', compact('instances'));
    }

    public function conversations(Request $request)
    {
        $user = auth()->user();
        $instanceId = $request->instance_id;

        if (!$instanceId) {
            return response()->json(['error' => 'instance_id es requerido'], 400);
        }

        $instance = Instance::where('id', $instanceId)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $conversations = WhatsAppConversation::forInstance($instanceId)
            ->with('assignedAgent:id,name')
            ->when($request->search, function ($query, $search) {
                $query->search($search);
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->orderByDesc('last_message_at')
            ->paginate(50);

        return response()->json($this->sanitizeUtf8($conversations->toArray()));
    }

    public function updates(Request $request)
    {
        $user = auth()->user();
        $instanceId = $request->instance_id;
        $since = $request->since;

        if (!$instanceId) {
            return response()->json(['error' => 'instance_id es requerido'], 400);
        }

        $instance = Instance::where('id', $instanceId)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $updatedConversations = WhatsAppConversation::forInstance($instanceId)
            ->with('assignedAgent:id,name')
            ->when($since, function ($query, $since) {
                $query->where('updated_at', '>', $since);
            })
            ->orderByDesc('last_message_at')
            ->get();

        $newMessages = [];
        if ($request->conversation_id && $since) {
            $newMessages = WhatsAppMessage::where('conversation_id', $request->conversation_id)
                ->with('sender:id,name')
                ->where('created_at', '>', $since)
                ->orderBy('created_at', 'asc')
                ->get();
        }

        $updatedStatuses = [];
        if ($request->conversation_id && $since) {
            $updatedStatuses = WhatsAppMessage::where('conversation_id', $request->conversation_id)
                ->where('updated_at', '>', $since)
                ->whereIn('status', ['delivered', 'read', 'failed'])
                ->select('id', 'wamid', 'status', 'delivered_at', 'read_at')
                ->get();
        }

        return response()->json($this->sanitizeUtf8([
            'conversations' => $updatedConversations,
            'new_messages' => $newMessages,
            'updated_statuses' => $updatedStatuses,
            'timestamp' => now()->toIso8601String()
        ]));
    }

    public function messages($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        $conversation->markAsRead();

        return response()->json($this->sanitizeUtf8([
            'conversation' => $conversation,
            'messages' => $messages,
            'timestamp' => now()->toIso8601String()
        ]));
    }

    public function sendMessage(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:4096'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $instance = $conversation->instance;

        if (!$instance->isMetaConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'Instancia no configurada'
            ], 400);
        }

        $result = $this->metaService->sendMessage(
            $instance->phone_number_id,
            $conversation->phone_number,
            $request->message
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'text',
                'content' => $request->message,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_by' => $user->id,
                'sent_at' => now()
            ]);

            $conversation->update([
                'last_message' => $request->message,
                'last_message_at' => now()
            ]);

            return response()->json($this->sanitizeUtf8([
                'success' => true,
                'message' => 'Mensaje enviado',
                'data' => $message->load('sender')
            ]));
        }

        return response()->json([
            'success' => false,
            'error' => $result['error']['error']['message'] ?? 'Error al enviar'
        ], 500);
    }

    public function sendImage(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|max:5120',
            'caption' => 'nullable|string|max:1024'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $instance = $conversation->instance;

        $path = $request->file('image')->store('whatsapp/outbound', 'public_uploads');
        $imageUrl = Storage::disk('public_uploads')->url($path);

        $result = $this->metaService->sendImage(
            $instance->phone_number_id,
            $conversation->phone_number,
            $imageUrl,
            $request->caption ?? ''
        );

        if ($result['success']) {
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => $result['data']['messages'][0]['id'],
                'type' => 'image',
                'content' => $request->caption ?? '',
                'media_url' => $imageUrl,
                'direction' => 'outbound',
                'status' => 'sent',
                'sent_by' => $user->id,
                'sent_at' => now()
            ]);

            $conversation->update([
                'last_message' => $request->caption ?? 'Imagen',
                'last_message_at' => now()
            ]);

            return response()->json($this->sanitizeUtf8([
                'success' => true,
                'message' => 'Imagen enviada',
                'data' => $message->load('sender')
            ]));
        }

        return response()->json([
            'success' => false,
            'error' => 'Error al enviar imagen'
        ], 500);
    }

    public function close($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')
            ->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $conversation->update(['status' => 'closed']);

        return response()->json([
            'success' => true,
            'message' => 'Conversación cerrada'
        ]);
    }

    /**
     * Recursively sanitize array data to ensure valid UTF-8.
     *
     * @param mixed $input
     * @return mixed
     */
    private function sanitizeUtf8($input)
    {
        if (is_string($input)) {
            return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        } elseif (is_object($input)) {
            // Convert objects to arrays for sanitization if they have toArray method
            if (method_exists($input, 'toArray')) {
                $input = $input->toArray();
            } else {
                $input = (array) $input;
            }
            foreach ($input as &$value) {
                $value = $this->sanitizeUtf8($value);
            }
            unset($value);
        } elseif (is_array($input)) {
            foreach ($input as &$value) {
                $value = $this->sanitizeUtf8($value);
            }
            unset($value);
        }
        return $input;
    }
}
