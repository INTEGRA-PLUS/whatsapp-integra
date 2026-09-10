<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enviar un PDF por el API: la pieza que faltaba para tener un solo emisor.
 *
 * Integra 2.0 mandaba las tirillas y las facturas **directamente** a
 * `graph.facebook.com` y después llamaba a `/messages/register` para que el CRM
 * se enterara. Su propio código lo decía: *«Sin esto, el envío va directo a
 * graph.facebook.com y el microservicio nunca se entera»*
 * (`IngresoWhatsAppService.php:224`).
 *
 * Lo hacía porque este API sólo sabía enviar texto y plantillas: para un PDF no
 * había camino. Con dos emisores hacen falta dos configuraciones y hay dos
 * sitios donde mirar cuando un recibo no llega.
 */
class EnvioDocumentoApiTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3_media');
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.doc.1']]], 200));

        $company = Company::create(['name' => 'ISP', 'slug' => 'isp-'.Str::random(5), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);

        $this->token = $this->instance->generarApiToken();
    }

    public function test_envia_la_tirilla_y_la_deja_en_el_hilo(): void
    {
        $this->conversacionReciente('573001112233');

        $respuesta = $this->withHeader('X-Instance-Token', $this->token)
            ->post('/api/v1/messages/document', [
                'to' => '+57 300 111 2233',
                'file' => UploadedFile::fake()->create('Recibo_15979.pdf', 40, 'application/pdf'),
                'caption' => 'Su soporte de pago ha sido generado bajo el Nro. 15979',
                'incoming_payment_id' => 15979,
            ]);

        $respuesta->assertOk()->assertJson(['success' => true, 'wamid' => 'wamid.doc.1']);

        $mensaje = WhatsAppMessage::where('wamid', 'wamid.doc.1')->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('document', $mensaje->type);
        $this->assertSame('Recibo_15979.pdf', $mensaje->filename);
        $this->assertSame(15979, $mensaje->incoming_payment_id);
        $this->assertNotNull($mensaje->media_url, 'El PDF tiene que quedar en el hilo, no sólo en Meta');
    }

    /**
     * El destinatario se normaliza en la frontera. El ERP lo manda como lo
     * tenga guardado, y un hilo escrito de otra forma que el que abre el
     * webhook parte la conversación en dos.
     */
    public function test_el_numero_con_espacios_cae_en_el_hilo_que_ya_existe(): void
    {
        $conversacion = $this->conversacionReciente('573001112233');

        $this->withHeader('X-Instance-Token', $this->token)
            ->post('/api/v1/messages/document', [
                'to' => '+57 300 111 2233',
                'file' => UploadedFile::fake()->create('recibo.pdf', 10, 'application/pdf'),
            ])->assertOk();

        $this->assertSame(1, WhatsAppConversation::where('instance_id', $this->instance->id)->count());
        $this->assertSame($conversacion->id, WhatsAppMessage::latest('id')->first()->conversation_id);
    }

    /** También acepta una URL ya publicada, sin volver a subir el archivo. */
    public function test_acepta_un_documento_que_ya_esta_publicado(): void
    {
        $this->conversacionReciente('573001112233');

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/document', [
                'to' => '573001112233',
                'document_url' => 'https://s3images.integracolombia.online/public/facturas/F-100.pdf',
                'filename' => 'Factura_100.pdf',
            ])->assertOk();

        $this->assertSame('Factura_100.pdf', WhatsAppMessage::latest('id')->first()->filename);
    }

    /** Sin archivo ni URL no hay nada que enviar. */
    public function test_exige_el_archivo_o_su_url(): void
    {
        $this->conversacionReciente('573001112233');

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/document', ['to' => '573001112233'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'document_url']);
    }

    /**
     * Fuera de la ventana de 24h WhatsApp no entrega un documento suelto.
     *
     * Y aquí **no** vale la plantilla de respaldo de texto que usa
     * `/messages/send`: entregaría el aviso sin el PDF, que es justo el
     * documento que el cliente esperaba. Perder el recibo en silencio es peor
     * que decir que no se pudo enviar.
     */
    public function test_fuera_de_la_ventana_lo_rechaza_diciendo_por_que(): void
    {
        config(['whatsapp.window_guard.mode' => 'enforce']);
        $this->conversacionVieja('573004445566');

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/document', [
                'to' => '573004445566',
                'document_url' => 'https://s3images.integracolombia.online/public/r.pdf',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'window_closed');

        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    /** Pero con una plantilla sí sale: el PDF viaja en su encabezado. */
    public function test_fuera_de_la_ventana_con_plantilla_si_sale(): void
    {
        config(['whatsapp.window_guard.mode' => 'enforce']);
        $this->conversacionVieja('573004445566');

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/document', [
                'to' => '573004445566',
                'document_url' => 'https://s3images.integracolombia.online/public/r.pdf',
                'template_name' => 'aviso_recibo',
                'language_code' => 'es',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /**
     * La URL del PDF la pone el CRM, que es quien acaba de guardarlo.
     *
     * Es lo que permite mandar el archivo y la plantilla en una sola llamada:
     * quien llama no puede conocer la URL antes de subir el archivo, así que
     * pedírsela sería pedirle que adivine. Es el caso de la facturación
     * mensual: plantilla aprobada con la factura en el encabezado.
     */
    public function test_pone_el_pdf_en_el_encabezado_de_la_plantilla(): void
    {
        $this->conversacionReciente('573001112233');

        $this->withHeader('X-Instance-Token', $this->token)
            ->post('/api/v1/messages/document', [
                'to' => '573001112233',
                'file' => UploadedFile::fake()->create('Factura_100.pdf', 30, 'application/pdf'),
                'template_name' => 'factura_mensual',
                'language_code' => 'es',
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => 'Pedro']],
                ]],
            ])->assertOk();

        $enviado = null;
        Http::assertSent(function ($peticion) use (&$enviado) {
            if (! str_contains($peticion->url(), '/messages')) {
                return false;
            }
            $enviado = $peticion->data();

            return true;
        });

        $componentes = $enviado['template']['components'] ?? [];

        $this->assertSame('header', $componentes[0]['type'] ?? null, 'El encabezado va primero');
        $this->assertNotEmpty(
            $componentes[0]['parameters'][0]['document']['link'] ?? null,
            'El PDF tiene que ir por enlace, con la URL que guardó el CRM'
        );
        $this->assertSame('Factura_100.pdf', $componentes[0]['parameters'][0]['document']['filename']);
        // Y el cuerpo del ERP llega intacto.
        $this->assertSame('body', $componentes[1]['type'] ?? null);
        $this->assertSame('Pedro', $componentes[1]['parameters'][0]['text']);
    }

    /** Si ya venía un encabezado, se sustituye: dos documentos serían un error. */
    public function test_sustituye_el_encabezado_que_venga_en_la_llamada(): void
    {
        $this->conversacionReciente('573001112233');

        $this->withHeader('X-Instance-Token', $this->token)
            ->post('/api/v1/messages/document', [
                'to' => '573001112233',
                'file' => UploadedFile::fake()->create('Buena.pdf', 10, 'application/pdf'),
                'template_name' => 'factura_mensual',
                'components' => [[
                    'type' => 'header',
                    'parameters' => [[
                        'type' => 'document',
                        'document' => ['link' => 'https://otro-sitio/vieja.pdf', 'filename' => 'Vieja.pdf'],
                    ]],
                ]],
            ])->assertOk();

        $enviado = null;
        Http::assertSent(function ($peticion) use (&$enviado) {
            if (! str_contains($peticion->url(), '/messages')) {
                return false;
            }
            $enviado = $peticion->data();

            return true;
        });

        $encabezados = array_filter(
            $enviado['template']['components'] ?? [],
            fn ($c) => ($c['type'] ?? '') === 'header'
        );

        $this->assertCount(1, $encabezados, 'Sólo puede quedar un encabezado');
        $this->assertSame('Buena.pdf', array_values($encabezados)[0]['parameters'][0]['document']['filename']);
    }

    /**
     * El token de una empresa no puede enviar por la línea de otra.
     *
     * El aislamiento entre empresas en este proyecto es manual, así que cada
     * puerta nueva al exterior se comprueba: aquí sale de que la instancia se
     * **resuelve desde el token**, y todo lo demás cuelga de ella.
     */
    public function test_el_token_de_una_empresa_no_envia_por_la_linea_de_otra(): void
    {
        $vecina = Company::create(['name' => 'Vecina', 'slug' => 'vecina-'.Str::random(5), 'active' => true]);

        $instanciaAjena = Instance::create([
            'company_id' => $vecina->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'La de al lado',
            'phone_number_id' => '9999999999',
            'waba_id' => 'waba-2',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-2',
        ]);

        $this->conversacionReciente('573001112233');

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/document', [
                'to' => '573001112233',
                'document_url' => 'https://s3images.integracolombia.online/public/r.pdf',
            ])->assertOk();

        // El mensaje cayó en la instancia del token, no en la ajena.
        $mensaje = WhatsAppMessage::latest('id')->first();
        $this->assertSame($this->instance->id, $mensaje->conversation->instance_id);
        $this->assertSame(0, WhatsAppConversation::where('instance_id', $instanciaAjena->id)->count());
    }

    /**
     * Cada llamada del ERP deja marca en la instancia.
     *
     * Es lo que contesta «¿está conectado de verdad?» sin abrir un log ni contar
     * mensajes: el CRM e Integra 2.0 son dos bases de datos unidas por una
     * cadena de texto, y hasta ahora esa unión no se veía en ninguna pantalla.
     */
    public function test_deja_marca_de_cuando_hablo_el_erp(): void
    {
        $this->conversacionReciente('573001112233');

        $this->assertNull($this->instance->api_last_seen_at);

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/document', [
                'to' => '573001112233',
                'document_url' => 'https://s3images.integracolombia.online/public/r.pdf',
            ])->assertOk();

        $this->instance->refresh();

        $this->assertNotNull($this->instance->api_last_seen_at);
        $this->assertSame('token', $this->instance->api_last_seen_via);
    }

    /** Y dice con qué credencial, que es lo que indica si ya se puede migrar. */
    public function test_distingue_el_token_del_identificador_heredado(): void
    {
        $this->conversacionReciente('573001112233');

        $this->withHeader('X-Instance-Token', $this->instance->phone_number_id)
            ->postJson('/api/v1/messages/document', [
                'to' => '573001112233',
                'document_url' => 'https://s3images.integracolombia.online/public/r.pdf',
            ])->assertOk();

        $this->assertSame('phone_number_id', $this->instance->fresh()->api_last_seen_via);
    }

    /** Y sin token no entra nadie. */
    public function test_sin_token_no_envia(): void
    {
        $this->postJson('/api/v1/messages/document', [
            'to' => '573001112233',
            'document_url' => 'https://s3images.integracolombia.online/public/r.pdf',
        ])->assertStatus(401);
    }

    private function conversacionReciente(string $numero): WhatsAppConversation
    {
        return $this->conversacion($numero, now()->subMinutes(5));
    }

    private function conversacionVieja(string $numero): WhatsAppConversation
    {
        return $this->conversacion($numero, now()->subDays(3));
    }

    private function conversacion(string $numero, $ultimoEntrante): WhatsAppConversation
    {
        $conversacion = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => $numero,
            'phone_number' => $numero,
            'status' => 'open',
            'last_message_at' => $ultimoEntrante,
        ]);

        // La ventana se mide por el último mensaje ENTRANTE del cliente.
        WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'wamid' => 'wamid.in.'.Str::random(6),
            'type' => 'text',
            'content' => 'hola',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => $ultimoEntrante,
            'created_at' => $ultimoEntrante,
        ]);

        return $conversacion;
    }
}
