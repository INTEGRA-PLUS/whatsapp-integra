<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppChatAi;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Services\WhatsAppMenuService;
use App\Support\AiAssistantProfile;
use App\Support\Documentos\ImagenDelCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La foto que manda el cliente por WhatsApp.
 *
 * Es lo primero de lo multimodal por una razón medida: sobre 104.569 mensajes
 * entrantes de treinta días, **las imágenes son el 8,6% y los audios el 3,1%**.
 * Casi el triple.
 *
 * Y el modelo va en la nube porque se probó al revés: `minicpm-v4.6` —el de
 * visión más pequeño que existe— no terminó de describir una sola imagen en diez
 * minutos sobre los seis núcleos de este servidor.
 */
class ImagenDelClienteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private WhatsAppConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true,
            'plan' => 'basico', 'ia' => 'completa',
        ]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'access_token' => 'token',
            'active' => true,
        ]);

        $this->conversation = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        CompanyIntegration::create([
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_AI_CHAT,
            'enabled' => true,
        ]);

        config([
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'n8n_llave',
            'services.vision.url' => 'https://ollama.test',
            'services.vision.token' => 'llave_de_vision',
            'services.vision.model' => 'qwen3.5:27b',
        ]);
    }

    // ─── El candado ──────────────────────────────────────────────────────────

    /**
     * Sin token de visión no se mira ninguna foto.
     *
     * Y es importante que no se «haga cargo» igualmente: sin poder mirarla, la
     * foto tiene que seguir su camino hacia un asesor, que es lo que pasaba
     * antes de esto.
     *
     * @test
     */
    public function sin_modelo_de_vision_la_foto_sigue_su_camino(): void
    {
        config(['services.vision.token' => null]);

        $this->assertFalse(ImagenDelCliente::esLegible([
            'type' => 'image', 'media_url' => 'https://s3.test/foto.jpg',
        ]));
    }

    /** Y con el texto de ejemplo puesto, tampoco: no es un token. */
    public function test_el_token_de_ejemplo_no_cuenta(): void
    {
        config(['services.vision.token' => '<tu llave de ollama>']);

        $this->assertFalse(ImagenDelCliente::configurado());
    }

    /** Apagado por defecto: cada foto cuesta una llamada que paga la empresa. */
    public function test_por_defecto_una_foto_no_llega_a_la_ia(): void
    {
        Queue::fake();

        $this->assertFalse(AiAssistantProfile::veImagenes($this->company->id));

        $atendido = app(WhatsAppMenuService::class)->handleInbound(
            $this->instance,
            $this->conversation,
            ['type' => 'image', 'media_url' => 'https://s3.test/foto.jpg', 'content' => ''],
            'wamid.IMG1'
        );

        $this->assertFalse($atendido);
        Queue::assertNotPushed(ProcessWhatsAppChatAi::class);
    }

    /** @test */
    public function encendido_la_foto_va_al_chat_ia(): void
    {
        Queue::fake();

        $this->encender();

        $atendido = app(WhatsAppMenuService::class)->handleInbound(
            $this->instance,
            $this->conversation,
            ['type' => 'image', 'media_url' => 'https://s3.test/foto.jpg', 'content' => 'no entiendo esto'],
            'wamid.IMG2'
        );

        $this->assertTrue($atendido);

        Queue::assertPushed(ProcessWhatsAppChatAi::class, function (ProcessWhatsAppChatAi $job) {
            // La imagen NO se mira en el webhook: ahí sólo se decide. Lo que
            // viaja es la ficha, y el job la mira.
            return $job->documento['type'] === 'image'
                && $job->message === 'no entiendo esto';
        });
    }

    // ─── Lo que acaba en el prompt ───────────────────────────────────────────

    /** @test */
    public function lo_que_ve_el_modelo_viaja_como_datos_y_no_como_instrucciones(): void
    {
        Http::fake([
            's3.test/*' => Http::response($this->unJpeg(), 200),
            'ollama.test/*' => Http::response([
                'message' => ['content' => 'Es un comprobante de pago de Bancolombia por $89.900, aprobado el 15/09/2026.'],
            ], 200),
        ]);

        $texto = ImagenDelCliente::comoTexto([
            'type' => 'image',
            'media_url' => 'https://s3.test/comprobante.jpg',
        ], '¿ya me quedó el pago?');

        $this->assertStringContainsString('comprobante de pago', $texto);
        $this->assertStringContainsString('89.900', $texto);
        // Declarado como datos, y avisando de que un modelo puede leer mal.
        $this->assertStringContainsString('no instrucciones', $texto);
        $this->assertStringContainsString('errores de lectura', $texto);
        // Y el comentario que escribió junto a la foto no se pierde.
        $this->assertStringContainsString('¿ya me quedó el pago?', $texto);
    }

    /**
     * Si el modelo no responde, la IA se lo dice al cliente.
     *
     * El cliente ya mandó su foto y espera respuesta. Callarse es el peor
     * resultado posible.
     *
     * @test
     */
    public function si_no_se_pudo_ver_la_foto_la_ia_lo_dice(): void
    {
        Http::fake([
            's3.test/*' => Http::response($this->unJpeg(), 200),
            'ollama.test/*' => Http::response('se cayó', 500),
        ]);

        $texto = ImagenDelCliente::comoTexto([
            'type' => 'image', 'media_url' => 'https://s3.test/foto.jpg',
        ]);

        $this->assertStringContainsString('no se pudo ver', $texto);
        $this->assertStringContainsString('por escrito', $texto);
    }

    /**
     * La imagen se encoge antes de mandarla.
     *
     * Una foto de móvil son 3 o 4 MB y en base64 crece un tercio más. Sin
     * encogerla, cada foto arrastra megas por toda la cadena por nada: un
     * comprobante se lee igual a 1024 px.
     *
     * @test
     */
    public function la_imagen_se_encoge_antes_de_subirla(): void
    {
        $grande = $this->unJpeg(2400, 1800);

        Http::fake([
            's3.test/*' => Http::response($grande, 200),
            'ollama.test/*' => Http::response(['message' => ['content' => 'Una foto.']], 200),
        ]);

        ImagenDelCliente::comoTexto(['type' => 'image', 'media_url' => 'https://s3.test/grande.jpg']);

        Http::assertSent(function ($request) use ($grande) {
            if (! str_contains($request->url(), 'ollama.test')) {
                return true;
            }

            $enviada = base64_decode($request->data()['messages'][0]['images'][0]);
            $tamano = getimagesizefromstring($enviada);

            return max($tamano[0], $tamano[1]) <= ImagenDelCliente::LADO_MAXIMO
                && strlen($enviada) < strlen($grande);
        });
    }

    /** Un vídeo o un sticker no son una foto y siguen sin entrar. */
    public function test_solo_entran_las_imagenes(): void
    {
        foreach (['video', 'sticker', 'audio', 'document'] as $tipo) {
            $this->assertFalse(
                ImagenDelCliente::esLegible(['type' => $tipo, 'media_url' => 'https://s3.test/x']),
                "«{$tipo}» no es una foto."
            );
        }
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function encender(): void
    {
        AiAssistantProfile::save($this->company->id, array_replace(
            AiAssistantProfile::settings($this->company->id),
            ['ver_imagenes' => true]
        ));
    }

    /** Un JPEG de verdad, para que GD pueda abrirlo y encogerlo. */
    private function unJpeg(int $ancho = 400, int $alto = 300): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 240));
        imagestring($im, 5, 20, 20, 'COMPROBANTE 89.900', imagecolorallocate($im, 10, 10, 10));

        ob_start();
        imagejpeg($im, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }
}
