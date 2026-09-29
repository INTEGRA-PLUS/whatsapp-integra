<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PlanDeLaEmpresa;
use App\Support\Suscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El paquete de Integra trae el Básico, no cualquier plan.
 *
 * EL FALLO QUE SE PREVIENE: hasta el 29-sep-2026 al cliente de Integra no se le
 * cobraba el CRM fuera cual fuera su plan, y la pantalla de Planes pintaba
 * «Incluido» sobre el Pro y el Avanzado. Un cliente la vio y pidió el Pro
 * gratis. Ahora el Básico va incluido, subir se paga por diferencia, y los
 * cuatro que ya tenían un plan mayor lo conservan pactado.
 */
class PlanIncluidoEnIntegraTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_basico_de_integra_no_se_cobra(): void
    {
        $plan = $this->plan(['plan' => 'basico', 'viene_de_integra' => true, 'cobro' => 'integra']);

        $this->assertSame(0, $plan->precioMensual());
        $this->assertTrue($plan->cubiertoPorIntegra());
        $this->assertFalse($plan->seFactura());
    }

    public function test_subir_de_plan_desde_integra_cuesta_la_diferencia(): void
    {
        $pro = $this->plan(['plan' => 'pro', 'viene_de_integra' => true, 'cobro' => 'integra']);
        $avanzado = $this->plan(['plan' => 'avanzado', 'viene_de_integra' => true, 'cobro' => 'integra']);

        $this->assertSame(30, $pro->precioMensual(), 'Pro 59 menos el Básico 29.');
        $this->assertSame(80, $avanzado->precioMensual(), 'Avanzado 109 menos el Básico 29.');
        $this->assertFalse($pro->cubiertoPorIntegra());
        $this->assertTrue($pro->seFactura(), 'La diferencia sí se factura.');
    }

    public function test_la_diferencia_se_suma_al_complemento(): void
    {
        $plan = $this->plan(['plan' => 'pro', 'ia' => 'esencial', 'viene_de_integra' => true, 'cobro' => 'integra']);

        $this->assertSame(30 + config('planes.ia.esencial.precio'), $plan->precioMensual());
    }

    /** Transinternet, Comuna13, Megastore y Star NET: se respeta lo vendido. */
    public function test_el_plan_pactado_no_paga_la_diferencia(): void
    {
        $sinIa = $this->plan(['plan' => 'avanzado', 'viene_de_integra' => true, 'cobro' => 'integra', 'crm_pactado' => true]);
        $conIa = $this->plan(['plan' => 'pro', 'ia' => 'completa', 'viene_de_integra' => true, 'cobro' => 'integra', 'crm_pactado' => true]);

        $this->assertSame(0, $sinIa->precioMensual());
        $this->assertTrue($sinIa->cubiertoPorIntegra());
        $this->assertSame(config('planes.ia.completa.precio'), $conIa->precioMensual(), 'Megastore: sólo la IA.');
    }

    /** El pacto es sobre el paquete de Integra: sin Integra no regala nada. */
    public function test_el_pacto_no_vale_para_un_cliente_directo(): void
    {
        $plan = $this->plan(['plan' => 'pro', 'cobro' => 'activo', 'crm_pactado' => true]);

        $this->assertFalse($plan->crmPactado());
        $this->assertSame(59, $plan->precioMensual());
    }

    /** El que llega sin Integra paga el plan entero, con o sin IA. */
    public function test_el_cliente_directo_paga_plan_mas_complemento(): void
    {
        $this->assertSame(29, $this->plan(['plan' => 'basico', 'cobro' => 'activo'])->precioMensual());
        $this->assertSame(
            59 + config('planes.ia.esencial.precio'),
            $this->plan(['plan' => 'pro', 'ia' => 'esencial', 'cobro' => 'activo'])->precioMensual()
        );
    }

    /** El recibo explica que se paga la diferencia, y sale pendiente, no cubierto. */
    public function test_el_recibo_de_la_diferencia_se_explica(): void
    {
        $company = $this->empresa(['plan' => 'pro', 'viene_de_integra' => true, 'cobro' => 'integra']);

        $cobro = Suscripcion::emitir($company);

        $this->assertSame('pendiente', $cobro->estado);
        $this->assertSame(30, (int) $cobro->importe_usd);
        $this->assertTrue($cobro->hayQueCobrarlo());
        $this->assertStringContainsString('pagas la diferencia', $cobro->concepto());
        $this->assertSame(30, $cobro->desglose()[0]['importe']);
    }

    /** Megastore: el recibo cobra la IA y dice que su Pro no se cobra. */
    public function test_el_recibo_del_pactado_con_ia_cobra_solo_la_ia(): void
    {
        $company = $this->empresa([
            'plan' => 'pro', 'ia' => 'completa', 'viene_de_integra' => true, 'cobro' => 'integra', 'crm_pactado' => true,
        ]);

        $cobro = Suscripcion::emitir($company);

        $this->assertSame(config('planes.ia.completa.precio'), (int) $cobro->importe_usd);
        $this->assertFalse($cobro->pagaDiferenciaDeCrm());
        $this->assertNull($cobro->desglose()[0]['importe']);
    }

    /** Lo que vio el cliente: sólo el Básico puede salir como incluido. */
    public function test_la_tabla_de_planes_da_la_diferencia_de_cada_plan(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'viene_de_integra' => true, 'cobro' => 'integra']);

        $this->actingAs($this->admin($company))
            ->get('/planes')
            ->assertInertia(fn ($page) => $page
                ->where('actual.incluido_en_integra', true)
                ->where('actual.crm_pactado', false)
                ->where('actual.plan_de_integra', 'basico')
                ->where('crm.0.precio_integra', 0)
                ->where('crm.1.precio_integra', 30)
                ->where('crm.2.precio_integra', 80)
            );
    }

    private function plan(array $extra): PlanDeLaEmpresa
    {
        return PlanDeLaEmpresa::de($this->empresa($extra));
    }

    private function empresa(array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
        ], $extra));
    }

    private function admin(Company $company): User
    {
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'a'.uniqid().'@test.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);

        setPermissionsTeamId($company->id);
        $role = Role::firstOrCreate(['name' => 'op', 'company_id' => $company->id, 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'extensions.view', 'guard_name' => 'web']));
        $user->assignRole($role);

        return $user->fresh();
    }
}
