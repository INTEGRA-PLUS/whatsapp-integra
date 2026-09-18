<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Mueve el arranque de los periodos ya emitidos al día de corte.
 *
 * Existe porque el día de corte del cliente de Integra vive en la configuración
 * —`planes.dia_de_corte_integra`— y cambiarlo deja atrás a todo lo que ya se
 * emitió: los recibos siguen diciendo el periodo viejo y el cliente lee dos
 * fechas distintas para el mismo mes.
 *
 * Pasó el 18-sep-2026, el mismo día de la primera emisión: los 42 recibos
 * salieron con «del 18 de septiembre al 15 de octubre», que ni cuadra con la
 * factura de Integra ni parece un mes completo.
 *
 * ## Lo que NO toca
 *
 * El **final** del periodo, que es lo que se facturó. Si un recibo no acaba en
 * el día de corte se deja como está y se dice: cambiarle el fin a un cobro
 * emitido es cambiar lo que se cobró, y eso no lo arregla un comando.
 *
 * Tampoco los anulados —no cubren nada— ni a los clientes directos, cuyo
 * periodo arranca cuando pagan y no en un día fijo.
 */
class RealinearPeriodos extends Command
{
    protected $signature = 'suscripciones:realinear {--dry : Enseña lo que movería y no toca nada}';

    protected $description = 'Alinea el arranque de los periodos ya emitidos con el día de corte de Integra';

    public function handle(): int
    {
        $dia = (int) config('planes.dia_de_corte_integra', 15);

        $deIntegra = Company::all()
            ->filter(fn (Company $c) => PlanDeLaEmpresa::de($c)->incluidoEnIntegra())
            ->pluck('name', 'id');

        if ($deIntegra->isEmpty()) {
            $this->info('No hay clientes de Integra.');

            return self::SUCCESS;
        }

        $cobros = SuscripcionCobro::whereIn('company_id', $deIntegra->keys())
            ->where('estado', '!=', 'anulado')
            ->orderBy('company_id')
            ->get();

        $filas = [];
        $movidos = 0;

        foreach ($cobros as $cobro) {
            if (! $cobro->periodo_desde || ! $cobro->periodo_hasta) {
                continue;
            }

            // Sólo los que ya acaban en el corte. Uno que acaba otro día viene de
            // otra regla, y moverle el arranque lo dejaría con una duración que
            // no es la que se le cobró.
            if ((int) $cobro->periodo_hasta->day !== $dia) {
                $filas[] = [$deIntegra[$cobro->company_id], $cobro->periodo_desde->toDateString(), '—', 'no acaba en el corte'];

                continue;
            }

            if ((int) $cobro->periodo_desde->day === $dia) {
                continue;
            }

            $nuevo = self::corteAnterior($cobro->periodo_desde, $dia);

            $filas[] = [
                $deIntegra[$cobro->company_id],
                $cobro->periodo_desde->toDateString(),
                $nuevo->toDateString(),
                $this->option('dry') ? 'se movería' : 'movido',
            ];

            if (! $this->option('dry')) {
                $cobro->update(['periodo_desde' => $nuevo]);
                $movidos++;
            }
        }

        if ($filas === []) {
            $this->info('Todos los periodos ya arrancan el día '.$dia.'.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'Arrancaba', 'Arranca', 'Estado'], $filas);

        if ($this->option('dry')) {
            $this->newLine();
            $this->line('Con --dry no se ha tocado nada.');

            return self::SUCCESS;
        }

        Log::channel('whatsapp')->info('🗓️ Periodos realineados al día de corte', [
            'dia' => $dia,
            'movidos' => $movidos,
        ]);

        $this->newLine();
        $this->info("Movidos {$movidos} periodos al día {$dia}. El importe y el final no se han tocado.");

        return self::SUCCESS;
    }

    /** El día de corte en la misma fecha o antes. */
    private static function corteAnterior(Carbon $fecha, int $dia): Carbon
    {
        $corte = $fecha->copy()->day($dia);

        return $corte->greaterThan($fecha) ? $corte->subMonthNoOverflow()->day($dia) : $corte;
    }
}
