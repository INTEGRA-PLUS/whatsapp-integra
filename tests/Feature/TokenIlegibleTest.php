<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Una credencial que ya no se puede descifrar tumbaba la pantalla entera.
 *
 * `company_integrations.access_token` tiene el cast `encrypted`, y ese cast
 * lanza `DecryptException` cuando la fila se cifró con otra `APP_KEY`. El
 * 10-sep-2026 pasó de verdad: veintiuna filas quedaron ilegibles al cambiar el
 * entorno del contenedor. La excepción salía desde `isConnected()`, que es lo
 * primero que hace el listado de Complementos al pintarse, así que **una sola
 * fila mala** devolvía `{"message":"Server Error"}` para toda la empresa, sin
 * decir de qué integración se trataba ni qué hacer.
 *
 * Un token que no se puede descifrar no sirve para nada: vale lo mismo que no
 * tener token. La integración sale desconectada, y la pantalla dice lo único
 * que lo arregla —volver a conectar—, en vez de dejar a alguien pulsando
 * «Verificar» contra algo que nunca va a responder.
 */
class TokenIlegibleTest extends TestCase
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

    public function test_el_listado_no_revienta_con_una_credencial_ilegible(): void
    {
        $this->conIntegracionRota();

        $this->actingAs($this->usuario)
            ->getJson('/api/integrations')
            ->assertOk()
            ->assertJsonPath('0.connected', false)
            ->assertJsonPath('0.token_ilegible', true);
    }

    /** Y no se confunde con no tener credencial: son dos cosas distintas. */
    public function test_sin_credencial_no_se_marca_como_ilegible(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->usuario->company_id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'base_url' => 'https://miempresa.integra.test',
            'status' => 'disconnected',
        ]);

        $respuesta = $this->actingAs($this->usuario)->getJson('/api/integrations')->assertOk();

        $this->assertFalse($respuesta->json('0.token_ilegible'));
    }

    /** Una legible sigue contando como conectada, claro. */
    public function test_una_credencial_legible_sigue_conectada(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->usuario->company_id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'base_url' => 'https://miempresa.integra.test',
            'access_token' => 'itg_'.Str::random(20),
            'status' => 'connected',
        ]);

        $this->actingAs($this->usuario)
            ->getJson('/api/integrations')
            ->assertOk()
            ->assertJsonPath('0.connected', true)
            ->assertJsonPath('0.token_ilegible', false);
    }

    /** Verificar tampoco puede reventar: contesta el estado y ya. */
    public function test_verificar_no_revienta_con_una_credencial_ilegible(): void
    {
        $this->conIntegracionRota();

        $this->actingAs($this->usuario)
            ->getJson('/api/integrations/'.CompanyIntegration::KEY_INVOICE_PAYMENTS.'/status')
            ->assertOk()
            ->assertJsonPath('connected', false);
    }

    /** Ni el cliente HTTP: sin token legible no hay cliente que construir. */
    public function test_no_construye_cliente_con_una_credencial_ilegible(): void
    {
        $integracion = $this->conIntegracionRota();

        $this->assertNull($integracion->fresh()->client());
        $this->assertFalse($integracion->fresh()->isConnected());
    }

    /**
     * Y alguien tiene que darse cuenta sin que lo reporte el cliente.
     *
     * Con la llave equivocada la fila sigue en «connected» y la pantalla pide
     * conectar de nuevo, así que se vive como «se me borró la integración» y se
     * vuelve a crear — hasta el siguiente despliegue. El 10-sep-2026 pasó tres
     * veces con la misma empresa antes de que nadie mirara el log.
     */
    public function test_el_comando_avisa_cuando_una_credencial_no_se_puede_leer(): void
    {
        $this->conIntegracionRota();

        $this->artisan('integraciones:credenciales')
            ->expectsOutputToContain('no se pueden descifrar')
            ->assertFailed();
    }

    /** Y calla cuando no hay nada que avisar: si no, se vuelve ruido. */
    public function test_el_comando_pasa_cuando_todas_se_leen(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->usuario->company_id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'base_url' => 'https://miempresa.integra.test',
            'access_token' => 'itg_'.Str::random(20),
            'status' => 'connected',
        ]);

        $this->artisan('integraciones:credenciales')->assertSuccessful();
    }

    /**
     * Se escribe el cifrado a pelo, saltándose el cast: es exactamente lo que
     * hay en la base de datos cuando la `APP_KEY` cambió debajo.
     */
    private function conIntegracionRota(): CompanyIntegration
    {
        $integracion = CompanyIntegration::create([
            'company_id' => $this->usuario->company_id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'base_url' => 'https://miempresa.integra.test',
            'access_token' => 'itg_'.Str::random(20),
            'status' => 'connected',
        ]);

        DB::table('company_integrations')
            ->where('id', $integracion->id)
            ->update(['access_token' => 'eyJpdiI6ImNpZnJhZG8tY29uLW90cmEtbGxhdmUiLCJ2YWx1ZSI6Inh4In0=']);

        return $integracion;
    }
}
