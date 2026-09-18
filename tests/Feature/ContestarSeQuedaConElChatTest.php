<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\QuienAtiende;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quien contesta se queda con el chat.
 *
 * Una conversación sin dueño la responde cualquiera, y eso está bien hasta que
 * alguien contesta: a partir de ahí tiene dueño aunque la ficha siga diciendo
 * «sin asignar». El resultado es el de siempre —dos asesores escribiéndole al
 * mismo cliente porque los dos la vieron libre— y un chat que no aparece en la
 * bandeja de nadie.
 *
 * Antes había que pulsar «Atenderla yo» ANTES de escribir, y un botón que hay
 * que recordar es un botón que no existe.
 */
class ContestarSeQuedaConElChatTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.x']]], 200));

        $company = Company::create(['name' => 'Fibra', 'slug' => 'fibra-'.uniqid(), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea', 'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392', 'type' => 'meta',
            'access_token' => 'token', 'active' => true,
        ]);

        $this->luis = $this->agente($company->id, 'Luis');
    }

    /** Contestar por el endpoint real deja el chat asignado a quien escribió. */
    public function test_al_contestar_la_conversacion_queda_asignada(): void
    {
        $conversation = $this->conversacion();

        $this->actingAs($this->luis)
            ->postJson("/api/chat/conversations/{$conversation->id}/send", [
                'message' => 'hola buenas tardes, en qué le podemos colaborar?',
            ])
            ->assertSuccessful();

        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to);
    }

    /**
     * Pero no se la quita a quien ya la lleva.
     *
     * Un supervisor que entra a aclarar algo no debería robarle el chat al
     * asesor que lo tiene. Es la misma regla de «Atenderla yo».
     *
     * @test
     */
    public function contestar_no_le_roba_el_chat_a_nadie(): void
    {
        $ana = $this->agente($this->instance->company_id, 'Ana');
        $conversation = $this->conversacion(['assigned_to' => $ana->id]);

        $this->actingAs($this->luis)
            ->postJson("/api/chat/conversations/{$conversation->id}/send", ['message' => 'una nota rápida'])
            ->assertSuccessful();

        $this->assertSame($ana->id, $conversation->refresh()->assigned_to, 'El chat sigue siendo de Ana.');
    }

    /**
     * Y el bot no se queda con ningún chat.
     *
     * Sus envíos no traen `sent_by`. Un chat asignado al bot sería además un
     * chat que se calla, porque el bot no le habla encima a un asignado.
     *
     * @test
     */
    public function el_bot_no_reclama_la_conversacion(): void
    {
        $conversation = $this->conversacion();

        $this->assertFalse(QuienAtiende::seQuedaConElChat($conversation, null));
        $this->assertNull($conversation->refresh()->assigned_to);
    }

    /**
     * Dos que contestan a la vez: se la queda uno solo.
     *
     * Los dos leen `assigned_to` a null antes de escribir. Sin el reclamo
     * atómico, el segundo pisaría al primero y la bandeja diría una cosa
     * distinta a lo que vio cada uno.
     *
     * @test
     */
    public function dos_a_la_vez_no_se_pisan(): void
    {
        $ana = $this->agente($this->instance->company_id, 'Ana');
        $conversation = $this->conversacion();

        // La misma fila leída dos veces, las dos con `assigned_to` a null.
        $comoLaVioLuis = WhatsAppConversation::find($conversation->id);
        $comoLaVioAna = WhatsAppConversation::find($conversation->id);

        $this->assertTrue(QuienAtiende::seQuedaConElChat($comoLaVioLuis, $this->luis));
        $this->assertFalse(QuienAtiende::seQuedaConElChat($comoLaVioAna, $ana));

        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to);
    }

    /** Una nota interna no es hablarle al cliente: no reclama nada. */
    public function test_la_nota_interna_no_reclama_el_chat(): void
    {
        $conversation = $this->conversacion();

        $this->actingAs($this->luis)
            ->postJson("/api/chat/conversations/{$conversation->id}/note", ['content' => 'Le devuelvo la llamada'])
            ->assertSuccessful();

        $this->assertNull($conversation->refresh()->assigned_to, 'Una nota no toma el chat.');
    }

    /**
     * Una conversación con la ventana de 24 h abierta.
     *
     * El «Hola» del cliente no es decorado: sin un entrante reciente la Cloud
     * API no acepta texto libre y el endpoint responde 422 antes de llegar a
     * crear nada.
     */
    private function conversacion(array $extra = []): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::create(array_merge([
            'instance_id' => $this->instance->id,
            'wa_id' => '573228150386',
            'phone_number' => '573228150386',
            'name' => 'M',
            'status' => 'open',
            'last_message_at' => now(),
        ], $extra));

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.'.Str::uuid(),
            'type' => 'text',
            'content' => 'Hola',
            'direction' => 'inbound',
            'status' => 'received',
            'sent_at' => now()->subMinutes(9),
        ]);

        return $conversation;
    }

    private function agente(int $companyId, string $nombre): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => $nombre,
            'email' => Str::uuid().'@x.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);
    }
}
