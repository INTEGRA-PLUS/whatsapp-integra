<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Notifications\PlantillaDeMetaNotification;
use App\Services\WhatsAppFallbackTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que Meta hace con las plantillas, contado por webhook.
 *
 * Hasta el 1-oct-2026 los cuatro campos de plantillas se descartaban sin
 * leerlos: una plantilla pausada por quejas seguía figurando como aprobada en
 * la caché del guardarraíl, en el respaldo fuera de ventana y en las campañas,
 * y la campaña seguía enviando un 132015 tras otro sin que nadie supiera por
 * qué. Y Ajustes prometía que la suscripción servía para «recibir
 * aprobación/rechazo de plantillas».
 */
class AvisosDePlantillaDeMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_pausa_detiene_la_campana_que_la_enviaba_y_avisa_al_admin(): void
    {
        Notification::fake();
        [$instancia, $admin] = $this->empresa('Fibra', 'waba-1');
        $enviando = $this->campana($instancia, 'aviso_factura', 'sending');
        $otraPlantilla = $this->campana($instancia, 'promo_octubre', 'sending');
        $terminada = $this->campana($instancia, 'aviso_factura', 'completed');

        $this->postSignedWebhook($this->estado('waba-1', 'aviso_factura', 'PAUSED', [
            'reason' => null,
            'other_info' => ['title' => 'FIRST_PAUSE', 'description' => 'Paused for 3 hours.'],
        ]))->assertOk();

        $this->assertSame('paused', $enviando->fresh()->status);
        $this->assertNotNull($enviando->fresh()->paused_at);
        $this->assertSame('sending', $otraPlantilla->fresh()->status);
        $this->assertSame('completed', $terminada->fresh()->status);

        Notification::assertSentTo($admin, PlantillaDeMetaNotification::class, function ($n) use ($admin) {
            $data = $n->toDatabase($admin);

            return str_contains($data['title'], 'pausó')
                && str_contains($data['body'], '3 horas')
                && str_contains($data['body'], 'Facturación');
        });
    }

    /**
     * `entry.id` es la WABA, y una WABA puede estar conectada en dos empresas:
     * las dos tienen que enterarse, y cada una de lo suyo.
     */
    public function test_el_aviso_llega_a_todas_las_empresas_que_comparten_la_waba(): void
    {
        Notification::fake();
        [$a, $adminA] = $this->empresa('Fibra', 'waba-compartida');
        [$b, $adminB] = $this->empresa('Telnext', 'waba-compartida', 'pnid-b');
        [$ajena, $adminAjeno] = $this->empresa('Ajena', 'waba-otra', 'pnid-c');

        $campanaA = $this->campana($a, 'aviso_factura', 'queued');
        $campanaB = $this->campana($b, 'aviso_factura', 'sending');
        $campanaAjena = $this->campana($ajena, 'aviso_factura', 'sending');

        $this->postSignedWebhook($this->estado('waba-compartida', 'aviso_factura', 'DISABLED'))->assertOk();

        $this->assertSame('paused', $campanaA->fresh()->status);
        $this->assertSame('paused', $campanaB->fresh()->status);
        $this->assertSame('sending', $campanaAjena->fresh()->status);

        Notification::assertSentTo($adminA, PlantillaDeMetaNotification::class);
        Notification::assertSentTo($adminB, PlantillaDeMetaNotification::class);
        Notification::assertNotSentTo($adminAjeno, PlantillaDeMetaNotification::class);

        // Cada aviso habla sólo de las campañas de su empresa.
        Notification::assertSentTo($adminA, PlantillaDeMetaNotification::class, function ($n) use ($adminA) {
            return substr_count($n->toDatabase($adminA)['body'], '«Facturación»') === 1;
        });
    }

    public function test_un_rechazo_explica_el_motivo_en_espanol(): void
    {
        Notification::fake();
        [$instancia, $admin] = $this->empresa('Fibra', 'waba-1');

        $this->postSignedWebhook($this->estado('waba-1', 'aviso_factura', 'REJECTED', [
            'reason' => 'INCORRECT_CATEGORY',
        ]))->assertOk();

        Notification::assertSentTo($admin, PlantillaDeMetaNotification::class, function ($n) use ($admin) {
            $body = $n->toDatabase($admin)['body'];

            return str_contains($body, 'La categoría no corresponde al contenido');
        });
    }

    /** Meta reintenta durante días: el mismo aviso no puede notificar dos veces. */
    public function test_el_mismo_aviso_repetido_no_notifica_dos_veces(): void
    {
        Notification::fake();
        [, $admin] = $this->empresa('Fibra', 'waba-1');
        $payload = $this->estado('waba-1', 'aviso_factura', 'PAUSED');

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        Notification::assertSentToTimes($admin, PlantillaDeMetaNotification::class, 1);
    }

    /**
     * Un aviso de una plantilla o WABA que no conocemos no puede lanzar: el
     * webhook respondería 500 y Meta lo reintentaría siete días.
     */
    public function test_una_waba_o_plantilla_desconocida_no_rompe_el_webhook(): void
    {
        Notification::fake();
        $this->empresa('Fibra', 'waba-1');

        $this->postSignedWebhook($this->estado('waba-de-nadie', 'lo_que_sea', 'PAUSED'))->assertOk();
        $this->postSignedWebhook($this->estado('waba-1', 'plantilla_que_no_usamos', 'APPROVED'))->assertOk();
        $this->postSignedWebhook($this->estado('waba-1', 'otra', 'EVENTO_QUE_META_INVENTE'))->assertOk();

        Notification::assertNothingSent();
    }

    /** El guardarraíl validaría contra un catálogo de hace diez minutos. */
    public function test_tira_la_cache_del_catalogo_del_guardarrail(): void
    {
        Notification::fake();
        $this->empresa('Fibra', 'waba-1');
        Cache::put('wa:templates:waba-1', [['name' => 'aviso_factura', 'status' => 'APPROVED']], 600);

        $this->postSignedWebhook($this->estado('waba-1', 'aviso_factura', 'APPROVED'))->assertOk();

        $this->assertFalse(Cache::has('wa:templates:waba-1'));
    }

    /**
     * El DISABLED de Meta no es el DISABLED de la empresa: guardarlo tal cual
     * hacía que Ajustes dijera «Desactivado» con el interruptor encendido.
     */
    public function test_la_plantilla_de_respaldo_toma_el_estado_que_dice_meta(): void
    {
        Notification::fake();
        [$instancia, $admin] = $this->empresa('Fibra', 'waba-1');
        $instancia->mergeFallbackTemplate([
            'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'language' => 'es',
            'status' => 'APPROVED',
            'body' => 'Hola {{1}}: {{2}}',
            'checked_at' => now()->toIso8601String(),
        ]);
        $instancia->save();

        $nombre = WhatsAppFallbackTemplateService::CATALOG_KEY;

        $this->postSignedWebhook($this->estado('waba-1', $nombre, 'PAUSED', ['message_template_language' => 'es']))->assertOk();
        $this->assertSame('PAUSED', $instancia->fresh()->fallbackTemplateSettings()['status']);

        $this->postSignedWebhook($this->estado('waba-1', $nombre, 'DISABLED', ['message_template_language' => 'es']))->assertOk();
        $settings = $instancia->fresh()->fallbackTemplateSettings();
        $this->assertSame(WhatsAppFallbackTemplateService::STATUS_META_DISABLED, $settings['status']);
        $this->assertFalse($instancia->fresh()->fallbackTemplateDisabled());

        // Y el aviso dice que era la del respaldo.
        Notification::assertSentTo($admin, PlantillaDeMetaNotification::class, function ($n) use ($admin) {
            return str_contains($n->toDatabase($admin)['body'], 'plantilla de respaldo');
        });

        $this->postSignedWebhook($this->estado('waba-1', $nombre, 'REINSTATED', ['message_template_language' => 'es']))->assertOk();
        $this->assertSame('APPROVED', $instancia->fresh()->fallbackTemplateSettings()['status']);
    }

    /** Una traducción que no es la nuestra no toca nuestro estado. */
    public function test_otro_idioma_de_la_plantilla_de_respaldo_no_la_cambia(): void
    {
        Notification::fake();
        [$instancia] = $this->empresa('Fibra', 'waba-1');
        $instancia->mergeFallbackTemplate([
            'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'language' => 'es',
            'status' => 'APPROVED',
        ]);
        $instancia->save();

        $this->postSignedWebhook($this->estado('waba-1', WhatsAppFallbackTemplateService::CATALOG_KEY, 'PAUSED', [
            'message_template_language' => 'en_US',
        ]))->assertOk();

        $this->assertSame('APPROVED', $instancia->fresh()->fallbackTemplateSettings()['status']);
    }

    public function test_un_cambio_de_categoria_a_marketing_avisa_de_que_cuesta_mas(): void
    {
        Notification::fake();
        [, $admin] = $this->empresa('Fibra', 'waba-1');

        $this->postSignedWebhook($this->payload('waba-1', 'template_category_update', [
            'message_template_id' => 278077987957091,
            'message_template_name' => 'aviso_factura',
            'message_template_language' => 'es',
            'previous_category' => 'UTILITY',
            'new_category' => 'MARKETING',
        ]))->assertOk();

        Notification::assertSentTo($admin, PlantillaDeMetaNotification::class, function ($n) use ($admin) {
            $data = $n->toDatabase($admin);

            return str_contains($data['body'], 'de utilidad a marketing')
                && str_contains($data['body'], 'cuestan más');
        });
    }

    public function test_el_aviso_de_cambio_inminente_dice_la_categoria_que_tendra(): void
    {
        Notification::fake();
        [, $admin] = $this->empresa('Fibra', 'waba-1');

        $this->postSignedWebhook($this->payload('waba-1', 'template_category_update', [
            'message_template_id' => 278077987957091,
            'message_template_name' => 'aviso_factura',
            'message_template_language' => 'es',
            'new_category' => 'UTILITY',
            'correct_category' => 'MARKETING',
            'category_update_timestamp' => now()->addDay()->timestamp,
        ]))->assertOk();

        Notification::assertSentTo($admin, PlantillaDeMetaNotification::class, function ($n) use ($admin) {
            return str_contains($n->toDatabase($admin)['body'], 'va a cambiar su categoría de utilidad a marketing');
        });
    }

    /** Editar la plantilla en Meta deja viejo el cuerpo guardado del respaldo. */
    public function test_editar_la_plantilla_de_respaldo_obliga_a_volver_a_consultarla(): void
    {
        Notification::fake();
        [$instancia] = $this->empresa('Fibra', 'waba-1');
        $instancia->mergeFallbackTemplate([
            'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'language' => 'es',
            'status' => 'APPROVED',
            'checked_at' => now()->toIso8601String(),
        ]);
        $instancia->save();

        $this->postSignedWebhook($this->payload('waba-1', 'message_template_components_update', [
            'message_template_id' => 1,
            'message_template_name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'message_template_language' => 'es',
            'message_template_element' => 'Texto nuevo {{1}}',
        ]))->assertOk();

        $this->assertNull($instancia->fresh()->fallbackTemplateSettings()['checked_at']);
        Notification::assertNothingSent();
    }

    public function test_una_calidad_roja_avisa_antes_de_la_pausa(): void
    {
        Notification::fake();
        [, $admin] = $this->empresa('Fibra', 'waba-1');

        $this->postSignedWebhook($this->payload('waba-1', 'message_template_quality_update', [
            'previous_quality_score' => 'GREEN',
            'new_quality_score' => 'YELLOW',
            'message_template_id' => 1,
            'message_template_name' => 'aviso_factura',
            'message_template_language' => 'es',
        ]))->assertOk();
        Notification::assertNothingSent();

        $this->postSignedWebhook($this->payload('waba-1', 'message_template_quality_update', [
            'previous_quality_score' => 'YELLOW',
            'new_quality_score' => 'RED',
            'message_template_id' => 1,
            'message_template_name' => 'aviso_factura',
            'message_template_language' => 'es',
        ]))->assertOk();
        Notification::assertSentTo($admin, PlantillaDeMetaNotification::class);
    }

    /* ------------------------------------------------------------------ */

    private function estado(string $waba, string $plantilla, string $evento, array $extra = []): array
    {
        return $this->payload($waba, 'message_template_status_update', array_merge([
            'event' => $evento,
            'message_template_id' => crc32($plantilla),
            'message_template_name' => $plantilla,
            'message_template_language' => 'es',
            'reason' => 'NONE',
            'message_template_category' => 'UTILITY',
        ], $extra));
    }

    private function payload(string $waba, string $campo, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba,
                'time' => 1759320000,
                'changes' => [['field' => $campo, 'value' => $value]],
            ]],
        ];
    }

    /** @return array{0: Instance, 1: User} */
    private function empresa(string $nombre, string $waba, string $phoneId = 'pnid-a'): array
    {
        $company = Company::create(['name' => $nombre, 'slug' => Str::slug($nombre), 'active' => true]);

        $admin = User::create([
            'company_id' => $company->id,
            'name' => 'Admin '.$nombre,
            'email' => Str::random(8).'@'.Str::slug($nombre).'.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        $instancia = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea de '.$nombre,
            'phone_number_id' => $phoneId,
            'waba_id' => $waba,
            'display_phone_number' => '+57 300 000 0000',
            'access_token' => 'token',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ]);

        return [$instancia, $admin];
    }

    private function campana(Instance $instancia, string $plantilla, string $estado): WhatsAppCampaign
    {
        return WhatsAppCampaign::create([
            'company_id' => $instancia->company_id,
            'instance_id' => $instancia->id,
            'name' => 'Facturación',
            'message_type' => 'template',
            'template_name' => $plantilla,
            'template_language' => 'es',
            'status' => $estado,
            'schedule_type' => 'manual',
            'total_recipients' => 0,
        ]);
    }
}
