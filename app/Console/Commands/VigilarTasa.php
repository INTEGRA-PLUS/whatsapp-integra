<?php

namespace App\Console\Commands;

use App\Support\TasaDelDolar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Compara la tasa con la que facturamos contra la TRM oficial.
 *
 * Corre diez minutos antes que `suscripciones:emitir`, y ése es todo el
 * propósito: que si la tasa se quedó vieja, el aviso esté escrito **antes** de
 * que salgan las facturas del día, no después.
 *
 * No cambia la tasa. Esa decisión es de una persona: cambiarla cambia el precio
 * de todos los clientes a la vez, y eso no lo hace un cron a las siete de la
 * mañana.
 */
class VigilarTasa extends Command
{
    protected $signature = 'tasas:vigilar {--fresca : Ignora la caché y vuelve a consultar la TRM}';

    protected $description = 'Avisa si la tasa con la que se factura se separó de la TRM oficial';

    public function handle(): int
    {
        $fresca = (bool) $this->option('fresca');
        $resumen = TasaDelDolar::comoSeLee($fresca);
        $desviacion = TasaDelDolar::desviacion();

        $this->line($resumen);

        if ($desviacion === null) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo comparar la tasa con la TRM', ['resumen' => $resumen]);
            $this->warn('Sin TRM no se puede comparar. La facturación sigue: una API caída no puede dejar de cobrar.');

            return self::SUCCESS;
        }

        if (! TasaDelDolar::estaDesviada()) {
            $this->info('Dentro de lo aceptable (tope '.TasaDelDolar::DESVIACION_MAXIMA.'%).');

            return self::SUCCESS;
        }

        Log::channel('whatsapp')->warning('💱 La tasa de facturación se separó de la TRM', [
            'facturada' => TasaDelDolar::facturada(),
            'oficial' => TasaDelDolar::oficial(),
            'desviacion_pct' => $desviacion,
        ]);

        $this->newLine();
        $this->error('La tasa está fuera de rango: la facturación NO enviará a OnePay hasta que se revise.');
        $this->line('  Ajusta <fg=yellow>PLANES_TASA_COP</> en .env.docker y reinicia, o emite con <fg=yellow>--forzar-tasa</> si es a propósito.');

        // Salida 1 para que el scheduler lo marque como fallo y quede visible en
        // el historial: un aviso que nadie ve es un aviso que no existe.
        return self::FAILURE;
    }
}
