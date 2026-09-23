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
 * Las plantillas por defecto que vienen de Integra.
 *
 * Son las que dispara el ERP con el PDF adjunto —la factura y la tirilla del
 * pago— y por eso no se le ofrecen a quien no tiene Integra conectado:
 * aprobarlas en Meta tarda días y sin conexión no hay quien las envíe.
 */
class PlantillasDeIntegraTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function sin_integra_conectado_no_se_ofrecen(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->getJson('/api/templates/defaults')
            ->assertOk()
            ->assertJsonMissingPath('data.facturacion')
            ->assertJsonMissingPath('data.tirilla')
            // Las que no dependen del ERP siguen estando para todos.
            ->assertJsonPath('data.reanudar_conversacion_cliente.category', 'UTILITY');
    }

    /**
     * La de avisos operativos sale para todo el mundo, tenga Integra o no.
     *
     * La manda una persona, no el ERP, así que condicionarla a la conexión
     * dejaría sin ella justo a quien más la necesita: el que todavía no tiene
     * nada automatizado.
     */
    /** @test */
    public function la_de_notificaciones_se_ofrece_a_todos(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->getJson('/api/templates/defaults')
            ->assertOk();

        $respuesta->assertJsonPath('data.notificaciones.category', 'UTILITY');
        $respuesta->assertJsonPath('data.notificaciones.language', 'es_CO');
    }

    /**
     * Es UTILITY y tiene que seguir siéndolo.
     *
     * Marcarla como MARKETING la mete en otro carril: Meta la cobra más cara,
     * no la entrega a quien tenga silenciadas las promociones y castiga la
     * calidad del número cuando la marcan como no deseada. Un corte de
     * servicio no es una promoción.
     */
    /** @test */
    public function la_de_notificaciones_no_es_marketing(): void
    {
        $catalogo = config('whatsapp_default_templates');

        $this->assertSame('UTILITY', $catalogo['notificaciones']['category']);
        $this->assertArrayNotHasKey('requiere_integra', $catalogo['notificaciones']);
    }

    /**
     * Y su texto es, carácter por carácter, el que Meta ya aprobó.
     *
     * Está copiado del que Comuna13 lleva meses enviando. Retocarlo —aunque
     * sea un espacio del final de línea, que los tiene— la devuelve a la cola
     * de revisión de Meta y puede volver rechazada, así que el parecido no es
     * casual y no se "mejora".
     */
    /** @test */
    public function el_texto_de_notificaciones_es_el_aprobado(): void
    {
        $cuerpo = collect(config('whatsapp_default_templates.notificaciones.components'))
            ->firstWhere('type', 'BODY');

        $this->assertSame(
            "Usuario \u{2139}\u{FE0F} \n{{1}} \nEste mensaje corresponde a información operativa de su servicio activo.\nGracias.",
            $cuerpo['text']
        );

        // El encabezado es de texto fijo: no lleva variable ni adjunto, así que
        // no necesita archivo de muestra para que Meta la apruebe.
        $encabezado = collect(config('whatsapp_default_templates.notificaciones.components'))
            ->firstWhere('type', 'HEADER');

        $this->assertSame('TEXT', $encabezado['format']);
        $this->assertArrayNotHasKey('sample_file', config('whatsapp_default_templates.notificaciones'));
    }

    /** @test */
    public function con_integra_conectado_aparecen_las_dos(): void
    {
        $user = $this->admin();
        $this->conIntegra($user->company_id);

        $respuesta = $this->actingAs($user)->getJson('/api/templates/defaults')->assertOk();

        $respuesta->assertJsonPath('data.facturacion.origen', 'Integra');
        $respuesta->assertJsonPath('data.tirilla.origen', 'Integra');
        $respuesta->assertJsonPath('data.facturacion.language', 'es_CO');
    }

    /**
     * Y las dos llevan encabezado de documento.
     *
     * Es el adjunto —la factura, el comprobante— y sin él la plantilla no sirve
     * para lo que se creó.
     *
     * @test
     */
    public function las_dos_adjuntan_un_documento(): void
    {
        $user = $this->admin();
        $this->conIntegra($user->company_id);

        $data = $this->actingAs($user)->getJson('/api/templates/defaults')->json('data');

        foreach (['facturacion', 'tirilla'] as $clave) {
            $header = collect($data[$clave]['components'])->firstWhere('type', 'HEADER');

            $this->assertSame('DOCUMENT', $header['format'], "La plantilla {$clave} tiene que adjuntar el documento.");
            $this->assertNotEmpty($data[$clave]['sample_file'], "La plantilla {$clave} necesita archivo de muestra.");
            $this->assertFileExists(base_path($data[$clave]['sample_file']));
        }
    }

    /**
     * Sincronizar sube la muestra del encabezado antes de crear la plantilla.
     *
     * Meta no aprueba un encabezado multimedia sin ejemplo, y el handle está
     * atado al WABA que subió el archivo: no vale guardar uno y repartirlo.
     *
     * @test
     */
    public function al_sincronizar_se_sube_la_muestra(): void
    {
        $user = $this->admin();
        $this->conIntegra($user->company_id);
        $instancia = $this->instancia($user->company_id);

        Http::fake([
            '*/debug_token*' => Http::response(['data' => ['app_id' => '865904982715022']], 200),
            '*/uploads*' => Http::response(['id' => 'upload:123'], 200),
            '*upload:123*' => Http::response(['h' => 'HANDLE-DE-LA-MUESTRA'], 200),
            '*/message_templates*' => Http::response(['id' => '999', 'status' => 'PENDING'], 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/templates/defaults/facturacion/sync', ['instance_id' => $instancia->id])
            ->assertStatus(201);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'message_templates')) {
                return false;
            }

            $header = collect($request['components'])->firstWhere('type', 'HEADER');

            return ($header['example']['header_handle'][0] ?? null) === 'HANDLE-DE-LA-MUESTRA';
        });
    }

    /** Y sin Integra, sincronizarlas no es que falle: es que no existen. */
    public function test_sin_integra_no_se_puede_sincronizar(): void
    {
        $user = $this->admin();
        $this->instancia($user->company_id);

        $this->actingAs($user)
            ->postJson('/api/templates/defaults/facturacion/sync', [])
            ->assertStatus(404);
    }

    /**
     * La vista previa saluda con el nombre de quien mira.
     *
     * `example.body_text` es lo que se le manda a Meta para que revise la
     * plantilla, y ahí hay un valor fijo —«MEGASTORE»—. La pantalla leía ese
     * mismo ejemplo, así que toda empresa abría «Plantillas por defecto» y veía
     * un mensaje que saludaba a otra, bajo un encabezado que decía «Tu negocio».
     *
     * @test
     */
    public function la_vista_previa_usa_el_nombre_de_la_empresa(): void
    {
        $user = $this->admin();
        $this->conIntegra($user->company_id);

        $respuesta = $this->actingAs($user)->getJson('/api/templates/defaults')->assertOk();

        $respuesta->assertJsonPath('negocio', $user->company->name);

        $cuerpo = collect($respuesta->json('data.facturacion.components'))->firstWhere('type', 'BODY');

        $this->assertSame(
            $user->company->name,
            $cuerpo['example']['body_text'][0][1],
            'El {{2}} de facturación es el negocio: tiene que ser el suyo.'
        );
    }

    /**
     * Pero a Meta se le sigue mandando el ejemplo del catálogo.
     *
     * Cambiarlo empresa por empresa sería cambiar la plantilla que se crea, y el
     * ejemplo existe para que Meta la revise, no para que la lea el cliente.
     *
     * @test
     */
    public function a_meta_se_le_manda_el_ejemplo_del_catalogo(): void
    {
        $user = $this->admin();
        $this->conIntegra($user->company_id);
        $instancia = $this->instancia($user->company_id);

        Http::fake([
            '*/debug_token*' => Http::response(['data' => ['app_id' => '865904982715022']], 200),
            '*/uploads*' => Http::response(['id' => 'upload:123'], 200),
            '*upload:123*' => Http::response(['h' => 'HANDLE'], 200),
            '*/message_templates*' => Http::response(['id' => '999', 'status' => 'PENDING'], 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/templates/defaults/facturacion/sync', ['instance_id' => $instancia->id])
            ->assertStatus(201);

        $delCatalogo = config('whatsapp_default_templates.facturacion.components.1.example.body_text.0.1');

        Http::assertSent(function ($request) use ($delCatalogo) {
            if (! str_contains($request->url(), 'message_templates')) {
                return false;
            }

            $cuerpo = collect($request['components'])->firstWhere('type', 'BODY');

            return ($cuerpo['example']['body_text'][0][1] ?? null) === $delCatalogo;
        });
    }

    private function conIntegra(int $companyId): void
    {
        CompanyIntegration::create([
            'company_id' => $companyId,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://erp.integracolombia.co',
            'access_token' => 'token-de-integra',
            'enabled' => true,
            'connected_at' => now(),
        ]);
    }

    private function instancia(int $companyId): Instance
    {
        return Instance::create([
            'company_id' => $companyId,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1311984867684767',
            'type' => 'meta',
            'access_token' => 'token-de-meta',
            'active' => true,
        ]);
    }

    private function admin(): User
    {
        $company = Company::create(['name' => 'Fibra '.uniqid(), 'slug' => 'fibra-'.uniqid(), 'active' => true]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => Str::uuid().'@x.test',
            'password' => 'secreto123',
            'role' => 'admin',
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        $rol = Role::firstOrCreate(['name' => 'op', 'company_id' => $company->id, 'guard_name' => 'web']);

        foreach (['templates.view', 'templates.create'] as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        $user->assignRole($rol);

        return $user->fresh();
    }
}
