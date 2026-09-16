<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use App\Support\PlanDeLaEmpresa;
use App\Support\Suscripcion;
use Illuminate\Console\Command;

/**
 * Emite los cobros de las suscripciones que vencen.
 *
 * Mientras no exista IntegraPay, esto es lo que convierte «a esta empresa hay
 * que cobrarle» en una fila con un periodo y un importe. Cuando llegue la
 * pasarela, este comando seguirá sirviendo: lo que cambiará es que alguien
 * llamará a `Suscripcion::pagar()` desde un webhook en vez de a mano.
 *
 * **No cobra nada ni contacta con nadie.** Deja el cobro pendiente y se aparta.
 *
 * No se programa a propósito. Emitir un cobro es un acto comercial —lleva
 * importe y periodo— y encadenarlo a un cron antes de que haya con qué cobrarlo
 * llenaría la tabla de pendientes que nadie va a pagar. Cuando esté la pasarela
 * se decide si se automatiza.
 */
class EmitirSuscripciones extends Command
{
    protected $signature = 'suscripciones:emitir
        {--dry : Enseña lo que emitiría y no crea nada}
        {--dias=7 : Cuántos días antes del vencimiento se emite}
        {--company= : Sólo esta empresa (id)}';

    protected $description = 'Emite los cobros pendientes de las suscripciones que vencen pronto';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');

        $empresas = Company::query()
            ->where('interna', false)
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get();

        $filas = [];
        $emitidos = 0;

        foreach ($empresas as $company) {
            $plan = PlanDeLaEmpresa::de($company);

            if (! $plan->seFactura()) {
                continue;
            }

            $faltan = $plan->diasParaRenovar();

            // `null` es que nunca se le emitió nada: ése es justo el que hay que
            // emitir, no el que hay que saltarse.
            if ($faltan !== null && $faltan > $dias) {
                continue;
            }

            // Un pendiente sin pagar ya cubre el aviso. Emitir otro encima deja
            // dos cobros por el mismo periodo, y el día que se paguen los dos la
            // suscripción se alarga el doble.
            $pendiente = SuscripcionCobro::where('company_id', $company->id)
                ->where('estado', 'pendiente')
                ->exists();

            if ($pendiente) {
                $filas[] = [$company->name, '—', '—', 'ya tiene uno pendiente'];

                continue;
            }

            [$desde, $hasta] = Suscripcion::proximoPeriodo($company);

            if (! $this->option('dry')) {
                Suscripcion::emitir($company);
                $emitidos++;
            }

            $filas[] = [
                $company->name,
                '$'.$plan->precioDelCiclo(),
                mb_strtolower($plan->nombreCiclo()),
                $desde->format('d-M').' a '.$hasta->format('d-M'),
            ];
        }

        if ($filas === []) {
            $this->info('Ninguna suscripción vence en los próximos '.$dias.' días.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'Importe', 'Ciclo', 'Periodo'], $filas);

        $this->newLine();
        $this->line($this->option('dry')
            ? 'Con --dry no se ha creado nada.'
            : "Emitidos {$emitidos} cobros, en estado pendiente.");

        $this->line('<fg=gray>Emitir no cobra. Marcar como pagado es lo que alarga la suscripción.</>');

        return self::SUCCESS;
    }
}
