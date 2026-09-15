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
            $this->line('Es lo esperado mientras todas las empresas estén en cortesía:');
            $this->line('la transición se hace cambiando `cobro` a `activo` desde el panel maestro.');
        } else {
            $this->table(
                ['Empresa', 'Plan', 'Tramo', 'Reales', 'USD/mes', 'Aviso'],
                collect($datos['cobrar'])->map(fn (array $f) => [
                    $f['empresa'],
                    $f['plan'],
                    $f['tramo'] ? number_format($f['tramo']) : '—',
                    number_format($f['contactos_reales']),
                    $f['usd'] ? '$'.$f['usd'] : 'a cotizar',
                    $f['se_paso'] ? '⚠ pasado del tramo' : '',
                ])->all()
            );

            $this->newLine();
            $this->line('  <fg=green>Empresas a facturar:</> '.count($datos['cobrar']));
            $this->line('  <fg=green>Total:</> $'.number_format($datos['total_usd']).' USD/mes');

            if ($datos['sin_tramo']) {
                $this->warn("  {$datos['sin_tramo']} sin tramo asignado: hay que cotizarlas a mano.");
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
