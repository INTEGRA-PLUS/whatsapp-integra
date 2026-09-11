<?php

namespace Tests\Feature;

use App\Extensions\KeywordRoutingExtension;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Notifications\ExtensionAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extensión «Enrutado por palabra clave», probada contra el webhook real.
 *
 * Se entra por el webhook y no llamando a la extensión a mano porque lo que hay
 * que demostrar es que el gancho está enchufado donde dice: una extensión
 * perfecta a la que nadie llama es exactamente igual de inútil que no tenerla.
 */
class ExtensionKeywordRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(6)]]], 200));

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

    /** @param list<array<string, mixed>> $rules */
    private function instalar(array $rules, array $settings = []): CompanyExtension
    {
        return CompanyExtension::create([
            'company_id' => $this->company->id,
            'slug' => 'keyword_routing',
            'enabled' => true,
            'settings' => array_merge([
                'rules' => $rules,
                'match_mode' => 'word',
                'first_message_only' => false,
            ], $settings),
            'installed_by' => $this->admin->id,
            'installed_at' => now(),
        ]);
    }

    private function recibir(string $texto): void
    {
        $this->postSignedWebhook([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $this->instance->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '573001234567',
                            'phone_number_id' => $this->instance->phone_number_id,
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Cliente'],
                            'wa_id' => '573007852081',
                        ]],
                        'messages' => [[
                            'from' => '573007852081',
                            'id' => 'wamid.IN'.Str::random(8),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    private function conversacion(): WhatsAppConversation
    {
        return WhatsAppConversation::where('instance_id', $this->instance->id)->firstOrFail();
    }

    public function test_etiqueta_y_asigna_cuando_coincide(): void
    {
        $tag = Tag::create(['company_id' => $this->company->id, 'name' => 'Ventas', 'color' => '#0f0']);
        $agente = User::create([
            'company_id' => $this->company->id,
            'name' => 'Vendedor',
            'email' => 'ventas@fibra.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'agent',
        ]);

        $this->instalar([[
            'label' => 'Intención de compra',
            'keywords' => ['quiero comprar', 'cotizar'],
            'tag_id' => $tag->id,
            'assign' => (string) $agente->id,
            'notify' => true,
        ]]);

        $this->recibir('Hola, quiero comprar el plan de 300 megas');

        $conversacion = $this->conversacion();
        $this->assertTrue($conversacion->tags()->where('tags.id', $tag->id)->exists());
        $this->assertSame($agente->id, $conversacion->assigned_to);
        Notification::assertSentTo($agente, ExtensionAlertNotification::class);
    }

    public function test_no_hace_nada_si_ninguna_regla_coincide(): void
    {
        $tag = Tag::create(['company_id' => $this->company->id, 'name' => 'Ventas', 'color' => '#0f0']);

        $this->instalar([[
            'label' => 'Compra', 'keywords' => ['comprar'], 'tag_id' => $tag->id,
            'assign' => 'none', 'notify' => false,
        ]]);

        $this->recibir('Se me fue el internet otra vez');

        $this->assertFalse($this->conversacion()->tags()->exists());
    }

    /**
     * «Palabra completa» es lo que evita que "compra" salte con "incomprable" —
     * o, en el caso real que lo motiva, que "baja" salte con "trabajar".
     */
    public function test_palabra_completa_no_coincide_dentro_de_otra_palabra(): void
    {
        $tag = Tag::create(['company_id' => $this->company->id, 'name' => 'Ventas', 'color' => '#0f0']);

        $this->instalar([[
            'label' => 'Compra', 'keywords' => ['compra'], 'tag_id' => $tag->id,
            'assign' => 'none', 'notify' => false,
        ]], ['match_mode' => 'word']);

        $this->recibir('Eso me parece incomprable');

        $this->assertFalse($this->conversacion()->tags()->exists());
    }

    /** Media Colombia escribe "garantia" sin tilde y la otra media con ella. */
    public function test_coincide_sin_tildes_y_sin_mayusculas(): void
    {
        $tag = Tag::create(['company_id' => $this->company->id, 'name' => 'Garantías', 'color' => '#00f']);

        $this->instalar([[
            'label' => 'Garantía', 'keywords' => ['garantía'], 'tag_id' => $tag->id,
            'assign' => 'none', 'notify' => false,
        ]]);

        $this->recibir('NECESITO LA GARANTIA DEL EQUIPO');

        $this->assertTrue($this->conversacion()->tags()->where('tags.id', $tag->id)->exists());
    }

    /**
     * Encadenar todas las reglas dejaría el chat asignado dos veces y nadie
     * podría explicar por qué ganó la segunda.
     */
    public function test_manda_la_primera_regla_que_coincide(): void
    {
        $primera = Tag::create(['company_id' => $this->company->id, 'name' => 'Primera', 'color' => '#111']);
        $segunda = Tag::create(['company_id' => $this->company->id, 'name' => 'Segunda', 'color' => '#222']);

        $this->instalar([
            ['label' => 'A', 'keywords' => ['internet'], 'tag_id' => $primera->id, 'assign' => 'none', 'notify' => false],
            ['label' => 'B', 'keywords' => ['internet'], 'tag_id' => $segunda->id, 'assign' => 'none', 'notify' => false],
        ]);

        $this->recibir('problema con el internet');

        $conversacion = $this->conversacion();
        $this->assertTrue($conversacion->tags()->where('tags.id', $primera->id)->exists());
        $this->assertFalse($conversacion->tags()->where('tags.id', $segunda->id)->exists());
    }

    /** Quitarle el chat a quien ya lo está atendiendo es peor que no enrutar. */
    public function test_no_reasigna_un_chat_que_ya_tiene_agente(): void
    {
        $ocupado = User::create([
            'company_id' => $this->company->id, 'name' => 'Ocupado', 'email' => 'ocupado@fibra.test',
            'password' => 'secret', 'active' => true, 'role' => 'agent',
        ]);
        $otro = User::create([
            'company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'otro@fibra.test',
            'password' => 'secret', 'active' => true, 'role' => 'agent',
        ]);

        $this->instalar([[
            'label' => 'Compra', 'keywords' => ['comprar'], 'tag_id' => null,
            'assign' => (string) $otro->id, 'notify' => false,
        ]]);

        $this->recibir('hola');
        $this->conversacion()->update(['assigned_to' => $ocupado->id]);

        $this->recibir('quiero comprar');

        $this->assertSame($ocupado->id, $this->conversacion()->assigned_to);
    }

    public function test_una_extension_apagada_no_enruta(): void
    {
        $tag = Tag::create(['company_id' => $this->company->id, 'name' => 'Ventas', 'color' => '#0f0']);

        $this->instalar([[
            'label' => 'Compra', 'keywords' => ['comprar'], 'tag_id' => $tag->id,
            'assign' => 'none', 'notify' => false,
        ]])->update(['enabled' => false]);

        $this->recibir('quiero comprar');

        $this->assertFalse($this->conversacion()->tags()->exists());
    }

    /**
     * La instalación es de una empresa: el mensaje que entra por la instancia de
     * otra no puede disparar sus reglas ni llevarse su etiqueta.
     */
    public function test_las_reglas_de_una_empresa_no_alcanzan_a_otra(): void
    {
        $tag = Tag::create(['company_id' => $this->company->id, 'name' => 'Ventas', 'color' => '#0f0']);
        $this->instalar([[
            'label' => 'Compra', 'keywords' => ['comprar'], 'tag_id' => $tag->id,
            'assign' => 'none', 'notify' => false,
        ]]);

        $otra = Company::create(['name' => 'Fibra Norte', 'slug' => 'fibra-norte', 'active' => true]);
        $instanciaVecina = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Norte',
            'phone_number_id' => '2222222222',
            'waba_id' => '3333333333',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $this->instance = $instanciaVecina;
        $this->recibir('quiero comprar');

        $ajena = WhatsAppConversation::where('instance_id', $instanciaVecina->id)->firstOrFail();
        $this->assertFalse($ajena->tags()->exists());
    }

    /**
     * Un id de agente de otra empresa no se guarda: el desplegable sólo ofrecía
     * los suyos, pero mandar el id a mano asignaría el chat a un desconocido.
     */
    public function test_el_saneado_descarta_un_agente_de_otra_empresa(): void
    {
        $otra = Company::create(['name' => 'Fibra Norte', 'slug' => 'fibra-norte', 'active' => true]);
        $ajeno = User::create([
            'company_id' => $otra->id, 'name' => 'Ajeno', 'email' => 'ajeno@norte.test',
            'password' => 'secret', 'active' => true, 'role' => 'agent',
        ]);

        $extension = app(KeywordRoutingExtension::class);

        $limpio = $extension->sanitizeSettings([
            'rules' => [
                ['label' => 'Compra', 'keywords' => 'comprar', 'assign' => (string) $ajeno->id],
                ['label' => 'Vacía', 'keywords' => '  ,  '],
            ],
            'match_mode' => 'palabra',
        ], $this->company->id);

        $this->assertCount(1, $limpio['rules']);
        $this->assertSame('none', $limpio['rules'][0]['assign']);
        $this->assertSame(['comprar'], $limpio['rules'][0]['keywords']);
        $this->assertSame('word', $limpio['match_mode']);
    }
}
