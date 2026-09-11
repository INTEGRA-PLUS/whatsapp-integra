<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Qué dato del ERP va en cada `{{n}}` de la plantilla.
 *
 * Elegir la plantilla y decir qué lleva dentro son la misma decisión, y estaban
 * repartidas: la plantilla se elige en el CRM y sus variables se editaban en
 * Integra 2.0. Ahora se resuelven las dos aquí, aunque la parametrización se
 * siga guardando allí —es de donde el cron la lee, y copiarla desincroniza.
 *
 * Lo que esta pantalla añade sobre lo que sabe el ERP es **el texto real de la
 * plantilla en Meta**. Es el único que decide cuántos parámetros se envían: si
 * alguien la edita en Meta y pasa a pedir una variable más, Integra no se
 * entera y el envío se cae entero con «number of parameters does not match»,
 * factura a factura y sin nada que lo anuncie.
 */
class ParametrizarPlantillaDelErpTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create(['name' => 'ISP', 'slug' => 'isp-'.Str::random(5), 'active' => true]);

        $this->usuario = User::create([
            'company_id' => $empresa->id, 'name' => 'Admin',
            'email' => Str::random(6).'@test.local', 'password' => bcrypt('x'),
            'role' => 'admin', 'active' => true,
        ]);

        setPermissionsTeamId($empresa->id);
        $rol = Role::firstOrCreate(['name' => 'admin', 'company_id' => $empresa->id, 'guard_name' => 'web']);
        foreach (['integrations.view', 'integrations.create'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $rol->syncPermissions(Permission::all());
        $this->usuario->assignRole($rol);
        $this->usuario = $this->usuario->fresh();
    }

    public function test_trae_del_erp_las_variables_y_el_catalogo(): void
    {
        $this->conectarIntegra();
        Http::fake([
            '*/plantillas/7/campos' => Http::response(['success' => true, 'data' => $this->delErp()], 200),
        ]);

        $this->actingAs($this->usuario)
            ->getJson('/integrations/plantillas/7/campos')
            ->assertOk()
            ->assertJsonPath('title', 'facturacion')
            ->assertJsonPath('variables.0', '[contacto.nombre]')
            ->assertJsonPath('catalogo.0.clave', '[contacto.nombre]');
    }

    /**
     * El texto de Meta viaja junto al del ERP, con su cuenta de variables. Es
     * lo que permite avisar antes de guardar en vez de después de rebotar.
     */
    public function test_añade_el_texto_de_la_plantilla_en_meta(): void
    {
        $this->conectarIntegra();
        $this->lineaDelErp();

        Http::fake([
            '*/plantillas/7/campos' => Http::response(['success' => true, 'data' => $this->delErp()], 200),
            '*/message_templates*' => Http::response($this->enMeta('Hola {{1}}, debe {{2}} y vence el {{3}}.'), 200),
        ]);

        $this->actingAs($this->usuario)
            ->getJson('/integrations/plantillas/7/campos')
            ->assertOk()
            ->assertJsonPath('meta.encontrada', true)
            ->assertJsonPath('meta.huecos', 3)
            ->assertJsonPath('meta.estado', 'APPROVED')
            // Los componentes enteros: el encabezado, el pie y los botones
            // también son parte de lo que ve el cliente, y la vista previa los
            // pinta. Sólo con el cuerpo el PDF adjunto no se veía por ninguna
            // parte, que es justo lo que más se pregunta.
            ->assertJsonPath('meta.componentes.0.format', 'DOCUMENT')
            ->assertJsonPath('meta.componentes.1.type', 'BODY');
    }

    /**
     * La plantilla que no está en la línea por la que envía el ERP es el fallo
     * que dejó a Transinternet sin facturar al cambiar de número: los catálogos
     * son por WABA y no se heredan.
     */
    public function test_avisa_si_la_plantilla_no_esta_en_la_linea_del_erp(): void
    {
        $this->conectarIntegra();
        $this->lineaDelErp();

        Http::fake([
            '*/plantillas/7/campos' => Http::response(['success' => true, 'data' => $this->delErp()], 200),
            '*/message_templates*' => Http::response(['data' => [['name' => 'otra_cosa', 'language' => 'es_CO']]], 200),
        ]);

        $this->actingAs($this->usuario)
            ->getJson('/integrations/plantillas/7/campos')
            ->assertOk()
            ->assertJsonPath('meta.encontrada', false)
            ->assertJsonPath('meta.linea', '+57 311 5775385');
    }

    /**
     * Si Meta no contesta no se bloquea nada: no saber no es lo mismo que saber
     * que falta, y dejar a alguien sin poder parametrizar porque Graph tuvo un
     * mal minuto sería peor que el problema.
     */
    public function test_si_meta_no_contesta_se_sigue_pudiendo_parametrizar(): void
    {
        $this->conectarIntegra();
        $this->lineaDelErp();

        Http::fake([
            '*/plantillas/7/campos' => Http::response(['success' => true, 'data' => $this->delErp()], 200),
            '*/message_templates*' => Http::response('boom', 500),
        ]);

        $this->actingAs($this->usuario)
            ->getJson('/integrations/plantillas/7/campos')
            ->assertOk()
            ->assertJsonPath('meta', null)
            ->assertJsonPath('huecos', 2);
    }

    public function test_guardar_escribe_las_variables_en_integra(): void
    {
        $this->conectarIntegra();

        Http::fake(['*/plantillas/7/campos' => Http::response(['success' => true, 'data' => $this->delErp()], 200)]);

        $this->actingAs($this->usuario)
            ->putJson('/integrations/plantillas/7/campos', [
                'variables' => ['[contacto.nombre]', '[factura.porpagar]'],
            ])
            ->assertOk();

        Http::assertSent(fn ($p) => $p->method() === 'PUT'
            && str_contains($p->url(), '/api/v1/whatsapp/plantillas/7/campos')
            && $p->data()['variables'] === ['[contacto.nombre]', '[factura.porpagar]']);
    }

    /** Sin Integra conectado no hay dónde guardar, y se dice. */
    public function test_sin_integra_conectado_no_guarda_nada(): void
    {
        Http::fake();

        $this->actingAs($this->usuario)
            ->putJson('/integrations/plantillas/7/campos', ['variables' => []])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Un token anterior a esta función pide reconectar, no reintentar. */
    public function test_un_token_viejo_pide_reconectar(): void
    {
        $this->conectarIntegra();

        Http::fake(['*/plantillas/7/campos' => Http::response(['message' => 'sin permiso'], 403)]);

        $respuesta = $this->actingAs($this->usuario)->getJson('/integrations/plantillas/7/campos');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Vuelve a conectarla', $respuesta->json('message'));
    }

    /** Sin permiso de gestión se puede mirar, no cambiar. */
    public function test_sin_permiso_no_se_puede_guardar(): void
    {
        $this->conectarIntegra();
        Http::fake();

        $rol = Role::where('name', 'admin')->where('company_id', $this->usuario->company_id)->first();
        $rol->syncPermissions([Permission::where('name', 'integrations.view')->first()]);

        $this->actingAs($this->usuario->fresh())
            ->putJson('/integrations/plantillas/7/campos', ['variables' => ['[contacto.nombre]']])
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function delErp(): array
    {
        return [
            'id' => 7,
            'title' => 'facturacion',
            'language' => 'es_CO',
            'contenido' => 'Hola {{1}}, debe {{2}}.',
            'con_documento' => true,
            'huecos' => 2,
            'variables' => ['[contacto.nombre]', '[factura.porpagar]'],
            'catalogo' => [['clave' => '[contacto.nombre]', 'etiqueta' => 'Nombre del cliente', 'grupo' => 'Cliente']],
            'ejemplos' => ['[contacto.nombre]' => 'María'],
        ];
    }

    /** @return array<string, mixed> */
    private function enMeta(string $cuerpo): array
    {
        return ['data' => [[
            'name' => 'facturacion',
            'language' => 'es_CO',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'DOCUMENT'],
                ['type' => 'BODY', 'text' => $cuerpo],
            ],
        ]]];
    }

    private function lineaDelErp(): Instance
    {
        return Instance::create([
            'company_id' => $this->usuario->company_id,
            'uuid' => (string) Str::uuid(),
            'name' => 'WhatsApp Business',
            'phone_number_id' => 'pnid-1',
            'waba_id' => 'waba-1',
            'display_phone_number' => '+57 311 5775385',
            'access_token' => 'token',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ]);
    }

    private function conectarIntegra(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->usuario->company_id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'base_url' => 'https://miempresa.integra.test',
            'access_token' => 'itg_'.Str::random(20),
            'status' => 'connected',
        ]);
    }
}
