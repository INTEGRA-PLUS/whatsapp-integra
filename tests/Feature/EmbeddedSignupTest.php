<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Registro insertado de Meta (Embedded Signup).
 *
 * Sustituye al pegado manual de phone_number_id, waba_id y token. Lo que se
 * protege aquí es que el atajo no se salte nada de lo que sí hacía el camino
 * manual, y que un fallo a mitad no deje una instancia rota en la lista.
 */
class EmbeddedSignupTest extends TestCase
{
    use RefreshDatabase;

    /** El navegador sólo recibe identificadores públicos, nunca el secreto. */
    public function test_la_configuracion_no_expone_el_secreto(): void
    {
        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.embedded_signup_config_id' => '1739855773915048',
            'services.meta.app_secret' => 'secreto-que-no-debe-salir',
        ]);

        $respuesta = $this->actingAs($this->admin())->getJson('/api/embedded-signup/config');

        $respuesta->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('app_id', '865904982715022')
            ->assertJsonPath('config_id', '1739855773915048');

        $this->assertStringNotContainsString('secreto-que-no-debe-salir', $respuesta->getContent());
    }

    /**
     * Sin configuración el botón no debe pintarse.
     *
     * Un botón que abre una ventana rota es peor que no tener botón: el admin
     * cree que el camino existe y abandona el manual, que sí funciona.
     */
    public function test_sin_configuracion_queda_deshabilitado(): void
    {
        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.embedded_signup_config_id' => null,
            'services.meta.app_secret' => 'x',
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/embedded-signup/config')
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }

    /** El camino feliz: canjea, suscribe y deja la instancia usable. */
    public function test_conecta_la_cuenta_y_crea_la_instancia(): void
    {
        $this->fakeMeta();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/embedded-signup', [
                'code' => 'AQB-codigo',
                'waba_id' => '102290129340398',
                'phone_number_id' => '106540352242922',
            ])
            ->assertOk()
            ->assertJsonPath('instance.display_phone_number', '+57 318 1234567');

        $instance = Instance::where('company_id', $admin->company_id)->first();

        $this->assertNotNull($instance);
        $this->assertSame('102290129340398', $instance->waba_id);
        $this->assertSame('EAA-token-del-cliente', $instance->access_token);
        $this->assertSame('Fibra XYZ', $instance->name);
        $this->assertTrue((bool) $instance->active);

        // El secreto se usa para canjear, y el canje ocurre en el servidor.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/access_token')
            && str_contains($r->url(), 'client_secret='));

        // Sin suscribir la app al WABA la cuenta queda muda.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/102290129340398/subscribed_apps')
            && $r->method() === 'POST');
    }

    /**
     * Si no se puede suscribir la app, NO se crea la instancia.
     *
     * Una instancia sin suscripción se ve conectada en la lista y no recibe un
     * solo mensaje: es el peor resultado posible, porque nadie va a buscar el
     * fallo hasta que un cliente reclame que no le contestan.
     */
    public function test_si_falla_la_suscripcion_no_queda_instancia_a_medias(): void
    {
        $this->fakeMeta(subscribeOk: false);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/embedded-signup', [
                'code' => 'AQB-codigo',
                'waba_id' => '102290129340398',
                'phone_number_id' => '106540352242922',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Instance::count());
    }

    /** Un código ya usado o caducado se explica, no se traga. */
    public function test_un_codigo_invalido_no_crea_nada(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['error' => ['message' => 'This authorization code has been used.']], 400),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/embedded-signup', [
                'code' => 'AQB-viejo',
                'waba_id' => '102290129340398',
                'phone_number_id' => '106540352242922',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Instance::count());
    }

    /**
     * Un número ya conectado se rechaza ANTES de canjear el código.
     *
     * El código es de un solo uso: si se gasta y después falla la validación,
     * el cliente tiene que repetir toda la ventana de Meta desde cero.
     */
    public function test_un_numero_repetido_se_corta_antes_de_gastar_el_codigo(): void
    {
        $this->fakeMeta();
        $admin = $this->admin();

        Instance::create([
            'company_id' => $admin->company_id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Ya conectada',
            'phone_number_id' => '106540352242922',
            'waba_id' => '102290129340398',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/embedded-signup', [
                'code' => 'AQB-codigo',
                'waba_id' => '102290129340398',
                'phone_number_id' => '106540352242922',
            ])
            ->assertStatus(422);

        $this->assertSame(1, Instance::count());
        Http::assertNothingSent();
    }

    /**
     * El secreto puede venir de META_APP_SECRETS, con la forma "app_id:secreto".
     *
     * Es como está configurado producción: el singular META_APP_SECRET está
     * vacío. Mirar sólo ahí dejaba el registro insertado apagado sin un solo
     * error — la pantalla no mostraba botón y no había nada que investigar.
     */
    public function test_el_secreto_se_encuentra_en_la_lista_por_app(): void
    {
        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.app_secret' => null,
            'services.meta.webhook_app_secrets' => '1862365350983129:otro,865904982715022:el-bueno',
            'services.meta.embedded_signup_config_id' => '1739855773915048',
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/embedded-signup/config')
            ->assertOk()
            ->assertJsonPath('enabled', true);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token'], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
            '*' => Http::response(['id' => '106540352242922'], 200),
        ]);

        $this->actingAs($this->admin2())
            ->postJson('/api/embedded-signup', [
                'code' => 'AQB-codigo',
                'waba_id' => '102290129340398',
                'phone_number_id' => '106540352242922',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/access_token')
            && str_contains($r->url(), 'client_secret=el-bueno'));
    }

    /**
     * Coexistencia: el payload trae sólo el waba_id.
     *
     * El evento que cierra ese camino
     * (FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING) no devuelve phone_number_id,
     * porque el número ya existía. Exigirlo hacía fallar la conexión justo al
     * final, después de que el cliente ya había aceptado todo en la ventana de
     * Meta y conectado su WhatsApp Business.
     */
    public function test_sin_phone_number_id_lo_resuelve_desde_el_waba(): void
    {
        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.app_secret' => 'secreto',
            'services.meta.embedded_signup_config_id' => '1739855773915048',
        ]);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token'], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
            '*/phone_numbers*' => Http::response(['data' => [[
                'id' => '106540352242922',
                'display_phone_number' => '+57 318 1454747',
                'verified_name' => 'Mi Negocio',
            ]]], 200),
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/embedded-signup', [
                'code' => 'AQB-codigo',
                'waba_id' => '102290129340398',
            ])
            ->assertOk()
            ->assertJsonPath('instance.display_phone_number', '+57 318 1454747');

        $instance = Instance::where('company_id', $admin->company_id)->first();
        $this->assertSame('106540352242922', $instance->phone_number_id);
        $this->assertSame('Mi Negocio', $instance->name);
    }

    /** Y si el WABA todavía no tiene número, se explica en vez de reventar. */
    public function test_un_waba_sin_numeros_no_crea_instancia(): void
    {
        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.app_secret' => 'secreto',
        ]);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token'], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
            '*/phone_numbers*' => Http::response(['data' => []], 200),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/embedded-signup', [
                'code' => 'AQB-codigo',
                'waba_id' => '102290129340398',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Instance::count());
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function fakeMeta(bool $subscribeOk = true): void
    {
        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.app_secret' => 'secreto',
            'services.meta.embedded_signup_config_id' => '1739855773915048',
        ]);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token-del-cliente'], 200),
            '*/subscribed_apps' => $subscribeOk
                ? Http::response(['success' => true], 200)
                : Http::response(['error' => ['message' => 'No autorizado']], 403),
            '*/106540352242922*' => Http::response([
                'id' => '106540352242922',
                'display_phone_number' => '+57 318 1234567',
                'verified_name' => 'Fibra XYZ',
            ], 200),
        ]);
    }

    private function admin2(): User
    {
        return $this->admin('Otra Fibra', 'otra-fibra', 'otro@fibra.test');
    }

    private function admin(string $nombre = 'Fibra XYZ', string $slug = 'fibra-xyz', string $email = 'admin@fibra.test'): User
    {
        $company = Company::create(['name' => $nombre, 'slug' => $slug, 'active' => true]);

        foreach (['instances.create', 'instances.view', 'instances.update'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        return User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => $email,
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);
    }
}
