<?php

namespace App\Support;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emitir cobros de suscripción y alargar el periodo pagado.
 *
 * Es el único sitio que mueve `companies.suscripcion_hasta`. Crear una fila de
 * `suscripcion_cobros` a pelo deja la fecha sin tocar, y entonces el cobro
 * existe pero la empresa sigue apareciendo como vencida.
 *
 * Está escrito para que IntegraPay pueda usarlo tal cual cuando llegue: lo que
 * cambiará es quién llama a `pagar()` —hoy una persona desde el panel, mañana
 * un webhook— no lo que significa.
 */
class Suscripcion
{
    /**
     * Emite un cobro pendiente por el siguiente periodo.
     *
     * No cobra nada ni contacta con ninguna pasarela: deja la fila esperando a
     * que alguien confirme el pago. Es lo que permite tener la lista de «esto
     * está emitido y sin pagar» antes de que exista la pasarela.
     */
    public static function emitir(Company $company, ?int $creadoPor = null, ?string $nota = null): SuscripcionCobro
    {
        $plan = PlanDeLaEmpresa::de($company);
        [$desde, $hasta] = self::proximoPeriodo($company);

        return SuscripcionCobro::create([
            'company_id' => $company->id,
            // Copia, no referencia: los precios cambian y el recibo tiene que
            // decir lo que se cobró.
            'plan' => $plan->slug(),
            'ia' => $plan->slugIa(),
            'ciclo' => $plan->ciclo(),
            'importe_usd' => $plan->precioDelCiclo(),
            'periodo_desde' => $desde,
            'periodo_hasta' => $hasta,
            'estado' => 'pendiente',
            'nota' => $nota,
            'creado_por' => $creadoPor,
        ]);
    }

    /**
     * Da un cobro por pagado y alarga la suscripción hasta el fin de su periodo.
     *
     * **Idempotente.** La misma referencia dos veces no alarga dos veces: las
     * pasarelas reintentan sus webhooks —todas— y sin esto el segundo intento
     * regalaría un periodo. Es el mismo patrón que el `wamid` de los mensajes.
     *
     * Devuelve `false` cuando el cobro ya estaba pagado, para que quien llama
     * pueda distinguir «lo hice» de «ya estaba hecho» sin mirar el estado.
     */
    public static function pagar(SuscripcionCobro $cobro, ?string $referencia = null, ?Carbon $cuando = null): bool
    {
        if ($cobro->estaPagado()) {
            return false;
        }

        return DB::transaction(function () use ($cobro, $referencia, $cuando) {
            try {
                $cobro->update([
                    'estado' => 'pagado',
                    'pagado_at' => $cuando ?? now(),
                    'referencia' => $referencia ?: $cobro->referencia,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Esa referencia ya la tiene otro cobro: es el webhook
                // repetido de la pasarela. No es un error y no hay nada que
                // hacer — el periodo ya se alargó la primera vez.
                return false;
            }

            $company = $cobro->company;

            // Se alarga sólo si este periodo va más allá de lo que ya tenía. Un
            // cobro atrasado que se paga tarde no puede acortar la suscripción
            // de quien ya renovó por otro lado.
            if (! $company->suscripcion_hasta || $cobro->periodo_hasta->greaterThan($company->suscripcion_hasta)) {
                $company->update(['suscripcion_hasta' => $cobro->periodo_hasta]);
            }

            return true;
        });
    }

    /**
     * Desde y hasta cuándo iría el próximo periodo.
     *
     * Arranca **donde acaba el actual**, no hoy: quien paga con una semana de
     * antelación no puede perder esa semana. Si ya venció, arranca hoy — cobrar
     * retroactivo por los días que estuvo vencido es una discusión que no
     * compensa.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function proximoPeriodo(Company $company): array
    {
        $meses = (int) config("planes.ciclos.{$company->ciclo}.meses", 1);

        $desde = $company->suscripcion_hasta && $company->suscripcion_hasta->isFuture()
            ? $company->suscripcion_hasta->copy()->addDay()
            : now()->startOfDay();

        return [$desde, $desde->copy()->addMonths($meses)->subDay()];
    }
}
