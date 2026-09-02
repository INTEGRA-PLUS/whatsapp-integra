<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\OptOutRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «STOP», «BAJA»: reconocer la petición sin decidir por el cliente.
 *
 * El riesgo real aquí no es dejar de detectar una baja, es detectarla de más:
 * en Colombia «baja» es también dar de baja el servicio, y marcar por la
 * palabra dejaría sin avisos de facturación a quien nunca lo pidió. Por eso el
 * webhook solo **anota la petición** y la confirma una persona.
 */
class OptOutRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.webhook_app_secrets' => 'secreto']);

        $this->company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-' . Str::random(4), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);
    }

    #[DataProvider('frasesDeBaja')]
    public function test_reconoce_una_peticion_de_baja(string $texto): void
    {
        $this->assertTrue(OptOutRequest::looksLikeOptOut($texto), "Debía reconocerse: «{$texto}»");
    }

    public static function frasesDeBaja(): array
    {
        return [
            ['STOP'],
            ['stop'],
            ['Baja'],
            ['¡NO MÁS MENSAJES!'],
            ['no mas mensajes'],
            ['No molestar.'],
            ['unsubscribe'],
            ['  dar de baja  '],
        ];
    }

    #[DataProvider('frasesQueNoLoSon')]
    public function test_no_confunde_una_conversacion_con_una_baja(string $texto): void
    {
        $this->assertFalse(OptOutRequest::looksLikeOptOut($texto), "No debía reconocerse: «{$texto}»");
    }

    public static function frasesQueNoLoSon(): array
    {
        return [
            // El caso peligroso: habla del servicio, no de la publicidad.
            ['quiero dar de baja mi servicio de internet'],
            ['me pueden dar de baja el plan por favor'],
            ['mi internet esta muy lento, no mas problemas'],
            ['hola'],
            ['stop motion'],
            ['la baja de mi contrato cuando aplica'],
            [''],
        ];
    }

    public function test_la_peticion_queda_anotada_y_contada_en_el_hilo(): void
    {
        $conversation = $this->conversacion();

        $this->entrante($conversation, 'STOP')->assertOk();

        $this->assertNotNull($conversation->fresh()->opt_out_requested_at);

        $aviso = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('type', 'system')
            ->latest('id')
            ->first();

        $this->assertNotNull($aviso, 'El hilo debía contar lo que pasó.');
        $this->assertStringContainsString('no recibir campañas', $aviso->content);
    }

    /**
     * Anotar no es aplicar: hasta que un agente lo confirme, el contacto sigue
     * entrando en las campañas.
     */
    public function test_anotar_la_peticion_no_da_de_baja_al_contacto(): void
    {
        $contact = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Daniela',
            'phone_number' => '573007852081',
        ]);

        $this->entrante($this->conversacion(), 'BAJA')->assertOk();

        $this->assertNull($contact->fresh()->opted_out_at);
    }

    public function test_un_agente_confirma_la_baja_de_un_clic(): void
    {
        $conversation = $this->conversacion();
        $this->entrante($conversation, 'no molestar')->assertOk();

        $this->actingAs($this->agente())
            ->postJson("/api/contacts/opt-out-requests/{$conversation->id}", ['apply' => true])
            ->assertOk()
            ->assertJson(['applied' => true]);

        // El número no tenía ficha: la baja vive en el contacto, así que se crea.
        $contact = Contact::where('company_id', $this->company->id)
            ->where('phone_number', '573007852081')
            ->first();

        $this->assertNotNull($contact);
        $this->assertNotNull($contact->opted_out_at);
        $this->assertSame('client', $contact->opt_out_source);
        $this->assertNull($conversation->fresh()->opt_out_requested_at);
    }

    public function test_descartar_deja_al_cliente_como_estaba(): void
    {
        $conversation = $this->conversacion();
        $this->entrante($conversation, 'baja')->assertOk();

        $this->actingAs($this->agente())
            ->postJson("/api/contacts/opt-out-requests/{$conversation->id}", ['apply' => false])
            ->assertOk()
            ->assertJson(['applied' => false]);

        $this->assertNull($conversation->fresh()->opt_out_requested_at);
        $this->assertSame(0, Contact::optedOut()->count());
    }

    /* ── Ayudas ── */

    private function conversacion(): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Daniela',
            'status' => 'open',
        ]);
    }

    private function agente(): User
    {
        $user = User::create([
            'name' => 'Agente',
            'email' => Str::random(8) . '@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);

        setPermissionsTeamId($this->company->id);
        Permission::firstOrCreate(['name' => 'contacts.update', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web']);
        $role->syncPermissions(['contacts.update']);
        $user->assignRole($role);

        return $user;
    }

    private function entrante(WhatsAppConversation $conversation, string $texto)
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $this->instance->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $this->instance->phone_number_id],
                        'contacts' => [['wa_id' => $conversation->phone_number, 'profile' => ['name' => 'Daniela']]],
                        'messages' => [[
                            'id' => 'wamid.' . Str::random(10),
                            'from' => $conversation->phone_number,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secreto'),
        ]), $body);
    }
}
