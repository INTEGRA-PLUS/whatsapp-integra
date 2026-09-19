<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\QuickReply;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mandarle al cliente su factura desde el chat.
 *
 * Lo que se protege: que no se mande la factura de otro, que no se mande un
 * importe inventado —callar el error y enviar la plantilla con el monto en
 * blanco es peor que no mandarla, porque el cliente la cree— y que la opción no
 * exista para quien no tiene Integra.
 */
class FacturaRapidaTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Fibra del Sur', 'slug' => 'fibra-'.uniqid(), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1311984867684767',
            'type' => 'meta',
            'access_token' => 'token',
            'active' => true,
        ]);

        $this->agente = $this->usuario($company->id);
    }

    /** La lista propone primero la última, y una factura por opción. */
    public function test_las_facturas_salen_de_la_mas_nueva_a_la_mas_vieja(): void
    {
        $this->conIntegra();
        $conversacion = $this->conversacion();

        $this->documento($conversacion, 100, 'Factura_100.pdf', '2026-07-01');
        $this->documento($conversacion, 200, 'Factura_200.pdf', '2026-08-01');
        // La misma factura reenviada: sigue siendo una sola opción.
        $this->documento($conversacion, 200, 'Factura_200.pdf', '2026-08-15');

        $facturas = $this->actingAs($this->agente)
            ->getJson("/api/chat/conversations/{$conversacion->id}/facturas")
            ->assertOk()
            ->json('facturas');

        $this->assertCount(2, $facturas);
        $this->assertSame(200, $facturas[0]['factura_id'], 'La última va primero.');
        $this->assertSame(100, $facturas[1]['factura_id']);
    }

    /**
     * Preparar el envío arma la plantilla con el PDF y el saldo de hoy.
     *
     * El importe se vuelve a leer del ERP: una factura se abona, y decirle al
     * cliente el total de cuando se emitió es pedirle de más.
     *
     * @test
     */
    public function preparar_arma_la_plantilla_con_el_saldo_de_hoy(): void
    {
        $this->conIntegra();
        $conversacion = $this->conversacion();
        $this->documento($conversacion, 100, 'Factura_100.pdf', '2026-09-01');

        Http::fake([
            '*/api/v1/facturas/100' => Http::response(['data' => [
                'codigo' => 'F-100',
                'vencimiento' => '2026-09-25',
                'montos' => ['total' => 120000, 'pagado' => 55000, 'por_pagar' => 65000],
            ]], 200),
        ]);

        $payload = $this->actingAs($this->agente)
            ->postJson("/api/chat/conversations/{$conversacion->id}/facturas/100/preparar")
            ->assertOk()
            ->json();

        $this->assertSame('facturacion', $payload['template_name']);

        $header = collect($payload['components'])->firstWhere('type', 'header');
        $this->assertSame('https://s3.test/Factura_100.pdf', $header['parameters'][0]['document']['link']);

        $cuerpo = collect($payload['components'])->firstWhere('type', 'body');
        $textos = array_column($cuerpo['parameters'], 'text');

        $this->assertSame('Marta Ruiz', $textos[0], 'El nombre del cliente.');
        $this->assertSame('Fibra del Sur', $textos[1], 'El del negocio.');
        $this->assertSame('65.000', $textos[2], 'Lo que falta por pagar, no el total.');
        $this->assertStringContainsString('25 de septiembre', $textos[3]);
    }

    /**
     * Si Integra no responde, no se manda nada.
     *
     * Una factura con el importe en blanco o con el de otra es peor que no
     * mandarla: el cliente la cree y llama.
     *
     * @test
     */
    public function sin_respuesta_del_erp_no_se_prepara_nada(): void
    {
        $this->conIntegra();
        $conversacion = $this->conversacion();
        $this->documento($conversacion, 100, 'Factura_100.pdf', '2026-09-01');

        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->actingAs($this->agente)
            ->postJson("/api/chat/conversations/{$conversacion->id}/facturas/100/preparar")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se pudo leer el importe de esta factura en Integra. Inténtalo de nuevo en un momento.']);
    }

    /** La factura de otro cliente no se puede mandar por este chat. */
    public function test_no_se_puede_mandar_la_factura_de_otra_conversacion(): void
    {
        $this->conIntegra();
        $mia = $this->conversacion();
        $ajena = $this->conversacion('573009998877');

        $this->documento($ajena, 777, 'Factura_777.pdf', '2026-09-01');

        $this->actingAs($this->agente)
            ->postJson("/api/chat/conversations/{$mia->id}/facturas/777/preparar")
            ->assertStatus(404);
    }

    /** Y sin Integra conectado la puerta ni se abre. */
    public function test_sin_integra_no_hay_facturas(): void
    {
        $conversacion = $this->conversacion();

        $this->actingAs($this->agente)
            ->getJson("/api/chat/conversations/{$conversacion->id}/facturas")
            ->assertStatus(403);
    }

    /** Ni se puede crear la respuesta rápida que las envía. */
    public function test_sin_integra_no_se_crea_la_respuesta_rapida(): void
    {
        $this->actingAs($this->agente)
            ->postJson('/api/quick-replies', ['shortcut' => 'factura', 'tipo' => 'factura'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Enviar la factura necesita Integra conectado: el documento y el importe salen del ERP.']);
    }

    /** Con Integra sí, y nace sin texto: lo pone la plantilla. */
    public function test_con_integra_la_respuesta_rapida_nace_sin_texto(): void
    {
        $this->conIntegra();

        $this->actingAs($this->agente)
            ->postJson('/api/quick-replies', ['shortcut' => 'factura', 'tipo' => 'factura'])
            ->assertStatus(201);

        $reply = QuickReply::where('shortcut', 'factura')->first();

        $this->assertTrue($reply->mandaFactura());
        $this->assertNull($reply->message);
    }

    private function conIntegra(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->instance->company_id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://erp.test',
            'access_token' => 'token-erp',
            'enabled' => true,
            'connected_at' => now(),
        ]);
    }

    private function conversacion(string $wa = '573001112233'): WhatsAppConversation
    {
        $contacto = Contact::create([
            'company_id' => $this->instance->company_id,
            'name' => 'Marta Ruiz',
            'phone_number' => $wa,
        ]);

        return WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => $wa,
            'phone_number' => $wa,
            'name' => 'Marta',
            'contact_id' => $contacto->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    private function documento(WhatsAppConversation $c, int $facturaId, string $archivo, string $cuando): void
    {
        WhatsAppMessage::create([
            'conversation_id' => $c->id,
            'wamid' => 'wamid.'.Str::uuid(),
            'type' => 'template',
            'direction' => 'outbound',
            'status' => 'sent',
            'incoming_invoice_id' => $facturaId,
            'media_url' => 'https://s3.test/'.$archivo,
            'filename' => $archivo,
            'sent_at' => $cuando,
        ]);
    }

    private function usuario(int $companyId): User
    {
        $user = User::create([
            'company_id' => $companyId,
            'name' => 'Asesor',
            'email' => Str::uuid().'@x.test',
            'password' => 'secreto123',
            'role' => 'admin',
            'active' => true,
        ]);

        setPermissionsTeamId($companyId);
        $rol = Role::firstOrCreate(['name' => 'op', 'company_id' => $companyId, 'guard_name' => 'web']);

        foreach (['crm.view', 'quick_replies.create'] as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        $user->assignRole($rol);

        return $user->fresh();
    }
}
