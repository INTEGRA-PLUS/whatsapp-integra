<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los mensajes de Messenger, en la misma bandeja que los de WhatsApp.
 *
 * Se entra por el webhook de verdad y no llamando al servicio a mano: parte de
 * lo que hay que proteger es el enrutado por página, y ahí es donde un mensaje
 * podría acabar en la bandeja de otro cliente.
 *
 * La lógica la comparte con Instagram (`BandejaDeMeta`), así que lo que se
 * comprueba aquí es lo que **sí** es propio de este canal: que el tópico `page`
 * entra por su URL, que la línea se busca por el id de la página, y que el
 * nombre de quien escribe se arma con nombre y apellido porque en Facebook no
 * hay `@usuario`.
 */
class BandejaDeMessengerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-la-app-de-facebook';

    private const PAGINA = '102938475610293';

    private const CLIENTE = '7654321098765432';

    /** Lo que Meta contesta cuando se le pregunta quién escribe. */
    private ?array $perfil = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.messenger.verify_token' => 'el-token',
            'services.meta.webhook_app_secrets' => '865904982715022:'.self::SECRETO,
        ]);

        Http::fake(fn () => $this->perfil === null
            ? Http::response([], 404)
            : Http::response($this->perfil, 200));
    }

    public function test_un_mensaje_de_messenger_abre_la_conversacion(): void
    {
        $linea = $this->lineaDeMessenger();

        $this->enviar($this->evento(['text' => 'Buenas, ¿tienen servicio en mi barrio?']))->assertOk();

        $conversacion = WhatsAppConversation::where('instance_id', $linea->id)->first();

        $this->assertNotNull($conversacion);
        $this->assertSame(self::CLIENTE, $conversacion->wa_id);
        $this->assertSame('Buenas, ¿tienen servicio en mi barrio?', WhatsAppMessage::first()->content);
        $this->assertSame('inbound', WhatsAppMessage::first()->direction);
    }

    /**
     * En Facebook no hay `@usuario`: lo que se enseña es nombre y apellido.
     *
     * Es la única diferencia real con Instagram al resolver quién escribe, y si
     * alguien copia el de allá tal cual el chat se queda con el PSID.
     */
    public function test_el_nombre_se_arma_con_nombre_y_apellido(): void
    {
        $this->perfil = ['first_name' => 'Camilo', 'last_name' => 'Restrepo'];

        $linea = $this->lineaDeMessenger();

        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();

        $this->assertSame(
            'Camilo Restrepo',
            WhatsAppConversation::where('instance_id', $linea->id)->value('name')
        );

        // Y en Contactos entra sin `username`, que en este canal no existe.
        $contacto = Contact::where('company_id', $linea->company_id)->first();
        $this->assertNotNull($contacto);
        $this->assertNull($contacto->username);
        $this->assertSame('Camilo Restrepo', $contacto->name);
    }

    /** Si Meta no contesta, el mensaje se guarda igual con un nombre de respaldo. */
    public function test_sin_perfil_el_mensaje_se_guarda_igual(): void
    {
        $linea = $this->lineaDeMessenger();

        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();

        $conversacion = WhatsAppConversation::where('instance_id', $linea->id)->first();

        $this->assertNotNull($conversacion);
        $this->assertStringStartsWith('Messenger · ', $conversacion->name);
    }

    /**
     * El aislamiento de siempre, por página.
     *
     * Por el mismo callback entran los eventos de todas las páginas conectadas,
     * así que la búsqueda por `entry.id` es lo único que impide que el mensaje
     * de un cliente caiga en la bandeja de otro.
     */
    public function test_un_mensaje_no_cae_en_la_bandeja_de_otra_empresa(): void
    {
        $mia = $this->lineaDeMessenger();
        $ajena = $this->lineaDeMessenger('999888777666555');

        $this->enviar($this->evento(['text' => 'Hola']))->assertOk();

        $this->assertSame(1, WhatsAppConversation::where('instance_id', $mia->id)->count());
        $this->assertSame(0, WhatsAppConversation::where('instance_id', $ajena->id)->count());
    }

    /** Una página que no tenemos conectada no revienta ni hace reintentar a Meta. */
    public function test_una_pagina_que_no_tenemos_no_revienta(): void
    {
        $this->lineaDeMessenger();

        $cuerpo = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => '000000000000000',
                'time' => now()->timestamp,
                'messaging' => [$this->evento(['text' => 'Hola'])],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/webhooks/messenger', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, self::SECRETO),
        ], $cuerpo)->assertOk();

        $this->assertSame(0, WhatsAppMessage::count());
    }

    /** Sin firma válida no entra nada: es el mismo candado que en los otros canales. */
    public function test_sin_firma_valida_se_rechaza(): void
    {
        $this->lineaDeMessenger();

        $cuerpo = json_encode(['object' => 'page', 'entry' => []]);

        $this->call('POST', '/webhooks/messenger', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=lo-que-sea',
        ], $cuerpo)->assertStatus(403);
    }

    /** El apretón de manos del webhook, que es lo primero que hace Meta. */
    public function test_el_webhook_se_verifica_con_su_token(): void
    {
        $this->get('/webhooks/messenger?hub.mode=subscribe&hub.verify_token=el-token&hub.challenge=12345')
            ->assertOk()
            ->assertSee('12345');

        $this->get('/webhooks/messenger?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=12345')
            ->assertStatus(403);
    }

    /** Lo que contesta la empresa desde Facebook entra como saliente, no como del cliente. */
    public function test_el_eco_de_la_pagina_entra_como_saliente(): void
    {
        $linea = $this->lineaDeMessenger();

        $eco = [
            'sender' => ['id' => self::PAGINA],
            'recipient' => ['id' => self::CLIENTE],
            'timestamp' => now()->getTimestampMs(),
            'message' => ['mid' => 'mid-eco', 'is_echo' => true, 'text' => 'Ya vamos para allá'],
        ];

        $this->enviar($eco)->assertOk();

        $mensaje = WhatsAppMessage::first();

        $this->assertNotNull($mensaje);
        $this->assertSame('outbound', $mensaje->direction);
        $this->assertSame($linea->id, $mensaje->conversation->instance_id);
    }

    /** @return array<string, mixed> */
    private function evento(array $mensaje, string $mid = 'mid-1'): array
    {
        return [
            'sender' => ['id' => self::CLIENTE],
            'recipient' => ['id' => self::PAGINA],
            'timestamp' => now()->getTimestampMs(),
            'message' => array_merge(['mid' => $mid], $mensaje),
        ];
    }

    private function enviar(array $evento)
    {
        $cuerpo = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => self::PAGINA,
                'time' => now()->timestamp,
                'messaging' => [$evento],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/webhooks/messenger', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, self::SECRETO),
        ], $cuerpo);
    }

    private function lineaDeMessenger(string $pagina = self::PAGINA): Instance
    {
        $empresa = Company::create([
            'name' => 'Empresa '.Str::random(4),
            'slug' => 'e-'.Str::random(8),
            'active' => true,
        ]);

        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Fibra Sur',
            'channel' => Instance::CANAL_MESSENGER,
            'external_account_id' => $pagina,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-de-pagina',
            // El token de página no caduca: por eso va en null y no con fecha.
            'token_expires_at' => null,
        ]);
    }
}
