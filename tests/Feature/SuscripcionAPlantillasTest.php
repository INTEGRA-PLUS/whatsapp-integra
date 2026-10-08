<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Services\WhatsAppFallbackTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * La suscripción a los avisos de plantillas, y cómo los guarda el respaldo.
 *
 * Ajustes ponía en verde «Webhook suscrito a la WABA — necesario para recibir
 * aprobación/rechazo de plantillas» con la app suscrita sólo a `messages`
 * (1-oct-2026). Los campos se eligen a nivel de app, y Meta sustituye la lista
 * entera en cada POST: añadir los de plantillas sin reenviar los existentes
 * daría de baja `messages` y dejaría de entrar todo.
 */
class SuscripcionAPlantillasTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPOS_PLANTILLA = [
        'message_template_status_update',
        'message_template_quality_update',
        'template_category_update',
        'message_template_components_update',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.webhook_app_secrets' => 'app-1:secreto-1',
            'services.meta.webhook_verify_token' => 'verificame',
        ]);
    }

    public function test_suscribir_el_webhook_anade_los_campos_de_plantillas_sin_quitar_los_demas(): void
    {
        $enviados = null;
        $this->fakeMeta(['messages', 'calls'], function ($fields) use (&$enviados) {
            $enviados = $fields;
        });

        [$instancia, $admin] = $this->instanciaConAdmin();

        $this->actingAs($admin)
            ->postJson('/api/settings/whatsapp/subscribe-webhook', ['instance_id' => $instancia->id])
            ->assertOk()
            ->assertJsonPath('template_fields', true);

        $this->assertNotNull($enviados, 'No se actualizó la suscripción de la app');
        $campos = explode(',', $enviados);
        foreach (array_merge(['messages', 'calls'], self::CAMPOS_PLANTILLA) as $campo) {
            $this->assertContains($campo, $campos);
        }
    }

    public function test_si_ya_estan_todos_no_se_vuelve_a_escribir_la_suscripcion(): void
    {
        $enviados = null;
        $this->fakeMeta(array_merge(['messages'], self::CAMPOS_PLANTILLA), function ($fields) use (&$enviados) {
            $enviados = $fields;
        });

        [$instancia, $admin] = $this->instanciaConAdmin();

        $this->actingAs($admin)
            ->postJson('/api/settings/whatsapp/subscribe-webhook', ['instance_id' => $instancia->id])
            ->assertOk();

        $this->assertNull($enviados);
    }

    public function test_el_check_no_se_pone_en_verde_si_la_app_no_recibe_plantillas(): void
    {
        $this->fakeMeta(['messages']);
        [$instancia, $admin] = $this->instanciaConAdmin();

        $check = $this->actingAs($admin)
            ->getJson('/api/settings/whatsapp/readiness?instance_id='.$instancia->id)
            ->assertOk()
            ->json('checks.webhook_subscription');

        $this->assertSame('action', $check['state']);
        $this->assertSame('MISSING_TEMPLATE_FIELDS', $check['raw_status']);
        $this->assertContains('message_template_status_update', $check['extra']['missing_fields']);
    }

    public function test_el_check_esta_en_verde_con_los_campos_suscritos(): void
    {
        $this->fakeMeta(array_merge(['messages'], self::CAMPOS_PLANTILLA));
        [$instancia, $admin] = $this->instanciaConAdmin();

        $this->actingAs($admin)
            ->getJson('/api/settings/whatsapp/readiness?instance_id='.$instancia->id)
            ->assertOk()
            ->assertJsonPath('checks.webhook_subscription.state', 'ok');
    }

    public function test_el_comando_avisa_si_la_app_no_recibe_plantillas(): void
    {
        $this->fakeMeta(['messages']);
        $this->instanciaConAdmin();

        $this->artisan('whatsapp:check-subscription')
            ->expectsOutputToContain('no está suscrita a: message_template_status_update')
            ->assertSuccessful();
    }

    /* ------------------- Estados de Meta en el respaldo ------------------- */

    /**
     * El DISABLED de Meta guardado tal cual se confundía con el respaldo
     * apagado por la empresa.
     */
    public function test_el_disabled_de_meta_se_guarda_como_meta_disabled(): void
    {
        Http::fake(fn ($request) => Http::response(['data' => [[
            'id' => 'tpl-1',
            'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'language' => 'es',
            'status' => 'DISABLED',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}: {{2}}']],
        ]]], 200));

        [$instancia] = $this->instanciaConAdmin();

        $estado = app(WhatsAppFallbackTemplateService::class)->ensure($instancia, true);

        $this->assertSame(WhatsAppFallbackTemplateService::STATUS_META_DISABLED, $estado['status']);
        $this->assertNotEmpty($estado['last_error']);
        $this->assertFalse($instancia->fresh()->fallbackTemplateDisabled());
    }

    /** Un DISABLED guardado antes de separar los dos se vuelve a consultar. */
    public function test_un_disabled_viejo_guardado_no_se_da_por_fresco(): void
    {
        $consultas = 0;
        Http::fake(function () use (&$consultas) {
            $consultas++;

            return Http::response(['data' => [[
                'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
                'language' => 'es',
                'status' => 'APPROVED',
                'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}: {{2}}']],
            ]]], 200);
        });

        [$instancia] = $this->instanciaConAdmin();
        $instancia->mergeFallbackTemplate([
            'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'language' => 'es',
            'source' => 'catalog',
            'status' => 'DISABLED',
            'checked_at' => now()->toIso8601String(),
        ]);
        $instancia->save();

        $estado = app(WhatsAppFallbackTemplateService::class)->ensure($instancia);

        $this->assertSame(1, $consultas);
        $this->assertSame('APPROVED', $estado['status']);
    }

    public function test_un_132015_al_enviar_el_respaldo_obliga_a_volver_a_consultar(): void
    {
        [$instancia] = $this->instanciaConAdmin();
        $instancia->mergeFallbackTemplate(['status' => 'APPROVED', 'checked_at' => now()->toIso8601String()]);
        $instancia->save();

        $servicio = app(WhatsAppFallbackTemplateService::class);

        $this->assertFalse($servicio->refrescarTrasError($instancia, ['error' => ['code' => 131047]]));
        $this->assertNotNull($instancia->fresh()->fallbackTemplateSettings()['checked_at']);

        $this->assertTrue($servicio->refrescarTrasError($instancia, ['error' => ['code' => 132015]]));
        $this->assertNull($instancia->fresh()->fallbackTemplateSettings()['checked_at']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Graph falso con un solo closure: debug_token, la suscripción de la app
     * (GET y POST) y la WABA.
     */
    private function fakeMeta(array $camposActuales, ?\Closure $alActualizar = null): void
    {
        Http::fake(function ($request) use ($camposActuales, $alActualizar) {
            $url = $request->url();

            if (str_contains($url, '/debug_token')) {
                return Http::response(['data' => ['app_id' => 'app-1', 'is_valid' => true]], 200);
            }

            if (str_contains($url, '/app-1/subscriptions')) {
                if ($request->method() === 'POST') {
                    $alActualizar && $alActualizar($request->data()['fields'] ?? null);

                    return Http::response(['success' => true], 200);
                }

                return Http::response(['data' => [[
                    'object' => 'whatsapp_business_account',
                    'callback_url' => 'https://crm.test/webhooks/whatsapp',
                    'active' => true,
                    'fields' => array_map(fn ($f) => ['name' => $f, 'version' => 'v21.0'], $camposActuales),
                ]]], 200);
            }

            if (str_contains($url, '/subscribed_apps')) {
                return $request->method() === 'POST'
                    ? Http::response(['success' => true], 200)
                    : Http::response(['data' => [['whatsapp_business_api_data' => ['id' => 'app-1', 'name' => 'Integra']]]], 200);
            }

            return Http::response(['id' => 'x'], 200);
        });
    }

    /** @return array{0: Instance, 1: User} */
    private function instanciaConAdmin(): array
    {
        $company = Company::create(['name' => 'Fibra', 'slug' => 'fibra-'.Str::random(4), 'active' => true]);

        // User::booted() le da el rol admin con todos los permisos existentes
        // al primer usuario de la empresa: el permiso debe existir antes.
        Permission::firstOrCreate(['name' => 'instances.update', 'guard_name' => 'web']);

        $admin = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => Str::random(8).'@fibra.test',
            'password' => bcrypt('secreto123'),
            'active' => true,
        ]);

        $instancia = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => 'pnid-1',
            'waba_id' => 'waba-1',
            'access_token' => 'token',
            'type' => 'meta',
            'active' => true,
        ]);

        return [$instancia, $admin];
    }
}
