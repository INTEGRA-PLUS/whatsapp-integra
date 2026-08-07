<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegisterMessageApiTest extends TestCase
{
    use RefreshDatabase;

    private function metaInstance(): Instance
    {
        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'active' => true]);

        return Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '111',
            'waba_id'         => '222',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-meta',
        ]);
    }

    private function register(Instance $instance, array $payload)
    {
        return $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/register', array_merge([
                'to'      => '573001112233',
                'wamid'   => 'wamid.' . Str::random(10),
                'content' => 'Se ha procesado el pago de 70.000 $.',
            ], $payload));
    }

    public function test_un_documento_sin_archivo_se_guarda_como_texto(): void
    {
        $instance = $this->metaInstance();

        $this->register($instance, ['type' => 'document'])->assertOk();

        $message = WhatsAppMessage::latest('id')->first();

        $this->assertSame('text', $message->type);
        $this->assertSame('Se ha procesado el pago de 70.000 $.', $message->content);
    }

    public function test_un_documento_con_media_id_conserva_su_tipo(): void
    {
        $instance = $this->metaInstance();

        $this->register($instance, [
            'type'     => 'document',
            'media_url' => 'https://s3.example.test/whatsapp/media/recibo.pdf',
            'filename' => 'Recibo_Caja_42601.pdf',
        ])->assertOk();

        $message = WhatsAppMessage::latest('id')->first();

        $this->assertSame('document', $message->type);
        $this->assertSame('Recibo_Caja_42601.pdf', $message->filename);
    }
}
