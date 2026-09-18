<?php

namespace Tests\Feature;

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

    private function trmEs(float $valor): void
    {
        Http::fake(['datos.gov.co/*' => Http::response([['valor' => (string) $valor]], 200)]);
    }
}
