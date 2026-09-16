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
 * ## Lo que esta pantalla SÍ tiene que enseñar
 *
 * El CRM entero, antes que las extensiones. Lo que distingue a un plan de otro
 * son las cinco extensiones, pero lo que el cliente paga sobre todo es el CRM,
 * y una pantalla que sólo lista extensiones convierte al plan de entrada en un
 * plan de una sola función.
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

            // Lo que tiene por el simple hecho de ser cliente. Va primero en la
            // pantalla: sin esto, el plan Esencial se leía como «una función»
            // —la firma del agente— cuando lo que incluye es el CRM entero, y
            // el cliente de entrada veía su plan más pobre de lo que es.
            'nucleo' => config('planes.nucleo', []),
            'uso_ia' => ContadorDeIa::estado($company),
            'extensiones' => $extensiones,
            'planes' => collect(config('planes.crm'))
                ->map(fn (array $p, string $slug) => [
                    'slug' => $slug,
                    'nombre' => $p['nombre'],
                    'agentes' => $p['agentes'],
                    'contactos' => $p['contactos'],
                    'es_el_suyo' => $slug === $plan->slug(),
                ])
                ->values(),

            // El complemento, que es lo que se vende. Va aparte del plan porque
            // son dos decisiones distintas y mezclarlas es lo que obligaba a
            // subir de plan entero para tener una función con IA.
            'complementos' => collect(config('planes.ia'))
                ->map(fn (array $p, string $slug) => [
                    'slug' => $slug,
                    'nombre' => $p['nombre'],
                    'es_el_suyo' => $slug === $plan->slugIa(),
                ])
                ->values(),
        ]);
    }

    /**
     * El complemento de IA más bajo que incluye esta extensión.
     *
     * Para poder decir «esto está en IA Esencial» en vez de un «no incluido» a
     * secas, que no le dice a nadie qué tiene que hacer. `null` si va con el
     * CRM: entonces la tiene, y no hay nada que decir.
     */
    private function planMinimoPara(string $slug): ?string
    {
        if (in_array($slug, (array) config('planes.extensiones_del_crm', []), true)) {
            return null;
        }

        foreach (config('planes.ia', []) as $nivel) {
            if (in_array($slug, (array) ($nivel['extensiones'] ?? []), true)) {
                return $nivel['nombre'];
            }
        }

        return null;
    }
}
