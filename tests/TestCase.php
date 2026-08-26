<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Secreto de app usado para firmar los webhooks en pruebas.
     */
    protected const WEBHOOK_APP_SECRET = 'test-app-secret';

    /**
     * POST al webhook de Meta con la cabecera X-Hub-Signature-256 correcta.
     *
     * El controlador rechaza con 403 cualquier payload sin firma válida, así que
     * las pruebas tienen que firmar igual que Meta: HMAC-SHA256 sobre el JSON
     * exacto que viaja en el cuerpo.
     */
    protected function postSignedWebhook(array $payload, string $uri = '/webhooks/whatsapp'): TestResponse
    {
        config(['services.meta.webhook_app_secrets' => self::WEBHOOK_APP_SECRET]);

        $body = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $body, self::WEBHOOK_APP_SECRET);

        return $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type'          => 'application/json',
                'Accept'                => 'application/json',
                'X-Hub-Signature-256'   => $signature,
            ]),
            $body
        );
    }
}
