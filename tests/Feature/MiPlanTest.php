<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Contact;
use App\Models\User;
use App\Support\Suscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «Mi plan», la pantalla del cliente.
 *
 * Lo que se protege: que cada empresa vea LO SUYO —es una pantalla con datos
 * comerciales y de consumo— y que lo bloqueado se siga enseñando, porque
 * esconderlo es lo que hace que nadie pregunte por ello.
 */
class MiPlanTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * La comparativa lleva las tres medidas, líneas incluidas.
     *
     * Son las tres que decide `planSugerido()`. Sin las líneas en la pantalla,
     * a una empresa con dos líneas en un plan de una se le decía que se había
     * quedado corta sin enseñarle en qué, y la única forma de saberlo era
     * preguntar.
     */
    public function test_los_planes_traen_agentes_contactos_y_lineas(): void
    {
        $company = $this->empresa(['plan' => 'basico']);

        $planes = $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertOk()
            ->viewData('page')['props']['planes'];

        foreach ($planes as $plan) {
            $this->assertArrayHasKey('agentes', $plan);
            $this->assertArrayHasKey('contactos', $plan);
            $this->assertArrayHasKey('lineas', $plan, 'Las líneas no llegan a la pantalla.');

            // El crédito de IA vive en el plan de CRM y no en el complemento,
            // así que es en esta comparativa donde se explica en qué se nota
            // subir de plan a quien lo que quiere es la IA.
            $this->assertSame(
                config("planes.crm.{$plan['slug']}.credito_ia"),
                $plan['credito_ia'],
                'El crédito de IA del plan no llega a la comparativa.'
            );
        }
    }

    /**
     * Y señala a cuál pasar cuando el suyo se le queda pequeño.
     *
     * Es lo que convierte «te has quedado corto» en algo accionable. El propio
     * plan nunca se sugiere a sí mismo: un aviso que te recomienda quedarte
     * donde estás enseña a no leer los avisos.
     */
    public function test_senala_el_plan_que_le_tocaria(): void
    {
        $company = $this->empresa(['plan' => 'basico']);
        $this->contactos($company, config('planes.crm.basico.contactos') + 50);

        $planes = collect($this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertOk()
            ->viewData('page')['props']['planes']);

        $sugerido = $planes->firstWhere('es_el_sugerido', true);

        $this->assertNotNull($sugerido, 'No se sugiere ningún plan a quien se quedó corto.');
        $this->assertFalse($sugerido['es_el_suyo'], 'Se le sugiere el plan en el que ya está.');
    }

    private function contactos(Company $company, int $cuantos): void
    {
        $filas = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $filas[] = [
                'company_id' => $company->id,
                'name' => 'Contacto '.$i,
                'phone_number' => '57300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($filas, 500) as $lote) {
            \Illuminate\Support\Facades\DB::table('contacts')->insert($lote);
        }
    }

    public function test_el_admin_ve_su_plan(): void
    {
        $company = $this->empresa(['plan' => 'pro']);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MiPlan/Index')
                ->where('plan.plan_nombre', 'Pro')
                ->where('plan.tiene_ia', false));
    }

    /** Lo bloqueado se enseña: esconderlo hace que nadie pregunte por ello. */
    public function test_ensena_tambien_lo_que_no_tiene(): void
    {
        $company = $this->empresa(['plan' => 'basico']);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertOk()
            ->assertInertia(function ($page) {
                $extensiones = collect($page->toArray()['props']['extensiones']);

                $this->assertTrue($extensiones->contains('slug', 'conversation_summary'));

                $resumen = $extensiones->firstWhere('slug', 'conversation_summary');
                $this->assertFalse($resumen['en_plan']);
                // Y dice en qué complemento está, no un «no incluido» a secas:
                // el más barato que lo incluye, para que la conversación
                // comercial empiece por ahí y no por el paquete grande.
                $this->assertSame('IA Esencial', $resumen['plan_minimo']);
            });
    }

    public function test_cuenta_sus_contactos_y_no_los_de_otra_empresa(): void
    {
        $mia = $this->empresa(['plan' => 'basico']);
        $ajena = $this->empresa();

        Contact::create(['company_id' => $mia->id, 'phone_number' => '573001', 'name' => 'Mío']);
        Contact::create(['company_id' => $ajena->id, 'phone_number' => '573002', 'name' => 'Ajeno']);
        Contact::create(['company_id' => $ajena->id, 'phone_number' => '573003', 'name' => 'Ajeno 2']);

        $this->actingAs($this->admin($mia))
            ->get('/mi-plan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('plan.contactos_reales', 1));
    }

    /** El coste de la IA es margen nuestro, no asunto suyo. */
    public function test_no_le_ensena_el_coste_de_la_ia(): void
    {
        $company = $this->empresa(['plan' => 'pro']);

        $respuesta = $this->actingAs($this->admin($company))->get('/mi-plan')->assertOk();

        $props = $respuesta->viewData('page')['props'];

        $this->assertArrayHasKey('uso_ia', $props);
        $this->assertArrayNotHasKey('coste_usd', $props['plan']);
    }

    public function test_marca_las_extensiones_que_tiene_encendidas(): void
    {
        $company = $this->empresa(['plan' => 'avanzado', 'ia' => 'completa']);

        CompanyExtension::create([
            'company_id' => $company->id,
            'slug' => 'agent_signature',
            'enabled' => true,
            'settings' => [],
            'installed_by' => $this->admin($company)->id,
            'installed_at' => now(),
        ]);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertOk()
            ->assertInertia(function ($page) {
                $firma = collect($page->toArray()['props']['extensiones'])->firstWhere('slug', 'agent_signature');

                $this->assertTrue($firma['instalada']);
                $this->assertTrue($firma['encendida']);
            });
    }

    /**
     * El cliente de Integra ve desde y hasta cuándo lo tiene cubierto.
     *
     * Esa fecha sólo vivía en el panel maestro: el único que sabía cuándo
     * vencía la suscripción de un cliente éramos nosotros. Y va con el **desde**
     * porque una fecha suelta no se puede cotejar con ninguna factura, que es
     * justo lo que el cliente hace con este dato.
     *
     * @test
     */
    public function el_cliente_de_integra_ve_el_periodo_que_tiene_cubierto(): void
    {
        $company = $this->empresa(['plan' => 'pro', 'viene_de_integra' => true]);
        $cobro = Suscripcion::emitir($company);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertInertia(fn ($page) => $page
                ->where('periodo.desde', $cobro->periodo_desde->toDateString())
                ->where('periodo.hasta', $cobro->periodo_hasta->toDateString())
                ->where('periodo.cubierto_por_integra', true)
                ->where('periodo.vigente', true)
                // Nace saldado: no hay nada que pagar y no debe salir el aviso.
                ->where('por_pagar', null)
            );
    }

    /**
     * Y el que no es de Integra ve además lo que tiene por pagar.
     *
     * Se puede estar cubierto hasta el 15 y tener ya emitido el siguiente: son
     * dos cosas a la vez y por eso viajan en dos props.
     *
     * @test
     */
    public function el_cliente_directo_ve_su_cobro_pendiente(): void
    {
        $company = $this->empresa(['plan' => 'pro', 'cobro' => 'activo']);
        $cobro = Suscripcion::emitir($company);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertInertia(fn ($page) => $page
                ->where('por_pagar.desde', $cobro->periodo_desde->toDateString())
                ->where('por_pagar.hasta', $cobro->periodo_hasta->toDateString())
                ->where('por_pagar.importe_usd', (int) $cobro->importe_usd)
            );
    }

    /**
     * Sin nada emitido no se pinta un hueco.
     *
     * Es el estado de toda la base hasta la primera emisión. Un «sin periodo»
     * en pantalla alarma sin informar.
     *
     * @test
     */
    public function sin_cobros_no_se_inventa_un_periodo(): void
    {
        $company = $this->empresa(['plan' => 'pro']);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertInertia(fn ($page) => $page->where('periodo', null)->where('por_pagar', null));
    }

    /** Y el periodo de otra empresa no se cuela en esta pantalla. */
    public function test_no_ve_el_periodo_de_otra_empresa(): void
    {
        $otra = $this->empresa(['plan' => 'pro', 'cobro' => 'activo']);
        Suscripcion::emitir($otra);

        $company = $this->empresa(['plan' => 'pro']);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertInertia(fn ($page) => $page->where('periodo', null)->where('por_pagar', null));
    }
}
