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
            ->assertStatus(422);

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

    /**
     * Una plantilla ENTREGADA después de que empezara el problema prueba que
     * Meta ya cobra: la alerta se apaga sola. `sent` no basta: es sólo «Meta lo
     * aceptó», y el 131042 llega después por webhook.
     */
    public function test_una_plantilla_entregada_despues_del_problema_apaga_la_alerta(): void
    {
        [$instance] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA, 'problema_de_pago_desde' => now()->subHour()]);

        // Desde el 1-oct-2026 Meta cobra también el texto libre, pero cada
        // número tiene 1.000 de servicio gratis al mes y Meta los entrega
        // aunque no haya método de pago: no prueba nada.
        $this->mensaje($instance, 'delivered', 'text');
        $this->assertNotNull($instance->refresh()->problema_de_pago);

        $plantilla = $this->mensaje($instance, 'sent', 'template');
        $this->assertNotNull($instance->refresh()->problema_de_pago, 'Aceptada no es entregada.');

        $plantilla->update(['status' => 'delivered']);
        $this->assertNull($instance->refresh()->problema_de_pago);
        $this->assertNotNull($instance->pago_al_dia_desde);
    }

    /** El «leído» de la factura de ayer llega hoy y no dice nada de cómo está la cuenta ahora. */
    public function test_un_leido_de_un_envio_anterior_al_problema_no_la_apaga(): void
    {
        [$instance] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_METODO, 'problema_de_pago_desde' => now()->subHour()]);

        $this->mensaje($instance, 'read', 'template', enviado: now()->subDay());

        $this->assertSame(FacturacionDeMeta::SIN_METODO, $instance->refresh()->problema_de_pago);
    }

    /**
     * Meta reintenta durante días: el 131042 de una factura enviada antes del
     * arreglo puede llegar después. No vuelve a encender la alerta.
     */
    public function test_un_fallo_rezagado_de_antes_del_arreglo_no_la_reenciende(): void
    {
        Notification::fake();
        [$instance, $admin] = $this->linea(['pago_al_dia_desde' => now()->subMinutes(10)]);

        $this->mensaje($instance, 'failed', 'template', '131042', self::ERROR_DE_META, now()->subDays(2));
        $this->assertNull($instance->refresh()->problema_de_pago);

        $this->mensaje($instance, 'failed', 'template', '131042', self::ERROR_DE_META, now());
        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, $instance->refresh()->problema_de_pago);
        Notification::assertSentToTimes($admin, SystemNotification::class, 1);
    }

    /** Apagar y volver a encender en un rato no es otra noticia: un solo correo cada pocas horas. */
    public function test_no_vuelve_a_avisar_si_ya_aviso_hace_poco(): void
    {
        Notification::fake();
        [$instance, $admin] = $this->linea();

        $this->mensaje($instance, 'failed', 'text', '131042', self::ERROR_DE_META);
        FacturacionDeMeta::resolver($instance->refresh());
        $this->travel(1)->minutes();
        $this->mensaje($instance, 'failed', 'text', '131042', self::ERROR_DE_META);

        $this->assertNotNull($instance->refresh()->problema_de_pago);
        Notification::assertSentToTimes($admin, SystemNotification::class, 1);

        FacturacionDeMeta::resolver($instance->refresh());
        $this->travel(FacturacionDeMeta::ANTIRREBOTE_HORAS + 1)->hours();
        $this->mensaje($instance, 'failed', 'text', '131042', self::ERROR_DE_META);

        Notification::assertSentToTimes($admin, SystemNotification::class, 2);
    }

    /** El pago es de la cuenta: todas las líneas de esa WABA, y sólo las de esa empresa. */
    public function test_marca_y_apaga_todas_las_lineas_de_la_misma_cuenta_sin_cruzar_empresas(): void
    {
        Notification::fake();
        [$instance, $admin] = $this->linea();
        $hermana = $this->otraLinea($instance, ['name' => 'jheda soporte']);
        $otraCuenta = $this->otraLinea($instance, ['name' => 'otra cuenta', 'waba_id' => '999']);
        [$ajena] = $this->linea(); // otra empresa, MISMO waba_id

        $this->mensaje($instance, 'failed', 'template', '131042', self::ERROR_DE_META);

        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, $hermana->refresh()->problema_de_pago);
        $this->assertEquals($instance->refresh()->problema_de_pago_desde, $hermana->problema_de_pago_desde);
        $this->assertNull($otraCuenta->refresh()->problema_de_pago);
        $this->assertNull($ajena->refresh()->problema_de_pago);
        Notification::assertSentToTimes($admin, SystemNotification::class, 1);

        $this->travel(1)->minutes();
        $this->mensaje($hermana, 'delivered', 'template');

        $this->assertNull($instance->refresh()->problema_de_pago);
        $this->assertNull($hermana->refresh()->problema_de_pago);
    }

    /** El correo dice lo que falta de verdad, con las mismas palabras que la alerta. */
    public function test_el_aviso_dice_si_falta_la_moneda_o_el_metodo(): void
    {
        Notification::fake();
        [$conMoneda, $admin] = $this->linea();
        [$sinMetodo, $admin2] = $this->linea();

        $this->mensaje($conMoneda, 'failed', 'template', '131042', self::ERROR_DE_META);
        $this->mensaje($sinMetodo, 'failed', 'template', '131042', 'Business eligibility payment issue');

        // Los títulos de FacturacionDeMeta::explicacion(), los mismos que la alerta roja.
        Notification::assertSentTo($admin, SystemNotification::class, fn ($n) => str_contains($n->body, 'no tiene moneda ni tarjeta')
            && ! str_contains($n->body, 'cinco minutos'));
        Notification::assertSentTo($admin2, SystemNotification::class, fn ($n) => str_contains($n->body, 'método de pago válido'));
    }

    /** El chequeo diario sólo sabe «es de pago»: no convierte un «sin moneda» en «sin tarjeta». */
    public function test_un_aviso_generico_de_pago_no_pisa_el_sin_moneda(): void
    {
        [$instance] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA, 'problema_de_pago_desde' => now()]);

        FacturacionDeMeta::marcar($instance, FacturacionDeMeta::SIN_METODO);
        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, $instance->refresh()->problema_de_pago);

        // Un fallo con sólo el código tampoco.
        $this->mensaje($instance, 'failed', 'text', '131042', null);
        $this->assertSame(FacturacionDeMeta::SIN_MONEDA, $instance->refresh()->problema_de_pago);
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

        $this->mensaje($instance, 'failed', 'template', '131042', 'Business eligibility payment issue', detalle: self::PAGO_PENDIENTE_DE_META);

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
            // 422 y no 500: un rechazo de Meta no se arregla repitiendo, y con
            // un 500 el ERP reintentaba (fix/plantillas-de-punta-a-punta).
            ->assertStatus(422);

        $this->assertSame(FacturacionDeMeta::PAGO_PENDIENTE, $instance->refresh()->problema_de_pago);
    }

    /**
     * Los tipos finos (cobro pendiente, pago restringido) y el «por confirmar»
     * nacieron en ramas distintas (3 y 1-oct-2026). Juntos: un cobro pendiente
     * que Meta deja de marcar pasa a ámbar, no se queda en rojo, y lo apaga la
     * próxima plantilla entregada.
     */
    public function test_un_cobro_pendiente_tambien_pasa_por_confirmar(): void
    {
        [$instance] = $this->linea([
            'problema_de_pago' => FacturacionDeMeta::PAGO_PENDIENTE,
            'problema_de_pago_desde' => now()->subHour(),
            'detalle_de_pago' => self::PAGO_PENDIENTE_DE_META,
        ]);

        FacturacionDeMeta::porConfirmar($instance);
        $this->assertSame(FacturacionDeMeta::POR_CONFIRMAR, $instance->refresh()->problema_de_pago);
        $this->assertSame('Falta que Meta confirme el pago', FacturacionDeMeta::explicacion(FacturacionDeMeta::POR_CONFIRMAR)['titulo']);

        $this->travel(1)->minutes();
        $this->mensaje($instance, 'delivered', 'template');

        $this->assertNull($instance->refresh()->problema_de_pago);
        $this->assertNull($instance->detalle_de_pago, 'El motivo viejo no se queda colgado.');
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

    /**
     * AVAILABLE no basta para apagarla: una cuenta sin moneda puede salir así y
     * fallar en la primera plantilla. Queda «por confirmar» y la apaga la
     * próxima plantilla entregada.
     */
    public function test_comprobar_con_meta_disponible_la_deja_por_confirmar(): void
    {
        [$instance, $admin] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA, 'problema_de_pago_desde' => now()->subHour()]);

        Http::fake(function ($r) {
            return str_contains($r->url(), 'currency')
                ? Http::response(['currency' => 'COP', 'id' => '1'])
                : Http::response(['health_status' => ['can_send_message' => 'AVAILABLE'], 'id' => '1']);
        });

        $this->actingAs($admin)
            ->postJson("/instances/{$instance->id}/comprobar-pago")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('por_confirmar', true);

        $this->assertSame(FacturacionDeMeta::POR_CONFIRMAR, $instance->refresh()->problema_de_pago);

        $this->actingAs($admin)
            ->get('/instances/pago-en-meta')
            ->assertInertia(fn ($page) => $page->where('alertaPagoMeta.0.problema', FacturacionDeMeta::POR_CONFIRMAR));

        // Un fallo de una factura que salió antes de comprobar no la reenciende…
        $this->mensaje($instance, 'failed', 'template', '131042', self::ERROR_DE_META, now()->subMinutes(5));
        $this->assertSame(FacturacionDeMeta::POR_CONFIRMAR, $instance->refresh()->problema_de_pago);

        // …y la primera plantilla entregada la apaga del todo.
        $this->travel(1)->minutes();
        $this->mensaje($instance, 'delivered', 'template');
        $this->assertNull($instance->refresh()->problema_de_pago);
    }

    /** Si tras comprobar vuelve un 131042 de un envío nuevo, vuelve el rojo. */
    public function test_por_confirmar_que_vuelve_a_fallar_se_enciende_otra_vez(): void
    {
        [$instance] = $this->linea(['problema_de_pago' => FacturacionDeMeta::POR_CONFIRMAR, 'problema_de_pago_desde' => now()->subHour(), 'pago_al_dia_desde' => now()->subMinute()]);

        $this->mensaje($instance, 'failed', 'template', '131042', 'Business eligibility payment issue');

        $this->assertSame(FacturacionDeMeta::SIN_METODO, $instance->refresh()->problema_de_pago);
    }

    /**
     * Graph devuelve la moneda vacía también cuando el token no puede leerla.
     * Eso no puede encender la alerta en una línea que estaba bien.
     */
    public function test_comprobar_con_moneda_vacia_no_enciende_nada(): void
    {
        [$instance, $admin] = $this->linea();

        Http::fake(function ($r) {
            return str_contains($r->url(), 'currency')
                ? Http::response(['id' => '1'])
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
        [$instance, $admin] = $this->linea(['problema_de_pago' => FacturacionDeMeta::SIN_MONEDA, 'problema_de_pago_desde' => now()]);

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

        $this->assertSame(FacturacionDeMeta::POR_CONFIRMAR, $instance->refresh()->problema_de_pago);
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

        // No está en $fillable a propósito: sólo la escribe FacturacionDeMeta.
        if (isset($extra['pago_al_dia_desde'])) {
            $instance->forceFill(['pago_al_dia_desde' => $extra['pago_al_dia_desde']])->saveQuietly();
        }

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

    private function otraLinea(Instance $de, array $extra = []): Instance
    {
        return Instance::create(array_merge([
            'company_id' => $de->company_id,
            'uuid' => (string) Str::uuid(),
            'name' => 'otra línea',
            'phone_number_id' => (string) random_int(100000, 999999),
            'waba_id' => $de->waba_id,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ], $extra));
    }

    private function mensaje(Instance $instance, string $estado, string $tipo, ?string $codigo = null, ?string $error = null, $enviado = null, ?string $detalle = null): WhatsAppMessage
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
            'sent_at' => $enviado ?? now(),
            'wamid' => 'wamid.'.Str::random(10),
        ]);
    }
}
