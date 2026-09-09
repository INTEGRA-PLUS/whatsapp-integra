<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Services\MessagingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El aviso de cuánta gente admite hoy un número.
 *
 * WhatsApp limita a cuántos destinatarios distintos se le puede escribir primero
 * en 24 horas, por tramos, y no hay forma de acelerarlo. El producto ya leía el
 * tramo y lo pintaba en Configuración, pero no lo usaba: una campaña de 12.000
 * sobre un número en tramo de 1.000 arrancaba igual y el resto empezaba a
 * fallar de uno en uno.
 *
 * Esto avisa, no bloquea: el tramo es el techo por 24 horas y una campaña
 * repartida en varios días cabe de sobra.
 */
class MessagingLimitTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $company = Company::create([
            'name' => 'Cooperativa',
            'slug' => 'coop-'.Str::random(6),
            'active' => true,
        ]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '2253367844691746',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => Str::random(8).'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        Permission::firstOrCreate(['name' => 'campaigns.view', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web']);
        $role->syncPermissions(['campaigns.view']);
        $this->user->assignRole($role);
    }

    public function test_avisa_cuando_la_campana_no_cabe_en_el_tramo(): void
    {
        $this->meta(['messaging_limit_tier' => 'TIER_1K', 'quality_rating' => 'GREEN']);

        $r = app(MessagingLimitService::class)->revisarCampana($this->instance, 12000);

        $this->assertFalse($r['cabe']);
        $this->assertSame(1000, $r['limite']);
        $this->assertStringContainsString('1.000', $r['aviso']);
        $this->assertStringContainsString('12.000', $r['aviso']);
        // Dice cuántos se quedan fuera, que es el dato que se va a repetir.
        $this->assertStringContainsString('11.000', $r['aviso']);
    }

    public function test_no_avisa_cuando_cabe(): void
    {
        $this->meta(['messaging_limit_tier' => 'TIER_100K', 'quality_rating' => 'GREEN']);

        $r = app(MessagingLimitService::class)->revisarCampana($this->instance, 12000);

        $this->assertTrue($r['cabe']);
        $this->assertNull($r['aviso']);
    }

    public function test_un_numero_sin_limite_nunca_avisa(): void
    {
        $this->meta(['messaging_limit_tier' => 'TIER_UNLIMITED']);

        $r = app(MessagingLimitService::class)->revisarCampana($this->instance, 500000);

        $this->assertTrue($r['cabe']);
        $this->assertTrue($r['ilimitado']);
    }

    /**
     * Ante la duda, dejar pasar: un aviso inventado es peor que ninguno.
     */
    public function test_si_meta_no_contesta_no_se_inventa_un_aviso(): void
    {
        Http::fake(fn () => Http::response(['error' => 'nope'], 500));

        $r = app(MessagingLimitService::class)->revisarCampana($this->instance, 12000);

        $this->assertTrue($r['cabe']);
        $this->assertFalse($r['conocido']);
        $this->assertNull($r['aviso']);
    }

    /** Un tramo que Meta añada mañana no debe romper nada ni inventar avisos. */
    public function test_un_tramo_desconocido_no_bloquea(): void
    {
        $this->meta(['messaging_limit_tier' => 'TIER_500K']);

        $r = app(MessagingLimitService::class)->revisarCampana($this->instance, 12000);

        $this->assertTrue($r['cabe']);
        $this->assertFalse($r['conocido']);
    }

    public function test_la_calidad_en_rojo_avisa_aunque_la_campana_quepa(): void
    {
        $this->meta(['messaging_limit_tier' => 'TIER_100K', 'quality_rating' => 'RED']);

        $r = app(MessagingLimitService::class)->revisarCampana($this->instance, 100);

        $this->assertTrue($r['cabe']);
        $this->assertStringContainsString('rojo', $r['aviso']);
    }

    public function test_el_asistente_puede_consultarlo(): void
    {
        $this->meta(['messaging_limit_tier' => 'TIER_1K', 'quality_rating' => 'GREEN']);

        $this->actingAs($this->user)
            ->getJson(route('campaigns.capacity', ['instance_id' => $this->instance->id, 'recipients' => 12000]))
            ->assertOk()
            ->assertJson(['cabe' => false, 'limite' => 1000, 'tier' => 'TIER_1K']);
    }

    public function test_no_se_puede_consultar_el_numero_de_otra_empresa(): void
    {
        $otra = Company::create(['name' => 'Ajena', 'slug' => 'aj-'.Str::random(6), 'active' => true]);
        $suya = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Ajena',
            'phone_number_id' => '999999999999',
            'waba_id' => 'waba-2',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $this->actingAs($this->user)
            ->getJson(route('campaigns.capacity', ['instance_id' => $suya->id, 'recipients' => 10]))
            ->assertNotFound();
    }

    private function meta(array $campos): void
    {
        Http::fake(fn () => Http::response(array_merge([
            'id' => '2253367844691746',
            'display_phone_number' => '+57 311 5775385',
        ], $campos), 200));
    }
}
