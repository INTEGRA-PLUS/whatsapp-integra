<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla de la IA que responde sola.
 *
 * Vivía enterrada en `/settings` como una pestaña entre «Apariencia» y
 * «Horarios». Es la función que se vende, y lo que no se ve en el menú no lo
 * pide nadie: ahora es su propia entrada.
 *
 * Aquí sólo se pinta. Toda la configuración sigue pasando por
 * `AiFlowSettingsController` y sus rutas `api/settings/ai-flow/*`, que es donde
 * están los candados de verdad —el del plan y el del secreto—. Una pantalla no
 * es un candado: quien quiera saltárselo no va a usar el navegador.
 */
class FlujoIaController extends Controller
{
    /** GET /ia */
    public function index(Request $request): Response
    {
        $company = Company::findOrFail($request->user()->company_id);

        return Inertia::render('FlujoIa/Index', [
            // La única pregunta que se le hace al modelo de planes, y a
            // propósito: `PlanDeLaEmpresa` se está reescribiendo para partir el
            // plan de CRM del complemento de IA, y `tieneIa()` va a seguir
            // respondiendo lo mismo con la lógica nueva. Preguntar por
            // `$company->plan` o por el nombre de un plan habría durado una
            // semana.
            'tiene_ia' => PlanDeLaEmpresa::de($company)->tieneIa(),
            'plan' => PlanDeLaEmpresa::de($company)->nombre(),
        ]);
    }
}
