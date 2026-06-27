<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Models\WhatsAppCall;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppCallPermission;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;

class CallController extends Controller
{
    private $metaService;

    public function __construct(MetaWhatsAppService $metaService)
    {
        $this->metaService = $metaService;
    }

    /**
     * Solicita al usuario permiso para que el negocio pueda llamarlo. Manda el
     * mensaje interactivo "call_permission_request" y deja el permiso en "pending"
     * hasta que llegue la respuesta por webhook.
     */
    public function requestPermission(Request $request, $conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $instance = $conversation->instance;

        if (!$instance->isMetaConfigured()) {
            return response()->json(['success' => false, 'error' => 'Instancia no configurada'], 400);
        }

        // Si ya hay un permiso vigente, no hace falta volver a pedirlo
        if (WhatsAppCallPermission::activeFor($instance->id, $conversation->wa_id)) {
            return response()->json([
                'success' => true,
                'status' => 'granted',
                'message' => 'El contacto ya autorizó las llamadas.',
            ]);
        }

        $bodyText = $request->input('message', '¿Nos permites llamarte por WhatsApp?');

        $result = $this->metaService->requestCallPermission(
            $instance->phone_number_id,
            $conversation->wa_id,
            $bodyText
        );

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'No se pudo enviar la solicitud de permiso',
            ], 422);
        }

        WhatsAppCallPermission::updateOrCreate(
            ['instance_id' => $instance->id, 'wa_id' => $conversation->wa_id],
            [
                'conversation_id' => $conversation->id,
                'status' => 'pending',
                'requested_at' => now(),
                'responded_at' => null,
                'expires_at' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'message' => 'Solicitud de permiso enviada. Espera la respuesta del contacto.',
        ]);
    }

    /**
     * Devuelve el estado actual del permiso de llamada para la conversación,
     * para que la UI sepa si puede ofrecer el botón de llamar.
     */
    public function permissionStatus($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $permission = WhatsAppCallPermission::where('instance_id', $conversation->instance_id)
            ->where('wa_id', $conversation->wa_id)
            ->first();

        // Si el permiso estaba concedido pero ya venció, lo reflejamos como "expired"
        if ($permission && $permission->status === 'granted' && !$permission->isActive()) {
            $permission->update(['status' => 'expired']);
        }

        return response()->json([
            'success' => true,
            'status' => $permission->status ?? 'none',
            'can_call' => $permission ? $permission->isActive() : false,
            'expires_at' => $permission?->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Historial de llamadas de la empresa. Filtros opcionales: instance_id,
     * direction (inbound/outbound), status, conversation_id, search (número/nombre).
     * Devuelve resultados paginados, de la más reciente a la más antigua.
     */
    public function history(Request $request)
    {
        $user = auth()->user();

        $instanceIds = Instance::where('company_id', $user->company_id)->pluck('id');

        $query = WhatsAppCall::with(['conversation:id,name,phone_number,wa_id,contact_id', 'conversation.contact:id,name', 'handledBy:id,name'])
            ->whereIn('instance_id', $instanceIds);

        if ($request->filled('instance_id')) {
            $query->where('instance_id', $request->input('instance_id'));
        }
        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->input('conversation_id'));
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('from', 'like', "%{$search}%")
                    ->orWhere('to', 'like', "%{$search}%")
                    ->orWhereHas('conversation', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $calls = $query->orderByDesc('started_at')
            ->paginate(min((int) $request->input('per_page', 30), 100));

        return response()->json([
            'success' => true,
            'calls' => $calls->getCollection()->map(fn($c) => $this->formatCall($c))->all(),
            'pagination' => [
                'current_page' => $calls->currentPage(),
                'last_page' => $calls->lastPage(),
                'per_page' => $calls->perPage(),
                'total' => $calls->total(),
            ],
        ]);
    }

    /**
     * Historial de llamadas de una conversación concreta (para mostrarlo dentro
     * del chat).
     */
    public function conversationHistory($conversationId)
    {
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $calls = WhatsAppCall::with('handledBy:id,name')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('started_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'calls' => $calls->map(fn($c) => $this->formatCall($c))->all(),
        ]);
    }

    /**
     * Da forma a una llamada para el frontend (resumen plano y legible).
     */
    private function formatCall(WhatsAppCall $call): array
    {
        $contactName = $call->conversation?->contact?->name
            ?? $call->conversation?->name
            ?? ($call->direction === 'inbound' ? $call->from : $call->to);

        return [
            'id' => $call->id,
            'wacid' => $call->wacid,
            'conversation_id' => $call->conversation_id,
            'direction' => $call->direction,
            'status' => $call->status,
            'from' => $call->from,
            'to' => $call->to,
            'contact_name' => $contactName,
            'handled_by' => $call->handledBy?->name,
            'duration_seconds' => $call->duration_seconds,
            'started_at' => $call->started_at?->toIso8601String(),
            'connected_at' => $call->connected_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
        ];
    }
}
