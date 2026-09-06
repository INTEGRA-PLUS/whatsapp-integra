<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vigilante de instancias caídas.
 *
 * El 2026-09-05 aparecieron cinco empresas cuyo token o número ya no existían
 * en Meta —la más antigua llevaba seis meses— y las cinco se mostraban
 * "Activa" en verde, porque `active` es una casilla nuestra y no una
 * comprobación. Se descubrieron de casualidad, revisando otra cosa.
 *
 * Lo que se protege aquí son las dos mitades del arreglo: que la caída se
 * detecte, y que el aviso no se convierta en ruido diario — porque una alerta
 * que se repite todos los días es otra forma de que nadie la mire.
 */
class InstanceHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_instancia_viva_queda_marcada_ok(): void
    {
        Http::fake(['*' => Http::response(['id' => '123', 'display_phone_number' => '+57 300'], 200)]);
        $instance = $this->instancia();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('ok', $instance->health_status);
        $this->assertNotNull($instance->health_checked_at);
        $this->assertNull($instance->health_error);
    }

    /** La caída se detecta y el motivo se guarda para no repetir la consulta. */
    public function test_una_instancia_muerta_se_marca_y_guarda_el_motivo(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => "Unsupported get request. Object with ID '975117929018923' does not exist"],
        ], 400)]);

        $instance = $this->instancia();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('unreachable', $instance->health_status);
        $this->assertStringContainsString('does not exist', $instance->health_error);
    }

    /** Y se avisa a quien puede arreglarlo: los admins de esa empresa. */
    public function test_avisa_a_los_admins_de_la_empresa(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['error' => ['message' => 'token inválido']], 400)]);

        $instance = $this->instancia();
        $admin = $this->admin($instance->company_id);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        Notification::assertSentTo($admin, SystemNotification::class);
    }

    /**
     * El aviso NO se repite mientras siga caída.
     *
     * Repetirlo cada día lo convierte en ruido, y el ruido es justo lo que hizo
     * que nadie mirara las cinco que ya llevaban meses caídas.
     */
    public function test_no_repite_el_aviso_al_dia_siguiente(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'token inválido']], 400)]);

        $instance = $this->instancia();
        $admin = $this->admin($instance->company_id);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        // Segunda vuelta: sigue caída, pero el estado ya no cambia.
        Notification::fake();
        $this->artisan('whatsapp:health-check')->assertSuccessful();

        Notification::assertNothingSentTo($admin);
    }

    /** Si se recupera, vuelve a ok y el siguiente fallo sí vuelve a avisar. */
    public function test_al_recuperarse_vuelve_a_ok(): void
    {
        $instance = $this->instancia(['health_status' => 'unreachable', 'health_error' => 'lo que fuera']);
        Http::fake(['*' => Http::response(['id' => '123'], 200)]);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('ok', $instance->health_status);
        $this->assertNull($instance->health_error);
    }

    /** Una instancia sin token no se consulta: se marca caída de una. */
    public function test_una_instancia_sin_token_se_marca_caida_sin_llamar_a_meta(): void
    {
        Http::fake();
        $instance = $this->instancia(['access_token' => null]);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $this->assertSame('unreachable', $instance->refresh()->health_status);
        Http::assertNothingSent();
    }

    /** Las inactivas no se revisan: nadie espera mensajes de ellas. */
    public function test_las_inactivas_no_se_revisan(): void
    {
        Http::fake();
        $this->instancia(['active' => false]);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function instancia(array $extra = []): Instance
    {
        $company = Company::create([
            'name' => 'Fibra ' . Str::random(4),
            'slug' => 'fibra-' . Str::random(6),
            'active' => true,
        ]);

        return Instance::create(array_merge([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea principal',
            'phone_number_id' => '975117929018923',
            'waba_id' => '25479282995026673',
            'display_phone_number' => '+57 312 4579765',
            'access_token' => 'EAA-token',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ], $extra));
    }

    private function admin(int $companyId): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => 'Admin',
            'email' => 'admin-' . Str::random(6) . '@fibra.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);
    }
}
