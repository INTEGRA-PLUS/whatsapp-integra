<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los mensajes de Instagram, en la misma bandeja que los de WhatsApp.
 *
 * Se prueba entrando por el webhook de verdad y no llamando al servicio a mano:
 * lo que hay que proteger incluye el enrutado por cuenta, y ahí es donde un
 * mensaje podría acabar en la bandeja de otra empresa.
 */
class BandejaDeInstagramTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-la-app-de-instagram';

    private const CUENTA = '17841400008460056';

    private const CLIENTE = '1089304012345678';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.instagram.app_id' => '28822685693981719',
            'services.meta.instagram.verify_token' => 'el-token',
            'services.meta.webhook_app_secrets' => '28822685693981719:'.self::SECRETO,
        ]);
    }

    public function test_un_dm_entrante_abre_la_conversacion_y_se_guarda(): void
    {
        $linea = $this->lineaDeInstagram();

        $this->enviar($this->evento(['text' => 'Hola, ¿tienen internet en Tarso?']))->assertOk();

        $conversacion = WhatsAppConversation::where('instance_id', $linea->id)->firstOrFail();

        // La identidad del hilo es el IGSID, y no hay teléfono que inventar.
        $this->assertSame(self::CLIENTE, $conversacion->wa_id);
        $this->assertNull($conversacion->phone_number);
        $this->assertSame(1, $conversacion->unread_count);
        $this->assertSame('Instagram', $conversacion->nombreDelCanal());

        $mensaje = $conversacion->messages()->firstOrFail();
        $this->assertSame('inbound', $mensaje->direction);
        $this->assertSame('text', $mensaje->type);
        $this->assertSame('Hola, ¿tienen internet en Tarso?', $mensaje->content);
    }

    /**
     * Meta reintenta lotes enteros, y nuestro propio envío vuelve además como
     * eco con el mismo identificador. Sin idempotencia, cada mensaje saldría dos
     * veces en el chat.
     */
    public function test_el_mismo_mensaje_dos_veces_no_se_duplica(): void
    {
        $this->lineaDeInstagram();

        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();
        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();

        $this->assertSame(1, WhatsAppMessage::count());
    }

    /**
     * Lo que la empresa contesta desde la app de Instagram en el móvil llega
     * como eco. Se guarda —si no, el asesor vería el hilo a medias— pero como
     * saliente y sin contar como no leído.
     */
    public function test_lo_que_contesta_la_empresa_entra_como_saliente(): void
    {
        $linea = $this->lineaDeInstagram();

        $evento = [
            'sender' => ['id' => self::CUENTA],
            'recipient' => ['id' => self::CLIENTE],
            'timestamp' => now()->getTimestampMs(),
            'message' => ['mid' => 'mid-del-eco', 'text' => 'Sí, tenemos cobertura', 'is_echo' => true],
        ];

        $this->enviar($evento)->assertOk();

        $conversacion = WhatsAppConversation::where('instance_id', $linea->id)->firstOrFail();

        // El hilo es el del CLIENTE, no el de la cuenta de la empresa.
        $this->assertSame(self::CLIENTE, $conversacion->wa_id);
        $this->assertSame(0, $conversacion->unread_count);
        $this->assertSame('outbound', $conversacion->messages()->firstOrFail()->direction);
    }

    /**
     * La protección que de verdad importa: una app de Tech Provider recibe
     * eventos de todas las cuentas conectadas por el mismo callback.
     */
    public function test_un_mensaje_no_cae_en_la_bandeja_de_otra_empresa(): void
    {
        $mia = $this->lineaDeInstagram();
        $ajena = $this->lineaDeInstagram('17849999999999999');

        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();

        $this->assertSame(1, WhatsAppConversation::where('instance_id', $mia->id)->count());
        $this->assertSame(0, WhatsAppConversation::where('instance_id', $ajena->id)->count());
    }

    /**
     * Una cuenta que se desconectó de nuestro lado pero sigue suscrita en Meta.
     * Se acepta con 200: devolver error haría que Meta reintente para siempre un
     * mensaje que no tiene dónde ir.
     */
    public function test_una_cuenta_que_no_tenemos_no_revienta_ni_reintenta(): void
    {
        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();

        $this->assertSame(0, WhatsAppMessage::count());
    }

    /**
     * Instagram manda el timestamp en MILISEGUNDOS. Sin dividir, `sent_at` se va
     * a dentro de cincuenta mil años y la ventana de 24 h se daría por abierta
     * para siempre.
     */
    public function test_la_fecha_del_mensaje_es_de_este_siglo(): void
    {
        $this->lineaDeInstagram();

        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();

        $this->assertEqualsWithDelta(0, now()->diffInMinutes(WhatsAppMessage::firstOrFail()->sent_at), 2);
    }

    public function test_una_imagen_se_guarda_como_imagen(): void
    {
        $this->lineaDeInstagram();

        $this->enviar($this->evento([
            'attachments' => [[
                'type' => 'image',
                'payload' => ['url' => 'https://scontent.cdninstagram.com/foto.jpg'],
            ]],
        ]))->assertOk();

        $mensaje = WhatsAppMessage::firstOrFail();
        $this->assertSame('image', $mensaje->type);
        $this->assertSame('https://scontent.cdninstagram.com/foto.jpg', $mensaje->media_url);
    }

    /**
     * Un reel compartido no es un archivo que podamos pintar. Antes que dejar
     * una burbuja vacía, se dice qué llegó.
     */
    public function test_un_reel_compartido_dice_lo_que_es(): void
    {
        $this->lineaDeInstagram();

        $this->enviar($this->evento([
            'attachments' => [['type' => 'ig_reel', 'payload' => ['url' => 'https://lookaside.fbsbx.com/reel']]],
        ]))->assertOk();

        $this->assertStringContainsString('reel', WhatsAppMessage::firstOrFail()->content);
    }

    /**
     * Un «visto» no es un mensaje y no debe crear burbuja.
     */
    public function test_un_visto_no_crea_ningun_mensaje(): void
    {
        $this->lineaDeInstagram();

        $this->enviar([
            'sender' => ['id' => self::CLIENTE],
            'recipient' => ['id' => self::CUENTA],
            'timestamp' => now()->getTimestampMs(),
            'read' => ['mid' => 'mid-1'],
        ])->assertOk();

        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_el_cliente_que_vuelve_a_escribir_reabre_el_hilo(): void
    {
        $linea = $this->lineaDeInstagram();

        $this->enviar($this->evento(['text' => 'Hola'], 'mid-1'))->assertOk();

        WhatsAppConversation::where('instance_id', $linea->id)->update(['status' => 'closed']);

        $this->enviar($this->evento(['text' => '¿Sigue ahí?'], 'mid-2'))->assertOk();

        $this->assertSame('open', WhatsAppConversation::where('instance_id', $linea->id)->firstOrFail()->status);
    }

    private function evento(array $mensaje, string $mid = 'mid-1'): array
    {
        return [
            'sender' => ['id' => self::CLIENTE],
            'recipient' => ['id' => self::CUENTA],
            'timestamp' => now()->getTimestampMs(),
            'message' => array_merge(['mid' => $mid], $mensaje),
        ];
    }

    private function enviar(array $evento)
    {
        $cuerpo = json_encode([
            'object' => 'instagram',
            'entry' => [[
                'id' => self::CUENTA,
                'time' => now()->timestamp,
                'messaging' => [$evento],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/webhooks/instagram', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, self::SECRETO),
        ], $cuerpo);
    }

    private function lineaDeInstagram(string $cuenta = self::CUENTA): Instance
    {
        $empresa = Company::create([
            'name' => 'Empresa '.Str::random(4),
            'slug' => 'e-'.Str::random(8),
            'active' => true,
        ]);

        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => '@cuenta',
            'channel' => Instance::CANAL_INSTAGRAM,
            'external_account_id' => $cuenta,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'el-token-largo',
            'token_expires_at' => now()->addDays(60),
        ]);
    }
}
