<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Console\Command;

/**
 * Le pone a cada empresa el plan de CRM que le corresponde por lo que usa.
 *
 * Sustituye a `planes:asignar-tramos` y `planes:proponer`, que trabajaban sobre
 * la escalera de tramos de contactos. Esa escalera ya no existe: ahora son tres
 * planes de precio fijo y lo que decide cuál toca son **dos** números —contactos
 * y agentes— no uno.
 *
 * El plan se elige por el más pequeño que cubra los dos. Una empresa con 400
 * contactos pero cuatro agentes no cabe en Básico aunque sobre de contactos, y
 * ponerla ahí sería marcarla como «pasada de plan» desde el primer día.
 *
 * **No toca el complemento de IA ni el cobro.** Esas son decisiones comerciales
 * que se toman una a una desde el panel; aquí sólo se rellena el tamaño.
 *
 * Por defecto no pisa a quien ya tiene plan puesto: ese valor puede ser lo que
 * se negoció, y un ISP que contrató Pro sabiendo que hoy le sobra no quiere que
 * un comando se lo baje. `--forzar` es para cuando toca revisarlos todos.
 */
class AsignarPlanes extends Command
{
    protected $signature = 'planes:asignar
        {--dry : Enseña lo que haría y no guarda nada}
        {--forzar : Revisa también las que ya tienen plan}
        {--company= : Sólo esta empresa (id)}';

    protected $description = 'Asigna a cada empresa el plan de CRM que le corresponde por sus contactos y agentes';

    public function handle(): int
    {
        $empresas = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get();

        $filas = [];
        $cambiadas = 0;
        $intactas = 0;
        $aCotizar = 0;

        foreach ($empresas as $company) {
            $plan = PlanDeLaEmpresa::de($company);

            // Las nuestras no se tocan: `Meta App Review` necesita todo
            // encendido para las revisiones de Meta, y `PRUEBAS` existe para
            // probar lo que aún no se vende.
            if ($company->interna) {
                $intactas++;

                continue;
            }

            $sugerido = $plan->planSugerido();

            // Por encima del catálogo el precio es «a cotizar» a propósito: es
            // donde el margen da para negociar. Se enseña y no se toca.
            if ($sugerido === null) {
                $aCotizar++;
                $filas[] = [
                    $company->name,
                    number_format($plan->contactosReales()),
                    $plan->agentesReales(),
                    $plan->nombre(),
                    'SE SALE — cotizar a mano',
                ];

                continue;
            }

            if ($sugerido === $plan->slug()) {
                $intactas++;

                continue;
            }

            // Bajarle el plan a alguien es quitarle lo que ya usa. Se enseña
            // marcado y sólo se aplica con --forzar, que es una decisión.
            $baja = array_search($sugerido, array_keys(config('planes.crm')), true)
                < array_search($plan->slug(), array_keys(config('planes.crm')), true);

            if ($baja && ! $this->option('forzar')) {
                $intactas++;
                $filas[] = [
                    $company->name,
                    number_format($plan->contactosReales()),
                    $plan->agentesReales(),
                    $plan->nombre(),
                    'le sobraría '.config("planes.crm.{$sugerido}.nombre").' — no se baja sin --forzar',
                ];

                continue;
            }

            if (! $this->option('dry')) {
                $company->update(['plan' => $sugerido]);
            }

            $cambiadas++;
            $filas[] = [
                $company->name,
                number_format($plan->contactosReales()),
                $plan->agentesReales(),
                $plan->nombre(),
                config("planes.crm.{$sugerido}.nombre").' · $'.config("planes.crm.{$sugerido}.precio").'/mes',
            ];
        }

        if ($filas === []) {
            $this->info('Nada que cambiar: todas tienen el plan que les corresponde.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'Contactos', 'Agentes', 'Plan actual', 'Le corresponde'], $filas);

        $this->newLine();
        $this->line($this->option('dry')
            ? "Se cambiarían {$cambiadas}. Sin --dry se guardan."
            : "Cambiadas {$cambiadas}.");

        if ($intactas) {
            $this->line("Intactas {$intactas}.");
        }

        if ($aCotizar) {
            $this->warn("{$aCotizar} se salen del catálogo y hay que cotizarlas a mano.");
        }

        $this->newLine();
        $this->line('<fg=gray>El complemento de IA y el cobro no se han tocado: eso se decide empresa a empresa.</>');

        return self::SUCCESS;
    }
}
