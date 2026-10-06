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

    private const PAGO_PENDIENTE_DE_META = 'Message failed to send because your WhatsApp Business account has unsettled payments. '
        .'Visit https://business.facebook.com/billing_hub/accounts/details/?business_id=760374460373551&asset_id=1289115706586051'
        .'&wizard_name=PAY_NOW&account_type=whatsapp-business-account to resolve this issue.';

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

    /** Una plantilla entregada prueba que ya hay tarjeta: la alerta se apaga sola. */
    public function test_una_plantilla_entregada_apaga_la_alerta(): void
    {
        [$instance] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA, 'problema_de_pago_desde' => now()]);

        // Un texto dentro de las 24 h sale gratis aun sin tarjeta: no prueba nada.
        $this->mensaje($instance, 'delivered', 'text');
        $this->assertNotNull($instance->refresh()->problema_de_pago);

        $this->mensaje($instance, 'delivered', 'template');
        $this->assertNull($instance->refresh()->problema_de_pago);
    }

    /**
     * «sent» no prueba nada: Meta acepta el envío con un 200 y lo rechaza por
     * cobro segundos después. Apagar ahí hacía que la alerta parpadeara y
     * avisara a los admins con cada factura.
     */
    public function test_una_plantilla_solo_aceptada_no_apaga_la_alerta(): void
    {
        [$instance] = $this->linea(['problema_de_pago' => FacturacionDeMeta::PAGO_PENDIENTE, 'problema_de_pago_desde' => now()]);

        $this->mensaje($instance, 'sent', 'template');

        $this->assertSame(FacturacionDeMeta::PAGO_PENDIENTE, $instance->refresh()->problema_de_pago);
    }

    /**
     * El caso del 3-oct-2026: la tarjeta seguía asociada y Meta decía «unsettled
     * payments», pero el CRM sólo leía el título del error y avisaba «no tienes
     * método de pago». El motivo y el enlace de pagar vienen en el detalle.
     */
    public function test_un_cobro_pendiente_no_se_confunde_con_falta_de_tarjeta(): void
    {
        Notification::fake();
        [$instance] = $this->linea();

        $this->mensaje($instance, 'failed', 'template', '131042', 'Business eligibility payment issue', self::PAGO_PENDIENTE_DE_META);

        $instance->refresh();
        $this->assertSame(FacturacionDeMeta::PAGO_PENDIENTE, $instance->problema_de_pago);
        $this->assertStringContainsString('wizard_name=PAY_NOW', $instance->enlace_de_pago);
        $this->assertStringContainsString('unsettled payments', $instance->detalle_de_pago);
    }

    public function test_reconoce_cada_motivo_de_meta(): void
    {
        $this->assertSame(FacturacionDeMeta::PAGO_PENDIENTE, FacturacionDeMeta::tipo(self::PAGO_PENDIENTE_DE_META));
        $this->assertSame(FacturacionDeMeta::PAGO_RESTRINGIDO, FacturacionDeMeta::tipo(
            'Message failed to send because your WhatsApp Business account payment has been restricted. Visit https://business.facebook.com/billing_hub/accounts/details/?asset_id=1 to resolve this issue.'
        ));
        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, FacturacionDeMeta::tipo(self::ERROR_DE_META));
        $this->assertSame(FacturacionDeMeta::SIN_METODO, FacturacionDeMeta::tipo('Business eligibility payment issue'));
    }

    /** Por la API del ERP el detalle llega en `error_data.details`, no en `message`. */
    public function test_la_api_lee_el_detalle_del_error(): void
    {
        Notification::fake();
        [$instance] = $this->linea();
        $token = $instance->generarApiToken();

        Http::fake([
            '*/message_templates*' => Http::response(['data' => []]),
            'https://graph.facebook.com/*/messages' => Http::response(['error' => [
                'message' => 'Business eligibility payment issue',
                'code' => 131042,
                'error_data' => ['details' => self::PAGO_PENDIENTE_DE_META],
            ]], 400),
        ]);

        $this->withHeader('X-Instance-Token', $token)
            ->postJson('/api/v1/messages/template', [
                'to' => '573001112233',
                'template_name' => 'facturacion',
                'language_code' => 'es',
            ])
            ->assertStatus(500);

        $this->assertSame(FacturacionDeMeta::PAGO_PENDIENTE, $instance->refresh()->problema_de_pago);
    }

    /** La guía enseña lo que dijo Meta, sin el enlace, que ya es un botón. */
    public function test_la_guia_ensena_el_motivo_real(): void
    {
        [$instance, $admin] = $this->linea([
            'problema_de_pago' => FacturacionDeMeta::PAGO_PENDIENTE,
            'detalle_de_pago' => self::PAGO_PENDIENTE_DE_META,
            'enlace_de_pago' => 'https://business.facebook.com/billing_hub/accounts/details/?asset_id=1&wizard_name=PAY_NOW',
        ]);

        $this->actingAs($admin)
            ->get('/instances/pago-en-meta')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lineas.0.explicacion.titulo', 'Meta tiene un cobro pendiente sin pagar')
                ->where('lineas.0.detalle', 'Message failed to send because your WhatsApp Business account has unsettled payments.')
                ->where('lineas.0.pagar_ahora', true)
                ->where('alertaPagoMeta.0.titulo', 'Meta tiene un cobro pendiente sin pagar')
            );
    }

    /** Lo consumido, por categoría y separando lo gratis, en la moneda de la cuenta. */
    public function test_el_consumo_suma_lo_cobrado_por_categoria(): void
    {
        [$instance, $admin] = $this->linea(['problema_de_pago' => FacturacionDeMeta::PAGO_PENDIENTE]);

        Http::fake([
            'https://graph.facebook.com/v23.0/*' => Http::response([
                'currency' => 'USD',
                'pricing_analytics' => ['data' => [['data_points' => [
                    ['pricing_type' => 'FREE_CUSTOMER_SERVICE', 'pricing_category' => 'SERVICE', 'volume' => 184, 'cost' => 0],
                    ['pricing_type' => 'REGULAR', 'pricing_category' => 'UTILITY', 'volume' => 1238, 'cost' => 0.9904],
                    ['pricing_type' => 'REGULAR', 'pricing_category' => 'MARKETING', 'volume' => 802, 'cost' => 10.025],
                ]]]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson("/instances/{$instance->id}/consumo-meta")
            ->assertOk()
            ->assertJsonPath('moneda', 'USD')
            ->assertJsonPath('periodos.0.total', 11.02)
            ->assertJsonPath('periodos.0.cobrados', 2040)
            ->assertJsonPath('periodos.0.gratis', 184)
            ->assertJsonPath('periodos.0.categorias.0.categoria', 'MARKETING');
    }

    /** El portafolio que paga: hay clientes con varios en su Facebook. */
    public function test_el_consumo_dice_a_que_portafolio_cobra_meta(): void
    {
        [$instance, $admin] = $this->linea();

        Http::fake(fn ($r) => str_contains($r->url(), 'owner_business_info')
            ? Http::response([
                'name' => 'Principal',
                'owner_business_info' => ['id' => '123456789', 'name' => 'Ferretería Ejemplo SAS'],
            ])
            : Http::response(['currency' => 'COP', 'pricing_analytics' => ['data' => [['data_points' => []]]]]));

        $this->actingAs($admin)
            ->getJson("/instances/{$instance->id}/consumo-meta")
            ->assertOk()
            ->assertJsonPath('moneda', 'COP')
            ->assertJsonPath('cuenta', 'Principal')
            ->assertJsonPath('portafolio.id', '123456789')
            ->assertJsonPath('portafolio.nombre', 'Ferretería Ejemplo SAS');
    }

    /** Si Meta niega el consumo, el portafolio se sigue enseñando. */
    public function test_el_portafolio_sale_aunque_falle_el_consumo(): void
    {
        [$instance, $admin] = $this->linea();

        Http::fake(fn ($r) => str_contains($r->url(), 'owner_business_info')
            ? Http::response(['name' => 'Principal', 'owner_business_info' => ['id' => '123456789', 'name' => 'Integra Colombia SAS']])
            : Http::response(['error' => ['message' => 'nope']], 400));

        $this->actingAs($admin)
            ->getJson("/instances/{$instance->id}/consumo-meta")
            ->assertOk()
            ->assertJsonPath('periodos', null)
            ->assertJsonPath('portafolio.nombre', 'Integra Colombia SAS');
    }

    public function test_no_se_ve_el_consumo_de_otra_empresa(): void
    {
        [, $admin] = $this->linea();
        [$ajena] = $this->linea();

        $this->actingAs($admin)
            ->getJson("/instances/{$ajena->id}/consumo-meta")
            ->assertForbidden();
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

    /**
     * JHeda, 30-sep-2026: pago hecho, negocio sin verificar. Es «ya envías, con
     * tope», no «el pago sigue mal».
     */
    public function test_pagada_pero_sin_verificar_dice_el_motivo_real(): void
    {
        [$instance, $admin] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA]);

        Http::fake(function ($r) {
            return str_contains($r->url(), 'currency')
                ? Http::response(['currency' => 'COP', 'id' => '1'])
                : Http::response(['id' => '1', 'health_status' => [
                    'can_send_message' => 'LIMITED',
                    'entities' => [
                        ['entity_type' => 'WABA', 'can_send_message' => 'AVAILABLE'],
                        ['entity_type' => 'BUSINESS', 'can_send_message' => 'LIMITED', 'errors' => [[
                            'error_code' => 141010,
                            'error_description' => 'The Business has not passed business verification.',
                        ]]],
                    ],
                ]]);
        });

        $this->actingAs($admin)
            ->postJson("/instances/{$instance->id}/comprobar-pago")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('limitada', true)
            ->assertJsonPath('guia', '/instances/guia-limites-whatsapp')
            ->assertJsonPath('mensaje', fn ($m) => str_contains($m, 'verificación'));

        $this->assertNull($instance->refresh()->problema_de_pago);
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

    private function mensaje(Instance $instance, string $estado, string $tipo, ?string $codigo = null, ?string $error = null, ?string $detalle = null): WhatsAppMessage
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
            'error_details' => $detalle,
            'sent_at' => now(),
            'wamid' => 'wamid.'.Str::random(10),
        ]);
    }
}
