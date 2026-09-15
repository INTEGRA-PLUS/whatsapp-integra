<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Contact;
use App\Models\User;
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

    public function test_el_admin_ve_su_plan(): void
    {
        $company = $this->empresa(['plan' => 'automatizacion', 'contactos_contratados' => 2000]);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MiPlan/Index')
                ->where('plan.plan_nombre', 'Automatización')
                ->where('plan.tiene_ia', false));
    }

    /** Lo bloqueado se enseña: esconderlo hace que nadie pregunte por ello. */
    public function test_ensena_tambien_lo_que_no_tiene(): void
    {
        $company = $this->empresa(['plan' => 'esencial']);

        $this->actingAs($this->admin($company))
            ->get('/mi-plan')
            ->assertOk()
            ->assertInertia(function ($page) {
                $extensiones = collect($page->toArray()['props']['extensiones']);

                $this->assertTrue($extensiones->contains('slug', 'conversation_summary'));

                $resumen = $extensiones->firstWhere('slug', 'conversation_summary');
                $this->assertFalse($resumen['en_plan']);
                // Y dice en cuál está, no un «no incluido» a secas.
                $this->assertSame('Inteligente', $resumen['plan_minimo']);
            });
    }

    public function test_cuenta_sus_contactos_y_no_los_de_otra_empresa(): void
    {
        $mia = $this->empresa(['contactos_contratados' => 500]);
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
        $company = $this->empresa(['contactos_contratados' => 2000]);

        $respuesta = $this->actingAs($this->admin($company))->get('/mi-plan')->assertOk();

        $props = $respuesta->viewData('page')['props'];

        $this->assertArrayHasKey('uso_ia', $props);
        $this->assertArrayNotHasKey('coste_usd', $props['plan']);
    }

    public function test_marca_las_extensiones_que_tiene_encendidas(): void
    {
        $company = $this->empresa(['plan' => 'inteligente']);

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
}
