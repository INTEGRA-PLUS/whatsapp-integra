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
use Tests\TestCase;

/**
 * Que Integra envíe por la misma línea que el CRM conoce.
 *
 * Nova Partners, 24-sep-2026: conectó el número a las 19:27 e Integra a las
 * 19:31. El alta automática de la línea en Integra sólo se intentaba al
 * conectar el número, así que no llegó nunca, y cada factura volvía con
 * «Instancia no válida o token ausente» sin nada en pantalla que lo explicara.
 */
class LineaDeEnvioEnIntegraTest extends TestCase
{
    use RefreshDatabase;

    public function test_conectar_integra_despues_del_numero_lo_deja_como_linea_de_envio(): void
    {
        $empresa = $this->empresa();
        $linea = $this->linea($empresa, 'pnid-nova');
        $this->fingirIntegra();

        $this->actingAs($this->admin($empresa))
            ->postJson('/api/integrations/'.CompanyIntegration::KEY_INVOICE_PAYMENTS.'/connect', [
                'base_url' => 'https://nova.test',
                'email' => 'admin@nova.test',
                'password' => 'secreto',
            ])
            ->assertOk()
            ->assertJsonPath('lineas_en_integra.registradas', 1)
            ->assertJsonPath('lineas_en_integra.envio', 'Integra ya quedó configurado para enviar por esta línea.');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v1/whatsapp/instancias')
            && $r['phone_number_id'] === $linea->phone_number_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v1/whatsapp/linea-envio')
            && $r['phone_number_id'] === $linea->phone_number_id);
    }

    /**
     * Con varias líneas y ninguna elegida no se toca la de envío: la «por
     * defecto» del CRM puede no ser la que Integra usa, y reconectar para
     * renovar el token le cambiaría el número de facturación a alguien.
     */
    public function test_con_varias_lineas_sin_elegir_sólo_las_registra(): void
    {
        $empresa = $this->empresa();
        $this->linea($empresa, 'pnid-a');
        $this->linea($empresa, 'pnid-b');
        $this->fingirIntegra();

        $this->actingAs($this->admin($empresa))
            ->postJson('/api/integrations/'.CompanyIntegration::KEY_INVOICE_PAYMENTS.'/connect', [
                'base_url' => 'https://nova.test',
                'email' => 'admin@nova.test',
                'password' => 'secreto',
            ])
            ->assertOk()
            ->assertJsonPath('lineas_en_integra.registradas', 2)
            ->assertJsonPath('lineas_en_integra.envio', null);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'linea-envio'));
    }

    public function test_el_boton_sincroniza_la_linea_de_envio(): void
    {
        $empresa = $this->empresa();
        $linea = $this->linea($empresa, 'pnid-nova');
        $this->conectarIntegra($empresa);
        $this->fingirIntegra();

        $this->actingAs($this->admin($empresa))
            ->postJson("/instances/{$linea->id}/sincronizar-integra")
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v1/whatsapp/linea-envio')
            && $r['phone_number_id'] === 'pnid-nova'
            && $r['waba_id'] === $linea->waba_id);
    }

    /** Pasar las facturas a otra línea se decide en Integraciones, no aquí. */
    public function test_el_boton_no_cambia_de_linea(): void
    {
        $empresa = $this->empresa();
        $this->linea($empresa, 'pnid-a');
        $otra = $this->linea($empresa, 'pnid-b');
        $this->conectarIntegra($empresa);
        $this->fingirIntegra();

        $this->actingAs($this->admin($empresa))
            ->postJson("/instances/{$otra->id}/sincronizar-integra")
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'linea-envio'));
    }

    public function test_no_se_sincroniza_la_linea_de_otra_empresa(): void
    {
        $ajena = $this->linea($this->empresa('vecina'), 'pnid-vecina');
        $empresa = $this->empresa();
        $this->conectarIntegra($empresa);
        $this->fingirIntegra();

        $this->actingAs($this->admin($empresa))
            ->postJson("/instances/{$ajena->id}/sincronizar-integra")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_sin_integra_conectado_lo_dice(): void
    {
        $empresa = $this->empresa();
        $linea = $this->linea($empresa, 'pnid-nova');
        Http::fake();

        $this->actingAs($this->admin($empresa))
            ->postJson("/instances/{$linea->id}/sincronizar-integra")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Integra no está conectado. Conéctalo en Integraciones.');
    }

    private function fingirIntegra(): void
    {
        Http::fake([
            '*/api/v1/tokens' => Http::response(['success' => true, 'data' => ['token' => 'itg_nuevo']], 201),
            '*/api/v1/whatsapp/instancias' => Http::response(['success' => true, 'data' => ['creada' => true]], 201),
            '*/api/v1/whatsapp/linea-envio' => Http::response(['success' => true, 'data' => ['cambiada' => true, 'anterior' => null]], 200),
            '*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    private function empresa(string $slug = 'nova'): Company
    {
        return Company::create(['name' => ucfirst($slug), 'slug' => $slug.'-'.Str::random(4), 'active' => true]);
    }

    private function linea(Company $empresa, string $pnid): Instance
    {
        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea '.$pnid,
            'phone_number_id' => $pnid,
            'waba_id' => 'waba-'.$pnid,
            'display_phone_number' => '+57 322 5858693',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);
    }

    private function conectarIntegra(Company $empresa): void
    {
        CompanyIntegration::create([
            'company_id' => $empresa->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://nova.test/software',
            'access_token' => 'itg_token',
            'enabled' => true,
        ]);
    }

    /** El primer usuario de la empresa recibe el rol admin con los permisos que ya existan. */
    private function admin(Company $empresa): User
    {
        foreach (['integrations.view', 'integrations.update', 'integrations.create'] as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }

        return User::create([
            'company_id' => $empresa->id, 'name' => 'Admin',
            'email' => Str::random(8).'@test.local', 'password' => bcrypt('x'),
            'role' => 'admin', 'active' => true,
        ]);
    }
}
