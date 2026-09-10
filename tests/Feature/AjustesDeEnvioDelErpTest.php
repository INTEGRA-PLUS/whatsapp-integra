<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los envíos automáticos del ERP, vistos y cambiados desde el CRM.
 *
 * Los interruptores —factura del mes, recibo de pago— y las plantillas por
 * defecto se configuraban en Integra 2.0, mientras el WhatsApp lo lleva el CRM:
 * el cliente tenía que saber que una cosa se toca en un sitio y la otra en el
 * otro.
 *
 * Lo que **no** se hace es guardarlos aquí. Se leen y se escriben en Integra en
 * cada visita, para que no existan dos copias de la misma configuración: el
 * problema con el que empezó todo este trabajo.
 */
class AjustesDeEnvioDelErpTest extends TestCase
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

    /** Sin Integra conectado no hay ajustes que enseñar, y no es un error. */
    public function test_sin_integra_conectado_lo_dice_sin_romperse(): void
    {
        Http::fake();

        $this->actingAs($this->usuario)
            ->getJson('/integrations/ajustes-envio')
            ->assertOk()
            ->assertJson(['conectado' => false]);

        Http::assertNothingSent();
    }

    public function test_lee_los_ajustes_de_integra(): void
    {
        $this->conectarIntegra();

        Http::fake(['*/whatsapp/ajustes' => Http::response(['success' => true, 'data' => [
            'envio_automatico_facturas' => true,
            'envio_automatico_recibos' => false,
            'plantillas' => ['factura' => ['id' => 7, 'title' => 'facturacion']],
            'disponibles' => [],
        ]], 200)]);

        $this->actingAs($this->usuario)
            ->getJson('/integrations/ajustes-envio')
            ->assertOk()
            ->assertJsonPath('conectado', true)
            ->assertJsonPath('envio_automatico_facturas', true)
            ->assertJsonPath('plantillas.factura.id', 7);
    }

    /** Guardar escribe en Integra, no aquí: no hay tabla local que mirar. */
    public function test_guardar_escribe_en_integra(): void
    {
        $this->conectarIntegra();

        Http::fake(['*/whatsapp/ajustes' => Http::response(['success' => true, 'data' => [
            'envio_automatico_facturas' => false,
        ]], 200)]);

        $this->actingAs($this->usuario)
            ->putJson('/integrations/ajustes-envio', ['envio_automatico_facturas' => false])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($p) => $p->method() === 'PUT'
            && str_contains($p->url(), '/api/v1/whatsapp/ajustes')
            && $p->data()['envio_automatico_facturas'] === false);
    }

    /**
     * Un token emitido antes de esta función da 403. No se arregla
     * reintentando: hay que reconectar, y el aviso lo dice con esas palabras.
     */
    public function test_un_token_viejo_pide_reconectar(): void
    {
        $this->conectarIntegra();

        Http::fake(['*/whatsapp/ajustes' => Http::response(['message' => 'sin permiso'], 403)]);

        $this->actingAs($this->usuario)
            ->getJson('/integrations/ajustes-envio')
            ->assertOk()
            ->assertJsonPath('conectado', true);

        $respuesta = $this->actingAs($this->usuario)->getJson('/integrations/ajustes-envio');
        $this->assertStringContainsString('Vuelve a conectarla', $respuesta->json('error'));
    }

    /** Y un Integra sin actualizar dice que hay que actualizarlo. */
    public function test_un_integra_viejo_pide_actualizarse(): void
    {
        $this->conectarIntegra();

        Http::fake(['*/whatsapp/ajustes' => Http::response('Not Found', 404)]);

        $respuesta = $this->actingAs($this->usuario)->getJson('/integrations/ajustes-envio');

        $this->assertStringContainsString('Actualízala', $respuesta->json('error'));
    }

    /** Sin permiso de gestión no se puede guardar. */
    public function test_sin_permiso_no_se_puede_guardar(): void
    {
        $this->conectarIntegra();
        Http::fake();

        $rol = Role::where('name', 'admin')->where('company_id', $this->usuario->company_id)->first();
        $rol->syncPermissions([Permission::where('name', 'integrations.view')->first()]);

        $this->actingAs($this->usuario->fresh())
            ->putJson('/integrations/ajustes-envio', ['envio_automatico_facturas' => false])
            ->assertForbidden();
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
