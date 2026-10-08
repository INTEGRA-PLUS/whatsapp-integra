<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pegar la credencial del asistente de Integra en el panel.
 *
 * Es a mano porque el token se emite dentro del contenedor de cada cliente
 * (`php artisan mcp:token --perfil=…`) y el servidor lo enseña una sola vez: no
 * hay ningún endpoint que podamos llamar para que nos lo dé.
 *
 * Lo que se protege aquí es sobre todo que el token entre y no vuelva a salir,
 * y que una credencial que no funciona no se guarde en verde: el síntoma de eso
 * —"la IA no sabe mi factura"— no apunta a ningún sitio.
 */
class McpIntegraConnectionTest extends TestCase
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
            'name' => 'Admin', 'email' => 'admin@fibra.test',
            'password' => 'secret', 'active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web']);
        foreach (['integrations.view', 'integrations.update'] as $p) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }
        $this->user->assignRole($role);
    }

    /**
     * ¿El servidor da el token por revocado? Es una propiedad y no un segundo
     * Http::fake() porque los stubs de Laravel se apilan: el primero que
     * responde gana, así que volver a llamar a fake() no sustituye al de antes.
     */
    private bool $revoked = false;

    /** Un servidor MCP que acepta el token y expone herramientas. */
    private function fakeOk(int $tools = 39): void
    {
        Http::fake(function (Request $request) use ($tools) {
            if ($this->revoked) {
                return Http::response(['error' => 'revoked'], 401);
            }

            return ($request->data()['method'] ?? null) === 'tools/list'
                ? Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                    // `range(1, 0)` devuelve [1, 0] y colaba dos herramientas
                    // donde la prueba pedía cero.
                    'tools' => $tools === 0 ? [] : array_map(fn ($i) => ['name' => "t{$i}"], range(1, $tools)),
                ]])
                : Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'serverInfo' => ['name' => 'integra-mcp', 'version' => '2.4.0'],
                ]]);
        });
    }

    private function row(): ?CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $this->company->id)
            ->where('key', CompanyIntegration::KEY_MCP_INTEGRA)
            ->first();
    }

    // ------------------------------------------------------------------

    public function test_nace_desconectado(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/integrations/mcp')
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('perfiles', CompanyIntegration::MCP_PROFILES);
    }

    public function test_guarda_la_credencial_y_el_token_no_vuelve_nunca(): void
    {
        $this->fakeOk();

        $respuesta = $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online',
            'token' => 'itg_supersecreto',
            'perfil' => 'lectura',
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('tools', 39)
            ->assertJsonPath('base_url', 'https://cmnet.online/software/mcp');

        // Ni en esta respuesta ni en ninguna: el token es del ERP de un cliente.
        $this->assertStringNotContainsString('itg_supersecreto', $respuesta->getContent());
        $this->assertStringNotContainsString(
            'itg_supersecreto',
            $this->actingAs($this->user)->getJson('/api/integrations/mcp')->getContent()
        );

        // Y en la base queda cifrado, no en claro.
        $crudo = \DB::table('company_integrations')->where('id', $this->row()->id)->value('access_token');
        $this->assertNotSame('itg_supersecreto', $crudo);
        $this->assertSame('itg_supersecreto', $this->row()->access_token);
    }

    public function test_acepta_el_dominio_pelado_y_tambien_la_url_completa(): void
    {
        // La gente copia lo que tiene en la barra del navegador. Exigirle que
        // recuerde el sufijo sólo produce conexiones fallidas que parecen un
        // token malo.
        $this->fakeOk();

        foreach (['https://cmnet.online', 'https://cmnet.online/', 'https://cmnet.online/software/mcp'] as $entrada) {
            $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
                'base_url' => $entrada, 'token' => 'itg_x', 'perfil' => 'lectura',
            ])->assertOk()->assertJsonPath('base_url', 'https://cmnet.online/software/mcp');
        }
    }

    public function test_una_credencial_que_no_funciona_no_se_guarda_en_verde(): void
    {
        Http::fake(fn () => Http::response(['error' => 'unauthorized'], 401));

        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_malo', 'perfil' => 'lectura',
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertNull($this->row());
    }

    public function test_una_url_sin_servidor_mcp_lo_dice_con_palabras_utiles(): void
    {
        Http::fake(fn () => Http::response('<html>404</html>', 404));

        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'lectura',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'En esa URL no hay un servidor MCP. Suele ser el dominio de tu Integra más /software/mcp.']);
    }

    public function test_un_servidor_sin_herramientas_no_cuenta_como_conectado(): void
    {
        // Se parece demasiado a una conexión que funciona, y no sirve de nada.
        $this->fakeOk(tools: 0);

        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'lectura',
        ])->assertStatus(422);

        $this->assertNull($this->row());
    }

    public function test_el_perfil_de_nomina_no_se_acepta(): void
    {
        // Existe en Integra y deliberadamente no se ofrece: el CRM atiende a
        // suscriptores, y un token que además abre la nómina de la empresa no
        // tiene nada que hacer detrás de un modelo que habla con desconocidos.
        $this->fakeOk();

        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'nomina',
        ])->assertStatus(422);

        $this->assertNull($this->row());
        $this->assertNotContains('nomina', CompanyIntegration::MCP_PROFILES);
    }

    public function test_el_panel_avisa_de_que_un_token_de_lectura_no_escribe(): void
    {
        // Es el caso de las 43 empresas de hoy.
        $this->fakeOk();

        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'lectura',
        ])->assertOk()->assertJsonPath('writes', false);

        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'escritura',
        ])->assertOk()->assertJsonPath('writes', true);
    }

    public function test_verificar_descubre_un_token_revocado(): void
    {
        // El cliente puede revocarlo desde su servidor sin avisarnos, y
        // entonces la IA deja de saber cosas sin un solo error en el panel.
        $this->fakeOk();
        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'lectura',
        ])->assertOk();

        $this->revoked = true;

        $this->actingAs($this->user)
            ->postJson('/api/integrations/mcp/verify')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('connected', false);

        $this->assertSame('error', $this->row()->status);
        $this->assertNotNull($this->row()->last_error);
    }

    public function test_desconectar_borra_la_credencial(): void
    {
        $this->fakeOk();
        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'lectura',
        ])->assertOk();

        $this->actingAs($this->user)
            ->deleteJson('/api/integrations/mcp')
            ->assertOk()
            ->assertJsonPath('connected', false);

        $this->assertNull($this->row());
    }

    public function test_sin_permiso_no_se_toca(): void
    {
        $agente = User::create([
            'company_id' => $this->company->id,
            'name' => 'Agente', 'email' => 'agente@fibra.test',
            'password' => 'secret', 'active' => true,
        ]);

        $this->actingAs($agente)->getJson('/api/integrations/mcp')->assertStatus(403);
        $this->actingAs($agente)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'lectura',
        ])->assertStatus(403);
    }

    public function test_la_credencial_es_de_la_empresa_y_no_se_ve_desde_otra(): void
    {
        $this->fakeOk();
        $this->actingAs($this->user)->postJson('/api/integrations/mcp', [
            'base_url' => 'https://cmnet.online', 'token' => 'itg_x', 'perfil' => 'lectura',
        ])->assertOk();

        $otra = Company::create(['name' => 'Redes ABC', 'slug' => 'redes-abc', 'active' => true]);
        $ajeno = User::create([
            'company_id' => $otra->id,
            'name' => 'Admin ABC', 'email' => 'admin@abc.test',
            'password' => 'secret', 'active' => true,
        ]);
        $rol = Role::firstOrCreate(['name' => 'admin', 'company_id' => $otra->id, 'guard_name' => 'web']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'integrations.view', 'guard_name' => 'web']));
        $ajeno->assignRole($rol);

        $this->actingAs($ajeno)
            ->getJson('/api/integrations/mcp')
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('base_url', null);
    }

    /**
     * La empresa puede conectar bien y la IA no usarlo nunca, si el puente no
     * está encendido en el servidor. El panel tiene que distinguir los dos
     * casos: en verde y sin aviso, nadie sabría que falta la llave.
     */
    public function test_el_panel_dice_si_el_puente_esta_encendido_en_el_servidor(): void
    {
        config(['services.mcp_integra.key' => null]);

        $this->actingAs($this->user)
            ->getJson('/api/integrations/mcp')
            ->assertJsonPath('plataforma', false);

        config([
            'services.mcp_integra.key' => 'llave-de-plataforma',
            'services.mcp_integra.public_url' => 'https://crm.test',
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/integrations/mcp')
            ->assertJsonPath('plataforma', true);
    }
}
