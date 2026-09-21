<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\CompanyIntegration;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Extensión «Diagnóstico de internet».
 *
 * Lo que se protege aquí, por orden de gravedad:
 *
 * - **Que no se pueda diagnosticar un contrato que no es del cliente de esa
 *   conversación.** Los números de contrato de Integra son secuenciales, así
 *   que un endpoint que acepte cualquiera es un endpoint para pasearse por la
 *   base del ERP escribiendo números. Y el de siempre: la conversación se busca
 *   entre las líneas de la empresa del usuario, nunca por id a secas.
 * - **Que apagar la extensión la apague de verdad.** Esconder el botón no es
 *   apagarla: la ruta existe igual y se puede llamar a mano.
 * - **Que no se pueda instalar sin Integra conectado.** Es lo único que hace
 *   esta extensión —preguntarle al ERP—, así que instalarla sin conexión deja
 *   una función encendida que no puede funcionar y que parece rota.
 * - Que el cupo de Integra (20 por minuto y por cuenta) no se lo gaste un solo
 *   asesor impaciente.
 */
class DiagnosticoDeRedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private WhatsAppConversation $conversacion;

    private User $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        // La ficha se cachea un minuto por empresa+cliente y el tope de ritmo
        // vive en la caché: sin limpiar, un test hereda el estado del anterior.
        Cache::flush();
        RateLimiter::clear('integra:diagnostico:1');

        $this->company = Company::create([
            'name' => 'Cmnet '.Str::random(4),
            'slug' => Str::lower(Str::random(8)),
            'active' => true,
        ]);

        $instancia = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(100000, 999999),
            'waba_id' => (string) random_int(100000, 999999),
            'type' => 'meta',
            'active' => true,
        ]);

        $contacto = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Javier Elias Diaz',
            'phone_number' => '573046430059',
            'identificacion' => '1017924455',
        ]);

        $this->conversacion = WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => '573046430059',
            'phone_number' => '573046430059',
            'name' => 'Javier Elias Diaz',
            'status' => 'open',
            'contact_id' => $contacto->id,
        ]);

        $this->asesor = User::create([
            'company_id' => $this->company->id,
            'name' => 'Agente',
            'email' => 'agente-'.Str::random(6).'@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'agent',
            'active' => true,
        ]);
    }

    public function test_el_asesor_diagnostica_el_contrato_de_su_cliente(): void
    {
        $this->conectarIntegra();
        $this->encenderExtension();
        $this->fakeIntegra();

        $this->actingAs($this->asesor)
            ->getJson($this->url('10432'))
            ->assertOk()
            ->assertJsonPath('diagnostico.veredicto.codigo', 'nodo_incomunicado')
            ->assertJsonPath('diagnostico.veredicto.visita', true)
            ->assertJsonPath('diagnostico.veredicto.responsable', 'redes')
            ->assertJsonPath('diagnostico.whatsapp', 'Hay una afectación en el nodo de tu sector.');
    }

    /**
     * El router que no contesta NO es un error: Integra responde 200 con
     * `nodo_incomunicado` o `sin_router`, y ese veredicto es justo el que
     * decide una visita. Tratarlo como fallo escondería el caso más útil.
     */
    public function test_un_router_que_no_contesta_es_un_veredicto_y_no_un_error(): void
    {
        $this->conectarIntegra();
        $this->encenderExtension();
        $this->fakeIntegra(['veredicto' => [
            'codigo' => 'sin_router',
            'visita' => null,
            'responsable' => 'cliente',
            'confianza' => 'media',
        ]]);

        $this->actingAs($this->asesor)
            ->getJson($this->url('10432'))
            ->assertOk()
            ->assertJsonPath('diagnostico.veredicto.codigo', 'sin_router')
            ->assertJsonPath('diagnostico.veredicto.visita', null)
            ->assertJsonPath('diagnostico.veredicto.confianza', 'media');
    }

    public function test_no_se_diagnostica_un_contrato_de_otro_cliente(): void
    {
        $this->conectarIntegra();
        $this->encenderExtension();
        $this->fakeIntegra();

        // 10433 existe en el ERP, pero no es de quien está en este chat.
        $this->actingAs($this->asesor)
            ->getJson($this->url('10433'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Ese contrato no es del cliente de esta conversación.');

        // La ficha sí se consulta —es lo que dice de quién es el contrato—,
        // pero el diagnóstico no llega a pedirse.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/diagnostico'));
    }

    public function test_sin_la_extension_encendida_el_endpoint_no_responde(): void
    {
        $this->conectarIntegra();
        $this->fakeIntegra();

        // Ni instalada.
        $this->actingAs($this->asesor)
            ->getJson($this->url('10432'))
            ->assertStatus(403);

        // Instalada pero apagada: apagar tiene que apagar, no sólo esconder.
        $this->encenderExtension(enabled: false);

        $this->actingAs($this->asesor)
            ->getJson($this->url('10432'))
            ->assertStatus(403);

        // Ni una petición: la extensión se comprueba antes de tocar el ERP.
        Http::assertNothingSent();
    }

    /**
     * La empresa ajena llega con todo en regla —su Integra conectado y la
     * extensión encendida— para que lo único que pueda pararla sea el
     * `whereIn` sobre las líneas de su empresa. Sin eso, el test pasaría por el
     * motivo equivocado: bastaría con que no tuviera la extensión.
     */
    public function test_no_se_diagnostica_la_conversacion_de_otra_empresa(): void
    {
        $this->conectarIntegra();
        $this->encenderExtension();
        $this->fakeIntegra();

        $otra = Company::create([
            'name' => 'Otra', 'slug' => Str::lower(Str::random(8)), 'active' => true,
        ]);

        CompanyIntegration::create([
            'company_id' => $otra->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://otra.test/software',
            'access_token' => 'itg_otro',
            'enabled' => true,
        ]);

        CompanyExtension::create([
            'company_id' => $otra->id,
            'slug' => 'internet_diagnostic',
            'enabled' => true,
            'settings' => ['informe_whatsapp' => true],
        ]);

        $intruso = User::create([
            'company_id' => $otra->id,
            'name' => 'Ajeno',
            'email' => 'ajeno-'.Str::random(6).'@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'agent',
            'active' => true,
        ]);

        $this->actingAs($intruso)
            ->getJson($this->url('10432'))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Conversación no encontrada.');

        Http::assertNothingSent();
    }

    public function test_el_cupo_por_minuto_se_reparte_en_la_empresa(): void
    {
        $this->conectarIntegra();
        $this->encenderExtension();
        $this->fakeIntegra();

        // Integra admite 20 por minuto y por cuenta; el freno propio está en 12
        // para dejarle aire al resto de la API.
        for ($i = 0; $i < 12; $i++) {
            RateLimiter::hit('integra:diagnostico:'.$this->company->id);
        }

        $this->actingAs($this->asesor)
            ->getJson($this->url('10432'))
            ->assertStatus(429);
    }

    public function test_sin_integra_conectado_la_extension_no_se_puede_instalar(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/extensions/internet_diagnostic/install')
            ->assertStatus(409);

        $this->assertDatabaseMissing('company_extensions', [
            'company_id' => $this->company->id,
            'slug' => 'internet_diagnostic',
        ]);
    }

    public function test_con_integra_conectado_se_instala(): void
    {
        $this->conectarIntegra();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/extensions/internet_diagnostic/install')
            ->assertOk()
            ->assertJsonPath('installed', true)
            ->assertJsonPath('dependencia.conectada', true);

        $this->assertDatabaseHas('company_extensions', [
            'company_id' => $this->company->id,
            'slug' => 'internet_diagnostic',
            'enabled' => true,
        ]);
    }

    // ── Andamiaje ────────────────────────────────────────────────────────────

    private function url(string $contrato): string
    {
        return '/api/integrations/integra/diagnostico?conversation_id='
            .$this->conversacion->id.'&contrato='.$contrato;
    }

    private function conectarIntegra(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://demo.test/software',
            'access_token' => 'itg_token',
            'enabled' => true,
        ]);
    }

    private function encenderExtension(bool $enabled = true): void
    {
        CompanyExtension::updateOrCreate(
            ['company_id' => $this->company->id, 'slug' => 'internet_diagnostic'],
            ['enabled' => $enabled, 'settings' => ['informe_whatsapp' => true]]
        );
    }

    /** Un admin con el permiso de instalar, que va por equipos de Spatie. */
    private function admin(): User
    {
        $admin = User::create([
            'company_id' => $this->company->id,
            'name' => 'Admin',
            'email' => 'admin-'.Str::random(6).'@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        setPermissionsTeamId($this->company->id);

        foreach (['extensions.view', 'extensions.create'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $rol = Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web',
        ]);
        $rol->syncPermissions(['extensions.view', 'extensions.create']);
        $admin->assignRole($rol);

        return $admin;
    }

    /**
     * El ERP: el contacto con su contrato (que es lo que acota qué se puede
     * diagnosticar) y el diagnóstico.
     */
    private function fakeIntegra(array $diagnostico = []): void
    {
        Http::fake([
            '*/api/v1/contactos/buscar*' => Http::response(['success' => true, 'data' => [[
                'id' => 4012,
                'identificacion' => '1017924455',
                'nombre_completo' => 'Javier Elias Diaz',
                'contacto' => ['identificacion' => '1017924455', 'celular' => '3046430059'],
                'resumen' => ['total_contratos' => 1],
                'contratos' => [[
                    'nro' => '10432',
                    'activo' => true,
                    'vigente' => true,
                    'plan_internet' => ['nombre' => 'Fibra 100 Mbps'],
                    'red' => ['ip' => '10.80.1.59'],
                ]],
                'facturas_pendientes' => [],
                'total_por_pagar' => 0,
            ]], 'meta' => ['total_contactos' => 1]]),

            '*/api/v1/facturas?*' => Http::response(['success' => true, 'data' => []]),

            '*/api/v1/contratos/*/resumen*' => Http::response(['success' => true, 'data' => [
                'facturacion' => [], 'servicio' => [], 'soportes' => [],
            ]]),

            '*/api/v1/contratos/*/diagnostico*' => Http::response(['success' => true, 'data' => array_merge([
                'veredicto' => [
                    'codigo' => 'nodo_incomunicado',
                    'visita' => true,
                    'responsable' => 'redes',
                    'confianza' => 'alta',
                ],
                'whatsapp' => 'Hay una afectación en el nodo de tu sector.',
            ], $diagnostico)]),
        ]);
    }
}
