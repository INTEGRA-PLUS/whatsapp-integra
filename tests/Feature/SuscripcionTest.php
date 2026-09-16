<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use App\Support\PlanDeLaEmpresa;
use App\Support\Suscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El registro de suscripción: qué periodo tiene pagado cada empresa.
 *
 * Lo que se protege aquí son las tres formas de perder dinero con un módulo de
 * pagos, y ninguna lanza un error:
 *
 * 1. **Cobrar dos veces el mismo pago.** Las pasarelas reintentan sus webhooks.
 *    Sin idempotencia, el segundo intento regala un periodo.
 * 2. **Perder días al renovar antes de tiempo.** Si el periodo nuevo arranca hoy
 *    en vez de donde acaba el actual, quien paga con una semana de antelación
 *    pierde esa semana.
 * 3. **Reescribir el histórico.** Si el importe se calcula en vez de guardarse,
 *    cada cambio de tarifa reescribe todos los recibos anteriores.
 */
class SuscripcionTest extends TestCase
{
    use RefreshDatabase;

    /** Emitir crea el cobro pendiente por el periodo que viene. */
    public function test_emitir_crea_el_cobro_del_proximo_periodo(): void
    {
        $company = $this->empresa(['plan' => 'pro', 'ia' => 'esencial', 'ciclo' => 'mensual']);

        $cobro = Suscripcion::emitir($company);

        $this->assertSame('pendiente', $cobro->estado);
        $this->assertSame(
            config('planes.crm.pro.precio') + config('planes.ia.esencial.precio'),
            $cobro->importe_usd
        );

        // Emitir no alarga nada: eso lo hace pagar.
        $this->assertNull($company->refresh()->suscripcion_hasta);
    }

    /**
     * El importe se guarda, no se calcula.
     *
     * Si mañana sube la tarifa, el recibo de ayer tiene que seguir diciendo lo
     * que se cobró — o se pierde la discusión con el cliente que lo enseñe.
     */
    public function test_el_importe_queda_congelado_aunque_cambie_la_tarifa(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'ciclo' => 'mensual']);
        $cobro = Suscripcion::emitir($company);
        $original = $cobro->importe_usd;

        config(['planes.crm.basico.precio' => 999]);

        $this->assertSame($original, $cobro->refresh()->importe_usd);
    }

    /** El ciclo anual cobra diez mensualidades por doce meses. */
    public function test_el_anual_cobra_diez_mensualidades_por_doce_meses(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'ciclo' => 'anual']);

        $cobro = Suscripcion::emitir($company);

        $this->assertSame(config('planes.crm.basico.precio') * 10, $cobro->importe_usd);
        $this->assertSame(
            365,
            (int) $cobro->periodo_desde->diffInDays($cobro->periodo_hasta->copy()->addDay())
        );
    }

    /** Pagar alarga la suscripción hasta el fin del periodo. */
    public function test_pagar_alarga_la_suscripcion(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'ciclo' => 'mensual']);
        $cobro = Suscripcion::emitir($company);

        $this->assertTrue(Suscripcion::pagar($cobro, 'integrapay-001'));

        $this->assertSame(
            $cobro->periodo_hasta->toDateString(),
            $company->refresh()->suscripcion_hasta->toDateString()
        );
        $this->assertTrue(PlanDeLaEmpresa::de($company)->suscripcionVigente());
    }

    /**
     * El mismo pago dos veces no alarga dos veces.
     *
     * Es el fallo que más caro sale y el que no avisa: todas las pasarelas
     * reintentan sus webhooks, y sin esto el reintento regala un periodo entero.
     * Mismo patrón que el `wamid` de los mensajes.
     */
    public function test_pagar_dos_veces_no_alarga_dos_veces(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'ciclo' => 'mensual']);
        $cobro = Suscripcion::emitir($company);

        $this->assertTrue(Suscripcion::pagar($cobro, 'integrapay-001'));
        $hasta = $company->refresh()->suscripcion_hasta->toDateString();

        // El webhook repetido de la pasarela.
        $this->assertFalse(Suscripcion::pagar($cobro->refresh(), 'integrapay-001'));

        $this->assertSame($hasta, $company->refresh()->suscripcion_hasta->toDateString());
    }

    /**
     * Renovar antes de que venza no pierde los días que quedaban.
     *
     * El periodo nuevo arranca donde acaba el actual, no hoy. Quien paga con una
     * semana de antelación no puede perder esa semana — y es lo que hace todo el
     * mundo cuando se le avisa siete días antes.
     */
    public function test_renovar_antes_de_tiempo_no_pierde_dias(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'ciclo' => 'mensual']);

        $primero = Suscripcion::emitir($company);
        Suscripcion::pagar($primero, 'pago-1');

        $finDelPrimero = $company->refresh()->suscripcion_hasta->copy();

        $segundo = Suscripcion::emitir($company->refresh());

        $this->assertSame(
            $finDelPrimero->copy()->addDay()->toDateString(),
            $segundo->periodo_desde->toDateString(),
            'El periodo nuevo tiene que empezar el día siguiente al que acaba el actual.'
        );
    }

    /**
     * Un cobro atrasado que se paga tarde no acorta la suscripción.
     *
     * Pasa cuando alguien renueva por otro lado y después llega el pago de un
     * cobro viejo. Si se aplicara sin más, la fecha retrocedería y la empresa
     * aparecería vencida teniendo el periodo pagado.
     */
    public function test_un_pago_atrasado_no_acorta_la_suscripcion(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'ciclo' => 'mensual']);

        $viejo = SuscripcionCobro::create([
            'company_id' => $company->id,
            'plan' => 'basico', 'ia' => 'ninguno', 'ciclo' => 'mensual',
            'importe_usd' => 29,
            'periodo_desde' => now()->subMonths(2),
            'periodo_hasta' => now()->subMonth(),
            'estado' => 'pendiente',
        ]);

        $nuevo = Suscripcion::emitir($company);
        Suscripcion::pagar($nuevo, 'pago-nuevo');
        $hasta = $company->refresh()->suscripcion_hasta->toDateString();

        Suscripcion::pagar($viejo, 'pago-viejo');

        $this->assertSame($hasta, $company->refresh()->suscripcion_hasta->toDateString());
    }

    /** Sin periodo emitido, la suscripción no está vigente. */
    public function test_sin_periodo_no_esta_vigente(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa());

        $this->assertFalse($plan->suscripcionVigente());
        $this->assertNull($plan->diasParaRenovar());
    }

    /** El concepto se lee de la copia guardada, no del catálogo de hoy. */
    public function test_el_concepto_sale_de_lo_que_se_cobro(): void
    {
        $company = $this->empresa(['plan' => 'pro', 'ia' => 'completa', 'ciclo' => 'anual']);
        $cobro = Suscripcion::emitir($company);

        // Cambia el plan de la empresa después de emitir.
        $company->update(['plan' => 'basico', 'ia' => 'ninguno']);

        $this->assertStringContainsString('Pro', $cobro->refresh()->concepto());
        $this->assertStringContainsString('IA Completa', $cobro->concepto());
        $this->assertStringContainsString('anual', $cobro->concepto());
    }

    private function empresa(array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
        ], $extra));
    }
}
