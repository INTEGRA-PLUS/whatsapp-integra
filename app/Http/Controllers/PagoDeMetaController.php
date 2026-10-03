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
            ->get(['id', 'name', 'display_phone_number', 'waba_id', 'problema_de_pago', 'problema_de_pago_desde', 'enlace_de_pago', 'detalle_de_pago'])
            ->map(fn (Instance $i) => [
                'id' => $i->id,
                'nombre' => $i->name,
                'numero' => $i->display_phone_number,
                'waba_id' => $i->waba_id,
                'problema' => $i->problema_de_pago,
                'desde' => optional($i->problema_de_pago_desde)->toIso8601String(),
                'enlace' => FacturacionDeMeta::enlace($i),
                // Lo que dijo Meta, tal cual: «no tienes tarjeta» cuando la
                // tarjeta sigue ahí y lo que falla es un cobro hizo que una
                // empresa desconfiara del aviso entero (3-oct-2026).
                'explicacion' => $i->problema_de_pago ? FacturacionDeMeta::explicacion($i->problema_de_pago) : null,
                'detalle' => FacturacionDeMeta::detalleLegible($i->detalle_de_pago),
                'pagar_ahora' => str_contains((string) $i->enlace_de_pago, 'PAY_NOW'),
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

    /**
     * Lo que Meta lleva cobrado a la línea este mes y el anterior. Aparte de
     * la guía para que la página no espere a Meta: son dos consultas a Graph.
     */
    public function consumo(Request $request, Instance $instance, MetaWhatsAppService $meta)
    {
        abort_unless($instance->company_id === $request->user()->company_id, 403);

        $consumo = FacturacionDeMeta::consumo($instance, $meta);

        return $consumo
            ? response()->json($consumo)
            : response()->json(['message' => 'Meta no devolvió el consumo de esta cuenta.'], 502);
    }
}
