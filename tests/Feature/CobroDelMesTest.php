<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Support\CobroDelMes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La lista de a quién cobrarle este mes.
 *
 * Lo que se protege aquí no es un cálculo: es que **no aparezca en una factura
 * quien no debe**. De las 55 empresas del sistema, siete son nuestras y tres de
 * ellas tienen contactos de verdad, así que no se distinguen por estar vacías.
 * La primera vez que se exporte esta lista se la lleva alguien a facturación, y
 * de ahí sale un cobro a un cliente real.
 */
class CobroDelMesTest extends TestCase
{
    use RefreshDatabase;

    /** Una empresa nuestra no se factura aunque tenga el cobro activo. */
    public function test_las_internas_nunca_entran_en_la_factura(): void
    {
        $this->empresa('PRUEBAS', ['cobro' => 'activo', 'interna' => true, 'contactos_contratados' => 2000]);

        $datos = CobroDelMes::calcular();

        $this->assertSame([], array_column($datos['cobrar'], 'empresa'));
        $this->assertSame(0, $datos['total_usd']);
        $this->assertSame('interna', $datos['fuera'][0]['motivo']);
    }

    /**
     * Y se reporta como interna, no como cortesía, aunque tenga las dos cosas.
     *
     * El motivo es lo que explica por qué no paga: si saliera como «cortesía»,
     * alguien la pasaría a activo en la siguiente revisión creyendo que se
     * quedó a medias de la transición, y entraría a la factura.
     */
    public function test_interna_gana_a_cortesia_al_explicar_por_que_no_paga(): void
    {
        $this->empresa('Master Admin', ['cobro' => 'cortesia', 'interna' => true]);

        $this->assertSame('interna', CobroDelMes::calcular()['fuera'][0]['motivo']);
    }

    /**
     * Un cliente de Integra no se factura, y se reporta como tal.
     *
     * Es el caso que más dinero movía en el panel: la cuenta de «facturación
     * potencial» daba 2.391 USD/mes sobre 41 clientes, y casi todos ya pagaban
     * —el CRM iba dentro de lo que compraron con el ERP—. La cifra era
     * ficticia.
     */
    public function test_el_cliente_de_integra_no_se_factura(): void
    {
        $this->empresa('ISP con Integra', [
            'viene_de_integra' => true,
            'cobro' => 'integra',
            'plan' => 'esencial',
            'contactos_contratados' => 2000,
        ]);

        $datos = CobroDelMes::calcular();

        $this->assertSame([], $datos['cobrar']);
        $this->assertSame(0, $datos['total_usd']);
        $this->assertSame('integra', $datos['fuera'][0]['motivo']);
    }

    /**
     * Y se distingue de cortesía aunque la columna diga cortesía.
     *
     * Son las dos formas de «aquí no se le factura» y significan cosas
     * opuestas: Integra es un cliente que paga, cortesía es uno al que todavía
     * no se le cobra. Si Integra apareciera como cortesía, alguien la pasaría a
     * activo en la siguiente revisión y le cobraría dos veces el mismo CRM.
     */
    public function test_integra_no_se_confunde_con_cortesia(): void
    {
        $this->empresa('Marcada pero sin estado', [
            'viene_de_integra' => true,
            'cobro' => 'cortesia',
        ]);

        $this->assertSame('integra', CobroDelMes::calcular()['fuera'][0]['motivo']);
    }

    /** La transición: en cortesía no se factura, y se dice por qué. */
    public function test_cortesia_y_mes_gratis_quedan_fuera_por_motivos_distintos(): void
    {
        $this->empresa('En transición', ['cobro' => 'cortesia']);
        $this->empresa('Con mes gratis', [
            'cobro' => 'activo',
            'gratis_hasta' => now()->addDays(20)->toDateString(),
            'contactos_contratados' => 500,
        ]);

        $motivos = array_column(CobroDelMes::calcular()['fuera'], 'motivo', 'empresa');

        $this->assertSame('cortesia', $motivos['En transición']);
        $this->assertSame('mes_gratis', $motivos['Con mes gratis']);
    }

    /** Un cliente activo sí sale, con el precio de su tramo y su plan. */
    public function test_el_cliente_activo_sale_con_el_precio_de_su_tramo(): void
    {
        $this->empresa('Fibra Sur', [
            'cobro' => 'activo',
            'plan' => 'esencial',
            'contactos_contratados' => 2000,
        ]);

        $datos = CobroDelMes::calcular();
        $fila = $datos['cobrar'][0];

        $this->assertSame('Fibra Sur', $fila['empresa']);
        $this->assertSame(config('planes.precios.2000.esencial'), $fila['usd']);
        $this->assertSame(config('planes.precios.2000.esencial'), $datos['total_usd']);
    }

    /**
     * Un cliente activo sin tramo sale igual, marcado, y no suma.
     *
     * Es a quien nadie le puso precio. Si desapareciera de la lista, dejaría de
     * cobrársele sin que nadie lo note — que es exactamente lo que pasa hoy con
     * las 55, sólo que a propósito.
     */
    public function test_el_activo_sin_tramo_sale_a_cotizar_y_no_suma(): void
    {
        $this->empresa('Sin precio puesto', ['cobro' => 'activo']);

        $datos = CobroDelMes::calcular();

        $this->assertCount(1, $datos['cobrar']);
        $this->assertNull($datos['cobrar'][0]['usd']);
        $this->assertSame(1, $datos['sin_tramo']);
        $this->assertSame(0, $datos['total_usd']);
    }

    /**
     * Suspendido se factura: es quien no ha pagado, no quien no debe.
     *
     * Perdonarle la factura al contarlo haría que el total enseñara menos
     * ingreso pendiente del que hay, que es justo el número por el que alguien
     * mira esta pantalla.
     */
    public function test_el_suspendido_se_sigue_facturando(): void
    {
        $this->empresa('Debe dos meses', [
            'cobro' => 'suspendido',
            'plan' => 'esencial',
            'contactos_contratados' => 500,
        ]);

        $this->assertCount(1, CobroDelMes::calcular()['cobrar']);
    }

    /** Un `;` en el nombre de una empresa partiría la fila del CSV en dos. */
    public function test_el_csv_no_se_parte_con_un_punto_y_coma_en_el_nombre(): void
    {
        $this->empresa('Redes; Cables y Más', [
            'cobro' => 'activo',
            'plan' => 'esencial',
            'contactos_contratados' => 500,
        ]);

        $fila = collect(explode("\r\n", CobroDelMes::csv()))
            ->first(fn (string $l) => str_contains($l, 'Redes'));

        $this->assertSame(8, substr_count($fila, ';') + 1, 'La fila tiene que tener las ocho columnas de la cabecera.');
    }

    private function empresa(string $nombre, array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => $nombre,
            'slug' => Str::slug($nombre),
            'email' => Str::slug($nombre).'@x.test',
            'active' => true,
        ], $extra));
    }
}
