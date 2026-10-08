<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Support\McpGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El pass-through al MCP de Integra.
 *
 * El servidor MCP NO está en este repositorio: corre dentro de la instancia de
 * Integra de cada cliente, con sus 39 herramientas. Aquí sólo hay routing
 * multiempresa, y es exactamente lo que se prueba:
 *
 *   1. Que la llamada de una empresa acabe SIEMPRE en el dominio y con el token
 *      de esa empresa. Es la razón de que exista este proxy: la alternativa
 *      —credencial dinámica en n8n— falla usando el token de reserva, que es el
 *      de otra empresa.
 *   2. Que las tres escrituras respeten los permisos que concedió el admin, y
 *      que una empresa con token de sólo lectura (las 43 de hoy) se entere
 *      antes de prometerle nada a un cliente.
 *   3. Que un fallo del servidor llegue como "no pude comprobarlo" y nunca como
 *      "no existe".
 */
class McpIntegraProxyTest extends TestCase
{
    use RefreshDatabase;

    private const LLAVE = 'llave-de-plataforma';

    private const MCP = 'https://cmnet.online/software/mcp';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mcp_integra.key' => self::LLAVE,
            'services.mcp_integra.grant_ttl' => 15,
            'services.mcp_integra.public_url' => 'https://crm.test',
        ]);

        $this->company = $this->empresaConMcp('Fibra XYZ', self::MCP, 'itg_fibra', 'lectura');
    }

    private function empresaConMcp(string $name, string $url, string $token, string $perfil): Company
    {
        $company = Company::create(['name' => $name, 'slug' => Str::slug($name), 'active' => true]);

        CompanyIntegration::create([
            'company_id' => $company->id,
            'key' => CompanyIntegration::KEY_MCP_INTEGRA,
            'base_url' => $url,
            'access_token' => $token,
            'settings' => ['perfil' => $perfil],
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        return $company;
    }

    /**
     * El servidor MCP de mentira.
     *
     * @param  list<array<string, mixed>>  $tools  Lo que devuelve su `tools/list`.
     */
    private function fakeMcp(array $tools, array $callResult = ['content' => [['type' => 'text', 'text' => '{}']], 'isError' => false]): void
    {
        Http::fake(function (Request $request) use ($tools, $callResult) {
            $method = $request->data()['method'] ?? null;

            return match ($method) {
                'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'protocolVersion' => '2025-06-18',
                    'serverInfo' => ['name' => 'integra-mcp', 'version' => '2.4.0'],
                ]]),
                'tools/list' => Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => $tools]]),
                default => Http::response(['jsonrpc' => '2.0', 'id' => 9, 'result' => $callResult]),
            };
        });
    }

    /** @return list<array<string, mixed>> Un catálogo mínimo, con anotaciones. */
    private function catalogoAnotado(): array
    {
        return [
            ['name' => 'clientes_buscar', 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'cartera_cliente', 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'radicados_crear', 'annotations' => ['readOnlyHint' => false]],
            ['name' => 'pagos_registrar', 'annotations' => ['readOnlyHint' => false]],
        ];
    }

    private function rpc(array $message, ?string $grant = null, array $permisos = ['leer'], ?string $key = self::LLAVE)
    {
        $grant ??= McpGrant::mint($this->company->id, null, $permisos);

        return $this->withHeaders(array_filter(['X-Mcp-Key' => $key]))
            ->postJson("/api/mcp/integra/{$grant}", ['jsonrpc' => '2.0', 'id' => 7] + $message);
    }

    private function llamar(string $tool, array $permisos = ['leer'], ?string $grant = null)
    {
        return $this->rpc(
            ['method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]],
            $grant,
            $permisos
        );
    }

    private function textoDe($response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    // ------------------------------------------------------------------
    // La puerta
    // ------------------------------------------------------------------

    public function test_sin_llave_configurada_el_pass_through_no_existe(): void
    {
        config(['services.mcp_integra.key' => null]);

        $this->rpc(['method' => 'tools/list'])->assertStatus(404);
    }

    public function test_una_llave_equivocada_no_entra(): void
    {
        $this->rpc(['method' => 'tools/list'], null, ['leer'], 'casi-casi')->assertStatus(401);
        $this->rpc(['method' => 'tools/list'], null, ['leer'], null)->assertStatus(401);
    }

    public function test_un_permiso_caducado_o_manipulado_no_entra(): void
    {
        $grant = McpGrant::mint($this->company->id, null, ['leer']);

        $this->rpc(['method' => 'tools/list'], substr($grant, 0, -4).'AAAA')->assertStatus(401);
        $this->rpc(['method' => 'tools/list'], 'inventado')->assertStatus(401);

        $this->travel(16)->minutes();
        $this->rpc(['method' => 'tools/list'], $grant)->assertStatus(401);
    }

    public function test_una_notificacion_no_lleva_respuesta(): void
    {
        $this->fakeMcp($this->catalogoAnotado());

        $grant = McpGrant::mint($this->company->id, null, ['leer']);

        $this->withHeaders(['X-Mcp-Key' => self::LLAVE])
            ->postJson("/api/mcp/integra/{$grant}", [
                'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
            ])
            ->assertStatus(202);
    }

    // ------------------------------------------------------------------
    // Aislamiento multiempresa — el motivo de que esto exista
    // ------------------------------------------------------------------

    public function test_la_llamada_acaba_en_el_dominio_y_con_el_token_de_la_empresa_del_permiso(): void
    {
        $otra = $this->empresaConMcp('Redes ABC', 'https://abc.net/software/mcp', 'itg_abc', 'lectura');

        $this->fakeMcp($this->catalogoAnotado());

        $this->llamar('clientes_buscar', ['leer'], McpGrant::mint($otra->id, null, ['leer']))->assertOk();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://abc.net/software/mcp')
            && $r->header('Authorization') === ['Bearer itg_abc']);

        // Y ni un solo viaje al Integra de la otra empresa. Ésta es la línea
        // que separa este diseño del de la credencial dinámica en n8n.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cmnet.online'));
    }

    public function test_el_token_de_la_empresa_nunca_viaja_en_la_url(): void
    {
        // Lo que n8n ve es un permiso cifrado, no la credencial del ERP.
        $grant = McpGrant::mint($this->company->id, 5, ['leer']);

        $this->assertStringNotContainsString('itg_', $grant);
        $this->assertStringNotContainsString('cmnet', $grant);
    }

    public function test_una_empresa_sin_mcp_recibe_una_respuesta_que_el_modelo_puede_leer(): void
    {
        CompanyIntegration::where('company_id', $this->company->id)->delete();
        Http::fake();

        $respuesta = $this->llamar('clientes_buscar');

        $respuesta->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('asesor', $this->textoDe($respuesta));
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Reenvío
    // ------------------------------------------------------------------

    public function test_el_catalogo_se_reenvia_tal_cual(): void
    {
        // El proxy no conoce las 39 herramientas ni debe conocerlas: una
        // herramienta nueva en Integra tiene que funcionar sin tocar esto.
        $this->fakeMcp($this->catalogoAnotado());

        $this->rpc(['method' => 'tools/list'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'clientes_buscar')
            ->assertJsonCount(4, 'result.tools');
    }

    public function test_una_consulta_pasa_y_devuelve_lo_que_conteste_el_servidor(): void
    {
        $this->fakeMcp(
            $this->catalogoAnotado(),
            ['content' => [['type' => 'text', 'text' => '{"saldo":84500}']], 'isError' => false]
        );

        $respuesta = $this->llamar('cartera_cliente');

        $respuesta->assertOk();
        $this->assertStringContainsString('84500', $this->textoDe($respuesta));
    }

    public function test_si_el_servidor_no_responde_el_modelo_no_concluye_que_no_hay_datos(): void
    {
        // "No pude preguntar" no es "no hay": es la confusión que produce
        // respuestas falsas sobre el servicio de un cliente.
        Http::fake(fn () => throw new \RuntimeException('timeout'));

        $respuesta = $this->llamar('cartera_cliente');

        $respuesta->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('no has podido comprobarlo', $this->textoDe($respuesta));
    }

    // ------------------------------------------------------------------
    // Las tres escrituras
    // ------------------------------------------------------------------

    public function test_una_escritura_sin_el_permiso_de_la_empresa_no_sale_de_aqui(): void
    {
        $this->fakeMcp($this->catalogoAnotado());

        $respuesta = $this->llamar('radicados_crear', ['leer']);

        $respuesta->assertOk()->assertJsonPath('result.isError', true);
        Http::assertNotSent(fn (Request $r) => ($r->data()['method'] ?? null) === 'tools/call');
    }

    public function test_una_escritura_se_reconoce_por_el_nombre_si_el_servidor_no_anota(): void
    {
        // El `readOnlyHint` es lo primero que se mira, pero no todos los
        // servidores anotan: la lista de nombres es la red por debajo.
        $this->fakeMcp([['name' => 'radicados_crear'], ['name' => 'clientes_buscar']]);

        $this->llamar('radicados_crear', ['leer'])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_una_escritura_sin_clasificar_exige_todos_los_permisos(): void
    {
        // No sabemos cuál es, así que se pide el máximo: equivocarse hacia
        // "pide más permiso" cuesta una respuesta; hacia el otro lado, una fila
        // en el ERP de un cliente.
        $escribe = $this->empresaConMcp('Escribe SAS', 'https://escribe.net/software/mcp', 'itg_w', 'escritura');

        $this->fakeMcp([['name' => 'algo_nuevo', 'annotations' => ['readOnlyHint' => false]]]);

        $this->llamar('algo_nuevo', ['leer', 'radicados'], McpGrant::mint($escribe->id, null, ['leer', 'radicados']))
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $this->llamar('algo_nuevo', ['leer', 'radicados', 'pagos'], McpGrant::mint($escribe->id, null, ['leer', 'radicados', 'pagos']))
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    }

    public function test_con_permiso_pero_token_de_solo_lectura_se_avisa_antes_de_prometer_nada(): void
    {
        // Es el caso de las 43 empresas de hoy: todos los tokens emitidos son
        // de perfil `lectura`. Sin este aviso, el modelo promete un radicado,
        // el servidor lo rechaza y el síntoma parece un bug del CRM.
        $this->fakeMcp($this->catalogoAnotado());

        $respuesta = $this->llamar('radicados_crear', ['leer', 'radicados']);

        $respuesta->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('sólo lectura', $this->textoDe($respuesta));
        Http::assertNotSent(fn (Request $r) => ($r->data()['method'] ?? null) === 'tools/call');
    }

    public function test_con_permiso_y_token_de_escritura_la_accion_llega_al_servidor(): void
    {
        $empresa = $this->empresaConMcp('Escribe SAS', 'https://escribe.net/software/mcp', 'itg_w', 'escritura');

        $this->fakeMcp($this->catalogoAnotado());

        $this->llamar('radicados_crear', ['leer', 'radicados'], McpGrant::mint($empresa->id, null, ['leer', 'radicados']))
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        Http::assertSent(fn (Request $r) => ($r->data()['method'] ?? null) === 'tools/call'
            && str_starts_with($r->url(), 'https://escribe.net'));
    }

    public function test_sin_el_permiso_de_lectura_no_se_consulta_nada(): void
    {
        $this->fakeMcp($this->catalogoAnotado());

        $this->llamar('clientes_buscar', [])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    // ------------------------------------------------------------------
    // Frenos
    // ------------------------------------------------------------------

    public function test_el_tope_de_llamadas_frena_el_bucle(): void
    {
        // Al otro lado está el ERP de producción de un cliente, y alguna
        // herramienta llega hasta su router.
        $this->fakeMcp($this->catalogoAnotado());

        $grant = McpGrant::mint($this->company->id, 4242, ['leer']);

        for ($i = 0; $i < 60; $i++) {
            $this->llamar('clientes_buscar', ['leer'], $grant)->assertJsonPath('result.isError', false);
        }

        $ultima = $this->llamar('clientes_buscar', ['leer'], $grant);

        $ultima->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('asesor', $this->textoDe($ultima));
    }
}
