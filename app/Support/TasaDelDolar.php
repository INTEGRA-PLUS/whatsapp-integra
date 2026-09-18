<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La tasa con la que se factura, y cuánto se ha separado de la real.
 *
 * ## Por qué la tasa sigue siendo fija
 *
 * Porque cobrar con una tasa viva hace que el mismo plan cueste distinto cada
 * mes sin que nadie lo decida, que el cliente vea un importe que no cuadra con
 * lo que se le dijo, y que conciliar dos facturas seguidas sea arqueología. Esa
 * decisión no cambia: **se factura con `planes.tasa_cop`**.
 *
 * ## Por qué hace falta vigilarla
 *
 * Porque una tasa fija que nadie mira deja de ser estabilidad y pasa a ser un
 * precio equivocado. El 18-sep-2026 estaba en 4.000 y el dólar a 3.209: un 25%
 * de más. A Megastore le habrían llegado 196.000 COP por 49 dólares que valen
 * 157.243.
 *
 * Así que la tasa se sigue poniendo a mano —una persona decide cuándo cambia el
 * precio— pero se compara a diario con la real, y si se separa demasiado la
 * facturación **se para** en vez de emitir cuarenta y dos recibos mal.
 *
 * ## De dónde sale la real
 *
 * De la TRM oficial que publica el Estado colombiano en datos.gov.co. Es la que
 * vale para facturar aquí: no es «lo que dice Google», es el número con el que
 * la DIAN convierte. Si la consulta falla, se dice y no se bloquea nada — una
 * API caída no puede dejar sin cobrar a la empresa.
 */
class TasaDelDolar
{
    /** Cuánto puede separarse antes de que sea un problema, en tanto por ciento. */
    public const DESVIACION_MAXIMA = 8.0;

    private const CACHE = 'tasa:trm';

    /** Lo que se cobra hoy. La decide una persona, no una API. */
    public static function facturada(): int
    {
        return (int) config('planes.tasa_cop');
    }

    /**
     * La TRM oficial de hoy, o `null` si no se pudo consultar.
     *
     * Cacheada doce horas: la TRM cambia una vez al día y consultarla en cada
     * comprobación sólo añade una forma de fallar.
     */
    public static function oficial(bool $fresca = false): ?float
    {
        if ($fresca) {
            Cache::forget(self::CACHE);
        }

        return Cache::remember(self::CACHE, now()->addHours(12), function () {
            try {
                $respuesta = Http::timeout(15)->get(
                    'https://www.datos.gov.co/resource/32sa-8pi3.json',
                    ['$limit' => 1, '$order' => 'vigenciadesde DESC']
                );

                $valor = (float) ($respuesta->json('0.valor') ?? 0);

                return $valor > 0 ? $valor : null;
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->warning('⚠️ No se pudo consultar la TRM', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Cuánto se separa la que cobramos de la real, en por ciento.
     *
     * Positivo = estamos cobrando de más. `null` = no se pudo comparar.
     */
    public static function desviacion(bool $fresca = false): ?float
    {
        $oficial = self::oficial($fresca);

        if (! $oficial) {
            return null;
        }

        return round((self::facturada() - $oficial) / $oficial * 100, 2);
    }

    /**
     * ¿Se separó lo bastante como para no facturar hasta que alguien mire?
     *
     * Ante la duda NO bloquea: si la TRM no se pudo consultar, se factura con lo
     * que hay. Una API caída no puede dejar a la empresa sin cobrar un mes.
     */
    public static function estaDesviada(bool $fresca = false): bool
    {
        $desviacion = self::desviacion($fresca);

        return $desviacion !== null && abs($desviacion) > self::DESVIACION_MAXIMA;
    }

    /** La frase que resume la situación, para el log y para la consola. */
    public static function comoSeLee(bool $fresca = false): string
    {
        $oficial = self::oficial($fresca);

        if (! $oficial) {
            return 'Facturando a '.number_format(self::facturada()).' COP. No se pudo consultar la TRM oficial.';
        }

        $desviacion = self::desviacion($fresca);
        $signo = $desviacion > 0 ? 'de MÁS' : 'de MENOS';

        return 'Facturando a '.number_format(self::facturada()).' COP'
            .' · TRM oficial '.number_format($oficial, 2).' COP'
            .' · '.abs($desviacion).'% '.$signo;
    }
}
