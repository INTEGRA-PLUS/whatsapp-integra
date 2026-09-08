<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El apartado "Flujo IA" de Configuración.
 *
 * Lo que se prueba es la puerta: que sin el secreto no se enciende nada, que un
 * secreto mal escrito no deja rastro de por dónde ir, y que bloquear de nuevo
 * apaga lo que estaba encendido —dejarlo hablando detrás de una puerta cerrada
 * sería lo peor de los dos mundos.
 */
class AiFlowSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true]);

        $this->user = User::create([
            'company_id' => $this->company->id,
            'name' => 'Admin',
            'email' => 'admin@fibra.test',
            'password' => 'secret',
            'active' => true,
        ]);

        $role = Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web',
        ]);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'whatsapp_menus.update', 'guard_name' => 'web',
        ]));
        $this->user->assignRole($role);

        config([
            'services.ai_activation.secret' => 'abre-sesamo',
            'services.ai_menus.webhook_url' => 'https://n8n.example.test/webhook/whatsapp-menu-ia',
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'n8n_llave',
        ]);
    }

    private function unlocked(): void
    {
        $this->company->forceFill(['ai_flow_unlocked_at' => now(), 'ai_flow_unlocked_by' => $this->user->id])->save();
    }

    public function test_nace_bloqueado(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/settings/ai-flow')
            ->assertOk()
            ->assertJsonPath('unlocked', false)
            ->assertJsonPath('platform.secret_configured', true);
    }

    public function test_el_secreto_correcto_desbloquea_y_deja_rastro(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/settings/ai-flow/unlock', ['secret' => 'abre-sesamo'])
            ->assertOk()
            ->assertJsonPath('unlocked', true);

        $this->company->refresh();
        $this->assertNotNull($this->company->ai_flow_unlocked_at);
        // Quién lo encendió es la primera pregunta el día que la IA diga algo
        // que no debía.
        $this->assertSame($this->user->id, $this->company->ai_flow_unlocked_by);
    }

    public function test_un_secreto_equivocado_no_abre_nada(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/settings/ai-flow/unlock', ['secret' => 'casi-casi'])
            ->assertStatus(422);

        $this->assertNull($this->company->fresh()->ai_flow_unlocked_at);
    }

    public function test_sin_secreto_en_el_servidor_el_apartado_queda_cerrado(): void
    {
        // Que falte la variable no puede significar "todo vale": sería dejar la
        // IA a un clic en las instalaciones donde nadie la configuró.
        config(['services.ai_activation.secret' => null]);

        $this->actingAs($this->user)
            ->postJson('/api/settings/ai-flow/unlock', ['secret' => ''])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->postJson('/api/settings/ai-flow/unlock', ['secret' => 'lo-que-sea'])
            ->assertStatus(422);

        $this->assertNull($this->company->fresh()->ai_flow_unlocked_at);
    }

    public function test_sin_desbloquear_no_se_toca_ningun_interruptor(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/settings/ai-flow', ['chat_enabled' => true])
            ->assertStatus(403);

        $this->assertFalse(CompanyIntegration::chatAiEnabled($this->company->id));
    }

    public function test_enciende_la_ia_de_chats(): void
    {
        $this->unlocked();

        $this->actingAs($this->user)
            ->putJson('/api/settings/ai-flow', ['chat_enabled' => true])
            ->assertOk()
            ->assertJsonPath('chat.enabled', true);

        $this->assertTrue(CompanyIntegration::chatAiEnabled($this->company->id));
    }

    public function test_no_deja_encender_lo_que_el_servidor_no_tiene_configurado(): void
    {
        // Un interruptor en verde sin flujo detrás es peor que no tenerlo: el
        // admin cree que la IA atiende y nadie contesta.
        $this->unlocked();
        config(['services.ai_chat.api_key' => null]);

        $this->actingAs($this->user)
            ->putJson('/api/settings/ai-flow', ['chat_enabled' => true])
            ->assertStatus(422);

        $this->assertFalse(CompanyIntegration::chatAiEnabled($this->company->id));
    }

    public function test_los_permisos_se_guardan_canonicos(): void
    {
        $this->unlocked();

        $this->actingAs($this->user)
            ->putJson('/api/settings/ai-flow', ['permissions' => ['pagos', 'leer', 'pagos']])
            ->assertOk()
            ->assertJsonPath('menus.permissions', ['leer', 'pagos']);
    }

    public function test_un_permiso_inventado_se_rechaza(): void
    {
        $this->unlocked();

        $this->actingAs($this->user)
            ->putJson('/api/settings/ai-flow', ['permissions' => ['borrar_todo']])
            ->assertStatus(422);
    }

    public function test_bloquear_apaga_las_dos_ia(): void
    {
        $this->unlocked();

        $this->actingAs($this->user)->putJson('/api/settings/ai-flow', [
            'chat_enabled' => true, 'menus_enabled' => true,
        ])->assertOk();

        $this->actingAs($this->user)
            ->deleteJson('/api/settings/ai-flow/unlock')
            ->assertOk()
            ->assertJsonPath('unlocked', false)
            ->assertJsonPath('chat.enabled', false)
            ->assertJsonPath('menus.enabled', false);

        $this->assertFalse(CompanyIntegration::chatAiEnabled($this->company->id));
        $this->assertNull($this->company->fresh()->ai_flow_unlocked_at);
    }

    public function test_sin_permiso_no_se_entra(): void
    {
        $otro = User::create([
            'company_id' => $this->company->id,
            'name' => 'Agente', 'email' => 'agente@fibra.test',
            'password' => 'secret', 'active' => true,
        ]);

        $this->actingAs($otro)->getJson('/api/settings/ai-flow')->assertStatus(403);
    }
}
