<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Services\IntegraClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Emisión a la DIAN al registrar un pago.
 *
 * Integra puede convertir la factura estándar en electrónica y emitirla en el
 * mismo acto del pago, pero sólo si el POST lleva `emitir_electronica`. Quién
 * decide eso es la empresa, desde la tarjeta de la integración — no el agente
 * desde el chat, porque emitir es irreversible: una factura aceptada por la
 * DIAN sólo se deshace con nota crédito.
 *
 * Lo que se protege aquí es que ese interruptor no mienta en ninguna de sus
 * dos direcciones: apagado no debe emitir nada, y encendido no debe poder
 * quedarse encendido sobre un token que no lo autoriza.
 */
class IntegraElectronicInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Con la casilla apagada el payload es el de siempre.
     *
     * No basta con que `emitir_electronica` sea false: no debe ir. Un pago es
     * una operación fiscal y no vale la pena arriesgar los que ya funcionan
     * mandándole al ERP una llave que su versión podría no esperar.
     */
    public function test_apagado_no_manda_el_parametro(): void
    {
        $this->fakeIntegra();
        $this->actingAs($this->admin($this->connected(emitir: false)));

        $this->postJson('/api/integrations/invoice-payments/pay', $this->pago())
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/facturas/9001/pagos')
            && ! array_key_exists('emitir_electronica', $r->data()));
    }

    /** Con la casilla encendida viaja en true, junto a lo de siempre. */
    public function test_encendido_manda_el_parametro(): void
    {
        $this->fakeIntegra();
        $this->actingAs($this->admin($this->connected(emitir: true)));

        $this->postJson('/api/integrations/invoice-payments/pay', $this->pago())
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/facturas/9001/pagos')
            && $r['emitir_electronica'] === true
            && $r['monto'] === 80000);
    }

    /** El bloque que devuelve Integra llega tal cual al chat, para el agente. */
    public function test_el_estado_de_la_emision_llega_al_frontend(): void
    {
        $this->fakeIntegra(['factura_electronica' => [
            'estado' => 'en_proceso',
            'numero' => 'FVE19060',
            'cufe'   => null,
        ]]);
        $this->actingAs($this->admin($this->connected(emitir: true)));

        $this->postJson('/api/integrations/invoice-payments/pay', $this->pago())
            ->assertOk()
            ->assertJsonPath('result.factura_electronica.estado', 'en_proceso')
            ->assertJsonPath('result.factura_electronica.numero', 'FVE19060');
    }

    /**
     * Un token sin `facturas.emitir` no puede encender la casilla.
     *
     * Integra no responde 403 cuando falta el scope: responde `no_aplica` y
     * registra el pago igual. Sin este corte, el admin marcaría una casilla que
     * no hace nada y se enteraría sobre un cobro real, por un agente que no
     * puede arreglarlo.
     */
    public function test_sin_el_scope_no_se_puede_activar(): void
    {
        $integration = $this->connected(emitir: false);
        $integration->update(['abilities' => IntegraClient::ABILITIES]); // sin facturas.emitir

        $this->actingAs($this->admin($integration))
            ->postJson('/api/integrations/invoice_payments/activate', [
                'enabled' => true,
                'trigger_type' => 'slash',
                'trigger_command' => 'pagos',
                'emit_electronic_invoice' => true,
            ])
            ->assertStatus(422);

        $this->assertFalse($integration->fresh()->emit_electronic_invoice);
    }

    /** Con el scope concedido, sí. */
    public function test_con_el_scope_se_activa(): void
    {
        $integration = $this->connected(emitir: false);
        $integration->update([
            'abilities' => array_merge(IntegraClient::ABILITIES, [IntegraClient::ABILITY_EMIT]),
        ]);

        $this->actingAs($this->admin($integration))
            ->postJson('/api/integrations/invoice_payments/activate', [
                'enabled' => true,
                'trigger_type' => 'slash',
                'trigger_command' => 'pagos',
                'emit_electronic_invoice' => true,
            ])
            ->assertOk()
            ->assertJsonPath('emit_electronic_invoice', true)
            ->assertJsonPath('can_emit_electronic', true);
    }

    /**
     * Un token pegado a mano no dice qué autoriza, y eso es "no sabemos", no
     * "no puede": se deja activar y que responda Integra. Bloquearlo dejaría
     * sin emisión a quien conectó con un token válido perfectamente capaz.
     */
    public function test_si_no_sabemos_los_scopes_se_deja_activar(): void
    {
        $integration = $this->connected(emitir: false); // abilities queda null

        $this->actingAs($this->admin($integration))
            ->postJson('/api/integrations/invoice_payments/activate', [
                'enabled' => true,
                'trigger_type' => 'slash',
                'trigger_command' => 'pagos',
                'emit_electronic_invoice' => true,
            ])
            ->assertOk()
            ->assertJsonPath('emit_electronic_invoice', true)
            ->assertJsonPath('can_emit_electronic', null);
    }

    /**
     * Desconectar apaga la emisión, no sólo el chat.
     *
     * La emisión cuelga del token que se borra al desconectar. Si sobreviviera
     * encendida, la siguiente conexión —quizá con un token sin ese permiso—
     * heredaría en silencio una decisión fiscal que nadie volvió a tomar.
     */
    public function test_desconectar_apaga_la_emision(): void
    {
        $integration = $this->connected(emitir: true);
        $integration->update(['abilities' => [IntegraClient::ABILITY_EMIT]]);

        $this->actingAs($this->admin($integration))
            ->postJson('/api/integrations/invoice_payments/disconnect')
            ->assertOk();

        $integration->refresh();
        $this->assertFalse($integration->emit_electronic_invoice);
        $this->assertNull($integration->abilities);
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function pago(): array
    {
        return ['factura_id' => 9001, 'cuenta' => 3, 'metodo_pago' => 1, 'monto' => 80000];
    }

    private function fakeIntegra(array $extra = []): void
    {
        Http::fake([
            '*/api/v1/facturas/*/pagos' => Http::response([
                'success' => true,
                'data' => array_merge([
                    'ingreso_id' => 51204,
                    'recibo_caja' => 14397,
                    'monto_aplicado' => 80000,
                    'factura_estado' => 'cerrada',
                ], $extra),
            ], 200),
        ]);
    }

    private function connected(bool $emitir): CompanyIntegration
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        return CompanyIntegration::create([
            'company_id' => $company->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://demo.test/software',
            'access_token' => 'itg_token',
            'enabled' => true,
            'emit_electronic_invoice' => $emitir,
        ]);
    }

    private function admin(CompanyIntegration $integration): User
    {
        foreach (['integrations.view', 'integrations.update'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        return User::create([
            'company_id' => $integration->company_id,
            'name' => 'Admin',
            'email' => 'admin-' . $integration->company_id . '@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);
    }
}
