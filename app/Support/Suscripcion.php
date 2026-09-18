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

        // Al cliente de Integra sin complemento se le emite igual su recibo, en
        // cero y marcado como cubierto: paga el CRM dentro de su ERP, así que
        // hay servicio prestado y tiene que quedar constancia. Lo que no hay es
        // nada que cobrar, y por eso no es un «pendiente» — un pendiente en cero
        // se quedaría ahí para siempre esperando un pago que nadie va a hacer.
        $cubierto = $plan->cubiertoPorIntegra();

        $cobro = SuscripcionCobro::create([
            'company_id' => $company->id,
            // Copia, no referencia: los precios cambian y el recibo tiene que
            // decir lo que se cobró.
            'plan' => $plan->slug(),
            'ia' => $plan->slugIa(),
            'ciclo' => $plan->ciclo(),
            'importe_usd' => $cubierto ? 0 : $plan->precioDelCiclo(),
            'periodo_desde' => $desde,
            'periodo_hasta' => $hasta,
            'estado' => $cubierto ? SuscripcionCobro::CUBIERTO : SuscripcionCobro::PENDIENTE,
            // Un cubierto nace con su periodo ya cerrado: no hay pago que
            // esperar, así que la fecha es la de emisión y no la de un cobro
            // que nunca va a llegar.
            'pagado_at' => $cubierto ? now() : null,
            'nota' => $nota,
            'creado_por' => $creadoPor,
        ]);

        // El cubierto avanza el periodo en el acto. No hay pago que esperar, y
        // dejarlo sin avanzar dejaría a todo cliente de Integra apareciendo como
        // vencido mes tras mes mientras paga religiosamente por su ERP.
        if ($cobro->estaCubierto()
            && (! $company->suscripcion_hasta || $hasta->greaterThan($company->suscripcion_hasta))) {
            $company->update(['suscripcion_hasta' => $hasta]);
        }

        return $cobro;
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
        // Un cubierto ya nació saldado por el paquete de Integra. Pagarlo
        // otra vez alargaría el periodo por segunda vez, que es el error que
        // este método lleva evitando desde el primer día con las referencias
        // repetidas de la pasarela.
        if ($cobro->estaPagado() || $cobro->estaCubierto()) {
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
     * ## Y el del cliente de Integra va del 15 al 15
     *
     * Porque su CRM lo paga su ERP, y el ERP le factura el 15. Su periodo aquí
     * empieza y acaba el mismo día para que sea **el mismo mes** del que habla
     * su factura de Integra: «del 15 de septiembre al 15 de octubre» es una
     * frase que el cliente puede cotejar; «del 18 de septiembre al 15 de
     * octubre» no cuadra con nada y además parece un mes recortado.
     *
     * Para el primero se va al 15 **anterior**, aunque quede unos días atrás.
     * Arrancarlo hoy dejaría un periodo corto y una frase rara para siempre; ir
     * al 15 siguiente dejaría sin cubrir los días de en medio.
     *
     * El 15 es a la vez el final de un periodo y el principio del siguiente, y
     * es a propósito: así se lee en una factura. Quien busque qué periodo cubre
     * un día concreto tiene que quedarse con el más nuevo, que es lo que hace
     * `MiPlanController::periodoCubierto()`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function proximoPeriodo(Company $company): array
    {
        $meses = (int) config("planes.ciclos.{$company->ciclo}.meses", 1);

        $vigente = $company->suscripcion_hasta && $company->suscripcion_hasta->isFuture();

        if (PlanDeLaEmpresa::de($company)->incluidoEnIntegra()) {
            // Arranca donde acabó el anterior —el mismo 15— o en el último que
            // haya pasado si es el primero que se le emite.
            $desde = $vigente ? $company->suscripcion_hasta->copy() : self::ultimoDiaDeCorte();

            return [$desde, $desde->copy()->addMonthsNoOverflow($meses)];
        }

        // Al cliente directo no se le mueve la fecha: la suya arranca cuando
        // paga, y empujarla a un día fijo sería regalarle o quitarle días que sí
        // se le facturan.
        $desde = $vigente ? $company->suscripcion_hasta->copy()->addDay() : now()->startOfDay();

        return [$desde, $desde->copy()->addMonths($meses)->subDay()];
    }

    /** El último día de corte que ya pasó, hoy incluido. */
    private static function ultimoDiaDeCorte(): Carbon
    {
        $dia = (int) config('planes.dia_de_corte_integra', 15);

        $hoy = now()->startOfDay();
        $corte = $hoy->copy()->day($dia);

        return $corte->greaterThan($hoy) ? $corte->subMonthNoOverflow()->day($dia) : $corte;
    }
}
