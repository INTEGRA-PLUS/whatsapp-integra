<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\KanbanColumn;
use App\Models\WhatsAppConversation;
use App\Models\Instance;

class KanbanController extends Controller
{
    private static array $defaultColumns = [
        ['name' => 'Nuevo Prospecto', 'color' => 'bg-blue-500',    'icon' => 'Plus',         'subtitle' => 'Primer contacto', 'position' => 0],
        ['name' => 'En Atención',     'color' => 'bg-amber-500',   'icon' => 'MessageSquare', 'subtitle' => 'Chat activo',     'position' => 1],
        ['name' => 'Seguimiento',     'color' => 'bg-purple-500',  'icon' => 'Calendar',      'subtitle' => 'Nutrición',       'position' => 2],
        ['name' => 'Venta / Cerrado', 'color' => 'bg-emerald-500', 'icon' => 'CheckCircle2',  'subtitle' => 'Éxito',           'position' => 3],
    ];

    /**
     * Ensure a company has at least the default columns seeded.
     */
    public static function ensureDefaultColumns(int $companyId): void
    {
        if (KanbanColumn::where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (self::$defaultColumns as $col) {
            KanbanColumn::create(array_merge($col, ['company_id' => $companyId]));
        }
    }

    // GET /api/kanban/columns
    public function columns()
    {
        $companyId = auth()->user()->company_id;
        self::ensureDefaultColumns($companyId);

        return response()->json(
            KanbanColumn::where('company_id', $companyId)->orderBy('position')->get()
        );
    }

    // POST /api/kanban/columns
    public function storeColumn(Request $request)
    {
        $user = auth()->user();
        $validated = $request->validate([
            'name'     => 'required|string|max:100',
            'color'    => 'nullable|string|max:50',
            'icon'     => 'nullable|string|max:50',
            'subtitle' => 'nullable|string|max:100',
        ]);

        $maxPos = KanbanColumn::where('company_id', $user->company_id)->max('position') ?? -1;

        $column = KanbanColumn::create([
            'company_id' => $user->company_id,
            'name'       => $validated['name'],
            'color'      => $validated['color']    ?? 'bg-slate-500',
            'icon'       => $validated['icon']     ?? 'Zap',
            'subtitle'   => $validated['subtitle'] ?? 'Personalizado',
            'position'   => $maxPos + 1,
        ]);

        return response()->json($column, 201);
    }

    // PUT /api/kanban/columns/{id}
    public function updateColumn(Request $request, int $id)
    {
        $column = KanbanColumn::where('company_id', auth()->user()->company_id)->findOrFail($id);

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:100',
            'color'    => 'sometimes|string|max:50',
            'icon'     => 'sometimes|string|max:50',
            'subtitle' => 'sometimes|string|max:100',
            'position' => 'sometimes|integer|min:0',
        ]);

        $column->update($validated);

        return response()->json($column);
    }

    // DELETE /api/kanban/columns/{id}
    public function deleteColumn(int $id)
    {
        $user   = auth()->user();
        $column = KanbanColumn::where('company_id', $user->company_id)->findOrFail($id);

        // Reassign cards to the next available column
        $fallback = KanbanColumn::where('company_id', $user->company_id)
            ->where('id', '!=', $id)
            ->orderBy('position')
            ->first();

        WhatsAppConversation::where('kanban_column_id', $id)
            ->update(['kanban_column_id' => $fallback?->id]);

        $column->delete();

        return response()->json(['success' => true]);
    }

    // POST /api/kanban/conversations/{id}/move
    public function moveCard(Request $request, int $conversationId)
    {
        $user         = auth()->user();
        $conversation = WhatsAppConversation::with('instance')->findOrFail($conversationId);

        if ($conversation->instance->company_id !== $user->company_id) {
            abort(403, 'No autorizado');
        }

        $validated = $request->validate([
            'column_id' => 'required|integer',
        ]);

        $column = KanbanColumn::where('company_id', $user->company_id)
            ->findOrFail($validated['column_id']);

        $conversation->update(['kanban_column_id' => $column->id]);

        return response()->json(['success' => true]);
    }

    // POST /api/kanban/cards
    public function storeCard(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name'         => 'nullable|string|max:100',
            'phone_number' => 'required|string|max:20',
            'column_id'    => 'nullable|integer',
            'instance_id'  => 'required|integer',
        ]);

        $instance = Instance::where('id', $validated['instance_id'])
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        // Resolve target column
        $columnId = null;
        if (!empty($validated['column_id'])) {
            $columnId = KanbanColumn::where('company_id', $user->company_id)
                ->find($validated['column_id'])?->id;
        }
        if (!$columnId) {
            self::ensureDefaultColumns($user->company_id);
            $columnId = KanbanColumn::where('company_id', $user->company_id)
                ->orderBy('position')
                ->value('id');
        }

        $phone = preg_replace('/[^0-9]/', '', $validated['phone_number']);

        $conversation = WhatsAppConversation::firstOrCreate(
            ['instance_id' => $instance->id, 'wa_id' => $phone],
            [
                'phone_number'    => $phone,
                'name'            => $validated['name'] ?? null,
                'kanban_column_id'=> $columnId,
                'status'          => 'open',
                'unread_count'    => 0,
            ]
        );

        if (!$conversation->wasRecentlyCreated) {
            $conversation->update(['kanban_column_id' => $columnId]);
        }

        return response()->json(
            $conversation->load('assignedAgent:id,name'),
            $conversation->wasRecentlyCreated ? 201 : 200
        );
    }
}
