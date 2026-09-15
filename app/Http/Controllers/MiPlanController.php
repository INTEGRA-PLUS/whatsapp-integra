<?php

namespace App\Http\Controllers;

use App\Extensions\Extension;
use App\Extensions\ExtensionRegistry;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Support\ContadorDeIa;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * «Mi plan»: lo que la empresa tiene contratado, contado desde su lado.
 *
 * El panel maestro ya enseñaba todo esto, pero sólo a nosotros. Un admin no
 * sabía en qué plan estaba, ni qué le faltaba, ni cuánta IA llevaba gastada —y
 * nadie pide mejorar un plan que no sabe que tiene—.
 *
 * ## Lo que esta pantalla NO enseña
 *
 * **El precio no sale**, ni el suyo ni el de los planes de arriba. Es
 * deliberado: el precio depende del tramo, de si paga anual, de si es cliente de
 * Integra y de lo que se haya negociado, y un número suelto en pantalla acaba
 * contradiciendo lo que dijo un comercial. Lo que sí sale es qué se desbloquea
 * subiendo, que es la parte que decide.
 *
 * Tampoco sale el **coste** de la IA que consume. Es nuestro margen, no su
 * asunto, y enseñárselo invita a una conversación que no lleva a ningún sitio.
 * Lo que ve es cuánto le queda de lo suyo.
 */
class MiPlanController extends Controller
{
    public function __construct(private ExtensionRegistry $registry) {}

    public function index(Request $request)
    {
        $company = Company::findOrFail($request->user()->company_id);
        $plan = PlanDeLaEmpresa::de($company);
        $instaladas = CompanyExtension::where('company_id', $company->id)->pluck('enabled', 'slug');

        // El catálogo entero, marcando qué entra en su plan y qué no. Enseñar lo
        // que no tienes vende mejor que esconderlo: quien no sabe que existe el
        // resumen con IA no va a preguntar por él.
        $extensiones = collect($this->registry->all())
            ->map(fn (Extension $e) => [
                'slug' => $e->slug(),
                'nombre' => $e->name(),
                'descripcion' => $e->description(),
                'icono' => $e->icon(),
                'en_plan' => $plan->permiteExtension($e->slug()),
                'instalada' => $instaladas->has($e->slug()),
                'encendida' => (bool) ($instaladas[$e->slug()] ?? false),
                'plan_minimo' => $this->planMinimoPara($e->slug()),
            ])
            ->values();

        return Inertia::render('MiPlan/Index', [
            'plan' => $plan->resumen(),
            'uso_ia' => ContadorDeIa::estado($company),
            'extensiones' => $extensiones,
            'planes' => collect(config('planes.disponibles'))
                ->map(fn (array $p, string $slug) => [
                    'slug' => $slug,
                    'nombre' => $p['nombre'],
                    'con_ia' => ($p['ia'] ?? null) !== null,
                    'es_el_suyo' => $slug === $plan->slug(),
                ])
                ->values(),
        ]);
    }

    /**
     * El plan más bajo que incluye esta extensión.
     *
     * Para poder decir «esto está en Automatización» en vez de un «no incluido»
     * a secas, que no le dice a nadie qué tiene que hacer. Recorre el catálogo
     * en su orden, que va de menos a más.
     */
    private function planMinimoPara(string $slug): ?string
    {
        foreach (config('planes.disponibles', []) as $datos) {
            $permitidas = $datos['extensiones'] ?? [];

            if ($permitidas === '*' || in_array($slug, (array) $permitidas, true)) {
                return $datos['nombre'];
            }
        }

        return null;
    }
}
