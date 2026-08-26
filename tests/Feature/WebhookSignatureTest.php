<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Firma de los webhooks entrantes de Meta.
 *
 * El endpoint es público y, como Tech Provider, va a recibir eventos de WABAs de
 * terceros. Sin validar X-Hub-Signature-256 cualquiera puede inyectar mensajes
 * falsos en las conversaciones de un cliente. La validación existía en
 * MetaWhatsAppService pero nadie la llamaba.
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [['id' => '1', 'changes' => []]],
        ];
    }

    public function test_un_payload_sin_firma_se_rechaza(): void
    {
        config(['services.meta.webhook_app_secrets' => self::WEBHOOK_APP_SECRET]);

        $this->postJson('/webhooks/whatsapp', $this->payload())->assertForbidden();
    }

    public function test_un_payload_con_firma_incorrecta_se_rechaza(): void
    {
        config(['services.meta.webhook_app_secrets' => self::WEBHOOK_APP_SECRET]);

        $body = json_encode($this->payload());

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type'        => 'application/json',
                'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secreto-de-otro'),
            ]),
            $body
        )->assertForbidden();
    }

    public function test_un_payload_bien_firmado_se_acepta(): void
    {
        $this->postSignedWebhook($this->payload())->assertOk();
    }

    public function test_sin_app_secret_configurado_no_se_acepta_nada(): void
    {
        // Antes esto devolvía true y dejaba pasar cualquier POST: quedarse sin
        // secreto no puede traducirse en "acepta todo".
        config(['services.meta.webhook_app_secrets' => '']);

        $body = json_encode($this->payload());

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type'        => 'application/json',
                'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, ''),
            ]),
            $body
        )->assertForbidden();
    }

    public function test_acepta_la_firma_de_cualquiera_de_las_apps_configuradas(): void
    {
        // Integra e Ispintegra entregan al mismo callback y firman con secretos
        // distintos. Validar sólo contra uno dejaría sin mensajes a las empresas
        // de la otra app.
        $integra = 'secreto-integra';
        $ispintegra = 'secreto-ispintegra';

        config(['services.meta.webhook_app_secrets' => $integra . ',' . $ispintegra]);

        foreach ([$integra, $ispintegra] as $secret) {
            $body = json_encode($this->payload());

            $this->call(
                'POST',
                '/webhooks/whatsapp',
                [],
                [],
                [],
                $this->transformHeadersToServerVars([
                    'Content-Type'        => 'application/json',
                    'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret),
                ]),
                $body
            )->assertOk();
        }
    }

    public function test_un_tercero_sigue_sin_pasar_aunque_haya_varios_secretos(): void
    {
        config(['services.meta.webhook_app_secrets' => 'secreto-integra,secreto-ispintegra']);

        $body = json_encode($this->payload());

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type'        => 'application/json',
                'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secreto-de-un-atacante'),
            ]),
            $body
        )->assertForbidden();
    }

    public function test_los_espacios_alrededor_de_las_comas_no_rompen_la_lista(): void
    {
        config(['services.meta.webhook_app_secrets' => '  secreto-integra ,  secreto-ispintegra  ']);

        $body = json_encode($this->payload());

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type'        => 'application/json',
                'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secreto-ispintegra'),
            ]),
            $body
        )->assertOk();
    }
}
