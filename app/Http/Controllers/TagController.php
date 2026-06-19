<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\WhatsAppConversation;
use Illuminate\Http\Request;

use App\Models\KanbanColumn;
use App\Services\WebhookDispatcher;

class TagController extends Controller
{
    public function index()
    {
        return response()->json(
            Tag::where('company_id', auth()->user()->company_id)->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'nullable|string|max:20',
        ]);

        $tag = Tag::create([
            'company_id' => auth()->user()->company_id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#0d9488',
        ]);

        return response()->json($tag, 201);
    }

    public function update(Request $request, Tag $tag)
    {
        if ($tag->company_id !== auth()->user()->company_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:50',
            'color' => 'sometimes|string|max:20',
        ]);

        $tag->update($validated);

        return response()->json($tag);
    }

    public function destroy(Tag $tag)
    {
        if ($tag->company_id !== auth()->user()->company_id) {
            abort(403);
        }

        $tag->delete();

        return response()->json(['success' => true]);
    }

    public function attachToConversation(Request $request, $conversationId)
    {
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        
        // Ensure conversation belongs to user's company through its instance
        if ($conversation->instance->company_id !== auth()->user()->company_id) {
            abort(403);
        }

        $validated = $request->validate([
            'tag_id' => 'required|exists:tags,id',
        ]);

        $tag = Tag::where('company_id', auth()->user()->company_id)->findOrFail($validated['tag_id']);

        // When attaching a tag, update the kanban column to match this tag
        $column = KanbanColumn::where('tag_id', $tag->id)->first();
        if ($column) {
            $conversation->update(['kanban_column_id' => $column->id]);
        }

        $conversation->tags()->syncWithoutDetaching([$tag->id]);

        WebhookDispatcher::emit(
            auth()->user()->company_id,
            'conversation.tag_added',
            WebhookDispatcher::conversationPayload($conversation, [
                'tag' => ['id' => $tag->id, 'name' => $tag->name],
            ])
        );

        return response()->json(['success' => true, 'tags' => $conversation->tags()->get()]);
    }

    public function detachFromConversation(Request $request, $conversationId)
    {
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        
        if ($conversation->instance->company_id !== auth()->user()->company_id) {
            abort(403);
        }

        $validated = $request->validate([
            'tag_id' => 'required|exists:tags,id',
        ]);

        $conversation->tags()->detach($validated['tag_id']);

        $detachedTag = Tag::where('company_id', auth()->user()->company_id)->find($validated['tag_id']);
        WebhookDispatcher::emit(
            auth()->user()->company_id,
            'conversation.tag_removed',
            WebhookDispatcher::conversationPayload($conversation, [
                'tag' => $detachedTag ? ['id' => $detachedTag->id, 'name' => $detachedTag->name] : ['id' => (int) $validated['tag_id']],
            ])
        );

        // If the kanban card was sitting in the column linked to this tag, move it out.
        // Fall back to another tag the conversation still has (its column), otherwise
        // leave it without a column so it returns to the first/"unassigned" column.
        $companyId = auth()->user()->company_id;
        $column = KanbanColumn::where('company_id', $companyId)
            ->where('tag_id', $validated['tag_id'])
            ->first();

        if ($column && (int) $conversation->kanban_column_id === (int) $column->id) {
            $remainingTagIds = $conversation->tags()->pluck('tags.id');
            $fallback = KanbanColumn::where('company_id', $companyId)
                ->whereIn('tag_id', $remainingTagIds)
                ->orderBy('position')
                ->first();

            $conversation->update(['kanban_column_id' => $fallback?->id]);
        }

        return response()->json(['success' => true, 'tags' => $conversation->tags()->get()]);
    }
}
