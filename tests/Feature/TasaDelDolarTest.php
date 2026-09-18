<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use App\Support\TasaDelDolar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El vigilante de la tasa con la que se factura.
 *
 * La tasa sigue siendo fija y puesta a mano —una tasa viva haría que el mismo
 * plan costara distinto cada mes sin que nadie lo decida— pero una tasa fija
 * que nadie mira deja de ser estabilidad y pasa a ser un precio equivocado.
 *
 * El 18-sep-2026 estaba en 4.000 con el dólar a 3.151: un 27% de más sobre
 * cuarenta y dos recibos a punto de salir.
 */
class TasaDelDolarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @test */
    public function detecta_que_cobramos_de_mas(): void
    {
        $this->trmEs(3151.73);
        config(['planes.tasa_cop' => 4000]);

        $this->assertEqualsWithDelta(26.91, TasaDelDolar::desviacion(), 0.01);
        $this->assertTrue(TasaDelDolar::estaDesviada(), 'Un 27% de más tiene que parar la facturación.');
        $this->assertStringContainsString('de MÁS', TasaDelDolar::comoSeLee());
    }

    /** Y una diferencia pequeña no para nada: el dólar se mueve todos los días. */
    public function test_una_diferencia_pequena_no_bloquea(): void
    {
        $this->trmEs(3900);
        config(['planes.tasa_cop' => 4000]);

        $this->assertFalse(TasaDelDolar::estaDesviada());
    }

    /**
     * Si la TRM no se puede consultar, NO se bloquea.
     *
     * Una API caída no puede dejar a la empresa sin cobrar un mes. Ante la duda
     * se factura con lo que hay y se deja dicho que no se pudo comparar.
     *
     * @test
     */
    public function sin_trm_no_se_bloquea_la_facturacion(): void
    {
        Http::fake(['datos.gov.co/*' => Http::response('', 500)]);

        $this->assertNull(TasaDelDolar::desviacion());
        $this->assertFalse(TasaDelDolar::estaDesviada());
        $this->assertStringContainsString('No se pudo consultar', TasaDelDolar::comoSeLee());
    }

    /** El comando se queja con salida de error, para que el scheduler lo marque. */
    public function test_el_comando_falla_cuando_la_tasa_esta_vieja(): void
    {
        $this->trmEs(3151.73);
        config(['planes.tasa_cop' => 4000]);

        $this->artisan('tasas:vigilar')
            ->expectsOutputToContain('26.91')
            ->assertFailed();
    }

    /** Y la emisión se para antes de crear nada. */
    public function test_la_emision_se_detiene_con_la_tasa_vieja(): void
    {
        $this->trmEs(3151.73);
        config(['planes.tasa_cop' => 4000]);

        $this->artisan('suscripciones:emitir')
            ->expectsOutputToContain('Facturación detenida')
            ->assertFailed();

        $this->assertSame(0, \App\Models\SuscripcionCobro::count(), 'Ni un recibo con la tasa mal.');
    }

    /**
     * La propuesta cubre el techo de la ventana, no el promedio.
     *
     * Quedarse en el promedio es cobrar de menos la mitad de los días. Y 3.213,97
     * no es una tasa: se redondea a 50 hacia arriba porque un precio se dice en
     * la mesa.
     *
     * @test
     */
    public function propone_una_tasa_que_cubre_el_techo_y_esta_redondeada(): void
    {
        $this->trmHaSido([
            $this->haceDias(0) => 3151.73,
            $this->haceDias(3) => 3109.30,
            $this->haceDias(17) => 3213.97,
            $this->haceDias(27) => 3048.12,
        ]);

        $s = TasaDelDolar::sugerencia(30);

        $this->assertSame(3250, $s['tasa']);
        $this->assertEqualsWithDelta(3213.97, $s['techo'], 0.01);
        $this->assertLessThan(TasaDelDolar::DESVIACION_MAXIMA, abs($s['desviacion']));
    }

    /**
     * Y nunca propone un número que mañana haría saltar su propio freno.
     *
     * Si el peso se apreció fuerte, el techo del mes pasado está muy por encima
     * de hoy: copiarlo dejaría la facturación bloqueada al día siguiente de
     * cambiarla. Se baja hasta la mitad del margen del vigilante.
     *
     * @test
     */
    public function no_propone_una_tasa_que_bloquearia_la_facturacion_manana(): void
    {
        $this->trmHaSido([
            $this->haceDias(0) => 3000.00,
            $this->haceDias(24) => 3800.00,
        ]);

        $s = TasaDelDolar::sugerencia(30);

        $this->assertSame(3100, $s['tasa'], 'El techo era 3.800: hay que bajarlo al margen del vigilante.');
        $this->assertLessThanOrEqual(TasaDelDolar::DESVIACION_MAXIMA / 2, $s['desviacion']);
    }

    /** Desde cuándo habría aguantado: la prueba de que no sirve sólo para hoy. */
    public function test_dice_desde_cuando_esa_tasa_habria_aguantado(): void
    {
        $this->trmHaSido([
            $this->haceDias(0) => 3151.73,
            $this->haceDias(8) => 3100.00,
            $this->haceDias(17) => 3213.97,
            // Aquí el dólar estaba un 20% más arriba: el freno habría saltado.
            $this->haceDias(29) => 3900.00,
        ]);

        $this->assertSame($this->haceDias(17), TasaDelDolar::aguantariaDesde(3250));
    }

    /** La serie respeta los anuncios tal cual: el viernes cubre el fin de semana. */
    public function test_la_serie_conserva_el_rango_de_vigencia(): void
    {
        $this->trmHaSido(
            [$this->haceDias(6) => 3072.27],
            [$this->haceDias(6) => $this->haceDias(4)],
        );

        $serie = TasaDelDolar::serie(30);

        $this->assertSame($this->haceDias(6), $serie[0]['fecha']);
        $this->assertSame($this->haceDias(4), $serie[0]['hasta']);
    }

    /** Y el comando deja la línea lista para pegar, sin tener que calcular nada. */
    public function test_el_comando_dice_que_poner_en_el_env(): void
    {
        config(['planes.tasa_cop' => 4000]);
        $this->trmHaSido([
            $this->haceDias(0) => 3151.73,
            $this->haceDias(17) => 3213.97,
        ]);

        $this->artisan('tasas:vigilar --serie')
            ->expectsOutputToContain('PLANES_TASA_COP=3250')
            ->assertFailed();
    }

    private function haceDias(int $dias): string
    {
        return now()->subDays($dias)->toDateString();
    }

    private function trmEs(float $valor): void
    {
        Http::fake(['datos.gov.co/*' => Http::response([['valor' => (string) $valor]], 200)]);
    }

    /**
     * Un histórico falso, `fecha => valor`. La primera es la de hoy.
     *
     * El mismo endpoint sirve el día suelto y la serie; se distinguen por el
     * `$where`, que es lo único que cambia entre las dos llamadas.
     *
     * @param  array<string, float>  $valores
     * @param  array<string, string>  $hasta
     */
    private function trmHaSido(array $valores, array $hasta = []): void
    {
        $filas = [];

        foreach ($valores as $fecha => $valor) {
            $filas[] = [
                'valor' => (string) $valor,
                'unidad' => 'COP',
                'vigenciadesde' => $fecha.'T00:00:00.000',
                'vigenciahasta' => ($hasta[$fecha] ?? $fecha).'T00:00:00.000',
            ];
        }

        Http::fake(function ($request) use ($filas) {
            $esLaSerie = str_contains(urldecode($request->url()), 'vigenciadesde >=');

            return Http::response($esLaSerie ? $filas : [$filas[0]], 200);
        });
    }

    /**
     * Pero `--sin-onepay` no lo frena: el freno guarda la pasarela.
     *
     * Lo que se convierte a pesos es la factura de OnePay; la fila sólo guarda
     * dólares. Sin envío no hay nada que la tasa pueda estropear, y bloquear ahí
     * dejaría sin emitir unos recibos que no cobran nada.
     *
     * @test
     */
    public function sin_enviar_a_la_pasarela_la_tasa_no_frena_nada(): void
    {
        $this->trmEs(3151.73);
        config(['planes.tasa_cop' => 4000]);

        $company = Company::create([
            'name' => 'Fibra del Sur',
            'slug' => 'fibra-del-sur',
            'active' => true,
            'plan' => 'pro',
            'cobro' => 'activo',
        ]);

        $this->artisan('suscripciones:emitir --sin-onepay')
            ->expectsOutputToContain('No se envió nada a OnePay')
            ->assertSuccessful();

        $cobro = SuscripcionCobro::where('company_id', $company->id)->first();

        $this->assertNotNull($cobro, 'El recibo se emite igual.');
        $this->assertSame(SuscripcionCobro::PENDIENTE, $cobro->estado);
        $this->assertNull($cobro->referencia_onepay, 'Y no pasó por la pasarela.');
    }
}
