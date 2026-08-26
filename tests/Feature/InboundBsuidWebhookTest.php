<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mensajes de clientes que ocultan su teléfono tras un nombre de usuario.
 *
 * Meta los identifica con un BSUID ("CO.1402615141764490") en `from_user_id`
 * en vez de un teléfono en `from`. Leer sólo `from` hacía que el webhook
 * respondiera 500; Meta reintentaba, volvía a fallar y terminaba descartando el
 * mensaje. El cliente veía sus dos chulos y en el CRM no aparecía nada.
 */
class InboundBsuidWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const BSUID = 'CO.1402615141764490';

    private function metaInstance(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        return Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id'         => '1022301494026392',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-meta',
        ]);
    }

    /** Payload tal cual lo entrega Meta, tomado del log de producción. */
    private function payload(array $message = [], array $contact = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '2212436902867081',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '573104047030',
                            'phone_number_id' => '1177962515404155',
                        ],
                        'contacts' => [array_merge([
                            'profile' => ['name' => 'Katherine', 'username' => '_Katherine_PerezC'],
                            'user_id' => self::BSUID,
                        ], $contact)],
                        'messages' => [array_merge([
                            'from_user_id' => self::BSUID,
                            'id' => 'wamid.' . Str::random(12),
                            'timestamp' => '1786590653',
                            'type' => 'text',
                            'text' => ['body' => 'Llevamos dos días sin conexión'],
                        ], $message)],
                    ],
                ]],
            ]],
        ];
    }

    public function test_un_mensaje_sin_telefono_se_guarda_en_vez_de_devolver_500(): void
    {
        $this->metaInstance();

        $this->postSignedWebhook($this->payload())->assertOk();

        $message = WhatsAppMessage::latest('id')->first();

        $this->assertNotNull($message, 'El mensaje del cliente sin teléfono debía guardarse.');
        $this->assertSame('Llevamos dos días sin conexión', $message->content);
        $this->assertSame('inbound', $message->direction);
    }

    public function test_el_hilo_se_identifica_por_bsuid_y_se_titula_con_el_nombre_de_usuario(): void
    {
        $instance = $this->metaInstance();

        $this->postSignedWebhook($this->payload())->assertOk();

        $conversation = WhatsAppConversation::firstWhere('instance_id', $instance->id);

        $this->assertSame(self::BSUID, $conversation->wa_id, 'El BSUID debe guardarse sin normalizar a dígitos.');
        $this->assertSame('', $conversation->phone_number, 'Un BSUID no es un teléfono y no debe fingirlo.');
        $this->assertSame('@_Katherine_PerezC', $conversation->name);
        $this->assertSame(self::BSUID, $conversation->metadata['bsuid'] ?? null);
    }

    public function test_dos_mensajes_del_mismo_cliente_van_al_mismo_hilo(): void
    {
        $this->metaInstance();

        $this->postSignedWebhook($this->payload())->assertOk();
        $this->postSignedWebhook($this->payload([
            'text' => ['body' => '¿Alguien me responde?'],
        ]))->assertOk();

        $this->assertSame(1, WhatsAppConversation::count(), 'El BSUID identifica un único hilo.');
        $this->assertSame(2, WhatsAppMessage::count());
    }

    public function test_un_cliente_sin_telefono_no_crea_ficha_en_la_agenda(): void
    {
        $this->metaInstance();

        $this->postSignedWebhook($this->payload())->assertOk();

        // La agenda se indexa por número y se sincroniza con Integra: un BSUID
        // ahí sería una ficha basura que no casa con ningún abonado.
        $this->assertSame(0, Contact::count());
    }

    public function test_un_mensaje_con_telefono_sigue_funcionando_igual(): void
    {
        $instance = $this->metaInstance();

        $payload = $this->payload(
            ['from' => '573007852081', 'from_user_id' => null],
            ['wa_id' => '573007852081', 'user_id' => null]
        );

        $this->postSignedWebhook($payload)->assertOk();

        $conversation = WhatsAppConversation::firstWhere('instance_id', $instance->id);

        $this->assertSame('573007852081', $conversation->wa_id);
        $this->assertSame('573007852081', $conversation->phone_number);
        $this->assertSame('Katherine', $conversation->name);
    }

    public function test_un_mensaje_sin_identidad_alguna_no_provoca_reintentos_de_meta(): void
    {
        $this->metaInstance();

        $payload = $this->payload(['from' => null, 'from_user_id' => null]);

        // 200, no 500: reintentar no lo arregla y el bucle de reintentos es
        // justo lo que hacía perder los mensajes buenos del mismo lote.
        $this->postSignedWebhook($payload)->assertOk();

        $this->assertSame(0, WhatsAppMessage::count());
    }
}
