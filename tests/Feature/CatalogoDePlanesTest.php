<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «Planes»: el catálogo entero, para comparar y para pedir el cambio.
 *
 * Lo que se protege: que pedir un cambio **no cambie nada** —no hay
 * autoservicio, el precio se negocia— y que el aviso llegue a quien puede
 * aplicarlo. Un botón que dijera «plan cambiado» y luego no cambiara nada es
 * peor que no tenerlo.
 */
class CatalogoDePlanesTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function el_catalogo_trae_los_precios(): void
    {
        $company = $this->empresa(['plan' => 'basico']);

        $this->actingAs($this->admin($company))
            ->get('/planes')
            ->assertInertia(fn ($page) => $page
                ->component('Planes/Index')
                ->where('actual.crm', 'basico')
                ->has('crm', 3)
                ->where('crm.1.precio', config('planes.crm.pro.precio'))
                ->has('ia', 3)
                ->where('ia.2.precio', config('planes.ia.completa.precio'))
            );
    }

    /**
     * Al cliente de Integra se le dice que su CRM ya está pagado.
     *
     * Sin eso, ver «Pro $59» cuando llevas dos años sin pagarlo se lee como una
     * subida de precio.
     *
     * @test
     */
    public function al_de_integra_se_le_marca_que_el_crm_va_incluido(): void
    {
        $company = $this->empresa(['plan' => 'pro', 'viene_de_integra' => true]);

        $this->actingAs($this->admin($company))
            ->get('/planes')
            ->assertInertia(fn ($page) => $page->where('actual.incluido_en_integra', true));
    }

    /** Pedir un cambio avisa a los master y no toca el plan. */
    public function test_pedir_un_cambio_avisa_y_no_cambia_nada(): void
    {
        Notification::fake();

        $company = $this->empresa(['plan' => 'basico']);
        $master = $this->master();

        $this->actingAs($this->admin($company))
            ->post('/planes/solicitar', ['crm' => 'pro'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('basico', $company->refresh()->plan, 'Pedirlo no lo aplica.');

        Notification::assertSentTo($master, SystemNotification::class);
    }

    /** Pedir lo que ya se tiene no molesta a nadie. */
    public function test_pedir_lo_que_ya_tiene_no_avisa(): void
    {
        Notification::fake();

        $company = $this->empresa(['plan' => 'pro']);
        $this->master();

        $this->actingAs($this->admin($company))
            ->post('/planes/solicitar', ['crm' => 'pro'])
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    /** Y un plan que no existe se rechaza. */
    public function test_un_plan_inventado_se_rechaza(): void
    {
        $company = $this->empresa(['plan' => 'basico']);

        $this->actingAs($this->admin($company))
            ->post('/planes/solicitar', ['crm' => 'platino'])
            ->assertSessionHasErrors('crm');
    }

    /**
     * IA Completa dice que el menú lo puede contestar la IA.
     *
     * Es lo que se vende y no salía en ninguna parte: la tarjeta decía «la IA
     * responde los chats» y «resuelve contra tu ERP», y quien leía eso no sabía
     * que una opción de su menú la puede contestar la IA con su documentación.
     *
     * La lista se deriva de los flujos del complemento, así que no puede
     * prometer nada que el plan no encienda.
     *
     * @test
     */
    public function la_ia_completa_dice_que_el_menu_lo_contesta_la_ia(): void
    {
        $company = $this->empresa(['plan' => 'pro']);

        $this->actingAs($this->admin($company))
            ->get('/planes')
            ->assertInertia(function ($page) {
                $completa = collect($page->toArray()['props']['ia'])->firstWhere('slug', 'completa');
                $frases = collect($completa['flujos'])->implode(' | ');

                $this->assertStringContainsString('menú', $frases);
                $this->assertStringContainsString('documentación', $frases);
                $this->assertStringContainsString('ERP', $frases);
            });
    }

    /**
     * IA Esencial resuelve contra el ERP, pero NO promete conversar.
     *
     * Es la mitad que importa: prometer en el de 19 lo que sólo abre el de 49
     * es una devolución. Y la frontera entre los dos niveles es exactamente
     * ésa —resolver una consulta no es conversar— así que se comprueba por las
     * palabras que ve el cliente y no por el nombre del candado.
     *
     * @test
     */
    public function la_ia_esencial_resuelve_pero_no_conversa(): void
    {
        $company = $this->empresa(['plan' => 'pro']);

        $this->actingAs($this->admin($company))
            ->get('/planes')
            ->assertInertia(function ($page) {
                $esencial = collect($page->toArray()['props']['ia'])->firstWhere('slug', 'esencial');
                $frases = collect($esencial['flujos'])->implode(' | ');

                $this->assertStringContainsString('ERP', $frases);
                $this->assertStringNotContainsString('responde los chats', $frases);
                $this->assertStringNotContainsString('documentación', $frases);
            });
    }

    /**
     * Y el candado de conversar sigue siendo sólo del de 49.
     *
     * `permiteFlujoIa('ai_chat')` es lo que abre la IA de los chats y la opción
     * de menú con IA. Que la frase no salga en Esencial no basta: lo que hay que
     * proteger es que el plan tampoco lo encienda.
     *
     * @test
     */
    public function el_candado_de_conversar_sigue_siendo_del_de_49(): void
    {
        $esencial = \App\Support\PlanDeLaEmpresa::de($this->empresa(['plan' => 'pro', 'ia' => 'esencial']));
        $completa = \App\Support\PlanDeLaEmpresa::de($this->empresa(['plan' => 'pro', 'ia' => 'completa']));

        $this->assertTrue($esencial->permiteFlujoIa('ai_menus'), 'Esencial resuelve contra el ERP.');
        $this->assertFalse($esencial->permiteFlujoIa('ai_chat'), 'Pero no conversa.');

        $this->assertTrue($completa->permiteFlujoIa('ai_menus'));
        $this->assertTrue($completa->permiteFlujoIa('ai_chat'));
    }

    private function empresa(array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
        ], $extra));
    }

    private function master(): User
    {
        $empresa = $this->empresa();
        $user = User::create([
            'company_id' => $empresa->id,
            'name' => 'Master',
            'email' => 'm'.uniqid().'@test.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'master',
        ]);

        setPermissionsTeamId($empresa->id);
        $rol = Role::firstOrCreate(['name' => 'master', 'company_id' => $empresa->id, 'guard_name' => 'web']);
        $user->assignRole($rol);

        return $user->fresh();
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
        $rol = Role::firstOrCreate(['name' => 'op', 'company_id' => $company->id, 'guard_name' => 'web']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'extensions.view', 'guard_name' => 'web']));
        $user->assignRole($rol);

        return $user->fresh();
    }
}
