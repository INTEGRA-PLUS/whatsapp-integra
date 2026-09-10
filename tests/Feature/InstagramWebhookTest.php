<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El webhook del tópico `instagram`.
 *
 * Lo que estas pruebas protegen no es la ingesta —todavía no la hay— sino las
 * dos cosas que deciden si el canal existe: que el apretón de manos del panel
 * funcione, y que nadie pueda inyectar eventos sin firmar. El endpoint es
 * público y, como Tech Provider, recibe eventos de cuentas de terceros.
 */
class InstagramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO_IG = 'secreto-de-la-app-de-instagram';

    private const SECRETO_WA = 'secreto-de-la-app-de-whatsapp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.instagram.app_id' => '28822685693981719',
            'services.meta.instagram.verify_token' => 'el-token-de-verificacion',
            'services.meta.webhook_app_secrets' => '865904982715022:'.self::SECRETO_WA.',28822685693981719:'.self::SECRETO_IG,
        ]);
    }

    public function test_devuelve_el_desafio_cuando_el_token_coincide(): void
    {
        $this->get('/webhooks/instagram?hub_mode=subscribe&hub_verify_token=el-token-de-verificacion&hub_challenge=1234567890')
            ->assertOk()
            ->assertSee('1234567890');
    }

    public function test_rechaza_la_verificacion_con_un_token_ajeno(): void
    {
        $this->get('/webhooks/instagram?hub_mode=subscribe&hub_verify_token=el-de-otro&hub_challenge=1234567890')
            ->assertForbidden();
    }

    /**
     * Sin token configurado no se puede probar que quien verifica sea Meta, así
     * que no hay camino permisivo: un `verify_token` vacío no valida a nadie.
     */
    public function test_sin_token_configurado_no_verifica(): void
    {
        config(['services.meta.instagram.verify_token' => '']);

        $this->get('/webhooks/instagram?hub_mode=subscribe&hub_verify_token=&hub_challenge=1234567890')
            ->assertForbidden();
    }

    public function test_acepta_un_evento_firmado_con_la_clave_de_instagram(): void
    {
        $this->enviar($this->evento(), self::SECRETO_IG)->assertOk();
    }

    /**
     * La clave de la app de WhatsApp también vale: las dos están en
     * META_APP_SECRETS y todas son secretos nuestros. Esto es lo que permite
     * sumar un canal sin tocar el validador de firmas.
     */
    public function test_acepta_tambien_la_clave_de_la_otra_app(): void
    {
        $this->enviar($this->evento(), self::SECRETO_WA)->assertOk();
    }

    public function test_rechaza_un_evento_firmado_con_una_clave_inventada(): void
    {
        $this->enviar($this->evento(), 'una-clave-que-no-es-nuestra')->assertForbidden();
    }

    public function test_rechaza_un_evento_sin_firma(): void
    {
        $this->postJson('/webhooks/instagram', $this->evento())->assertForbidden();
    }

    /**
     * Un evento raro no debe tumbar el lote: si esto devolviera 500, Meta
     * reintentaría el mismo payload roto para siempre.
     */
    public function test_un_evento_sin_los_campos_esperados_no_revienta(): void
    {
        $this->enviar([
            'object' => 'instagram',
            'entry' => [['id' => '178414', 'messaging' => [[]]]],
        ], self::SECRETO_IG)->assertOk();
    }

    public function test_un_payload_sin_entradas_se_acepta(): void
    {
        $this->enviar(['object' => 'instagram'], self::SECRETO_IG)->assertOk();
    }

    private function evento(): array
    {
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841400008460056',
                'time' => 1_757_500_000,
                'messaging' => [[
                    'sender' => ['id' => '1089304012345678'],
                    'recipient' => ['id' => '17841400008460056'],
                    'timestamp' => 1_757_500_000,
                    'message' => ['mid' => 'aWdfZG1f', 'text' => 'Hola, ¿tienen internet en Tarso?'],
                ]],
            ]],
        ];
    }

    private function enviar(array $payload, string $secreto)
    {
        // La firma se calcula sobre el cuerpo crudo, así que hay que mandar la
        // misma cadena que se firmó: re-serializar no reproduce los mismos bytes.
        $cuerpo = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call(
            'POST',
            '/webhooks/instagram',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, $secreto),
            ],
            $cuerpo
        );
    }
}
