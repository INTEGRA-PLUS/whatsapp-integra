<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Realinear los periodos ya emitidos con el día de corte.
 *
 * Lo que se protege: que no cambie **lo que se cobró**. El comando mueve el
 * arranque y nada más; el final y el importe son el recibo, y un recibo no se
 * reescribe con un comando.
 */
class RealinearPeriodosTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function mueve_el_arranque_al_dia_de_corte(): void
    {
        $company = $this->deIntegra();
        $cobro = $this->cobro($company, '2026-09-18', '2026-10-15');

        $this->artisan('suscripciones:realinear')->assertSuccessful();

        $this->assertSame('2026-09-15', $cobro->refresh()->periodo_desde->toDateString());
        $this->assertSame('2026-10-15', $cobro->periodo_hasta->toDateString(), 'El final no se toca.');
    }

    /** Con --dry no se mueve nada. */
    public function test_el_dry_no_toca_nada(): void
    {
        $cobro = $this->cobro($this->deIntegra(), '2026-09-18', '2026-10-15');

        $this->artisan('suscripciones:realinear --dry')->assertSuccessful();

        $this->assertSame('2026-09-18', $cobro->refresh()->periodo_desde->toDateString());
    }

    /**
     * Un recibo que no acaba en el corte se deja como está.
     *
     * Viene de otra regla, y moverle el arranque lo dejaría con una duración
     * distinta de la que se le cobró.
     *
     * @test
     */
    public function no_toca_el_que_acaba_otro_dia(): void
    {
        $cobro = $this->cobro($this->deIntegra(), '2026-09-18', '2026-10-17');

        $this->artisan('suscripciones:realinear')->assertSuccessful();

        $this->assertSame('2026-09-18', $cobro->refresh()->periodo_desde->toDateString());
    }

    /** Y al cliente directo no se le toca: su periodo arranca cuando paga. */
    public function test_no_toca_al_cliente_directo(): void
    {
        $company = Company::create([
            'name' => 'Directa', 'slug' => 'directa', 'active' => true,
            'plan' => 'pro', 'cobro' => 'activo',
        ]);
        $cobro = $this->cobro($company, '2026-09-18', '2026-10-15');

        $this->artisan('suscripciones:realinear')->assertSuccessful();

        $this->assertSame('2026-09-18', $cobro->refresh()->periodo_desde->toDateString());
    }

    /** Un anulado no cubre nada, así que no hay periodo que alinear. */
    public function test_no_toca_los_anulados(): void
    {
        $cobro = $this->cobro($this->deIntegra(), '2026-09-18', '2026-10-15', 'anulado');

        $this->artisan('suscripciones:realinear')->assertSuccessful();

        $this->assertSame('2026-09-18', $cobro->refresh()->periodo_desde->toDateString());
    }

    private function deIntegra(): Company
    {
        return Company::create([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
            'plan' => 'pro',
            'viene_de_integra' => true,
        ]);
    }

    private function cobro(Company $company, string $desde, string $hasta, string $estado = 'cubierto'): SuscripcionCobro
    {
        return SuscripcionCobro::create([
            'company_id' => $company->id,
            'plan' => 'pro',
            'ia' => 'ninguno',
            'ciclo' => 'mensual',
            'importe_usd' => 0,
            'periodo_desde' => $desde,
            'periodo_hasta' => $hasta,
            'estado' => $estado,
        ]);
    }
}
