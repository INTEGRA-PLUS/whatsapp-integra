<?php

namespace App\Http\Controllers;

use App\Extensions\Extension;
use App\Extensions\ExtensionRegistry;
use App\Models\Company;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Master;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * El catálogo entero, para comparar y para pedir el cambio.
 *
 * «Mi plan» cuenta lo que tienes; esto cuenta lo que hay. Son dos preguntas
 * distintas y meterlas en la misma pantalla obligaba a elegir: o enseñas tu
 * consumo o enseñas la tabla, y la tabla acababa siendo tres tarjetas sin
 * precio porque el precio no cabía en esa conversación.
 *
 * ## Aquí sí hay precios
 *
 * Y es un cambio deliberado respecto a «Mi plan», que los esconde. El motivo de
 * esconderlos allí era que un número suelto junto a tu plan actual contradice lo
 * que negoció un comercial. Una tabla de catálogo es otra cosa: es el precio de
 * lista, se lee como tal, y sin él nadie puede comparar nada — que es justo lo
 * que se pide al entrar aquí.
 *
 * Al cliente de Integra se le dice en la propia tabla que su CRM ya está pagado
 * y que lo único que sumaría es el complemento. Sin eso, ver «Pro $59» cuando
 * llevas dos años sin pagarlo se lee como una subida de precio.
 *
 * ## Cambiar de plan es pedirlo, no pulsarlo
 *
 * No hay autoservicio y no se finge que lo haya: el precio depende del tramo,
 * del pago anual y de lo negociado. El botón manda el aviso a los master, deja
 * la línea en el log y le dice al cliente que le vamos a escribir. Un botón que
 * dijera «plan cambiado» y luego no cambiara nada es peor que no tenerlo.
 */
class PlanesController extends Controller
{
    public function __construct(private ExtensionRegistry $registry) {}

    public function index(Request $request)
    {
        $company = $this->empresa($request);
        $plan = PlanDeLaEmpresa::de($company);

        return Inertia::render('Planes/Index', [
            'actual' => [
                'crm' => $plan->slug(),
                'ia' => $plan->slugIa(),
                'ciclo' => $plan->ciclo(),
                'incluido_en_integra' => $plan->incluidoEnIntegra(),
                'sugerido' => $plan->planSugerido() !== $plan->slug() ? $plan->planSugerido() : null,
                // Qué le aprieta hoy: es lo que convierte la tabla en una
                // decisión en vez de una lista de precios.
                'se_paso_de' => $plan->sePasoDe(),
                'agentes' => $plan->agentesReales(),
                'contactos' => $plan->contactosReales(),
                'lineas' => $plan->lineasReales(),
            ],

            'crm' => collect(config('planes.crm'))
                ->map(fn (array $p, string $slug) => [
                    'slug' => $slug,
                    'nombre' => $p['nombre'],
                    'precio' => $p['precio'],
                    'agentes' => $p['agentes'],
                    'contactos' => $p['contactos'],
                    'lineas' => $p['lineas'],
                    'credito_ia' => $p['credito_ia'],
                ])
                ->values(),

            'ia' => collect(config('planes.ia'))
                ->map(fn (array $p, string $slug) => [
                    'slug' => $slug,
                    'nombre' => $p['nombre'],
                    'precio' => $p['precio'],
                    'extensiones' => collect($p['extensiones'] ?? [])
                        ->map(fn (string $s) => $this->registry->find($s)?->name())
                        ->filter()->values(),
                    // Un candado puede encender varias cosas: `ai_chat` es
                    // a la vez la IA de los chats y la opción de menú que
                    // contesta con tu documentación.
                    'flujos' => collect($p['flujos'] ?? [])
                        ->flatMap(fn (string $f) => (array) config("planes.flujos.{$f}", [$f]))
                        ->values(),
                ])
                ->values(),

            // Para explicar el descuento sin que nadie tenga que dividir: el
            // anual son doce meses por diez mensualidades.
            'ciclos' => collect(config('planes.ciclos'))
                ->map(fn (array $c, string $slug) => [
                    'slug' => $slug,
                    'nombre' => $c['nombre'],
                    'meses' => $c['meses'],
                    'mensualidades' => $c['mensualidades'],
                ])
                ->values(),

            // Lo que lleva cualquier plan. Va aquí por lo mismo que en «Mi
            // plan»: sin esto, el de entrada parece un plan de una sola función.
            'nucleo' => config('planes.nucleo', []),
        ]);
    }

    /**
     * POST /planes/solicitar
     *
     * Pide el cambio. No lo aplica: avisa a quien puede aplicarlo.
     */
    public function solicitar(Request $request)
    {
        $company = $this->empresa($request);
        $plan = PlanDeLaEmpresa::de($company);

        $datos = $request->validate([
            'crm' => ['nullable', Rule::in(array_keys(config('planes.crm', [])))],
            'ia' => ['nullable', Rule::in(array_keys(config('planes.ia', [])))],
            'nota' => ['nullable', 'string', 'max:300'],
        ]);

        $crm = $datos['crm'] ?? null;
        $ia = $datos['ia'] ?? null;

        // Pedir lo que ya se tiene no es una solicitud: es un botón mal puesto.
        // Se contesta y no se molesta a nadie.
        if (($crm === null || $crm === $plan->slug()) && ($ia === null || $ia === $plan->slugIa())) {
            return back()->with('error', 'Eso es justo lo que ya tienes contratado.');
        }

        $quiere = collect([
            $crm && $crm !== $plan->slug() ? 'plan '.config("planes.crm.{$crm}.nombre", $crm) : null,
            $ia && $ia !== $plan->slugIa() ? config("planes.ia.{$ia}.nombre", $ia) : null,
        ])->filter()->implode(' y ');

        $masters = Master::activos();

        Notification::send($masters, new SystemNotification(
            $company->name.' quiere cambiar de plan',
            'Pide '.$quiere.'. Hoy tiene '.$plan->nombre().' + '.$plan->nombreIa().'.'
                .($datos['nota'] ?? '' ? ' Dice: «'.$datos['nota'].'»' : ''),
            'Planes'
        ));

        Log::channel('whatsapp')->info('📈 Solicitud de cambio de plan', [
            'empresa' => $company->id,
            'pide_crm' => $crm,
            'pide_ia' => $ia,
            'tiene_crm' => $plan->slug(),
            'tiene_ia' => $plan->slugIa(),
            'por' => $request->user()->id,
            // Si no hay ningún master activo el aviso no llega a nadie, y eso
            // hay que poder verlo después: el cliente ya se quedó esperando.
            'avisados' => $masters->count(),
        ]);

        return back()->with('success', 'Recibido: te escribimos para cerrar el cambio. No se ha modificado nada todavía.');
    }

    private function empresa(Request $request): Company
    {
        $company = Company::find($request->user()?->company_id);

        abort_unless($company !== null, 403);

        return $company;
    }
}
