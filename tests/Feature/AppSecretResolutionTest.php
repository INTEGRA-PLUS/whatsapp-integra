<?php

namespace Tests\Feature;

use App\Services\MetaWhatsAppService;
use Tests\TestCase;

/**
 * Resolución del app secret cuando hay varias apps de Meta en juego.
 *
 * El par "app_id|app_secret" del app access token sólo funciona si ambos son de
 * la misma app. Con un META_APP_SECRET único, las instancias de la otra app
 * fallaban al suscribir el webhook de llamadas sin una causa visible.
 */
class AppSecretResolutionTest extends TestCase
{
    private const INTEGRA = '1862365350983129';
    private const ISPINTEGRA = '865904982715022';

    private function service(): MetaWhatsAppService
    {
        return app(MetaWhatsAppService::class);
    }

    public function test_devuelve_el_secreto_de_cada_app(): void
    {
        config([
            'services.meta.app_secret' => null,
            'services.meta.webhook_app_secrets' => self::INTEGRA . ':secreto-integra,' . self::ISPINTEGRA . ':secreto-ispintegra',
        ]);

        $this->assertSame('secreto-integra', $this->service()->appSecretForAppId(self::INTEGRA));
        $this->assertSame('secreto-ispintegra', $this->service()->appSecretForAppId(self::ISPINTEGRA));
    }

    public function test_una_app_desconocida_sin_fallback_no_inventa_secreto(): void
    {
        config([
            'services.meta.app_secret' => null,
            'services.meta.webhook_app_secrets' => self::INTEGRA . ':secreto-integra,' . self::ISPINTEGRA . ':secreto-ispintegra',
        ]);

        // Devolver cualquiera de los dos produciría un app access token inválido
        // y un error de Meta imposible de diagnosticar.
        $this->assertNull($this->service()->appSecretForAppId('999999999999999'));
    }

    public function test_con_una_sola_app_configurada_se_usa_esa(): void
    {
        config([
            'services.meta.app_secret' => null,
            'services.meta.webhook_app_secrets' => 'secreto-unico',
        ]);

        $this->assertSame('secreto-unico', $this->service()->appSecretForAppId(self::INTEGRA));
    }

    public function test_sigue_funcionando_la_config_vieja_de_una_sola_app(): void
    {
        config([
            'services.meta.app_secret' => 'secreto-legacy',
            'services.meta.webhook_app_secrets' => 'secreto-legacy',
        ]);

        $this->assertSame('secreto-legacy', $this->service()->appSecretForAppId(self::INTEGRA));
    }

    public function test_el_formato_con_app_id_tambien_valida_firmas(): void
    {
        config([
            'services.meta.webhook_app_secrets' => self::INTEGRA . ':secreto-integra,' . self::ISPINTEGRA . ':secreto-ispintegra',
        ]);

        $payload = '{"object":"whatsapp_business_account"}';

        foreach (['secreto-integra', 'secreto-ispintegra'] as $secret) {
            $this->assertTrue(
                $this->service()->validateWebhookSignature($payload, 'sha256=' . hash_hmac('sha256', $payload, $secret)),
                "La firma hecha con {$secret} debía aceptarse."
            );
        }

        $this->assertFalse(
            $this->service()->validateWebhookSignature($payload, 'sha256=' . hash_hmac('sha256', $payload, 'secreto-ajeno'))
        );
    }
}
