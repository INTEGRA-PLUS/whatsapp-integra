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
        $this->empresa('PRUEBAS', ['cobro' => 'activo', 'interna' => true, 'plan' => 'pro']);

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
            'plan' => 'pro',
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
            'plan' => 'basico',
        ]);

        $motivos = array_column(CobroDelMes::calcular()['fuera'], 'motivo', 'empresa');

        $this->assertSame('cortesia', $motivos['En transición']);
        $this->assertSame('mes_gratis', $motivos['Con mes gratis']);
    }

    /** Un cliente activo sale con el precio fijo de su plan. */
    public function test_el_cliente_activo_sale_con_el_precio_de_su_plan(): void
    {
        $this->empresa('Fibra Sur', ['cobro' => 'activo', 'plan' => 'pro']);

        $datos = CobroDelMes::calcular();
        $fila = $datos['cobrar'][0];

        $this->assertSame('Fibra Sur', $fila['empresa']);
        $this->assertSame(config('planes.crm.pro.precio'), $fila['usd']);
        $this->assertSame(config('planes.crm.pro.precio'), $datos['total_usd']);
    }

    /**
     * El complemento de IA se suma al plan.
     *
     * Son dos cosas que se venden por separado, así que el precio es la suma —
     * y es la única forma de que el cliente de Integra, que no paga CRM, pueda
     * aparecer en la factura por la IA.
     */
    public function test_el_complemento_de_ia_se_suma_al_plan(): void
    {
        $this->empresa('Con IA', ['cobro' => 'activo', 'plan' => 'basico', 'ia' => 'completa']);

        $esperado = config('planes.crm.basico.precio') + config('planes.ia.completa.precio');

        $this->assertSame($esperado, CobroDelMes::calcular()['total_usd']);
    }

    /**
     * Al cliente de Integra se le cobra SÓLO el complemento.
     *
     * Es la venta que se busca: el CRM ya se lo cobró el ERP, así que empieza a
     * aparecer en la lista el día que contrata la IA, y por el importe de la IA
     * y nada más. Cobrarle también el plan sería cobrarle dos veces el CRM.
     */
    public function test_al_de_integra_se_le_cobra_solo_el_complemento(): void
    {
        $this->empresa('ISP que compró IA', [
            'viene_de_integra' => true,
            'cobro' => 'integra',
            'plan' => 'avanzado',
            'ia' => 'esencial',
        ]);

        $datos = CobroDelMes::calcular();

        $this->assertCount(1, $datos['cobrar']);
        $this->assertSame(config('planes.ia.esencial.precio'), $datos['total_usd']);
        $this->assertTrue($datos['cobrar'][0]['solo_ia']);
    }

    /**
     * Un cliente de Integra sin complemento no sale en la lista: no debe nada.
     *
     * Es el caso de casi toda la base hoy, y el que hacía que el panel contara
     * 2.391 USD/mes de facturación potencial sobre gente que ya paga.
     */
    public function test_el_de_integra_sin_complemento_no_sale(): void
    {
        $this->empresa('ISP sin IA', ['viene_de_integra' => true, 'cobro' => 'integra', 'plan' => 'basico']);

        $datos = CobroDelMes::calcular();

        $this->assertSame([], $datos['cobrar']);
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
        $this->empresa('Debe dos meses', ['cobro' => 'suspendido', 'plan' => 'basico']);

        $this->assertCount(1, CobroDelMes::calcular()['cobrar']);
    }

    /** Un `;` en el nombre de una empresa partiría la fila del CSV en dos. */
    public function test_el_csv_no_se_parte_con_un_punto_y_coma_en_el_nombre(): void
    {
        $this->empresa('Redes; Cables y Más', ['cobro' => 'activo', 'plan' => 'basico']);

        $fila = collect(explode("\r\n", CobroDelMes::csv()))
            ->first(fn (string $l) => str_contains($l, 'Redes'));

        $this->assertSame(10, substr_count($fila, ';') + 1, 'La fila tiene que tener las diez columnas de la cabecera.');
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
