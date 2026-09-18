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

    // ─── El panel ────────────────────────────────────────────────────────────

    /**
     * Sólo el master emite y marca pagos.
     *
     * Es dinero: un admin de empresa que pudiera llamar a esta ruta se alargaría
     * la suscripción él solo. Y no basta con esconder el botón — la ruta se
     * llama sin botón.
     */
    public function test_solo_el_master_toca_los_cobros(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'cobro' => 'activo']);
        $admin = $this->usuario($company, 'admin');

        $this->actingAs($admin)
            ->post("/master/companies/{$company->id}/suscripcion/emitir")
            ->assertForbidden();
    }

    /** Emitir dos veces seguidas no deja dos cobros por el mismo periodo. */
    public function test_no_se_emiten_dos_cobros_pendientes(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'cobro' => 'activo']);
        $master = $this->usuario($company, 'master');

        $this->actingAs($master)->post("/master/companies/{$company->id}/suscripcion/emitir");
        $this->actingAs($master)->post("/master/companies/{$company->id}/suscripcion/emitir");

        $this->assertSame(1, SuscripcionCobro::where('company_id', $company->id)->count());
    }

    /**
     * La misma referencia no se puede aplicar a dos cobros.
     *
     * Es la red que queda cuando el mismo comprobante se mete dos veces a mano,
     * y la misma que protegerá del webhook repetido de IntegraPay.
     */
    public function test_la_misma_referencia_no_paga_dos_cobros(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'cobro' => 'activo']);

        $uno = Suscripcion::emitir($company);
        $this->assertTrue(Suscripcion::pagar($uno, 'transferencia-777'));

        $otro = Suscripcion::emitir($company->refresh());
        $this->assertFalse(Suscripcion::pagar($otro, 'transferencia-777'));

        $this->assertSame('pendiente', $otro->refresh()->estado);
    }

    /** Un cobro pagado no se anula: eso es una devolución y se hace fuera. */
    public function test_un_cobro_pagado_no_se_anula(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'cobro' => 'activo']);
        $cobro = Suscripcion::emitir($company);
        Suscripcion::pagar($cobro, 'ref-1');

        $this->actingAs($this->usuario($company, 'master'))
            ->post("/master/cobros/{$cobro->id}/anular");

        $this->assertSame('pagado', $cobro->refresh()->estado);
    }

    private function usuario(Company $company, string $rol): \App\Models\User
    {
        $user = \App\Models\User::create([
            'company_id' => $company->id,
            'name' => ucfirst($rol),
            'email' => \Illuminate\Support\Str::uuid().'@x.test',
            'password' => bcrypt('secreto123'),
            'role' => $rol,
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate([
            'name' => $rol, 'company_id' => $company->id, 'guard_name' => 'web',
        ]));

        return $user->fresh();
    }

    // ------------------------------------------------------------------
    // El cliente que llega con el paquete de Integra
    // ------------------------------------------------------------------

    /**
     * Se le emite su recibo, en cero y marcado como cubierto.
     *
     * No es lo mismo que «no se le cobra». Es un cliente que paga, sólo que por
     * la otra puerta: el CRM va dentro de su ERP. Sin este recibo no tiene
     * constancia del servicio, y nosotros no vemos a quién podríamos venderle
     * el complemento de IA.
     *
     * @test
     */
    public function al_cliente_de_integra_se_le_emite_en_cero_y_cubierto(): void
    {
        $company = $this->empresa(['viene_de_integra' => true, 'plan' => 'pro', 'ia' => 'ninguno']);

        $cobro = Suscripcion::emitir($company);

        $this->assertSame(SuscripcionCobro::CUBIERTO, $cobro->estado);
        $this->assertSame(0, (int) $cobro->importe_usd);
        $this->assertFalse($cobro->hayQueCobrarlo(), 'Y por tanto no va a ninguna pasarela.');
        $this->assertStringContainsString('incluido en tu paquete Integra', $cobro->concepto());
    }

    /**
     * Y su periodo avanza en el acto.
     *
     * No hay pago que esperar. Sin esto, todo cliente de Integra aparecería
     * vencido mes tras mes mientras paga religiosamente por su ERP.
     *
     * @test
     */
    public function el_cubierto_avanza_el_periodo_al_emitirse(): void
    {
        $company = $this->empresa(['viene_de_integra' => true, 'plan' => 'pro', 'ia' => 'ninguno']);

        $cobro = Suscripcion::emitir($company);

        $this->assertNotNull($company->refresh()->suscripcion_hasta);
        $this->assertTrue($company->suscripcion_hasta->isSameDay($cobro->periodo_hasta));
    }

    /** Pagarlo otra vez no alarga nada: ya nació saldado. */
    public function test_un_cubierto_no_se_puede_pagar_dos_veces(): void
    {
        $company = $this->empresa(['viene_de_integra' => true, 'plan' => 'pro', 'ia' => 'ninguno']);
        $cobro = Suscripcion::emitir($company);
        $hasta = $company->refresh()->suscripcion_hasta;

        $this->assertFalse(Suscripcion::pagar($cobro, 'REF-QUE-NO-TOCA'));
        $this->assertTrue($company->refresh()->suscripcion_hasta->isSameDay($hasta));
    }

    /**
     * En cuanto compra la IA, sí hay algo que cobrar — y sólo la IA.
     *
     * Es el único camino por el que un cliente de Integra empieza a aparecer en
     * la facturación, y es justo la venta que se busca.
     *
     * @test
     */
    public function cuando_compra_ia_se_le_cobra_solo_la_ia(): void
    {
        $company = $this->empresa(['viene_de_integra' => true, 'plan' => 'pro', 'ia' => 'completa']);

        $cobro = Suscripcion::emitir($company);

        $this->assertSame(SuscripcionCobro::PENDIENTE, $cobro->estado);
        $this->assertTrue($cobro->hayQueCobrarlo(), 'Y este sí va a OnePay.');

        $plan = PlanDeLaEmpresa::de($company);

        $this->assertSame($plan->precioIa(), (int) $cobro->importe_usd,
            'Sólo el complemento: el CRM ya se lo cobró el ERP.');
        $this->assertGreaterThan(0, (int) $cobro->importe_usd);
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
