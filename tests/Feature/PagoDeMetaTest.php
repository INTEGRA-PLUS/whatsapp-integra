<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\SystemNotification;
use App\Support\FacturacionDeMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La alerta roja de «falta el método de pago en Meta».
 *
 * EL FALLO QUE SE PREVIENE: el 30-sep-2026 las facturas de JHeda Comunicaciones
 * volvían todas con «your WhatsApp Business account currency is not
 * configured». El CRM sólo decía «Fallido» mensaje a mensaje y nadie en la
 * empresa sabía que tenía que ir a Meta a poner una tarjeta.
 */
class PagoDeMetaTest extends TestCase
{
    use RefreshDatabase;

    private const ERROR_DE_META = 'Message failed to send because your WhatsApp Business account currency is not configured. '
        .'Visit https://business.facebook.com/billing_hub/accounts/details/?business_id=932164282769511&asset_id=102644279143040'
        .'&wizard_name=CHANGE_COUNTRY_CURRENCY&account_type=whatsapp-business-account to resolve this issue.';

    public function test_reconoce_el_error_de_moneda_y_saca_el_enlace(): void
    {
        $this->assertTrue(FacturacionDeMeta::esErrorDePago(null, self::ERROR_DE_META), 'Por el texto, aunque no venga código.');
        $this->assertTrue(FacturacionDeMeta::esErrorDePago(131042, 'lo que sea'));
        $this->assertFalse(FacturacionDeMeta::esErrorDePago(131048, 'Spam Rate limit hit'));
        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, FacturacionDeMeta::tipo(self::ERROR_DE_META));
        $this->assertStringStartsWith('https://business.facebook.com/billing_hub/accounts/details/?business_id=932164282769511', FacturacionDeMeta::enlaceDe(self::ERROR_DE_META));
        $this->assertStringEndsWith('account_type=whatsapp-business-account', FacturacionDeMeta::enlaceDe(self::ERROR_DE_META));
    }

    /** Por donde llegó de verdad: las facturas del ERP, que no guardan burbuja al fallar. */
    public function test_una_factura_del_erp_rechazada_enciende_la_alerta(): void
    {
        Notification::fake();
        [$instance, $admin] = $this->linea();
        $token = $instance->generarApiToken();

        Http::fake([
            '*/message_templates*' => Http::response(['data' => []]),
            'https://graph.facebook.com/*/messages' => Http::response(['error' => [
                'message' => self::ERROR_DE_META,
                'code' => 131042,
            ]], 400),
        ]);

        $this->withHeader('X-Instance-Token', $token)
            ->postJson('/api/v1/messages/template', [
                'to' => '573001112233',
                'template_name' => 'facturacion',
                'language_code' => 'es',
            ])
            ->assertStatus(500);

        $instance->refresh();
        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, $instance->problema_de_pago);
        $this->assertStringContainsString('business_id=932164282769511', $instance->enlace_de_pago);
        Notification::assertSentTo($admin, SystemNotification::class);
    }

    /** Del chat, campañas o webhook: cualquier mensaje que falla por pago. */
    public function test_un_mensaje_fallido_por_pago_enciende_la_alerta_y_avisa_una_vez(): void
    {
        Notification::fake();
        [$instance, $admin] = $this->linea();

        $this->mensaje($instance, 'failed', 'text', '131042', self::ERROR_DE_META);
        $this->mensaje($instance, 'failed', 'text', '131042', self::ERROR_DE_META);

        $this->assertNotNull($instance->refresh()->problema_de_pago);
        Notification::assertSentToTimes($admin, SystemNotification::class, 1);
    }

    public function test_otro_fallo_no_la_enciende(): void
    {
        [$instance] = $this->linea();

        $this->mensaje($instance, 'failed', 'text', '131048', 'Spam Rate limit hit');

        $this->assertNull($instance->refresh()->problema_de_pago);
    }

    /** Una plantilla que sale prueba que ya hay tarjeta: la alerta se apaga sola. */
    public function test_una_plantilla_enviada_apaga_la_alerta(): void
    {
        [$instance] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA, 'problema_de_pago_desde' => now()]);

        // Un texto dentro de las 24 h sale gratis aun sin tarjeta: no prueba nada.
        $this->mensaje($instance, 'sent', 'text');
        $this->assertNotNull($instance->refresh()->problema_de_pago);

        $this->mensaje($instance, 'sent', 'template');
        $this->assertNull($instance->refresh()->problema_de_pago);
    }

    /** La alerta sale en todas las pantallas, sólo con las líneas de su empresa. */
    public function test_la_alerta_viaja_en_las_props_compartidas(): void
    {
        [$instance, $admin] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_METODO]);
        [$otra] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_METODO]);

        $this->actingAs($admin)
            ->get('/instances/pago-en-meta')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Instances/GuiaPagoMeta')
                ->has('alertaPagoMeta', 1)
                ->where('alertaPagoMeta.0.id', $instance->id)
                ->where('lineas.0.problema', FacturacionDeMeta::SIN_METODO)
            );
    }

    public function test_comprobar_con_meta_disponible_apaga_la_alerta(): void
    {
        [$instance, $admin] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA]);

        Http::fake(function ($r) {
            return str_contains($r->url(), 'currency')
                ? Http::response(['currency' => 'COP', 'id' => '1'])
                : Http::response(['health_status' => ['can_send_message' => 'AVAILABLE'], 'id' => '1']);
        });

        $this->actingAs($admin)
            ->postJson("/instances/{$instance->id}/comprobar-pago")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($instance->refresh()->problema_de_pago);
    }

    public function test_comprobar_sin_moneda_la_mantiene(): void
    {
        [$instance, $admin] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA]);

        Http::fake(function ($r) {
            return str_contains($r->url(), 'currency')
                ? Http::response(['id' => '1'])
                : Http::response(['health_status' => ['can_send_message' => 'AVAILABLE'], 'id' => '1']);
        });

        $this->actingAs($admin)
            ->postJson("/instances/{$instance->id}/comprobar-pago")
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, $instance->refresh()->problema_de_pago);
    }

    public function test_no_se_comprueba_la_linea_de_otra_empresa(): void
    {
        [, $admin] = $this->linea();
        [$ajena] = $this->linea();

        $this->actingAs($admin)
            ->postJson("/instances/{$ajena->id}/comprobar-pago")
            ->assertForbidden();
    }

    /** @return array{0: Instance, 1: User} */
    private function linea(array $extra = []): array
    {
        $company = Company::create(['name' => 'JHeda '.Str::random(4), 'slug' => 'jheda-'.Str::random(6), 'active' => true]);

        $instance = Instance::create(array_merge([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'jheda comunicaciones',
            'display_phone_number' => '+57 350 8241838',
            'phone_number_id' => (string) random_int(100000, 999999),
            'waba_id' => '102644279143040',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ], $extra));

        $admin = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'a'.uniqid().'@jheda.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        return [$instance, $admin];
    }

    private function mensaje(Instance $instance, string $estado, string $tipo, ?string $codigo = null, ?string $error = null): WhatsAppMessage
    {
        $conversacion = WhatsAppConversation::firstOrCreate(
            ['instance_id' => $instance->id, 'wa_id' => '573001112233'],
            ['name' => 'Cliente', 'status' => 'open', 'last_message_at' => now()]
        );

        return WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'type' => $tipo,
            'content' => 'Tu factura',
            'direction' => 'outbound',
            'status' => $estado,
            'error_code' => $codigo,
            'error_message' => $error,
            'sent_at' => now(),
            'wamid' => 'wamid.'.Str::random(10),
        ]);
    }
}
