<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * El cupo de líneas por canal: el Básico es una de WhatsApp, una de Instagram
 * y una de Messenger.
 *
 * Desde el 30-sep-2026 es la única regla de plan que bloquea. No apaga nada de
 * lo que ya funciona: impide conectar o reactivar una línea más de un canal que
 * ya tiene lleno, y dice qué hacer — desconectar la actual o subir de plan.
 */
class CupoDeLineasTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_basico_admite_una_linea_de_cada_canal(): void
    {
        $company = $this->empresa();
        $this->linea($company, Instance::CANAL_WHATSAPP);

        $plan = PlanDeLaEmpresa::de($company);

        $this->assertFalse($plan->puedeConectar(Instance::CANAL_WHATSAPP));
        $this->assertTrue($plan->puedeConectar(Instance::CANAL_MESSENGER), 'Su WhatsApp no gasta el cupo de Messenger.');
        $this->assertTrue($plan->puedeConectar(Instance::CANAL_INSTAGRAM));
        $this->assertStringContainsString('primero desconecta la actual', $plan->motivoParaNoConectar(Instance::CANAL_WHATSAPP));
        $this->assertStringContainsString('Pro', $plan->motivoParaNoConectar(Instance::CANAL_WHATSAPP));
    }

    /** Antes sumaban todas: WhatsApp + su página de Facebook salía «pasado de líneas». */
    public function test_messenger_no_cuenta_como_linea_de_whatsapp(): void
    {
        $company = $this->empresa();
        $this->linea($company, Instance::CANAL_WHATSAPP);
        $this->linea($company, Instance::CANAL_MESSENGER);

        $plan = PlanDeLaEmpresa::de($company);

        $this->assertSame(1, $plan->lineasReales());
        $this->assertNotContains('lineas', $plan->sePasoDe());
    }

    public function test_el_pro_admite_dos_de_whatsapp(): void
    {
        $company = $this->empresa(['plan' => 'pro']);
        $this->linea($company, Instance::CANAL_WHATSAPP);

        $this->assertTrue(PlanDeLaEmpresa::de($company)->puedeConectar(Instance::CANAL_WHATSAPP));
    }

    /** Un precio a medida ya negoció lo que tiene: el cupo del catálogo no le aplica. */
    public function test_a_medida_no_tiene_cupo(): void
    {
        $company = $this->empresa(['precio_personalizado' => 300]);
        $this->linea($company, Instance::CANAL_WHATSAPP);

        $this->assertTrue(PlanDeLaEmpresa::de($company)->puedeConectar(Instance::CANAL_WHATSAPP));
    }

    public function test_anadir_a_mano_una_segunda_linea_se_rechaza(): void
    {
        $admin = $this->admin();
        $this->linea($admin->company, Instance::CANAL_WHATSAPP);

        $this->actingAs($admin)
            ->post('/instances', [
                'name' => 'Segunda',
                'phone_number_id' => '999',
                'waba_id' => '888',
            ])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'primero desconecta la actual'));

        $this->assertSame(1, Instance::where('company_id', $admin->company_id)->count());
    }

    /**
     * Desactivar la vieja, conectar otra y reactivar la vieja daría dos líneas
     * en el Básico. Reactivar pasa por el cupo.
     */
    public function test_reactivar_una_linea_pasa_por_el_cupo(): void
    {
        $admin = $this->admin();
        $apagada = $this->linea($admin->company, Instance::CANAL_WHATSAPP, activa: false);
        $encendida = $this->linea($admin->company, Instance::CANAL_WHATSAPP);

        $this->actingAs($admin)
            ->post("/instances/{$apagada->id}/reconectar")
            ->assertSessionHas('error');

        $this->assertFalse($apagada->refresh()->active);

        // Con la otra desconectada, sí.
        $encendida->update(['active' => false]);

        $this->actingAs($admin)
            ->post("/instances/{$apagada->id}/reconectar")
            ->assertSessionHas('success');

        $this->assertTrue($apagada->refresh()->active);
    }

    /** Se corta antes de canjear el código, que es de un solo uso. */
    public function test_el_registro_insertado_se_corta_antes_de_ir_a_meta(): void
    {
        Http::fake();
        $admin = $this->admin();
        $this->linea($admin->company, Instance::CANAL_WHATSAPP);

        $this->actingAs($admin)
            ->postJson('/api/embedded-signup', ['code' => 'AQB-codigo', 'waba_id' => '1', 'phone_number_id' => '2'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'primero desconecta la actual'));

        Http::assertNothingSent();
    }

    /** Ni siquiera se le manda a Facebook: volver de allí para leer «no» es peor. */
    public function test_messenger_lleno_no_abre_la_autorizacion(): void
    {
        config(['services.meta.app_id' => '865904982715022', 'services.meta.app_secret' => 'x']);
        $admin = $this->admin();
        $this->linea($admin->company, Instance::CANAL_MESSENGER);

        $this->actingAs($admin)
            ->from('/instances')
            ->get('/instancias/conectar-messenger')
            ->assertRedirect('/instances')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Messenger'));
    }

    public function test_la_pantalla_recibe_el_cupo_de_cada_canal(): void
    {
        $admin = $this->admin();
        $this->linea($admin->company, Instance::CANAL_WHATSAPP);

        $this->actingAs($admin)
            ->get('/instances')
            ->assertInertia(fn ($page) => $page
                ->where('cupos.whatsapp.usadas', 1)
                ->where('cupos.whatsapp.incluidas', 1)
                ->where('cupos.whatsapp.puede', false)
                ->where('cupos.messenger.puede', true)
                ->where('cupos.instagram.puede', true)
            );
    }

    private function empresa(array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
            'plan' => 'basico',
        ], $extra));
    }

    private function linea(Company $company, string $canal, bool $activa = true): Instance
    {
        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => $canal.' '.uniqid(),
            'channel' => $canal,
            'phone_number_id' => $canal === Instance::CANAL_WHATSAPP ? (string) random_int(100000, 999999) : null,
            'external_account_id' => $canal === Instance::CANAL_WHATSAPP ? null : (string) random_int(100000, 999999),
            'type' => 'meta',
            'active' => $activa,
            'access_token' => 'token',
        ]);
    }

    private function admin(): User
    {
        $company = $this->empresa();

        foreach (['instances.create', 'instances.view', 'instances.update'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        return User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'a'.uniqid().'@fibra.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);
    }
}
