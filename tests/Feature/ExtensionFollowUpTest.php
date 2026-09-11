<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\ExtensionAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extensión «Seguimiento de conversaciones sin respuesta».
 *
 * Lo que hay que asegurar no es que avise —eso es lo fácil—, sino que NO avise
 * en los tres casos en los que avisar sería ruido: el chat que ya contestaron,
 * el que acaba de escribir y el que ya avisó hace un rato. Una campana que grita
 * de más se acaba ignorando, y entonces la extensión no sirve para nada.
 */
class ExtensionFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->company = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true]);

        $this->admin = User::create([
            'company_id' => $this->company->id,
            'name' => 'Admin',
            'email' => 'admin@fibra.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-meta',
        ]);
    }

    private function instalar(array $settings = []): CompanyExtension
    {
        return CompanyExtension::create([
            'company_id' => $this->company->id,
            'slug' => 'follow_up',
            'enabled' => true,
            'settings' => array_merge([
                'minutes' => 30,
                'notify' => 'both',
                'tag_id' => null,
                'business_hours_only' => false,
                'repeat_minutes' => 240,
            ], $settings),
            'installed_by' => $this->admin->id,
            'installed_at' => now(),
        ]);
    }

    private function conversacion(string $direction, int $minutosAtras, array $attributes = []): WhatsAppConversation
    {
        $cuando = now()->subMinutes($minutosAtras);

        $conversation = WhatsAppConversation::create(array_merge([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Cliente',
            'status' => 'open',
            'last_message' => 'Hola',
            'last_message_at' => $cuando,
        ], $attributes));

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.'.Str::random(10),
            'type' => 'text',
            'content' => 'Hola',
            'direction' => $direction,
            'status' => 'sent',
            'sent_at' => $cuando,
            'created_at' => $cuando,
        ]);

        return $conversation;
    }

    private function correr(): void
    {
        $this->artisan('extensions:run')->assertExitCode(0);
    }

    public function test_avisa_cuando_el_cliente_lleva_esperando(): void
    {
        $this->instalar();
        $this->conversacion('inbound', 45);

        $this->correr();

        Notification::assertSentTo($this->admin, ExtensionAlertNotification::class);
    }

    /** Si el último mensaje es del agente, nadie está esperando. */
    public function test_no_avisa_si_el_agente_ya_contesto(): void
    {
        $this->instalar();
        $this->conversacion('outbound', 45);

        $this->correr();

        Notification::assertNothingSent();
    }

    public function test_no_avisa_antes_de_tiempo(): void
    {
        $this->instalar(['minutes' => 60]);
        $this->conversacion('inbound', 20);

        $this->correr();

        Notification::assertNothingSent();
    }

    /**
     * Pasadas las 24h el agente ya no puede contestar en texto libre: el aviso
     * llegaría con una acción imposible detrás.
     */
    public function test_no_avisa_fuera_de_la_ventana_de_24h(): void
    {
        $this->instalar();
        $this->conversacion('inbound', 60 * 30);

        $this->correr();

        Notification::assertNothingSent();
    }

    public function test_no_repite_el_aviso_del_mismo_chat(): void
    {
        $this->instalar(['repeat_minutes' => 240]);
        $this->conversacion('inbound', 45);

        $this->correr();
        Notification::assertSentTimes(ExtensionAlertNotification::class, 1);

        $this->correr();
        Notification::assertSentTimes(ExtensionAlertNotification::class, 1);
    }

    public function test_una_extension_apagada_no_corre(): void
    {
        $this->instalar()->update(['enabled' => false]);
        $this->conversacion('inbound', 45);

        $this->correr();

        Notification::assertNothingSent();
    }

    public function test_aplica_la_etiqueta_configurada(): void
    {
        $tag = Tag::create(['company_id' => $this->company->id, 'name' => 'Sin respuesta', 'color' => '#f00']);
        $this->instalar(['tag_id' => $tag->id]);
        $conversacion = $this->conversacion('inbound', 45);

        $this->correr();

        $this->assertTrue($conversacion->tags()->where('tags.id', $tag->id)->exists());
    }

    /**
     * La pasada recorre TODAS las empresas: una empresa que no lo instaló no
     * puede recibir avisos de sus propias conversaciones.
     */
    public function test_no_toca_las_conversaciones_de_otra_empresa(): void
    {
        $this->instalar();

        $otra = Company::create(['name' => 'Fibra Norte', 'slug' => 'fibra-norte', 'active' => true]);
        $vecino = User::create([
            'company_id' => $otra->id,
            'name' => 'Vecino',
            'email' => 'vecino@norte.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);
        $instanciaVecina = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Norte',
            'phone_number_id' => '999',
            'waba_id' => '888',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $ajena = WhatsAppConversation::create([
            'instance_id' => $instanciaVecina->id,
            'wa_id' => '573001112233',
            'phone_number' => '573001112233',
            'name' => 'Cliente del vecino',
            'status' => 'open',
            'last_message' => 'Hola',
            'last_message_at' => now()->subMinutes(90),
        ]);
        WhatsAppMessage::create([
            'conversation_id' => $ajena->id,
            'wamid' => 'wamid.'.Str::random(10),
            'type' => 'text',
            'content' => 'Hola',
            'direction' => 'inbound',
            'status' => 'sent',
            'sent_at' => now()->subMinutes(90),
        ]);

        $this->correr();

        Notification::assertNotSentTo($vecino, ExtensionAlertNotification::class);
        $ajena->refresh();
        $this->assertArrayNotHasKey('extension_follow_up_at', $ajena->metadata ?? []);
    }

    /** Con agente asignado y "sólo al agente", el aviso no salpica a los admins. */
    public function test_respeta_a_quien_avisar(): void
    {
        $agente = User::create([
            'company_id' => $this->company->id,
            'name' => 'Agente',
            'email' => 'agente@fibra.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'agent',
        ]);

        $this->instalar(['notify' => 'assigned']);
        $this->conversacion('inbound', 45, ['assigned_to' => $agente->id]);

        $this->correr();

        Notification::assertSentTo($agente, ExtensionAlertNotification::class);
        Notification::assertNotSentTo($this->admin, ExtensionAlertNotification::class);
    }

    /**
     * Un chat huérfano sin respuesta es justo el que más falta hace que alguien
     * vea, así que sube a los administradores aunque se pidiera "sólo al
     * agente asignado".
     */
    public function test_sin_agente_asignado_el_aviso_sube_a_los_administradores(): void
    {
        $this->instalar(['notify' => 'assigned']);
        $this->conversacion('inbound', 45);

        $this->correr();

        Notification::assertSentTo($this->admin, ExtensionAlertNotification::class);
    }
}
