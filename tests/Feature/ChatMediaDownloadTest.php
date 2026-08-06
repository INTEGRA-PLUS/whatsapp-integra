<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatMediaDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function agentFor(Company $company): User
    {
        return User::create([
            'company_id' => $company->id,
            'name'       => 'Agente ' . $company->slug,
            'email'      => 'agente-' . $company->slug . '@example.test',
            'password'   => bcrypt('secret'),
            'role'       => 'agent',
            'active'     => true,
        ]);
    }

    private function scenario(array $messageAttributes = []): array
    {
        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'active' => true]);

        $user = $this->agentFor($company);

        $instance = Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) \Illuminate\Support\Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '111',
            'waba_id'         => '222',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-meta',
        ]);

        $conversation = WhatsAppConversation::create([
            'instance_id'  => $instance->id,
            'wa_id'        => '573001112233',
            'phone_number' => '573001112233',
            'name'         => 'Juliana',
            'status'       => 'open',
        ]);

        $message = WhatsAppMessage::create(array_merge([
            'conversation_id' => $conversation->id,
            'type'            => 'document',
            'direction'       => 'outbound',
            'status'          => 'sent',
        ], $messageAttributes));

        return [$company, $user, $instance, $message];
    }

    public function test_descarga_el_documento_con_su_nombre_original(): void
    {
        [, $user, , $message] = $this->scenario([
            'media_url' => 'https://s3.example.test/whatsapp/media/wa_abc.pdf',
            'filename'  => 'Recibo Caja 42601.pdf',
        ]);

        Http::fake(['*' => Http::response('%PDF-1.4 contenido', 200, ['Content-Type' => 'application/pdf'])]);

        $response = $this->actingAs($user)->get("/api/chat/messages/{$message->id}/media");

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="Recibo Caja 42601.pdf"');
        $this->assertSame('%PDF-1.4 contenido', $response->getContent());
    }

    public function test_inline_muestra_el_archivo_en_el_navegador(): void
    {
        [, $user, , $message] = $this->scenario([
            'media_url' => 'https://s3.example.test/whatsapp/media/wa_abc.pdf',
            'filename'  => 'factura.pdf',
        ]);

        Http::fake(['*' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf'])]);

        $this->actingAs($user)
            ->get("/api/chat/messages/{$message->id}/media?inline=1")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="factura.pdf"');
    }

    public function test_resuelve_contra_meta_el_mensaje_que_solo_tiene_media_id(): void
    {
        [, $user, , $message] = $this->scenario([
            'metadata' => [
                'components' => [[
                    'type'       => 'header',
                    'parameters' => [[
                        'type'     => 'document',
                        'document' => ['id' => '9988', 'filename' => 'Recibo_Caja_42601.pdf'],
                    ]],
                ]],
            ],
            'type' => 'template',
        ]);

        Http::fake([
            'graph.facebook.com/*/9988'  => Http::response(['url' => 'https://lookaside.test/file', 'mime_type' => 'application/pdf']),
            'lookaside.test/*'           => Http::response('%PDF-1.4 recuperado', 200, ['Content-Type' => 'application/pdf']),
            's3images.integracolombia.online/*' => Http::response('', 200),
            '*'                          => Http::response('%PDF-1.4 recuperado', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->actingAs($user)->get("/api/chat/messages/{$message->id}/media");

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="Recibo_Caja_42601.pdf"');

        // La copia queda persistida: la próxima apertura ya no depende de Meta.
        $message->refresh();
        $this->assertNotEmpty($message->media_url);
        $this->assertSame('Recibo_Caja_42601.pdf', $message->filename);
        $this->assertSame('9988', $message->media_id);
    }

    public function test_sin_adjunto_responde_404_con_mensaje_claro(): void
    {
        [, $user, , $message] = $this->scenario(['type' => 'template', 'content' => 'Solo texto']);

        $this->actingAs($user)
            ->getJson("/api/chat/messages/{$message->id}/media")
            ->assertNotFound()
            ->assertJson(['error' => 'Este mensaje no tiene un archivo adjunto disponible.']);
    }

    public function test_un_usuario_de_otra_empresa_no_accede_al_adjunto(): void
    {
        [, , , $message] = $this->scenario([
            'media_url' => 'https://s3.example.test/whatsapp/media/wa_abc.pdf',
            'filename'  => 'privado.pdf',
        ]);

        $otherCompany = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $intruder = $this->agentFor($otherCompany);

        Http::fake(['*' => Http::response('%PDF', 200)]);

        $this->actingAs($intruder)
            ->get("/api/chat/messages/{$message->id}/media")
            ->assertForbidden();
    }

    public function test_el_nombre_del_archivo_no_puede_inyectar_cabeceras(): void
    {
        [, $user, , $message] = $this->scenario([
            'media_url' => 'https://s3.example.test/whatsapp/media/wa_abc.pdf',
            'filename'  => "../../etc/pas\"swd\r\nX-Injected: 1",
        ]);

        Http::fake(['*' => Http::response('%PDF', 200)]);

        $response = $this->actingAs($user)->get("/api/chat/messages/{$message->id}/media");

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="passwdX-Injected: 1"');
        $this->assertNull($response->headers->get('X-Injected'));
    }
}
