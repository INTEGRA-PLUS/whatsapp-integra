<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\KanbanColumn;
use App\Models\Macro;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MacroController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()->company_id;

        $macros = Macro::where('company_id', $companyId)->orderBy('name')->get();
        $tags = Tag::where('company_id', $companyId)->orderBy('name')->get(['id', 'name', 'color']);
        $companyUsers = User::where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('Macros/Index', [
            'macros' => $macros,
            'tags' => $tags,
            'companyUsers' => $companyUsers,
        ]);
    }

    public function list()
    {
        return response()->json(
            Macro::where('company_id', auth()->user()->company_id)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $companyId = auth()->user()->company_id;

        $macro = Macro::create(array_merge($data, ['company_id' => $companyId]));

        return response()->json($macro, 201);
    }

    public function update(Request $request, Macro $macro)
    {
        $this->authorizeOwnership($macro);
        $data = $this->validateData($request);

        $macro->update($data);

        return response()->json($macro);
    }

    public function destroy(Macro $macro)
    {
        $this->authorizeOwnership($macro);
        $macro->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Ejecuta las acciones de un macro, en orden, sobre una conversación.
     */
    public function run(Macro $macro, $conversationId)
    {
        $this->authorizeOwnership($macro);
        $user = auth()->user();

        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);
        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $results = [];
        foreach ($macro->actions as $action) {
            $results[] = $this->runAction($action, $conversation, $user);
        }

        $conversation->refresh();

        return response()->json([
            'success' => true,
            'results' => $results,
            'conversation' => [
                'status' => $conversation->status,
                'assigned_to' => $conversation->assigned_to,
                'assigned_agent' => $conversation->assignedAgent()->select('id', 'name')->first(),
                'tags' => $conversation->tags()->get(),
            ],
        ]);
    }

    private function runAction(array $action, WhatsAppConversation $conversation, $user): array
    {
        try {
            match ($action['type']) {
                'send_message' => $this->actionSendMessage($action, $conversation, $user),
                'add_tag' => $this->actionAddTag($action, $conversation, $user),
                'remove_tag' => $this->actionRemoveTag($action, $conversation, $user),
                'assign' => $this->actionAssign($action, $conversation, $user),
                'change_status' => $this->actionChangeStatus($action, $conversation, $user),
                default => throw new \RuntimeException("Tipo de acción desconocido: {$action['type']}"),
            };

            return ['type' => $action['type'], 'success' => true];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ Acción de macro falló', [
                'type' => $action['type'] ?? null,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return ['type' => $action['type'] ?? null, 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // Replica ChatController::sendMessage (creación pendiente + envío por cola).
    private function actionSendMessage(array $action, WhatsAppConversation $conversation, $user): void
    {
        if (!$conversation->instance->isMetaConfigured()) {
            throw new \RuntimeException('Instancia no configurada.');
        }

        if (!$conversation->isWindowOpen()) {
            throw new \RuntimeException('La ventana de 24h para responder libremente expiró.');
        }

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => $action['message'],
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message' => $action['message'],
            'last_message_at' => now(),
        ]);

        DeliverWhatsAppMessage::dispatch($message->id);
    }

    // Replica TagController::attachToConversation.
    private function actionAddTag(array $action, WhatsAppConversation $conversation, $user): void
    {
        $tag = Tag::where('company_id', $user->company_id)->findOrFail($action['tag_id']);

        $column = KanbanColumn::where('tag_id', $tag->id)->first();
        if ($column) {
            $conversation->update(['kanban_column_id' => $column->id]);
        }

        $conversation->tags()->syncWithoutDetaching([$tag->id]);

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.tag_added',
            WebhookDispatcher::conversationPayload($conversation, ['tag' => ['id' => $tag->id, 'name' => $tag->name]])
        );
    }

    // Replica TagController::detachFromConversation.
    private function actionRemoveTag(array $action, WhatsAppConversation $conversation, $user): void
    {
        $tagId = $action['tag_id'];
        $conversation->tags()->detach($tagId);

        $tag = Tag::where('company_id', $user->company_id)->find($tagId);
        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.tag_removed',
            WebhookDispatcher::conversationPayload($conversation, [
                'tag' => $tag ? ['id' => $tag->id, 'name' => $tag->name] : ['id' => (int) $tagId],
            ])
        );

        $companyId = $user->company_id;
        $column = KanbanColumn::where('company_id', $companyId)->where('tag_id', $tagId)->first();
        if ($column && (int) $conversation->kanban_column_id === (int) $column->id) {
            $remainingTagIds = $conversation->tags()->pluck('tags.id');
            $fallback = KanbanColumn::where('company_id', $companyId)
                ->whereIn('tag_id', $remainingTagIds)
                ->orderBy('position')
                ->first();

            $conversation->update(['kanban_column_id' => $fallback?->id]);
        }
    }

    // Replica ChatController::assign.
    private function actionAssign(array $action, WhatsAppConversation $conversation, $user): void
    {
        $userId = $action['user_id'] ?? null;
        if ($userId) {
            User::where('id', $userId)->where('company_id', $user->company_id)->firstOrFail();
        }

        $conversation->update(['assigned_to' => $userId]);

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.assigned',
            WebhookDispatcher::conversationPayload($conversation, ['assigned_to' => $userId])
        );
    }

    // Replica ChatController::close / reopen.
    private function actionChangeStatus(array $action, WhatsAppConversation $conversation, $user): void
    {
        $status = $action['status'] === 'open' ? 'open' : 'closed';
        $conversation->update(['status' => $status]);

        $event = $status === 'closed' ? 'conversation.closed' : 'conversation.reopened';
        $extraKey = $status === 'closed' ? 'closed_by' : 'reopened_by';

        WebhookDispatcher::emit(
            $user->company_id,
            $event,
            WebhookDispatcher::conversationPayload($conversation, [$extraKey => $user->id])
        );
    }

    private function validateData(Request $request): array
    {
        // Solo se valida la forma general aquí: request()->validate() descarta
        // cualquier campo no listado en las reglas, así que si valida acá los
        // campos propios de cada tipo (tag_id, message, ...) se perderían.
        // normalizeAction() valida y limpia cada acción según su tipo.
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'active' => 'boolean',
            'actions' => 'required|array|min:1',
        ]);

        $companyId = auth()->user()->company_id;
        $rawActions = $request->input('actions', []);
        $data['actions'] = array_map(fn ($action) => $this->normalizeAction((array) $action, $companyId), $rawActions);
        $data['active'] = $data['active'] ?? true;

        return $data;
    }

    /**
     * Valida y limpia una acción según su tipo, comprobando que las referencias
     * (etiqueta, colaborador) pertenezcan a la empresa del macro.
     */
    private function normalizeAction(array $action, int $companyId): array
    {
        $type = $action['type'] ?? null;

        if ($type === 'send_message') {
            $message = trim((string) ($action['message'] ?? ''));
            if ($message === '') {
                throw ValidationException::withMessages(['actions' => ['El mensaje de la acción "Enviar mensaje" no puede estar vacío.']]);
            }
            return ['type' => 'send_message', 'message' => $message];
        }

        if ($type === 'add_tag' || $type === 'remove_tag') {
            $tag = Tag::where('company_id', $companyId)->find($action['tag_id'] ?? null);
            if (!$tag) {
                throw ValidationException::withMessages(['actions' => ['La etiqueta seleccionada no existe.']]);
            }
            return ['type' => $type, 'tag_id' => $tag->id];
        }

        if ($type === 'assign') {
            $userId = $action['user_id'] ?? null;
            if ($userId) {
                $target = User::where('company_id', $companyId)->find($userId);
                if (!$target) {
                    throw ValidationException::withMessages(['actions' => ['El colaborador seleccionado no existe.']]);
                }
                $userId = $target->id;
            } else {
                $userId = null;
            }
            return ['type' => 'assign', 'user_id' => $userId];
        }

        if ($type === 'change_status') {
            $status = ($action['status'] ?? null) === 'open' ? 'open' : 'closed';
            return ['type' => 'change_status', 'status' => $status];
        }

        throw ValidationException::withMessages(['actions' => ["Tipo de acción inválido: {$type}"]]);
    }

    private function authorizeOwnership(Macro $macro): void
    {
        if ($macro->company_id !== auth()->user()->company_id) {
            abort(403);
        }
    }
}
