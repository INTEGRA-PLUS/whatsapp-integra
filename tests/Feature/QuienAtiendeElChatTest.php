<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quién viene atendiendo cada chat, en la lista de conversaciones.
 *
 * «¿Esto lo lleva la IA o mi menú?» no tenía respuesta en ninguna pantalla:
 * había que abrir el chat y mirar burbuja por burbuja. Y la distinción importa
 * para arreglar lo que salga mal — si contestó raro la IA se revisa su
 * documentación; si lo dijo el menú, se edita una opción.
 */
class QuienAtiendeElChatTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Cootramed', 'slug' => 'coop-'.uniqid(), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea', 'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392', 'type' => 'meta',
            'access_token' => 'token', 'active' => true,
        ]);

        $this->user = User::create([
            'company_id' => $company->id, 'name' => 'Admin',
            'email' => 'admin-atiende@x.test', 'password' => 'secret', 'active' => true,
        ]);
    }

    /** @test */
    public function el_ultimo_saliente_dice_quien_atiende(): void
    {
        $deLaIa = $this->conversacionCon('573001', ['ia' => ['flujo' => 'chat']]);
        $delMenu = $this->conversacionCon('573002', ['menu_id' => 7]);
        $aSecas = $this->conversacionCon('573003', []);

        $filas = $this->filas();

        $this->assertSame('ia', $filas[$deLaIa->id]);
        $this->assertSame('menu', $filas[$delMenu->id]);
        $this->assertNull($filas[$aSecas->id], 'Un mensaje de una persona no atribuye a ningún bot.');
    }

    /**
     * Con un asesor asignado manda la persona, aunque la última burbuja fuera
     * de la IA: con el chat asignado el bot se calla.
     *
     * @test
     */
    public function con_asesor_asignado_atiende_la_persona(): void
    {
        $conv = $this->conversacionCon('573004', ['ia' => ['flujo' => 'chat']]);
        $conv->update(['assigned_to' => $this->user->id]);

        $this->assertSame('persona', $this->filas()[$conv->id]);
    }

    /** @return array<int, ?string> */
    private function filas(): array
    {
        $respuesta = $this->actingAs($this->user)
            ->getJson('/api/chat/conversations?instance_id='.$this->instance->id)
            ->assertOk()
            ->json('data');

        return collect($respuesta)->pluck('atendido_por', 'id')->all();
    }

    private function conversacionCon(string $telefono, array $metadata): WhatsAppConversation
    {
        $conv = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => $telefono, 'phone_number' => $telefono,
            'status' => 'open', 'last_message_at' => now(),
        ]);

        WhatsAppMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.$telefono,
            'direction' => 'outbound', 'type' => 'text',
            'content' => 'Lo que sea', 'status' => 'sent', 'sent_at' => now(),
            'metadata' => $metadata,
        ]);

        return $conv;
    }
}
