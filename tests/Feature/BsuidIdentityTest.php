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
 * Identidad del cliente cuando Meta manda el teléfono a ratos.
 *
 * El BSUID llega en todos los webhooks de mensajes, pero el teléfono sólo si
 * hubo contacto en los últimos 30 días. Quedarse con el teléfono y tirar el
 * BSUID funcionaba hasta el día que el número dejaba de venir: ese día el mismo
 * cliente entraba como identidad nueva, se le abría un hilo aparte y el agente
 * veía un chat vacío de alguien con quien llevaba meses hablando.
 */
class BsuidIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const BSUID = 'CO.1402615141764490';
    private const PHONE = '573007852081';

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

    /**
     * Un webhook con los contactos y mensajes que se le pasen, para poder armar
     * tanto el caso normal como un lote con varios clientes distintos.
     */
    private function webhook(array $contacts, array $messages): array
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
                        'contacts' => $contacts,
                        'messages' => $messages,
                    ],
                ]],
            ]],
        ];
    }

    private function mensaje(array $extra = []): array
    {
        return array_merge([
            'id' => 'wamid.' . Str::random(12),
            'timestamp' => '1786590653',
            'type' => 'text',
            'text' => ['body' => 'Llevamos dos días sin conexión'],
        ], $extra);
    }

    // ─── Crítico 1: la identidad se guarda antes de hacer falta ──────────────

    public function test_el_bsuid_se_guarda_aunque_meta_todavia_mande_el_telefono(): void
    {
        $instance = $this->metaInstance();

        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine'], 'wa_id' => self::PHONE, 'user_id' => self::BSUID]],
            [$this->mensaje(['from' => self::PHONE, 'from_user_id' => self::BSUID])]
        ))->assertOk();

        $conversation = WhatsAppConversation::firstWhere('instance_id', $instance->id);

        $this->assertSame(self::PHONE, $conversation->phone_number);
        $this->assertSame(self::BSUID, $conversation->bsuid, 'El BSUID debe guardarse desde el primer mensaje, no cuando ya falte el teléfono.');
    }

    public function test_el_cliente_no_estrena_hilo_cuando_meta_deja_de_mandar_su_telefono(): void
    {
        $this->metaInstance();

        // Hoy: Meta todavía revela el número.
        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine'], 'wa_id' => self::PHONE, 'user_id' => self::BSUID]],
            [$this->mensaje(['from' => self::PHONE, 'from_user_id' => self::BSUID])]
        ))->assertOk();

        // Un mes después, sin contacto de por medio: sólo queda el BSUID.
        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine', 'username' => 'katherine.pc'], 'user_id' => self::BSUID]],
            [$this->mensaje(['from_user_id' => self::BSUID, 'text' => ['body' => '¿Alguien me responde?']])]
        ))->assertOk();

        $this->assertSame(1, WhatsAppConversation::count(), 'Es el mismo cliente: el historial no puede partirse en dos hilos.');
        $this->assertSame(2, WhatsAppMessage::count());
    }

    public function test_el_hilo_abierto_sin_telefono_lo_absorbe_cuando_meta_lo_revela(): void
    {
        $instance = $this->metaInstance();

        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine', 'username' => 'katherine.pc'], 'user_id' => self::BSUID]],
            [$this->mensaje(['from_user_id' => self::BSUID])]
        ))->assertOk();

        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine'], 'wa_id' => self::PHONE, 'user_id' => self::BSUID]],
            [$this->mensaje(['from' => self::PHONE, 'from_user_id' => self::BSUID])]
        ))->assertOk();

        $conversation = WhatsAppConversation::firstWhere('instance_id', $instance->id);

        $this->assertSame(1, WhatsAppConversation::count());
        $this->assertSame(self::PHONE, $conversation->phone_number, 'En cuanto Meta revela el número hay que guardarlo: es lo que permite llamar y facturar.');
        $this->assertSame(self::BSUID, $conversation->wa_id, 'La clave del hilo no se reescribe: rompería el historial ya enlazado.');
    }

    public function test_un_cliente_conocido_que_empieza_a_ocultar_su_numero_no_ensucia_la_agenda(): void
    {
        $instance = $this->metaInstance();

        // Hilo abierto por el ERP para un aviso de pago: tiene número, pero
        // todavía no tiene ficha en la agenda.
        WhatsAppConversation::create([
            'instance_id'  => $instance->id,
            'wa_id'        => self::PHONE,
            'bsuid'        => self::BSUID,
            'phone_number' => self::PHONE,
            'name'         => 'Katherine',
            'status'       => 'open',
        ]);

        // El cliente responde, ya con el número oculto: sólo llega el BSUID.
        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine', 'username' => 'katherine.pc'], 'user_id' => self::BSUID]],
            [$this->mensaje(['from_user_id' => self::BSUID])]
        ))->assertOk();

        $contact = Contact::first();

        $this->assertNotNull($contact, 'El hilo tiene número: la ficha debe crearse igual.');
        $this->assertSame(self::PHONE, $contact->phone_number, 'La agenda se indexa por número: un BSUID ahí no casa con ningún abonado de Integra.');
        $this->assertSame(1, Contact::count());
    }

    // ─── Crítico 3: un lote con varios clientes ──────────────────────────────

    public function test_cada_cliente_del_lote_conserva_su_propia_identidad(): void
    {
        $instance = $this->metaInstance();
        $otroBsuid = 'CO.9988776655443322';

        $this->postSignedWebhook($this->webhook(
            [
                ['profile' => ['name' => 'Katherine', 'username' => 'katherine.pc'], 'user_id' => self::BSUID],
                ['profile' => ['name' => 'Andrés', 'username' => 'andres.gv'], 'user_id' => $otroBsuid],
            ],
            [
                $this->mensaje(['from_user_id' => self::BSUID, 'text' => ['body' => 'Sin conexión']]),
                $this->mensaje(['from_user_id' => $otroBsuid, 'text' => ['body' => 'Quiero el recibo']]),
            ]
        ))->assertOk();

        $katherine = WhatsAppConversation::firstWhere('bsuid', self::BSUID);
        $andres = WhatsAppConversation::firstWhere('bsuid', $otroBsuid);

        $this->assertSame('@katherine.pc', $katherine->name);
        $this->assertSame('@andres.gv', $andres->name, 'El perfil del primer contacto del lote no puede titular el hilo del segundo.');
        $this->assertSame(2, WhatsAppConversation::where('instance_id', $instance->id)->count());
    }

    public function test_el_nombre_de_usuario_no_se_queda_con_dos_arrobas(): void
    {
        $instance = $this->metaInstance();

        // Hay payloads que ya traen la arroba incluida.
        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine'], 'username' => '@katherine.pc', 'user_id' => self::BSUID]],
            [$this->mensaje(['from_user_id' => self::BSUID])]
        ))->assertOk();

        $conversation = WhatsAppConversation::firstWhere('instance_id', $instance->id);

        $this->assertSame('@katherine.pc', $conversation->name);
        $this->assertSame('katherine.pc', $conversation->metadata['username'] ?? null, 'El nombre de usuario se guarda sin arroba; la arroba es de presentación.');
    }

    // ─── Crítico 2: la API que usa el ERP ────────────────────────────────────

    public function test_la_api_externa_no_mutila_un_bsuid(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $instance = $this->metaInstance();

        // La ventana de 24h se abre con un mensaje del cliente, igual que en
        // producción: sin eso el envío se rechaza antes de llegar a Meta.
        $conversation = WhatsAppConversation::resolveFor($instance->id, null, ['status' => 'open'], self::BSUID);
        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.' . Str::random(10),
            'type' => 'text',
            'content' => 'Hola',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => self::BSUID,
                'message' => 'Su pago quedó registrado.',
            ])->assertOk();

        Http::assertSent(fn ($request) => ($request['recipient'] ?? null) === self::BSUID
            && !array_key_exists('to', $request->data()));

        $this->assertSame(1, WhatsAppConversation::count(), 'Un BSUID normalizado a dígitos abría un hilo fantasma aparte.');
    }

    public function test_la_api_externa_rechaza_un_destinatario_que_no_es_ni_telefono_ni_identificador(): void
    {
        $instance = $this->metaInstance();

        $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', [
                'to' => 'sin identidad',
                'message' => 'Su pago quedó registrado.',
            ])->assertStatus(422);
    }

    // ─── Crítico 4: lo que el chat necesita para pintar la identidad ─────────

    public function test_el_hilo_lleva_la_identidad_que_el_chat_tiene_que_mostrar(): void
    {
        $instance = $this->metaInstance();

        $this->postSignedWebhook($this->webhook(
            [['profile' => ['name' => 'Katherine', 'username' => 'katherine.pc'], 'user_id' => self::BSUID]],
            [$this->mensaje(['from_user_id' => self::BSUID])]
        ))->assertOk();

        // El chat pinta `@usuario` donde iría el teléfono; si esto no viaja en
        // el payload, el agente ve un hueco en blanco y no sabe con quién habla.
        $payload = WhatsAppConversation::firstWhere('instance_id', $instance->id)->toArray();

        $this->assertSame(self::BSUID, $payload['bsuid']);
        $this->assertSame('katherine.pc', $payload['metadata']['username']);
        $this->assertSame('', $payload['phone_number']);
    }
}
