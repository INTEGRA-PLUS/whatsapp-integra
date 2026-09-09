<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\ConversationEvent;
use App\Support\Realtime;
use App\Models\KanbanColumn;
use App\Models\WhatsAppConversation;
use App\Models\Instance;
use App\Services\WebhookDispatcher;

class KanbanController extends Controller
{
    /**
     * Ensure a company has at least the default columns seeded.
     * (Deprecated: Now only tag-based columns are used)
     */
    public static function ensureDefaultColumns(int $companyId): void
    {
        // Default columns are now handled via Tag model creation
    }

    // GET /api/kanban/columns/{id}/cards?page=1&per_page=30&search=&filtros[]=
    public function columnCards(Request $request, int $columnId)
    {
        $user   = auth()->user();
        $column = KanbanColumn::where('company_id', $user->company_id)->findOrFail($columnId);

        $delGrupo = $this->columnasDelGrupo($user->company_id, $column->grupo);

        $perPage = min((int) ($request->per_page ?? 30), 100);

        $query = WhatsAppConversation::query()
            ->select(['id', 'instance_id', 'phone_number', 'name', 'last_message', 'last_message_at', 'status', 'kanban_column_id', 'assigned_to', 'unread_count'])
            ->with(['assignedAgent:id,name', 'tags'])
            ->whereIn('instance_id', Instance::where('company_id', $user->company_id)->pluck('id'))
            ->when($request->search, fn ($q, $s) => $q->search($s))
            ->orderByDesc('last_message_at');

        $this->colocarEnColumna($query, $column, $delGrupo);
        $this->aplicarFiltros($query, $request->input('filtros', []), $user->company_id);

        $paginated = $query->simplePaginate($perPage);

        return response()->json([
            'data'         => $this->sanitizeUtf8($paginated->items()),
            'current_page' => $paginated->currentPage(),
            'has_more'     => $paginated->hasMorePages(),
        ]);
    }

    /** Las columnas de un grupo, en orden. `null` es el grupo «sin agrupar». */
    private function columnasDelGrupo(int $companyId, ?string $grupo): \Illuminate\Support\Collection
    {
        return KanbanColumn::where('company_id', $companyId)
            ->whereNotNull('tag_id')
            ->when(
                $grupo === null,
                fn ($q) => $q->whereNull('grupo'),
                fn ($q) => $q->where('grupo', $grupo)
            )
            ->orderBy('position')
            ->get();
    }

    /**
     * Qué tarjetas caen en esta columna.
     *
     * La posición sale de la **etiqueta**, no de `kanban_column_id`. Ese campo
     * guarda una sola columna, y con grupos una conversación está a la vez en
     * una columna de «Estado», otra de «Zona» y otra de «Área»: por
     * `kanban_column_id` el tablero de Zona no encontraría ninguna tarjeta.
     *
     * Si una conversación arrastra varias etiquetas del mismo grupo —herencia
     * de cuando todas las columnas estaban revueltas: 119 de las 396 tarjetas
     * de Star NET— se queda en la primera del grupo, para no salir duplicada en
     * dos columnas. Se coloca sola en su sitio en cuanto alguien la arrastre.
     *
     * La columna marcada como bandeja recoge además lo que no lleva ninguna
     * etiqueta del grupo. En «Estado» eso es «Nuevo», y sin ello las
     * conversaciones nuevas no aparecerían en ninguna parte del CRM. En «Zona»
     * no hay bandeja que valga: lo que no tiene municipio no es de Cereté, así
     * que no sale, y la suma de las columnas es menor que el total.
     */
    private function colocarEnColumna($query, KanbanColumn $column, \Illuminate\Support\Collection $delGrupo): void
    {
        $tagsDelGrupo = $delGrupo->pluck('tag_id')->filter();
        $anteriores   = $delGrupo->takeWhile(fn ($c) => $c->id !== $column->id)->pluck('tag_id')->filter();

        $tieneLaSuya = fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $column->tag_id))
            ->when($anteriores->isNotEmpty(), fn ($qq) => $qq->whereDoesntHave('tags', fn ($t) => $t->whereIn('tags.id', $anteriores)));

        if ($column->es_bandeja) {
            $query->where(function ($q) use ($tieneLaSuya, $tagsDelGrupo) {
                $q->where($tieneLaSuya)
                    ->orWhereDoesntHave('tags', fn ($t) => $t->whereIn('tags.id', $tagsDelGrupo));
            });

            return;
        }

        $query->where($tieneLaSuya);
    }

    /**
     * Los filtros son las columnas de los **otros** grupos: ver el tablero de
     * «Estado» sólo con lo de MONTERIA, o sólo lo de FACTURACION en MONTERIA.
     * Se acumulan (y), que es lo que espera quien va marcando chips.
     */
    private function aplicarFiltros($query, array $filtros, int $companyId): void
    {
        if (empty($filtros)) {
            return;
        }

        $validos = KanbanColumn::where('company_id', $companyId)
            ->whereIn('id', array_filter(array_map('intval', $filtros)))
            ->pluck('tag_id')
            ->filter();

        foreach ($validos as $tagId) {
            $query->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId));
        }
    }

    private function sanitizeUtf8(mixed $input): mixed
    {
        if (is_string($input)) {
            return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        }
        if ($input instanceof \Illuminate\Database\Eloquent\Model) {
            $input = $input->toArray();
        } elseif (is_object($input) && method_exists($input, 'toArray')) {
            $input = $input->toArray();
        } elseif (is_object($input)) {
            $input = (array) $input;
        }
        if (is_array($input)) {
            foreach ($input as &$value) {
                $value = $this->sanitizeUtf8($value);
            }
            unset($value);
        }
        return $input;
    }

    // GET /api/kanban/columns
    public function columns()
    {
        $companyId = auth()->user()->company_id;

        return response()->json(
            KanbanColumn::where('company_id', $companyId)
                ->whereNotNull('tag_id')
                ->orderBy('position')
                ->get()
        );
    }

    // GET /api/kanban/counts?grupo=&filtros[]=
    public function columnCounts(Request $request)
    {
        $user        = auth()->user();
        $instanceIds = Instance::where('company_id', $user->company_id)->pluck('id');
        $grupo       = $request->filled('grupo') ? $request->input('grupo') : null;
        $delGrupo    = $this->columnasDelGrupo($user->company_id, $grupo);

        if ($delGrupo->isEmpty()) {
            return response()->json([]);
        }

        // El conjunto sobre el que se cuenta: la empresa, menos lo que quiten
        // los filtros de los otros grupos.
        $base = WhatsAppConversation::whereIn('instance_id', $instanceIds);
        $this->aplicarFiltros($base, $request->input('filtros', []), $user->company_id);

        $tagsDelGrupo = $delGrupo->pluck('tag_id')->filter();

        // Una sola consulta a la tabla pivote en vez de una por columna: sólo
        // trae las filas de las etiquetas de este grupo, que son cientos, no
        // los catorce mil registros de conversaciones.
        $etiquetadas = \Illuminate\Support\Facades\DB::table('whatsapp_conversation_tag')
            ->whereIn('tag_id', $tagsDelGrupo)
            ->whereIn('whatsapp_conversation_id', (clone $base)->select('id'))
            ->get(['whatsapp_conversation_id', 'tag_id'])
            ->groupBy('whatsapp_conversation_id');

        // Misma regla que al pintar las tarjetas: con varias etiquetas del
        // grupo, manda la columna que va primero.
        $posicionPorTag = $delGrupo->pluck('position', 'tag_id');
        $columnaPorTag  = $delGrupo->pluck('id', 'tag_id');

        $result = array_fill_keys($delGrupo->pluck('id')->all(), 0);

        foreach ($etiquetadas as $filas) {
            $tagId = collect($filas)
                ->sortBy(fn ($fila) => $posicionPorTag[$fila->tag_id] ?? PHP_INT_MAX)
                ->first()->tag_id;

            $result[$columnaPorTag[$tagId]]++;
        }

        // Y la bandeja del grupo, si la hay, suma lo que no lleva ninguna
        // etiqueta del grupo: las conversaciones nuevas. Un grupo sin bandeja
        // —«Zona»— simplemente no las cuenta.
        $bandeja = $delGrupo->firstWhere('es_bandeja', true);

        if ($bandeja) {
            $result[$bandeja->id] += (clone $base)->count() - $etiquetadas->count();
        }

        return response()->json($result);
    }

    // POST /api/kanban/columns
    public function storeColumn(Request $request)
    {
        $user = auth()->user();
        $validated = $request->validate([
            'name'     => 'required|string|max:50',
            'color'    => 'nullable|string|max:50',
            'icon'     => 'nullable|string|max:50',
            'subtitle' => 'nullable|string|max:100',
            'grupo'    => 'nullable|string|max:60',
            'es_bandeja' => 'nullable|boolean',
        ]);

        // Dos etapas con el mismo nombre no se pueden distinguir en el tablero, y
        // como cada una crea su propia etiqueta, la que ve el agente en el chat
        // depende de cuál se arrastró la última vez. El 9-sep-2026 había 22
        // columnas repetidas en la flota: «CLIENTE» tres veces en CMNET,
        // «COVEÑAS» tres veces y «MONTERIA» dos en Star NET.
        // La comparación se hace en PHP y no con LOWER() en SQL: el LOWER de
        // sqlite sólo baja ASCII, así que «COVEÑAS» y «coveñas» le parecen
        // distintos, y el de MySQL depende de la colación de la tabla. Son 43
        // columnas en el peor caso de la flota; no hay nada que optimizar.
        if ($this->nombreOcupado($user->company_id, $validated['name'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Ya existe una etapa con ese nombre.',
            ]);
        }

        // Columns are backed by tags: creating a tag fires the TagObserver, which
        // creates the matching kanban column. Creating a bare (tag-less) column
        // here would be hidden by columns()/columnCounts() (both filter on tag_id)
        // and would vanish on reload.
        $tag = \App\Models\Tag::create([
            'company_id' => $user->company_id,
            'name'       => $validated['name'],
            'color'      => '#64748b', // slate-500
        ]);

        $column = KanbanColumn::where('company_id', $user->company_id)
            ->where('tag_id', $tag->id)
            ->first();

        // El observador de etiquetas crea la columna, así que el grupo se pone
        // después: la etiqueta no sabe nada de tableros.
        if ($column && ! empty($validated['grupo'])) {
            $column->update(['grupo' => trim($validated['grupo'])]);
        }

        return response()->json($column, 201);
    }

    // PUT /api/kanban/columns/{id}
    public function updateColumn(Request $request, int $id)
    {
        $column = KanbanColumn::where('company_id', auth()->user()->company_id)->findOrFail($id);

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:50',
            'color'    => 'sometimes|string|max:50',
            'icon'     => 'sometimes|string|max:50',
            'subtitle' => 'sometimes|string|max:100',
            'grupo'    => 'sometimes|nullable|string|max:60',
            'es_bandeja' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0',
        ]);

        // Renombrar tampoco puede acabar en dos etapas iguales.
        if (array_key_exists('name', $validated)) {
            if ($this->nombreOcupado($column->company_id, $validated['name'], $column->id)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'name' => 'Ya existe una etapa con ese nombre.',
                ]);
            }
        }

        // Keep the backing tag's name in sync so it doesn't get reverted later
        // (the TagObserver pushes the tag name back onto the column on tag updates).
        if (array_key_exists('name', $validated) && $column->tag_id) {
            \App\Models\Tag::where('id', $column->tag_id)->update(['name' => $validated['name']]);
        }

        $column->update($validated);

        // Dos bandejas en el mismo grupo mostrarían las mismas conversaciones
        // sin clasificar en dos columnas a la vez.
        if (! empty($validated['es_bandeja'])) {
            $this->dejarUnaSolaBandeja($column);
        }

        return response()->json($column->fresh());
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

        // The column is backed by a tag. Remove the tag too (and its pivot rows)
        // so we don't leave an orphan tag with no column. Deleting the tag fires
        // the TagObserver, which removes this column.
        if ($column->tag_id) {
            $tag = \App\Models\Tag::find($column->tag_id);
            if ($tag) {
                $tag->conversations()->detach();
                $tag->delete(); // TagObserver::deleted removes the column
            } else {
                $column->delete();
            }
        } else {
            $column->delete();
        }

        return response()->json(['success' => true]);
    }

    /** La bandeja es única dentro de su grupo: al marcar una, se desmarca la otra. */
    private function dejarUnaSolaBandeja(KanbanColumn $column): void
    {
        KanbanColumn::where('company_id', $column->company_id)
            ->where('id', '!=', $column->id)
            ->when(
                $column->grupo === null,
                fn ($q) => $q->whereNull('grupo'),
                fn ($q) => $q->where('grupo', $column->grupo)
            )
            ->update(['es_bandeja' => false]);
    }

    /** ¿Hay ya otra etapa de esta empresa que se llame así? */
    private function nombreOcupado(int $companyId, string $nombre, ?int $exceptoId = null): bool
    {
        $buscado = mb_strtolower(trim($nombre));

        return KanbanColumn::where('company_id', $companyId)
            ->when($exceptoId, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->pluck('name')
            ->contains(fn ($existente) => mb_strtolower(trim($existente)) === $buscado);
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

        $fromColumn = $conversation->kanban_column_id
            ? KanbanColumn::where('company_id', $user->company_id)->find($conversation->kanban_column_id)
            : null;

        // Note: we intentionally do NOT touch last_message_at here. That field
        // drives chat ordering across the whole inbox, so bumping it on a board
        // move would reorder conversations everywhere, not just on the kanban.
        $conversation->update(['kanban_column_id' => $column->id]);

        // La etiqueta se sincroniza con la posición en el tablero: la tarjeta
        // ahora «es» la columna de destino. Pero sólo dentro de su grupo.
        //
        // Antes se desenganchaban **todas** las demás etiquetas de columna, y
        // eso borraba datos: en Star NET, donde las 43 columnas eran seis
        // dimensiones distintas mezcladas (área, tipo de falla, etapa
        // comercial, cartera, estado y municipio), una tarjeta etiquetada
        // «MESA DE AYUDA + Sin servicio + Falla de zona + TOLU VIEJO» perdía
        // tres etiquetas al arrastrarla una sola vez. 119 de las 396 tarjetas
        // del tablero llevaban más de una (9-sep-2026).
        //
        // Mover dentro del grupo «Estado» ya no toca el municipio ni el área.
        if ($column->tag_id) {
            $delMismoGrupo = KanbanColumn::where('company_id', $user->company_id)
                ->whereNotNull('tag_id')
                ->where('tag_id', '!=', $column->tag_id)
                ->when(
                    $column->grupo === null,
                    fn ($q) => $q->whereNull('grupo'),
                    fn ($q) => $q->where('grupo', $column->grupo)
                )
                ->pluck('tag_id');

            $conversation->tags()->detach($delMismoGrupo);
            $conversation->tags()->syncWithoutDetaching([$column->tag_id]);
        }

        WebhookDispatcher::emit(
            $user->company_id,
            'conversation.column_changed',
            WebhookDispatcher::conversationPayload($conversation, [
                'from_column' => $fromColumn ? ['id' => $fromColumn->id, 'name' => $fromColumn->name] : null,
                'to_column'   => ['id' => $column->id, 'name' => $column->name],
            ])
        );

        // Mueve la tarjeta en el tablero de todos los que lo tengan abierto, y
        // de paso repinta las etiquetas en la lista del chat (moverla las
        // sincroniza).
        Realtime::push(ConversationEvent::updated($conversation, 'column_changed'));

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
                ->whereNotNull('tag_id')
                ->find($validated['column_id'])?->id;
        }
        if (!$columnId) {
            $columnId = KanbanColumn::where('company_id', $user->company_id)
                ->whereNotNull('tag_id')
                ->orderBy('position')
                ->value('id');
        }

        $phone = preg_replace('/[^0-9]/', '', $validated['phone_number']);

        $conversation = WhatsAppConversation::resolveFor(
            $instance->id,
            $phone,
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
