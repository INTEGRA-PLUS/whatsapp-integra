<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Los webhooks de Meta no pueden pasar por el CSRF.
 *
 * Meta los manda sin sesión ni token; su autenticidad la da la firma, que cada
 * controlador valida dentro. El 27-sep-2026 el primer mensaje real de Messenger
 * rebotó cinco veces con 419 porque `webhooks/messenger` no estaba en la lista.
 *
 * Los tests de cada webhook no lo ven: Laravel desactiva el CSRF cuando corren
 * los tests. Por eso aquí se pregunta directamente al middleware.
 */
class WebhooksSinCsrfTest extends TestCase
{
    public static function webhooksDeMeta(): array
    {
        return [
            'WhatsApp' => ['webhooks/whatsapp'],
            'Instagram' => ['webhooks/instagram'],
            'Messenger' => ['webhooks/messenger'],
        ];
    }

    /** @dataProvider webhooksDeMeta */
    public function test_el_webhook_esta_exento_del_csrf(string $ruta): void
    {
        $middleware = $this->app->make(ValidateCsrfToken::class);
        $exento = new \ReflectionMethod($middleware, 'inExceptArray');

        $this->assertTrue(
            $exento->invoke($middleware, Request::create('/'.$ruta, 'POST')),
            "POST /{$ruta} tiene que estar exento del CSRF o Meta recibe un 419."
        );
    }
}
