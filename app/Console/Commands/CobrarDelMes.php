<?php

namespace App\Console\Commands;

use App\Support\CobroDelMes;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * La lista de a quién cobrarle este mes, para llevársela a facturación.
 *
 * Existe además de la pantalla del panel porque el día de facturar se necesita
 * un archivo, no una pantalla: `--csv` deja el fichero listo para el que emite
 * las facturas.
 *
 * No emite nada ni cambia ningún estado. Es una consulta.
 */
class CobrarDelMes extends Command
{
    protected $signature = 'planes:cobrar
        {--csv= : Escribe la lista en este fichero en vez de enseñarla}
        {--fuera : Enseña también a quién NO se le cobra y por qué}';

    protected $description = 'Enseña a quién hay que cobrarle este mes y cuánto';

    public function handle(): int
    {
        if ($ruta = $this->option('csv')) {
            file_put_contents($ruta, CobroDelMes::csv());
            $this->info("Escrito en {$ruta}");

            return self::SUCCESS;
        }

        $datos = CobroDelMes::calcular();

        if ($datos['cobrar'] === []) {
            $this->warn('No hay a quién cobrarle este mes.');
            $this->line('Es lo esperado: casi toda la base llegó con Integra y el CRM va dentro');
            $this->line('de lo que ya paga por el ERP. Empiezan a aparecer aquí el día que');
            $this->line('contratan el complemento de IA, que es la venta que se busca.');
        } else {
            $this->table(
                ['Empresa', 'Plan CRM', 'IA', 'Concepto', 'Contactos', 'Agentes', 'USD/mes', 'Aviso'],
                collect($datos['cobrar'])->map(fn (array $f) => [
                    $f['empresa'],
                    $f['plan'],
                    $f['ia'],
                    // Decirlo aquí evita que quien factura se pregunte por qué
                    // este cliente paga menos que el de al lado: su CRM va
                    // dentro del ERP y aquí sólo se le cobra el complemento.
                    $f['solo_ia'] ? 'sólo IA' : 'CRM + IA',
                    number_format($f['contactos_reales']),
                    $f['agentes_reales'],
                    $f['usd'] ? '$'.$f['usd'] : 'sin plan',
                    $f['se_paso_de'] ? '⚠ pasado de '.implode(' y ', $f['se_paso_de']) : '',
                ])->all()
            );

            $this->newLine();
            $this->line('  <fg=green>Empresas a facturar:</> '.count($datos['cobrar']));
            $this->line('  <fg=green>Total:</> $'.number_format($datos['total_usd']).' USD/mes');

            if ($datos['sin_tramo']) {
                $this->warn("  {$datos['sin_tramo']} con el cobro activo y precio cero: hay que ponerles plan.");
            }
        }

        // El resumen de quién queda fuera va siempre, aunque el detalle no: es
        // el número que dice si la transición está avanzando o parada.
        $porMotivo = collect($datos['fuera'])->countBy('motivo');

        if ($porMotivo->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=gray>Fuera de facturación:</>');

            foreach ($porMotivo as $motivo => $cuantas) {
                $this->line(sprintf('    %-12s %d', $motivo, $cuantas));
            }
        }

        if ($this->option('fuera') && $datos['fuera'] !== []) {
            $this->newLine();
            $this->table(
                ['Empresa', 'Motivo', 'Hasta', 'Nota'],
                collect($datos['fuera'])->map(fn (array $f) => [
                    $f['empresa'],
                    $f['motivo'],
                    $f['hasta'] ?? '—',
                    Str::limit((string) $f['nota'], 44) ?: '—',
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}
