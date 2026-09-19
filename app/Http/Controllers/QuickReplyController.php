<?php

namespace App\Http\Controllers;

use App\Models\QuickReply;
use App\Services\Integra;
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
            'con_integra' => Integra::connected((int) auth()->user()->company_id),
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
            'tipo' => ['nullable', Rule::in(QuickReply::TIPOS)],
            // La de factura no lleva texto: lo que se manda es la plantilla con
            // el PDF, y un mensaje escrito aquí no saldría por ninguna parte.
            'message' => 'required_unless:tipo,'.QuickReply::TIPO_FACTURA.'|nullable|string|max:4000',
        ]);

        $tipo = $validated['tipo'] ?? QuickReply::TIPO_TEXTO;

        if ($error = $this->porQueNoSePuede($tipo)) {
            return response()->json(['message' => $error], 422);
        }

        $reply = QuickReply::create([
            'company_id' => $companyId,
            'shortcut' => $validated['shortcut'],
            'tipo' => $tipo,
            'message' => $tipo === QuickReply::TIPO_FACTURA ? null : $validated['message'],
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
            'tipo' => ['sometimes', Rule::in(QuickReply::TIPOS)],
            'message' => 'sometimes|nullable|string|max:4000',
        ]);

        $tipo = $validated['tipo'] ?? $quickReply->tipo;

        if ($error = $this->porQueNoSePuede($tipo)) {
            return response()->json(['message' => $error], 422);
        }

        if ($tipo === QuickReply::TIPO_FACTURA) {
            $validated['message'] = null;
        } elseif (array_key_exists('message', $validated) && blank($validated['message'])) {
            return response()->json(['message' => 'Una respuesta rápida de texto necesita un mensaje.'], 422);
        }

        $quickReply->update($validated);

        return response()->json($quickReply);
    }

    public function destroy(QuickReply $quickReply)
    {
        $this->authorizeOwnership($quickReply);
        $quickReply->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Por qué este tipo no se puede usar en esta empresa, en palabras.
     *
     * La de factura depende del ERP: el PDF y el importe salen de ahí, y la
     * plantilla `facturacion` sólo existe en la cuenta de Meta de quien tiene
     * Integra conectado. Dejar crearla sin eso es dejar un atajo que, el día
     * que alguien lo pulse delante de un cliente, no hace nada.
     */
    private function porQueNoSePuede(string $tipo): ?string
    {
        if ($tipo !== QuickReply::TIPO_FACTURA) {
            return null;
        }

        return Integra::connected((int) auth()->user()->company_id)
            ? null
            : 'Enviar la factura necesita Integra conectado: el documento y el importe salen del ERP.';
    }

    private function authorizeOwnership(QuickReply $quickReply): void
    {
        if ($quickReply->company_id !== auth()->user()->company_id) {
            abort(403);
        }
    }
}
