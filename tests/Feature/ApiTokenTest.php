<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La credencial de la API v1.
 *
 * El esquema original usaba el `phone_number_id` como token, y eso no es un
 * secreto: se enseña en la pantalla de Instancias del propio producto y en el
 * panel de Meta. Quien lo conociera podía leer los mensajes de esa empresa y
 * enviar en su nombre.
 *
 * Se acepta el esquema viejo mientras queden ERP por migrar, y por eso hace
 * falta un interruptor que lo apague y pruebas de que lo apaga de verdad.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.x']]], 200));

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
            'access_token' => 'token-meta',
        ]);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => Str::random(8).'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        Permission::firstOrCreate(['name' => 'instances.update', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web']);
        $role->syncPermissions(['instances.update']);
        $this->user->assignRole($role);
    }

    public function test_el_token_solo_se_ve_al_generarlo_y_se_guarda_hasheado(): void
    {
        $res = $this->actingAs($this->user)
            ->postJson(route('instances.api-token', $this->instance->id))
            ->assertOk();

        $token = $res->json('token');

        $this->assertStringStartsWith('wai_', $token);
        $this->assertFalse($res->json('reemplaza_uno_anterior'));

        $this->instance->refresh();

        // En la base vive el hash, nunca el token.
        $this->assertNotSame($token, $this->instance->api_token);
        $this->assertSame(hash('sha256', $token), $this->instance->api_token);
        $this->assertNotNull($this->instance->api_token_created_at);
    }

    public function test_el_hash_no_sale_al_serializar_la_instancia(): void
    {
        $this->instance->generarApiToken();

        $this->assertArrayNotHasKey('api_token', $this->instance->fresh()->toArray());
    }

    public function test_el_token_nuevo_autentica_y_deja_rastro_de_uso(): void
    {
        $token = $this->instance->generarApiToken();

        $this->withHeader('X-Instance-Token', $token)
            ->getJson('/api/v1/conversations')
            ->assertOk();

        $this->assertNotNull($this->instance->fresh()->api_token_last_used_at);
    }

    public function test_un_token_inventado_no_entra(): void
    {
        $this->instance->generarApiToken();

        $this->withHeader('X-Instance-Token', 'wai_'.Str::random(40))
            ->getJson('/api/v1/conversations')
            ->assertStatus(401);
    }

    /**
     * Rotar es también la forma de cortarle el acceso a una integración.
     */
    public function test_generar_uno_nuevo_invalida_el_anterior(): void
    {
        $viejo = $this->instance->generarApiToken();
        $nuevo = $this->instance->generarApiToken();

        $this->assertNotSame($viejo, $nuevo);

        $this->withHeader('X-Instance-Token', $viejo)
            ->getJson('/api/v1/conversations')
            ->assertStatus(401);

        $this->withHeader('X-Instance-Token', $nuevo)
            ->getJson('/api/v1/conversations')
            ->assertOk();
    }

    public function test_el_esquema_viejo_sigue_funcionando_durante_la_transicion(): void
    {
        config(['whatsapp.api.allow_legacy_token' => true]);

        $this->withHeader('X-Instance-Token', $this->instance->phone_number_id)
            ->getJson('/api/v1/conversations')
            ->assertOk();
    }

    public function test_apagar_el_esquema_viejo_lo_apaga_de_verdad(): void
    {
        config(['whatsapp.api.allow_legacy_token' => false]);

        $this->withHeader('X-Instance-Token', $this->instance->phone_number_id)
            ->getJson('/api/v1/conversations')
            ->assertStatus(401);

        // Y el token nuevo sigue entrando: apagar lo viejo no rompe lo nuevo.
        $token = $this->instance->generarApiToken();

        $this->withHeader('X-Instance-Token', $token)
            ->getJson('/api/v1/conversations')
            ->assertOk();
    }

    public function test_nadie_genera_el_token_de_una_instancia_ajena(): void
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
            ->postJson(route('instances.api-token', $suya->id))
            ->assertNotFound();

        $this->assertNull($suya->fresh()->api_token);
    }
}
