<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\ModoDeAtencion;
use App\Support\OrdenDeLaConversacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Elegir cómo atiende la empresa: a mano, con menú o con IA.
 *
 * Es un ajuste que toca dos mundos —los menús y la IA— y por eso no vive en
 * ninguno de los dos controladores: meterlo en el de menús dejaría a la pantalla
 * de IA llamando a una ruta de menús para apagarse a sí misma.
 *
 * El permiso es el de siempre, `whatsapp_menus.update`: quien puede configurar
 * los menús puede decidir si responden.
 */
class ModoDeAtencionController extends Controller
{
    /**
     * GET /api/atencion/modo
     *
     * En qué modo está y qué cambiaría al pasar a cada uno de los otros. Se
     * manda todo junto para que la pantalla pueda decir «se apagarán: Menú
     * principal» **antes** de que nadie pulse nada.
     */
    public function show(Request $request): JsonResponse
    {
        $company = $this->empresa($request);

        return response()->json([
            'modo' => ModoDeAtencion::actual($company),
            'cambios' => collect(ModoDeAtencion::MODOS)
                ->mapWithKeys(fn ($m) => [$m => ModoDeAtencion::loQueCambiaria($company, $m)])
                ->all(),
            'orden' => OrdenDeLaConversacion::de($company->id),
        ]);
    }

    /** POST /api/atencion/modo */
    public function update(Request $request): JsonResponse
    {
        $company = $this->empresa($request);

        $datos = $request->validate([
            'modo' => ['required', Rule::in(ModoDeAtencion::MODOS)],
        ]);

        $cambios = ModoDeAtencion::aplicar($company, $datos['modo']);

        // Encender la IA sin plan o sin flujo configurado no se aplica: se
        // responde 422 con el motivo en vez de dejar al admin con un modo
        // elegido que no está en marcha.
        if ($cambios['bloqueado'] !== null) {
            return response()->json(['ok' => false, 'message' => $cambios['bloqueado']], 422);
        }

        return response()->json([
            'ok' => true,
            'aplicado' => $cambios,
        ] + $this->show($request)->getData(true));
    }

    private function empresa(Request $request): Company
    {
        $company = $request->user()?->company;

        abort_unless($company !== null, 403);

        return $company;
    }
}
