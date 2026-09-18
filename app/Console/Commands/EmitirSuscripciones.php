<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use App\Services\OnePayClient;
use App\Support\PlanDeLaEmpresa;
use App\Support\TasaDelDolar;
use Illuminate\Support\Facades\Log;
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
 * **Sí manda a OnePay** desde el 18-sep-2026. Antes no: emitir dejaba la fila
 * pendiente y alguien la enviaba a mano desde el panel. Con la pasarela en
 * producción, separarlos sólo conseguía que el cobro existiera sin que el
 * cliente lo viera.
 *
 * Y sí se programa, desde la misma fecha y por el mismo motivo: el comentario
 * que había aquí decía «no se programa a propósito… cuando esté la pasarela se
 * decide si se automatiza». Ya está.
 *
 * ## Dos clases de emisión
 *
 * - **Cobrable** — hay un importe. Queda `pendiente` y se manda a OnePay.
 * - **Cubierto por Integra** — el CRM va dentro de su ERP. Se emite igual, en
 *   cero y en estado `cubierto`, y **no se manda a ninguna pasarela**: no hay
 *   nada que cobrar. Existe para que el cliente tenga constancia del servicio y
 *   para que se vea a quién se le podría vender el complemento de IA.
 *
 * Lo que no entra en ninguna de las dos: interna, cortesía, prueba y mes gratis.
 */
class EmitirSuscripciones extends Command
{
    protected $signature = 'suscripciones:emitir
        {--dry : Enseña lo que emitiría y no crea nada}
        {--dias=7 : Cuántos días antes del vencimiento se emite}
        {--company= : Sólo esta empresa (id)}
        {--forzar-tasa : Emite aunque la tasa se haya separado de la TRM}';

    protected $description = 'Emite los cobros pendientes de las suscripciones que vencen pronto';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');

        // La tasa vieja no se descubre revisando: se descubre cuando el cliente
        // reclama que le cobraron de más. El 18-sep-2026 estaba en 4.000 con el
        // dólar a 3.151: un 27% de más sobre cuarenta y dos recibos.
        //
        // Se para ANTES de emitir nada, no a mitad: cuarenta recibos mal son
        // cuarenta conversaciones incómodas y una nota de crédito por cada uno.
        if (! $this->option('dry') && ! $this->option('forzar-tasa') && TasaDelDolar::estaDesviada()) {
            $this->error('Facturación detenida: la tasa se separó de la TRM oficial.');
            $this->line('  '.TasaDelDolar::comoSeLee());
            $this->line('  Ajusta <fg=yellow>PLANES_TASA_COP</> o repite con <fg=yellow>--forzar-tasa</> si es a propósito.');

            Log::channel('whatsapp')->warning('💱 Emisión de suscripciones detenida por la tasa', [
                'facturada' => TasaDelDolar::facturada(),
                'oficial' => TasaDelDolar::oficial(),
                'desviacion_pct' => TasaDelDolar::desviacion(),
            ]);

            return self::FAILURE;
        }

        $empresas = Company::query()
            ->where('interna', false)
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get();

        $filas = [];
        $emitidos = 0;

        foreach ($empresas as $company) {
            $plan = PlanDeLaEmpresa::de($company);

            // Cortesía, prueba y mes gratis se quedan fuera de las dos clases:
            // no se les cobra Y no se les debe nada por otra vía, así que un
            // recibo suyo no diría nada cierto.
            if (! $plan->seFactura() && ! $plan->cubiertoPorIntegra()) {
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
                $filas[] = [$company->name, '—', '—', '—', 'ya tiene uno pendiente'];

                continue;
            }

            [$desde, $hasta] = Suscripcion::proximoPeriodo($company);

            $cubierto = $plan->cubiertoPorIntegra();
            $enviado = null;

            if (! $this->option('dry')) {
                $cobro = Suscripcion::emitir($company);
                $emitidos++;

                // A la pasarela sólo lo que tiene algo que cobrar. Un cubierto
                // en cero reventaría el mínimo de OnePay (5.000 pesos) y, sobre
                // todo, le pondría al cliente delante una factura de un dinero
                // que no debe.
                if ($cobro->hayQueCobrarlo()) {
                    $enviado = OnePayClient::crearFactura($cobro);
                }
            }

            $filas[] = [
                $company->name,
                $cubierto ? 'incluido' : '$'.$plan->precioDelCiclo(),
                mb_strtolower($plan->nombreCiclo()),
                $desde->format('d-M').' a '.$hasta->format('d-M'),
                match (true) {
                    $cubierto => 'cubierto por Integra',
                    $enviado === true => 'en OnePay',
                    $enviado === false => '⚠️ no entró en OnePay',
                    default => 'pendiente',
                },
            ];
        }

        if ($filas === []) {
            $this->info('Ninguna suscripción vence en los próximos '.$dias.' días.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'Importe', 'Ciclo', 'Periodo', 'Estado'], $filas);

        $this->newLine();
        $this->line($this->option('dry')
            ? 'Con --dry no se ha creado nada.'
            : "Emitidos {$emitidos} cobros, en estado pendiente.");

        $this->line('<fg=gray>Emitir no cobra: lo que alarga la suscripción es que OnePay confirme. Los cubiertos por Integra ya nacen saldados.</>');

        return self::SUCCESS;
    }
}
