<?php

namespace App\Console\Commands;

use App\Support\TasaDelDolar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Compara la tasa con la que facturamos contra la TRM oficial, y propone cuál
 * poner.
 *
 * Corre diez minutos antes que `suscripciones:emitir`, y ése es todo el
 * propósito: que si la tasa se quedó vieja, el aviso esté escrito **antes** de
 * que salgan las facturas del día, no después.
 *
 * No cambia la tasa. Esa decisión es de una persona: cambiarla cambia el precio
 * de todos los clientes a la vez, y eso no lo hace un cron a las siete de la
 * mañana. Lo que sí hace es dejar el número ya masticado —con el histórico
 * delante y el redondeo hecho— para que decidirlo no dependa de mirar el dólar
 * en Google.
 */
class VigilarTasa extends Command
{
    protected $signature = 'tasas:vigilar
        {--serie : Enseña el histórico día a día}
        {--dias=30 : Ventana que se mira para proponer la tasa}
        {--fresca : Ignora la caché y vuelve a consultar la TRM}';

    protected $description = 'Avisa si la tasa con la que se factura se separó de la TRM oficial, y propone cuál poner';

    public function handle(): int
    {
        $fresca = (bool) $this->option('fresca');
        $dias = max(7, (int) $this->option('dias'));

        $resumen = TasaDelDolar::comoSeLee($fresca);
        $desviacion = TasaDelDolar::desviacion();

        $this->newLine();
        $this->line('  <options=bold>'.$resumen.'</>');
        $this->newLine();

        $this->comoSeHaMovido($dias);

        if ($this->option('serie')) {
            $this->diaADia($dias);
        }

        $this->quePoner($dias);

        if ($desviacion === null) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo comparar la tasa con la TRM', ['resumen' => $resumen]);
            $this->warn('  Sin TRM no se puede comparar. La facturación sigue: una API caída no puede dejar de cobrar.');
            $this->newLine();

            return self::SUCCESS;
        }

        if (! TasaDelDolar::estaDesviada()) {
            $this->info('  Dentro de lo aceptable (tope '.TasaDelDolar::DESVIACION_MAXIMA.'%).');
            $this->newLine();

            return self::SUCCESS;
        }

        Log::channel('whatsapp')->warning('💱 La tasa de facturación se separó de la TRM', [
            'facturada' => TasaDelDolar::facturada(),
            'oficial' => TasaDelDolar::oficial(),
            'desviacion_pct' => $desviacion,
            'sugerida' => TasaDelDolar::sugerencia($dias)['tasa'] ?? null,
        ]);

        $this->error('  La tasa está fuera de rango: la facturación NO enviará a OnePay hasta que se revise.');
        $this->line('  Ajusta <fg=yellow>PLANES_TASA_COP</> y reinicia, o emite con <fg=yellow>--forzar-tasa</> si es a propósito.');
        $this->newLine();

        // Salida 1 para que el scheduler lo marque como fallo y quede visible en
        // el historial: un aviso que nadie ve es un aviso que no existe.
        return self::FAILURE;
    }

    /** Dos ventanas: la corta para decidir, la larga para no decidir a ciegas. */
    private function comoSeHaMovido(int $dias): void
    {
        foreach ([$dias, 90] as $ventana) {
            $r = TasaDelDolar::resumen($ventana);

            if ($r === null) {
                continue;
            }

            $this->line(sprintf(
                '  Últimos %3d días   mín <fg=cyan>%s</> · máx <fg=cyan>%s</> · promedio %s   <fg=gray>(%d anuncios desde el %s)</>',
                $ventana,
                number_format($r['minimo'], 2),
                number_format($r['maximo'], 2),
                number_format($r['promedio'], 2),
                $r['filas'],
                $this->enEspanol($r['desde']),
            ));
        }

        $this->newLine();
    }

    /**
     * El histórico con una barra por fila.
     *
     * La barra se escala entre el mínimo y el máximo de la ventana, no desde
     * cero: entre 3.048 y 3.214 hay un 5%, y desde cero todas las barras serían
     * la misma y no se vería nada.
     */
    private function diaADia(int $dias): void
    {
        $serie = TasaDelDolar::serie($dias);

        if ($serie === []) {
            return;
        }

        $valores = array_column($serie, 'valor');
        $min = min($valores);
        $recorrido = max($valores) - $min ?: 1;

        foreach ($serie as $fila) {
            $ancho = (int) round(($fila['valor'] - $min) / $recorrido * 28);

            $this->line(sprintf(
                '  %s  %s  <fg=cyan>%s</>%s',
                $this->enEspanol($fila['fecha']),
                number_format($fila['valor'], 2),
                str_repeat('▁', max(1, $ancho)),
                $fila['hasta'] !== $fila['fecha'] ? ' <fg=gray>(hasta el '.$this->enEspanol($fila['hasta']).')</>' : '',
            ));
        }

        $this->newLine();
    }

    /** El número, el porqué y la línea lista para pegar. */
    private function quePoner(int $dias): void
    {
        $s = TasaDelDolar::sugerencia($dias);

        if ($s === null) {
            return;
        }

        if ($s['tasa'] === TasaDelDolar::facturada()) {
            $this->line('  <fg=green>La tasa puesta es justo la que se propondría hoy.</> Nada que tocar.');
            $this->newLine();

            return;
        }

        $this->line('  <options=bold;fg=yellow>Sugerencia: '.number_format($s['tasa']).' COP</>');
        $this->line(sprintf(
            '    Cubre el techo de los últimos %d días (%s), redondeado a %d hacia arriba.',
            $s['ventana'],
            number_format($s['techo'], 2),
            TasaDelDolar::REDONDEO,
        ));
        $this->line(sprintf(
            '    Hoy quedaría %s%% por %s de la TRM: dentro del %s%% del vigilante.',
            abs($s['desviacion']),
            $s['desviacion'] >= 0 ? 'encima' : 'debajo',
            TasaDelDolar::DESVIACION_MAXIMA,
        ));

        if ($s['aguanta_desde'] !== null) {
            $this->line('    Con esa tasa el freno no habría saltado desde el '.$this->enEspanol($s['aguanta_desde']).'.');
        }

        $this->newLine();
        $this->line('    <fg=yellow>PLANES_TASA_COP='.$s['tasa'].'</>  <fg=gray>← en .env.docker, y reiniciar</>');
        $this->newLine();
    }

    private function enEspanol(string $fecha): string
    {
        return Carbon::parse($fecha)->locale('es')->isoFormat('D MMM YYYY');
    }
}
