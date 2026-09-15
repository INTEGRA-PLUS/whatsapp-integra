<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Console\Command;

/**
 * Le pone a cada empresa el tramo que le corresponde por sus contactos reales.
 *
 * El tramo decide el precio y el crédito de IA, y hasta ahora se ponía a ojo —o
 * no se ponía—. El dato para acertarlo estaba desde siempre: cada persona que
 * escribe queda registrada como contacto.
 *
 * Se guarda **el tope del tramo**, no el número de contactos. Son cosas
 * distintas: una empresa con 9.262 contactos contrata el tramo de 15.000, y
 * guardar 9.262 la dejaría «pasada de tramo» en cuanto entrara un cliente más,
 * cuando en realidad le quedan cinco mil de margen.
 *
 * Por defecto **no toca a quien ya tiene tramo**: ese número puede ser lo que se
 * negoció, y una cooperativa que contrató 15.000 sabiendo que hoy tiene 9.000 no
 * quiere que un comando se lo baje. `--forzar` es para cuando toca revisarlos
 * todos.
 *
 * Esto NO cobra nada. Sólo rellena el dato con el que el panel calcula.
 */
class AsignarTramos extends Command
{
    protected $signature = 'planes:asignar-tramos
        {--dry : Enseña lo que haría y no guarda nada}
        {--forzar : Revisa también las que ya tienen tramo}
        {--company= : Sólo esta empresa (id)}';

    protected $description = 'Asigna a cada empresa el tramo de contactos que le corresponde por los que tiene de verdad';

    public function handle(): int
    {
        $empresas = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get();

        $filas = [];
        $cambiadas = 0;
        $intactas = 0;
        $sinTramo = 0;

        foreach ($empresas as $company) {
            $plan = PlanDeLaEmpresa::de($company);
            $reales = $plan->contactosReales();
            $actual = (int) $company->contactos_contratados;
            $sugerido = $plan->tramoSugerido();

            if ($actual > 0 && ! $this->option('forzar')) {
                $intactas++;

                continue;
            }

            // Por encima del último tramo el precio es «a cotizar»: poner el
            // mayor sería inventarse una tarifa que nadie ha acordado.
            if ($sugerido === null) {
                $sinTramo++;
                $filas[] = [$company->name, number_format($reales), '—', 'a cotizar a mano'];

                continue;
            }

            if ($actual === $sugerido) {
                $intactas++;

                continue;
            }

            if (! $this->option('dry')) {
                $company->update(['contactos_contratados' => $sugerido]);
            }

            $cambiadas++;
            $precio = config("planes.precios.{$sugerido}.".$plan->slug());

            $filas[] = [
                $company->name,
                number_format($reales),
                number_format($sugerido),
                $precio ? '$'.$precio.'/mes · '.$plan->nombre() : '—',
            ];
        }

        if ($filas === []) {
            $this->info('Nada que cambiar: todas tienen ya su tramo.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'Contactos', 'Tramo', 'Le corresponde'], $filas);

        $this->newLine();
        $this->line($this->option('dry')
            ? "Se cambiarían {$cambiadas}. Sin --dry se guardan."
            : "Cambiadas {$cambiadas}.");

        if ($intactas) {
            $this->line("Intactas {$intactas} (ya tenían tramo; --forzar para revisarlas).");
        }

        if ($sinTramo) {
            $this->warn("{$sinTramo} se salen de la escalera y hay que cotizarlas a mano.");
        }

        return self::SUCCESS;
    }
}
