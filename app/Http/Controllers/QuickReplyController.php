<?php

namespace App\Http\Controllers;

use App\Models\QuickReply;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class QuickReplyController extends Controller
{
    public function index()
    {
        $replies = QuickReply::where('company_id', auth()->user()->company_id)
            ->orderBy('shortcut')
            ->get();

        return Inertia::render('QuickReplies/Index', [
            'replies' => $replies,
        ]);
    }

    public function list()
    {
        return response()->json(
            QuickReply::where('company_id', auth()->user()->company_id)
                ->orderBy('shortcut')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'shortcut' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9_-]+$/',
                Rule::unique('quick_replies')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'message' => 'required|string|max:4000',
        ]);

        $reply = QuickReply::create([
            'company_id' => $companyId,
            'shortcut' => $validated['shortcut'],
            'message' => $validated['message'],
        ]);

        return response()->json($reply, 201);
    }

    public function update(Request $request, QuickReply $quickReply)
    {
        $this->authorizeOwnership($quickReply);
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'shortcut' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9_-]+$/',
                Rule::unique('quick_replies')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($quickReply->id),
            ],
            'message' => 'sometimes|string|max:4000',
        ]);

        $quickReply->update($validated);

        return response()->json($quickReply);
    }

    public function destroy(QuickReply $quickReply)
    {
        $this->authorizeOwnership($quickReply);
        $quickReply->delete();

        return response()->json(['success' => true]);
    }

    private function authorizeOwnership(QuickReply $quickReply): void
    {
        if ($quickReply->company_id !== auth()->user()->company_id) {
            abort(403);
        }
    }
}
