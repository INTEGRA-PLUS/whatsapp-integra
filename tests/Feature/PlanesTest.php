<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\User;
use App\Support\ContadorDeIa;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El sistema de planes.
 *
 * Lo que se protege, por orden de gravedad:
 *
 * - **Que las empresas que ya usaban el CRM no pierdan nada.** Es lo primero
 *   porque el fallo no daría error: le apagaría funciones a un cliente que lleva
 *   un año trabajando con ellas y se enteraría él antes que nosotros.
 * - Que un plan bajo no pueda instalar lo que no contrató, ni por la puerta de
 *   atrás de los ajustes.
 * - Que nada de esto apague el CRM: ni el plan, ni el cobro, ni el crédito.
 */
class PlanesTest extends TestCase
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

    /**
     * Un admin con permisos de verdad.
     *
     * Los permisos son de Spatie con teams: sin `setPermissionsTeamId` ni rol
     * asignado, cualquier ruta con `permission:` responde 403 y el test acaba
     * midiendo el middleware en vez del candado del plan.
     */
    private function admin(Company $company, array $permisos = ['extensions.create', 'extensions.update', 'extensions.view']): User
    {
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin'.uniqid().'@test.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);

        setPermissionsTeamId($company->id);

        $role = Role::firstOrCreate([
            'name' => 'operacion', 'company_id' => $company->id, 'guard_name' => 'web',
        ]);

        foreach ($permisos as $nombre) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']));
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    /** `isMaster()` pregunta por el rol de Spatie, no por la columna. */
    private function master(): User
    {
        $company = $this->empresa(['name' => 'Integra']);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Master',
            'email' => 'master'.uniqid().'@test.test',
            'password' => 'secret',
            'role' => 'master',
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        $user->assignRole(Role::firstOrCreate([
            'name' => 'master', 'company_id' => $company->id, 'guard_name' => 'web',
        ]));

        return $user->fresh();
    }

    // ─── La transición ───────────────────────────────────────────────────────

    /**
     * La empresa que ya existía nace con todo encendido y sin factura. Si esto
     * se rompe, once clientes pierden funciones sin avisar.
     */
    public function test_una_empresa_nueva_nace_con_todo_y_sin_cobro(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa());

        $this->assertSame('inteligente', $plan->slug());
        $this->assertSame('cortesia', $plan->cobro());
        $this->assertFalse($plan->seFactura());
        $this->assertTrue($plan->permiteExtension('conversation_summary'));
        $this->assertTrue($plan->tieneIa());
    }

    public function test_la_cortesia_no_se_factura_aunque_pase_el_tiempo(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa([
            'cobro' => 'cortesia',
            'gratis_hasta' => now()->subYear(),
        ]));

        $this->assertFalse($plan->seFactura());
    }

    public function test_el_mes_gratis_para_el_cobro_de_quien_si_paga(): void
    {
        $activo = PlanDeLaEmpresa::de($this->empresa(['cobro' => 'activo']));
        $this->assertTrue($activo->seFactura());

        $conGracia = PlanDeLaEmpresa::de($this->empresa([
            'cobro' => 'activo',
            'gratis_hasta' => now()->addDays(10),
        ]));
        $this->assertFalse($conGracia->seFactura());
        $this->assertTrue($conGracia->enMesGratis());
    }

    public function test_el_mes_gratis_se_suma_en_vez_de_reemplazarse(): void
    {
        $company = $this->empresa(['gratis_hasta' => now()->addMonth()]);

        $this->actingAs($this->master())
            ->post("/master/companies/{$company->id}/mes-gratis")
            ->assertRedirect();

        // Dos meses desde hoy, no uno: quien da dos meses espera dos.
        $this->assertTrue(
            $company->refresh()->gratis_hasta->greaterThan(now()->addMonth()->addWeek())
        );
    }

    // ─── El candado ──────────────────────────────────────────────────────────

    public function test_el_plan_esencial_no_llega_a_las_extensiones_de_arriba(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'esencial']));

        $this->assertTrue($plan->permiteExtension('agent_signature'));
        $this->assertFalse($plan->permiteExtension('follow_up'));
        $this->assertFalse($plan->permiteExtension('conversation_summary'));
        $this->assertFalse($plan->tieneIa());
        $this->assertSame(0, $plan->creditoIa());
    }

    public function test_automatizacion_llega_al_semaforo_pero_no_a_la_ia(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'automatizacion']));

        $this->assertTrue($plan->permiteExtension('sentiment_traffic_light'));
        $this->assertTrue($plan->permiteExtension('keyword_routing'));
        $this->assertFalse($plan->permiteExtension('conversation_summary'));
        $this->assertFalse($plan->tieneIa());
    }

    /** El candado por la puerta de atrás: la extensión entra, el ajuste no. */
    public function test_automatizacion_no_puede_encender_afinar_con_ia(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'automatizacion']));

        $this->assertFalse($plan->permiteAjuste('sentiment_traffic_light', 'usar_ia'));
        // Los demás ajustes del semáforo sí.
        $this->assertTrue($plan->permiteAjuste('sentiment_traffic_light', 'sensibilidad'));
    }

    public function test_instalar_fuera_de_plan_responde_402(): void
    {
        $company = $this->empresa(['plan' => 'esencial']);

        $this->actingAs($this->admin($company))
            ->postJson('/api/extensions/conversation_summary/install')
            ->assertStatus(402);

        $this->assertDatabaseMissing('company_extensions', [
            'company_id' => $company->id,
            'slug' => 'conversation_summary',
        ]);
    }

    /**
     * 402 y no 403: el frontend tiene que poder decir «mejora tu plan» en vez de
     * «no tienes permiso», que son dos conversaciones muy distintas.
     */
    public function test_no_se_confunde_con_un_problema_de_permisos(): void
    {
        $company = $this->empresa(['plan' => 'esencial']);

        $this->actingAs($this->admin($company))
            ->postJson('/api/extensions/conversation_summary/install')
            ->assertStatus(402)
            ->assertJsonPath('message', 'Esta extensión no está incluida en tu plan.');
    }

    public function test_guardar_ajustes_no_cuela_la_ia_de_tapadillo(): void
    {
        $company = $this->empresa(['plan' => 'automatizacion']);

        CompanyExtension::create([
            'company_id' => $company->id,
            'slug' => 'sentiment_traffic_light',
            'enabled' => true,
            'settings' => ['sensibilidad' => 'medio', 'usar_ia' => false, 'ventana_mensajes' => 8, 'palabras_rojas' => ''],
            'installed_by' => $this->admin($company)->id,
            'installed_at' => now(),
        ]);

        $this->actingAs($this->admin($company))
            ->putJson('/api/extensions/sentiment_traffic_light/settings', [
                'settings' => ['sensibilidad' => 'alto', 'usar_ia' => true, 'ventana_mensajes' => 8],
            ])
            ->assertOk();

        $guardado = CompanyExtension::where('company_id', $company->id)->first()->settings();

        $this->assertFalse($guardado['usar_ia'], 'La IA se coló por el formulario de ajustes');
        // Lo que sí puede cambiar, cambia.
        $this->assertSame('alto', $guardado['sensibilidad']);
    }

    // ─── El crédito de IA ────────────────────────────────────────────────────

    public function test_el_credito_sale_del_tramo_contratado(): void
    {
        $this->assertSame(300, PlanDeLaEmpresa::de($this->empresa(['contactos_contratados' => 400]))->creditoIa());
        $this->assertSame(3000, PlanDeLaEmpresa::de($this->empresa(['contactos_contratados' => 4500]))->creditoIa());
        $this->assertSame(8000, PlanDeLaEmpresa::de($this->empresa(['contactos_contratados' => 12000]))->creditoIa());
    }

    /** Sin tramo asignado se da el suelo, no cero: cero parecería una avería. */
    public function test_sin_tramo_se_da_el_suelo_del_plan(): void
    {
        $this->assertSame(1200, PlanDeLaEmpresa::de($this->empresa())->creditoIa());
    }

    public function test_el_contador_suma_eventos_y_conversaciones(): void
    {
        $company = $this->empresa(['contactos_contratados' => 12000]);

        ContadorDeIa::apuntar($company->id, 'chat', conversacionNueva: true);
        ContadorDeIa::apuntar($company->id, 'chat');
        ContadorDeIa::apuntar($company->id, 'semaforo');

        $estado = ContadorDeIa::estado($company->refresh());

        // Tres eventos, UNA conversación: se vende por conversación.
        $this->assertSame(1, $estado['usadas']);
        $this->assertSame(8000, $estado['incluidas']);
        $this->assertSame(0, $estado['exceso']);
        $this->assertGreaterThan(0, $estado['coste_usd']);
    }

    /** Pasarse del crédito se apunta como exceso; no se bloquea nada. */
    public function test_pasarse_del_credito_no_bloquea_nada(): void
    {
        $company = $this->empresa(['plan' => 'inteligente', 'contactos_contratados' => 400]);

        for ($i = 0; $i < 302; $i++) {
            ContadorDeIa::apuntar($company->id, 'chat', conversacionNueva: true);
        }

        $estado = ContadorDeIa::estado($company->refresh());

        $this->assertSame(302, $estado['usadas']);
        $this->assertSame(2, $estado['exceso']);
        // Y la empresa sigue pudiendo usar todo lo suyo.
        $this->assertTrue(PlanDeLaEmpresa::de($company)->permiteExtension('conversation_summary'));
    }

    /** Un contador roto no puede dejar mudo al bot. */
    public function test_apuntar_sin_empresa_no_revienta(): void
    {
        ContadorDeIa::apuntar(null, 'chat');
        ContadorDeIa::apuntar(1, 'tipo-que-no-existe');

        $this->assertDatabaseCount('company_ai_usage', 0);
    }

    // ─── La escalera de precios ──────────────────────────────────────────────

    public function test_el_precio_sale_del_tramo_y_del_plan(): void
    {
        $this->assertSame(35, PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'esencial', 'contactos_contratados' => 300,
        ]))->precioMensual());

        $this->assertSame(299, PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'inteligente', 'contactos_contratados' => 12000,
        ]))->precioMensual());

        $this->assertSame(149, PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'automatizacion', 'contactos_contratados' => 4500,
        ]))->precioMensual());
    }

    /**
     * El número que se dijo en la mesa. 299 de lista menos los dos meses del
     * pago anual son los 250 USD que se le propusieron a Cootramed: si esto se
     * rompe, el panel contradice una cotización ya entregada.
     */
    public function test_el_anual_da_los_249_de_cootramed(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'inteligente', 'contactos_contratados' => 12000,
        ]));

        $this->assertSame(299, $plan->precioMensual());
        $this->assertSame(249, $plan->precioMensualAnual());
    }

    /** Sin tramo no se inventa un precio: se dice que falta ponerlo. */
    public function test_sin_tramo_no_hay_precio(): void
    {
        $this->assertNull(PlanDeLaEmpresa::de($this->empresa())->precioMensual());
        $this->assertNull(PlanDeLaEmpresa::de($this->empresa())->precioMensualAnual());
    }

    /** Por encima del último tramo es «a cotizar», y ahí tampoco se inventa. */
    public function test_por_encima_del_ultimo_tramo_se_cotiza_a_mano(): void
    {
        $this->assertNull(PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'inteligente', 'contactos_contratados' => 80000,
        ]))->precioMensual());
    }

    /**
     * Los dos mapas son el mismo tramo mirado desde dos sitios. Si alguien
     * añade un tramo de precio y se olvida del crédito, una empresa acabaría
     * pagando un escalón y recibiendo el crédito de otro.
     */
    public function test_los_tramos_de_precio_y_de_credito_no_se_separan(): void
    {
        $this->assertSame(
            array_keys(config('planes.credito_ia')),
            array_keys(config('planes.precios')),
            'Los tramos de precio y de crédito de IA dejaron de coincidir'
        );
    }

    /** Cada plan tiene precio en todos los tramos: un hueco sería un «sin definir» falso. */
    public function test_ningun_plan_se_queda_sin_precio_en_un_tramo(): void
    {
        foreach (config('planes.precios') as $tope => $fila) {
            foreach (array_keys(config('planes.disponibles')) as $plan) {
                $this->assertArrayHasKey($plan, $fila, "Falta el precio de {$plan} en el tramo {$tope}");
            }
        }
    }

    // ─── Lo que NO debe pasar nunca ──────────────────────────────────────────

    /**
     * Suspendido marca a quien no paga para que aparezca en el panel. NO apaga
     * el CRM: dejar sin WhatsApp a una cooperativa un día de recaudo por una
     * factura de 300 dólares es la forma más cara que existe de cobrar, y el que
     * se queda sin atender es el socio, que no debe nada.
     */
    public function test_suspendido_no_apaga_las_extensiones(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'inteligente',
            'cobro' => 'suspendido',
        ]));

        $this->assertTrue($plan->seFactura());
        $this->assertTrue($plan->permiteExtension('conversation_summary'));
        $this->assertTrue($plan->tieneIa());
    }

    /** Un plan escrito a mano que no existe cae en el más alto, no en ninguno. */
    public function test_un_plan_desconocido_no_deja_a_nadie_sin_nada(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'premium-que-no-existe']));

        $this->assertSame('inteligente', $plan->slug());
        $this->assertTrue($plan->permiteExtension('conversation_summary'));
    }
}
