<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las guías se ven con cualquier rol: son a donde se va cuando no se sabe
 * hacer algo, y quien no puede crear plantillas también tiene que poder
 * contestarle a un cliente cuánto tarda Meta en aprobarlas.
 */
class GuiasTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_usuario_sin_permisos_ve_el_indice_y_la_guia(): void
    {
        $usuario = $this->agenteSinPermisos();

        $this->actingAs($usuario)->get('/guias')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Guias/Index'));

        $this->actingAs($usuario)->get('/guias/crear-plantilla')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Guias/Show')->where('slug', 'crear-plantilla'));
    }

    public function test_sin_sesion_pide_entrar(): void
    {
        $this->get('/guias')->assertRedirect();
        $this->get('/guias/crear-plantilla')->assertRedirect();
    }

    /** El slug va a una URL que se pega por WhatsApp: nada raro cabe en él. */
    public function test_un_slug_con_caracteres_raros_no_existe(): void
    {
        $this->actingAs($this->agenteSinPermisos())->get('/guias/Crear_Plantilla')->assertNotFound();
    }

    private function agenteSinPermisos(): User
    {
        $empresa = Company::create(['name' => 'ISP', 'slug' => 'isp-'.Str::random(5), 'active' => true]);

        return User::create([
            'company_id' => $empresa->id, 'name' => 'Agente',
            'email' => Str::random(8).'@test.local', 'password' => bcrypt('x'),
            'role' => 'agent', 'active' => true,
        ]);
    }
}
