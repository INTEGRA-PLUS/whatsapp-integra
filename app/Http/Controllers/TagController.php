<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\WhatsAppConversation;
use Illuminate\Http\Request;

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

        $conversation->tags()->syncWithoutDetaching([$tag->id]);

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

        return response()->json(['success' => true, 'tags' => $conversation->tags()->get()]);
    }
}
