<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Support\PideUnAsesor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cuando el cliente pide una persona, se le da una persona.
 *
 * El prompt del worker ya se lo pedía al modelo —y llegaba, se comprobó en la
 * ejecución de n8n— pero el modelo no lo obedecía: contestaba «procederé a
 * comunicarlo con un asesor» y no marcaba nada. Dos veces seguidas con el
 * cliente escribiéndolo en letras bien grandes (17-sep-2026).
 *
 * Es la petición que menos puede fallar de todo el chat: es lo que alguien
 * escribe justo cuando el bot ya le falló.
 */
class PideUnAsesorTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function reconoce_las_formas_en_que_se_pide(): void
    {
        foreach ([
            'Okey comunícame con un asesor',
            'No, comunícame con un asesor',
            'quiero hablar con una persona',
            'pasame a un asesor por favor',
            'necesito un agente humano',
            'que me atienda alguien',
            'me puede atender una persona',
        ] as $texto) {
            $this->assertTrue(PideUnAsesor::loPide($texto), "«{$texto}» es pedir un asesor.");
        }
    }

    /**
     * Y no dispara con quien sólo lo menciona.
     *
     * Por eso se exige un verbo de petición delante y no basta con que aparezca
     * la palabra: «el asesor me dijo» es un relato, no una petición.
     *
     * @test
     */
    public function no_dispara_con_quien_solo_lo_menciona(): void
    {
        foreach ([
            'Quisiera saber qué características debo tener para solicitar un crédito',
            'el asesor me dijo que viniera hoy',
            '¿ustedes tienen asesores?',
            'necesito ayuda con mi factura',
            'gracias',
            '',
        ] as $texto) {
            $this->assertFalse(PideUnAsesor::loPide($texto), "«{$texto}» no es pedir un asesor.");
        }
    }

    /**
     * De punta a punta: el chat queda asignado y el cliente sabe con quién.
     *
     * @test
     */
    public function pedirlo_asigna_el_chat_y_lo_dice_con_nombre(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(6)]]], 200));

        $company = Company::create([
            'name' => 'Cootramed', 'slug' => 'coop-'.uniqid(), 'active' => true,
            'plan' => 'basico', 'ia' => 'completa',
        ]);

        WhatsAppMenu::where('company_id', $company->id)->delete();

        $instance = Instance::create([
            'company_id' => $company->id, 'uuid' => (string) Str::uuid(),
            'name' => 'Línea', 'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392', 'type' => 'meta',
            'access_token' => 'token', 'active' => true,
        ]);

        $asesora = User::create([
            'company_id' => $company->id, 'name' => 'Laura Restrepo',
            'email' => 'laura@x.test', 'password' => 'secret', 'active' => true,
        ]);

        // El reparto sólo mira a quien tiene rol de atención, y los roles de
        // Spatie van particionados por empresa.
        setPermissionsTeamId($company->id);
        $asesora->assignRole(\Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'agent', 'company_id' => $company->id, 'guard_name' => 'web',
        ]));

        CompanyIntegration::create([
            'company_id' => $company->id,
            'key' => CompanyIntegration::KEY_AI_CHAT,
            'enabled' => true,
        ]);

        config([
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'llave',
        ]);

        // El reparto de la empresa: al asesor con menos chats. Por defecto es
        // «bandeja» —nadie asignado— y entonces no hay nombre que dar.
        \App\Support\TraspasoAUnAsesor::guardar($company, [
            'estrategia' => \App\Support\TraspasoAUnAsesor::MENOS_CARGADO,
        ]);

        $conversation = WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => '573002457118', 'phone_number' => '573002457118',
            'status' => 'open', 'last_message_at' => now(),
        ]);

        // El webhook guarda el entrante antes de decidir, y de ahí sale la
        // ventana de 24 h: sin él, el job se salta el traspaso por creer que
        // Meta ya no deja escribir.
        \App\Models\WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.IN1',
            'direction' => 'inbound', 'type' => 'text',
            'content' => 'Okey comunícame con un asesor',
            'status' => 'delivered', 'sent_at' => now(),
        ]);

        app(\App\Services\WhatsAppMenuService::class)->handleInbound(
            $instance,
            $conversation,
            ['type' => 'text', 'content' => 'Okey comunícame con un asesor'],
            'wamid.IN1'
        );

        $this->assertSame(
            $asesora->id,
            (int) $conversation->refresh()->assigned_to,
            'El chat queda asignado sin pasar por el modelo.'
        );

        $textos = collect(Http::recorded())
            ->map(fn ($par) => $par[0]->data()['text']['body'] ?? '')
            ->filter()
            ->all();

        $this->assertTrue(
            collect($textos)->contains(fn ($t) => str_contains($t, 'Laura Restrepo')),
            'Y el cliente sabe con quién queda, por su nombre.'
        );
    }
}
