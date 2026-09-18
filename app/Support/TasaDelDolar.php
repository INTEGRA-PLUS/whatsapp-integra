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
 *
 * ## Y por qué además se mira la serie, no sólo el día
 *
 * Porque «qué número pongo» no se responde con la TRM de hoy. Si se copia el
 * valor del día, mañana ya está vieja y el freno vuelve a saltar en cuanto el
 * dólar se mueva; y como la tasa fija es lo que el cliente ve en su factura,
 * cambiarla cada semana es exactamente lo que se quería evitar.
 *
 * Se elige mirando **el techo de las últimas semanas**, no el promedio: quedarse
 * por debajo del máximo es cobrar menos de lo que cuesta cada vez que el dólar
 * sube, y el promedio garantiza estar por debajo la mitad de los días.
 *
 * El mismo conjunto de datos guarda el histórico, así que la serie no hay que
 * ir acumulándola día a día: se pide y ya está. Eso también evita duplicar en
 * una tabla propia un dato público del que no somos la fuente.
 */
class TasaDelDolar
{
    /** Cuánto puede separarse antes de que sea un problema, en tanto por ciento. */
    public const DESVIACION_MAXIMA = 8.0;

    /** Cuántos días se miran para proponer una tasa. */
    public const VENTANA = 30;

    /** A qué múltiplo se redondea la propuesta. Un precio se dice en la mesa. */
    public const REDONDEO = 50;

    private const CACHE = 'tasa:trm';

    private const CACHE_SERIE = 'tasa:trm:serie';

    /** Hasta dónde se pide el histórico. Medio año da contexto sin pesar. */
    private const DIAS_DE_HISTORICO = 180;

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

    /**
     * El histórico de la TRM, el más nuevo primero.
     *
     * Cada fila es un anuncio del Banco de la República, no un día del
     * calendario: el viernes trae `hasta` el domingo. Se respeta tal cual
     * —agregarlo a días sueltos inventaría datos que nadie publicó— y por eso
     * `dias` filtra por fecha, no por número de filas.
     *
     * @return list<array{fecha: string, hasta: string, valor: float}>
     */
    public static function serie(int $dias = self::VENTANA, bool $fresca = false): array
    {
        $desde = now()->subDays($dias)->toDateString();

        return array_values(array_filter(
            self::historico($fresca),
            fn (array $fila) => $fila['fecha'] >= $desde
        ));
    }

    /**
     * Mínimo, máximo y promedio de una ventana, o `null` si no hay datos.
     *
     * @return array{dias: int, filas: int, minimo: float, maximo: float, promedio: float, desde: string}|null
     */
    public static function resumen(int $dias = self::VENTANA, bool $fresca = false): ?array
    {
        $serie = self::serie($dias, $fresca);

        if ($serie === []) {
            return null;
        }

        $valores = array_column($serie, 'valor');

        return [
            'dias' => $dias,
            'filas' => count($valores),
            'minimo' => min($valores),
            'maximo' => max($valores),
            'promedio' => round(array_sum($valores) / count($valores), 2),
            'desde' => end($serie)['fecha'],
        ];
    }

    /**
     * Qué tasa poner, mirando las últimas semanas.
     *
     * Tres reglas, en este orden:
     *
     * 1. **Cubrir el techo de la ventana.** Por debajo del máximo reciente se
     *    cobra de menos cada vez que el dólar sube.
     * 2. **Redondear hacia arriba a 50.** Un precio se dice en la mesa; 3.213,97
     *    no es una tasa, es un decimal.
     * 3. **No pasarse del propio freno.** Si el techo quedó muy por encima de
     *    la TRM de hoy —el peso se apreció fuerte—, se baja hasta la mitad del
     *    margen del vigilante. Proponer un número que el día de mañana bloquea
     *    la facturación sería proponer el problema.
     *
     * @return array{tasa: int, techo: float, hoy: float, desviacion: float, aguanta_desde: ?string, ventana: int}|null
     */
    public static function sugerencia(int $dias = self::VENTANA, bool $fresca = false): ?array
    {
        $resumen = self::resumen($dias, $fresca);
        $hoy = self::oficial($fresca);

        if ($resumen === null || ! $hoy) {
            return null;
        }

        $techo = $resumen['maximo'];

        // Media anchura del margen del vigilante: deja sitio para que el dólar
        // se mueva en las dos direcciones sin tocar nada.
        $tope = self::alMultiplo($hoy * (1 + self::DESVIACION_MAXIMA / 200), 'abajo');

        $tasa = min(self::alMultiplo($techo, 'arriba'), $tope);

        return [
            'tasa' => $tasa,
            'techo' => $techo,
            'hoy' => $hoy,
            'desviacion' => round(($tasa - $hoy) / $hoy * 100, 2),
            'aguanta_desde' => self::aguantariaDesde($tasa, $fresca),
            'ventana' => $dias,
        ];
    }

    /**
     * Desde qué fecha hacia acá esta tasa no habría hecho saltar el freno.
     *
     * Es la prueba de que la propuesta no es sólo buena hoy. Se recorre el
     * histórico del más nuevo al más viejo y se para en el primer día que se
     * habría salido de rango.
     */
    public static function aguantariaDesde(int $tasa, bool $fresca = false): ?string
    {
        $aguanta = null;

        foreach (self::historico($fresca) as $fila) {
            if (abs(($tasa - $fila['valor']) / $fila['valor'] * 100) > self::DESVIACION_MAXIMA) {
                break;
            }

            $aguanta = $fila['fecha'];
        }

        return $aguanta;
    }

    /**
     * El histórico crudo, cacheado doce horas como la del día.
     *
     * Se pide una sola vez el medio año entero y las ventanas se recortan de
     * ahí: pedir 30 y 180 por separado son dos formas de fallar en vez de una.
     *
     * @return list<array{fecha: string, hasta: string, valor: float}>
     */
    private static function historico(bool $fresca = false): array
    {
        if ($fresca) {
            Cache::forget(self::CACHE_SERIE);
        }

        return Cache::remember(self::CACHE_SERIE, now()->addHours(12), function () {
            try {
                $respuesta = Http::timeout(20)->get(
                    'https://www.datos.gov.co/resource/32sa-8pi3.json',
                    [
                        '$limit' => 400,
                        '$order' => 'vigenciadesde DESC',
                        '$where' => "vigenciadesde >= '".now()->subDays(self::DIAS_DE_HISTORICO)->toDateString()."T00:00:00'",
                    ]
                );

                $filas = [];

                foreach ((array) $respuesta->json() as $fila) {
                    $valor = (float) ($fila['valor'] ?? 0);
                    $desde = substr((string) ($fila['vigenciadesde'] ?? ''), 0, 10);

                    // Sin fecha no sirve para una serie. Se descarta en vez de
                    // colocarla en un día inventado.
                    if ($valor <= 0 || $desde === '') {
                        continue;
                    }

                    $filas[] = [
                        'fecha' => $desde,
                        'hasta' => substr((string) ($fila['vigenciahasta'] ?? $desde), 0, 10) ?: $desde,
                        'valor' => $valor,
                    ];
                }

                return $filas;
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->warning('⚠️ No se pudo consultar el histórico de la TRM', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /** Al múltiplo de `REDONDEO` más cercano en la dirección que se pida. */
    private static function alMultiplo(float $valor, string $direccion): int
    {
        $pasos = $valor / self::REDONDEO;

        return (int) (($direccion === 'arriba' ? ceil($pasos) : floor($pasos)) * self::REDONDEO);
    }
}
