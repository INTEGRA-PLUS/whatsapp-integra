<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Contact;
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

    /** Contactos de verdad: es de donde sale `contactosReales()`. */
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

        foreach (array_chunk($filas, 200) as $lote) {
            \Illuminate\Support\Facades\DB::table('contacts')->insert($lote);
        }
    }

    /** Agentes de la empresa. `activos: false` para los que no atienden. */
    private function agentes(Company $company, int $cuantos, bool $activos = true): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            User::create([
                'company_id' => $company->id,
                'name' => 'Agente '.$i,
                'email' => 'agente'.$i.'.'.uniqid().'@x.test',
                'password' => bcrypt('secreto123'),
                'role' => 'agent',
                'active' => $activos,
            ]);
        }
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
     * Una empresa nueva nace en Básico, sin IA y sin factura.
     *
     * Cambió el 15-sep-2026, al separar el CRM de la IA. Antes nacían en
     * `inteligente` —con todo encendido— para no degradar a los clientes de
     * siempre al introducir los planes. Ahora el CRM va entero en los tres
     * planes, así que nacer en Básico no le quita nada a nadie, y nacer **sin
     * IA** es lo correcto: la IA cuesta tokens y es lo que se vende.
     */
    public function test_una_empresa_nueva_nace_en_basico_sin_ia_y_sin_cobro(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa());

        $this->assertSame('basico', $plan->slug());
        $this->assertSame('ninguno', $plan->slugIa());
        $this->assertSame('cortesia', $plan->cobro());
        $this->assertFalse($plan->seFactura());
        $this->assertFalse($plan->tieneIa());

        // El CRM entero, eso sí: las tres extensiones sin modelo.
        $this->assertTrue($plan->permiteExtension('agent_signature'));
        $this->assertTrue($plan->permiteExtension('follow_up'));
        $this->assertTrue($plan->permiteExtension('keyword_routing'));

        // Y nada de IA, semáforo incluido.
        $this->assertFalse($plan->permiteExtension('conversation_summary'));
        $this->assertFalse($plan->permiteExtension('sentiment_traffic_light'));
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

    /**
     * El tamaño del plan de CRM no cambia qué extensiones se pueden instalar.
     *
     * Las cuatro sin modelo van en los tres planes: se venden en paquete, y lo
     * que decide el plan es el tamaño —agentes, contactos, líneas—, no las
     * funciones. Antes no era así y por eso Esencial se leía como un plan de una
     * sola función.
     */
    public function test_el_plan_de_crm_no_decide_las_extensiones(): void
    {
        foreach (array_keys(config('planes.crm')) as $slug) {
            $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => $slug]));

            foreach (config('planes.extensiones_del_crm') as $extension) {
                $this->assertTrue(
                    $plan->permiteExtension($extension),
                    "El plan {$slug} tendría que llegar a {$extension}."
                );
            }

            $this->assertFalse($plan->permiteExtension('conversation_summary'));
            $this->assertFalse($plan->tieneIa());
            $this->assertSame(0, $plan->creditoIa());
        }
    }

    /** Sin complemento no hay IA, por grande que sea el plan de CRM. */
    public function test_el_plan_mas_grande_sin_complemento_no_tiene_ia(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'avanzado']));

        $this->assertFalse($plan->permiteExtension('conversation_summary'));
        $this->assertFalse($plan->permiteExtension('sentiment_traffic_light'));
        $this->assertFalse($plan->tieneIa());

        // Pero el CRM entero sí, que es lo que paga.
        $this->assertTrue($plan->permiteExtension('keyword_routing'));
    }

    /** El candado por la puerta de atrás: la extensión entra, el ajuste no. */
    public function test_sin_complemento_no_se_puede_encender_afinar_con_ia(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'avanzado']));

        $this->assertFalse($plan->permiteAjuste('sentiment_traffic_light', 'usar_ia'));
        // Los demás ajustes del semáforo sí.
        $this->assertTrue($plan->permiteAjuste('sentiment_traffic_light', 'sensibilidad'));
    }

    /**
     * El catálogo sabe decir «la tienes, pero su parte con IA no».
     *
     * Es el estado del semáforo y el que más confusión causó: se instala y
     * colorea con un diccionario sin llamar a ningún modelo, y lo único que
     * exige complemento es «afinar con IA». Sin este dato, la tarjeta enseñaba
     * un «Instalar» a secas y la pregunta «¿el semáforo no es con IA?» salía una
     * y otra vez.
     *
     * Y protege un fallo que ya ocurrió: el controlador leía los ajustes
     * bloqueados de `planes.ajustes_con_ia`, una clave que dejó de existir al
     * separar el CRM de la IA. Devolvía siempre una lista vacía — el candado
     * seguía cerrado, pero la pantalla no lo decía, que es la peor combinación.
     */
    public function test_el_catalogo_sabe_que_ajustes_quedan_cerrados(): void
    {
        $sin = PlanDeLaEmpresa::de($this->empresa(['ia' => 'ninguno']));

        // Sin complemento, el ajuste con IA del semáforo está cerrado — igual
        // que la extensión entera desde que se movió al complemento.
        $this->assertSame(['usar_ia'], $sin->ajustesDeIaBloqueados('sentiment_traffic_light'));

        // Con complemento no queda ninguno bloqueado.
        $con = PlanDeLaEmpresa::de($this->empresa(['ia' => 'esencial']));
        $this->assertSame([], $con->ajustesDeIaBloqueados('sentiment_traffic_light'));

        // Y una extensión sin ajustes de IA no inventa ninguno.
        $this->assertSame([], $sin->ajustesDeIaBloqueados('agent_signature'));
    }

    /**
     * Y con el complemento Esencial sí, sin tocar el plan de CRM.
     *
     * Es la razón de separarlos: antes, para tener una función con IA había que
     * subir de plan entero, y a un cliente de Integra —que ya paga el CRM por el
     * ERP— eso no se le podía ni plantear.
     */
    public function test_el_complemento_abre_la_ia_sin_cambiar_el_plan(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'basico', 'ia' => 'esencial']));

        $this->assertTrue($plan->tieneIa());
        $this->assertTrue($plan->permiteExtension('conversation_summary'));
        $this->assertTrue($plan->permiteAjuste('sentiment_traffic_light', 'usar_ia'));

        // Pero los flujos caros son del complemento Completa.
        $this->assertFalse($plan->permiteFlujoIa('ai_chat'));
        $this->assertFalse($plan->permiteFlujoIa('ai_menus'));
    }

    public function test_instalar_fuera_de_plan_responde_402(): void
    {
        $company = $this->empresa(['plan' => 'basico']);

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
        $company = $this->empresa(['plan' => 'basico']);

        $this->actingAs($this->admin($company))
            ->postJson('/api/extensions/conversation_summary/install')
            ->assertStatus(402)
            ->assertJsonPath('message', 'Esta extensión no está incluida en tu plan.');
    }

    public function test_guardar_ajustes_no_cuela_la_ia_de_tapadillo(): void
    {
        $company = $this->empresa(['plan' => 'basico']);

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

    /**
     * El crédito de IA sale del plan de CRM, no del complemento.
     *
     * Es un número que depende del **tamaño del cliente** —cuánta conversación
     * mueve— y no de qué funciones tenga encendidas. El complemento decide qué
     * se enciende; el plan, cuánto cabe.
     */
    public function test_el_credito_sale_del_plan_de_crm(): void
    {
        foreach (config('planes.crm') as $slug => $datos) {
            $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => $slug, 'ia' => 'completa']));

            $this->assertSame($datos['credito_ia'], $plan->creditoIa());
        }
    }

    /** Y sin complemento es cero: no hay nada que consumir. */
    public function test_sin_complemento_el_credito_es_cero(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'avanzado']));

        $this->assertSame(0, $plan->creditoIa());
    }

    // ─── Precio ──────────────────────────────────────────────────────────────

    /**
     * El precio es un número fijo por plan, no un rango por tramo.
     *
     * Se cambió el 15-sep-2026: antes eran quince precios —cinco tramos por tres
     * planes— y un rango se lee como «depende» o como negociable. Ahora se dice
     * un número en la mesa.
     */
    public function test_cada_plan_tiene_su_precio_fijo(): void
    {
        foreach (config('planes.crm') as $slug => $datos) {
            $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => $slug]));

            $this->assertSame($datos['precio'], $plan->precioMensual());
        }
    }

    /** El complemento de IA se suma al plan: son dos cosas que se venden aparte. */
    public function test_el_complemento_se_suma_al_precio_del_plan(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'pro', 'ia' => 'completa']));

        $this->assertSame(
            config('planes.crm.pro.precio') + config('planes.ia.completa.precio'),
            $plan->precioMensual()
        );
    }

    /**
     * Al cliente de Integra sólo se le cobra el complemento.
     *
     * El CRM ya se lo cobró el ERP. Cobrarle también el plan sería cobrarle dos
     * veces lo mismo, y es lo que hacía el panel antes de distinguirlos: contaba
     * 2.391 USD/mes de facturación potencial sobre gente que ya pagaba.
     */
    public function test_al_de_integra_solo_se_le_cobra_el_complemento(): void
    {
        $sinIa = PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'avanzado', 'viene_de_integra' => true, 'cobro' => 'integra',
        ]));

        $this->assertSame(0, $sinIa->precioMensual());
        $this->assertFalse($sinIa->seFactura());

        $conIa = PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'avanzado', 'ia' => 'esencial',
            'viene_de_integra' => true, 'cobro' => 'integra',
        ]));

        $this->assertSame(config('planes.ia.esencial.precio'), $conIa->precioMensual());
        $this->assertTrue($conIa->seFactura(), 'Contratar la IA es lo que le hace entrar en la factura.');
    }

    /** Dos meses gratis pagando el año: diez mensualidades repartidas en doce. */
    public function test_el_anual_son_diez_mensualidades(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'avanzado']));

        $esperado = (int) round(config('planes.crm.avanzado.precio') * 10 / 12);

        $this->assertSame($esperado, $plan->precioMensualAnual());
        $this->assertSame(2, (int) config('planes.meses_gratis_al_pagar_anual'));
    }

    /** Ningún plan del catálogo puede quedarse sin precio o sin crédito. */
    public function test_ningun_plan_se_queda_a_medias(): void
    {
        foreach (config('planes.crm') as $slug => $datos) {
            foreach (['precio', 'agentes', 'contactos', 'lineas', 'credito_ia'] as $campo) {
                $this->assertArrayHasKey($campo, $datos, "A {$slug} le falta {$campo}.");
                $this->assertGreaterThan(0, $datos[$campo], "{$slug}.{$campo} no puede ser cero.");
            }
        }

        foreach (config('planes.ia') as $slug => $datos) {
            $this->assertArrayHasKey('precio', $datos, "Al complemento {$slug} le falta el precio.");
        }
    }

    // ─── Lo que tiene de verdad ──────────────────────────────────────────────

    /**
     * Avisa de en QUÉ se pasó, no sólo de que se pasó.
     *
     * Son conversaciones distintas: «tienes más agentes de los que incluye tu
     * plan» se resuelve de otra manera que «te crecieron los contactos». Con un
     * `true` a secas, quien llama al cliente no sabe de qué hablarle.
     *
     * Y no bloquea nada: nadie deja de atender a un cliente porque la empresa
     * creció.
     */
    public function test_avisa_de_en_que_se_paso(): void
    {
        $company = $this->empresa(['plan' => 'basico']);
        $this->contactos($company, config('planes.crm.basico.contactos') + 1);

        $plan = PlanDeLaEmpresa::de($company->refresh());

        $this->assertSame(['contactos'], $plan->sePasoDe());
        $this->assertTrue($plan->sePasoDelTramo());

        // Y sigue pudiendo instalar todo lo suyo.
        $this->assertTrue($plan->permiteExtension('keyword_routing'));
    }

    /**
     * El plan sugerido mira contactos Y agentes, no sólo contactos.
     *
     * Una empresa con 400 contactos pero cuatro agentes no cabe en Básico
     * aunque le sobren contactos, y ponerla ahí la marcaría como «pasada de
     * plan» desde el primer día.
     */
    public function test_el_plan_sugerido_mira_las_dos_cosas(): void
    {
        $chica = $this->empresa();
        $this->assertSame('basico', PlanDeLaEmpresa::de($chica)->planSugerido());

        $conMuchosAgentes = $this->empresa();
        $this->agentes($conMuchosAgentes, config('planes.crm.basico.agentes') + 1);

        $this->assertSame(
            'pro',
            PlanDeLaEmpresa::de($conMuchosAgentes->refresh())->planSugerido(),
            'Con más agentes de los que incluye Básico, el suelo es Pro.'
        );
    }

    /** Un usuario inactivo no cuenta como agente: no atiende a nadie. */
    public function test_los_agentes_inactivos_no_cuentan(): void
    {
        $company = $this->empresa();
        $this->agentes($company, 3, activos: false);

        $this->assertSame(0, PlanDeLaEmpresa::de($company)->agentesReales());
    }


    public function test_suspendido_no_apaga_las_extensiones(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa([
            'plan' => 'avanzado', 'ia' => 'completa',
            'cobro' => 'suspendido',
        ]));

        $this->assertTrue($plan->seFactura());
        $this->assertTrue($plan->permiteExtension('conversation_summary'));
        $this->assertTrue($plan->tieneIa());
    }

    /**
     * Un plan que no existe cae en el suelo, y sigue teniendo el CRM entero.
     *
     * Antes caía en el plan más alto, para no degradar a nadie al introducir los
     * planes. Ahora el suelo es lo correcto: el CRM va completo en los tres, así
     * que caer en Básico no le quita ninguna función — sólo deja de regalar el
     * tamaño grande a quien tenga la columna con un plan retirado del catálogo.
     */
    public function test_un_plan_desconocido_cae_en_el_suelo_con_el_crm_entero(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['plan' => 'premium-que-no-existe']));

        $this->assertSame('basico', $plan->slug());

        foreach (config('planes.extensiones_del_crm') as $extension) {
            $this->assertTrue($plan->permiteExtension($extension));
        }
    }

    /** Y un complemento de IA que no existe no abre nada. */
    public function test_un_complemento_desconocido_no_abre_la_ia(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['ia' => 'ultra-que-no-existe']));

        $this->assertSame('ninguno', $plan->slugIa());
        $this->assertFalse($plan->tieneIa());
        $this->assertFalse($plan->permiteExtension('conversation_summary'));
    }
}
