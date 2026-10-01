<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\MetaWhatsAppService;
use App\Support\FacturacionDeMeta;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * La guía para activar el pago en Meta y el botón de comprobarlo.
 *
 * Sin permiso de instancias a propósito: la alerta roja sale a todo el que usa
 * el CRM, y el agente que la ve tiene que poder leer qué pasa y a quién
 * pedírselo, aunque no sea él quien lo arregla. Sólo enseña líneas de su
 * empresa.
 */
class PagoDeMetaController extends Controller
{
    public function guia(Request $request)
    {
        $empresa = $request->user()->company_id;

        $lineas = Instance::where('company_id', $empresa)
            ->where('active', true)
            ->where(fn ($q) => $q->where('channel', Instance::CANAL_WHATSAPP)->orWhereNull('channel'))
            ->orderByRaw('problema_de_pago is null')
            ->orderBy('name')
            ->get(['id', 'name', 'display_phone_number', 'waba_id', 'problema_de_pago', 'problema_de_pago_desde', 'enlace_de_pago'])
            ->map(fn (Instance $i) => [
                'id' => $i->id,
                'nombre' => $i->name,
                'numero' => $i->display_phone_number,
                'waba_id' => $i->waba_id,
                'problema' => $i->problema_de_pago,
                'desde' => optional($i->problema_de_pago_desde)->toIso8601String(),
                'enlace' => FacturacionDeMeta::enlace($i),
            ]);

        return Inertia::render('Instances/GuiaPagoMeta', [
            'lineas' => $lineas,
            'elegida' => $request->integer('linea') ?: null,
        ]);
    }

    public function comprobar(Request $request, Instance $instance, MetaWhatsAppService $meta)
    {
        abort_unless($instance->company_id === $request->user()->company_id, 403);

        return response()->json(FacturacionDeMeta::comprobar($instance, $meta));
    }
}
