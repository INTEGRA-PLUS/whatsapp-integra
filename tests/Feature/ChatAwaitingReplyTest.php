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
 * El campo `awaiting_reply` que la lista del chat usa para pintar el borde de
 * "esperando respuesta".
 *
 * Es la misma definición que la carpeta «Desatendidas»: una conversación abierta
 * cuyo último mensaje real (no interno) es del cliente. Lo que hay que asegurar
 * es que NO se encienda en los casos en los que nadie está esperando —el agente
 * ya contestó, el chat está cerrado—, porque un borde que grita de más es tan
 * inútil como una campana que grita de más.
 */
class ChatAwaitingReplyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true]);

        $this->agente = User::create([
            'company_id' => $this->company->id,
            'name' => 'Agente',
            'email' => 'agente@fibra.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'agent',
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

    private function conversacion(string $status = 'open', array $attributes = []): WhatsAppConversation
    {
        return WhatsAppConversation::create(array_merge([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081'.Str::random(3),
            'phone_number' => '573007852081',
            'name' => 'Cliente',
            'status' => $status,
            'last_message' => 'Hola',
            'last_message_at' => now()->subMinutes(45),
        ], $attributes));
    }

    private function mensaje(WhatsAppConversation $conv, string $direction, array $attributes = []): void
    {
        WhatsAppMessage::create(array_merge([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.Str::random(10),
            'type' => 'text',
            'content' => 'Hola',
            'direction' => $direction,
            'status' => 'sent',
            'is_internal' => false,
            'sent_at' => now()->subMinutes(45),
        ], $attributes));
    }

    private function pedirLista(): array
    {
        return $this->actingAs($this->agente)
            ->getJson('/api/chat/conversations?instance_id='.$this->instance->id)
            ->assertOk()
            ->json('data');
    }

    private function awaitingDe(array $data, int $conversationId): ?bool
    {
        foreach ($data as $fila) {
            if ($fila['id'] === $conversationId) {
                return $fila['awaiting_reply'] ?? null;
            }
        }

        return null;
    }

    public function test_marca_esperando_cuando_el_ultimo_mensaje_es_del_cliente(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'inbound');

        $this->assertTrue($this->awaitingDe($this->pedirLista(), $conv->id));
    }

    public function test_no_marca_si_el_agente_ya_contesto(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'inbound', ['sent_at' => now()->subMinutes(50)]);
        $this->mensaje($conv, 'outbound', ['sent_at' => now()->subMinutes(40)]);

        $this->assertFalse($this->awaitingDe($this->pedirLista(), $conv->id));
    }

    /** Una nota interna no la ve el cliente: no cuenta como respuesta. */
    public function test_una_nota_interna_no_cuenta_como_respuesta(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'inbound', ['sent_at' => now()->subMinutes(50)]);
        $this->mensaje($conv, 'outbound', ['is_internal' => true, 'sent_at' => now()->subMinutes(40)]);

        $this->assertTrue($this->awaitingDe($this->pedirLista(), $conv->id));
    }

    /**
     * Un aviso de sistema tampoco cuenta como respuesta.
     *
     * Son las pastillas del hilo —«conversación reabierta», «cerrada»— y
     * `ConversationNotice` las graba con `direction = 'internal'` pero con
     * `is_internal` en **false** a propósito. Filtrando sólo por `is_internal`,
     * como se hacía, un aviso quedaba de "último mensaje" y apagaba el borde de
     * una conversación en la que el cliente seguía esperando. En producción son
     * 14.603 mensajes así, frente a 367 notas privadas de verdad.
     */
    public function test_un_aviso_de_sistema_no_cuenta_como_respuesta(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'inbound', ['sent_at' => now()->subMinutes(50)]);
        $this->mensaje($conv, 'internal', [
            'type' => 'system',
            'content' => 'Conversación reabierta: el cliente volvió a escribir',
            'is_internal' => false,
            'sent_at' => now()->subMinutes(40),
        ]);

        $this->assertTrue($this->awaitingDe($this->pedirLista(), $conv->id));
    }

    /** Y el estado de los chulitos tampoco se lee de un aviso de sistema. */
    public function test_el_estado_ignora_los_avisos_de_sistema(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'outbound', ['status' => 'read', 'sent_at' => now()->subMinutes(50)]);
        $this->mensaje($conv, 'internal', [
            'type' => 'system',
            'content' => 'Conversación cerrada',
            'is_internal' => false,
            'sent_at' => now()->subMinutes(40),
        ]);

        $this->assertSame('read', $this->campoDe($this->pedirLista(), $conv->id, 'last_message_status'));
    }

    /** Un chat cerrado no espera respuesta aunque el último mensaje sea del cliente. */
    public function test_un_chat_cerrado_no_espera(): void
    {
        $conv = $this->conversacion('closed');
        $this->mensaje($conv, 'inbound');

        $this->assertFalse($this->awaitingDe($this->pedirLista(), $conv->id));
    }

    /**
     * Los chulitos de la lista son los del último mensaje, no un adorno.
     *
     * Iban con `status="read"` escrito a mano en el JSX y pintados sólo en la
     * fila seleccionada: la lista enseñaba el doble chulito azul —leído— sobre
     * un mensaje que en la conversación aparecía como recién enviado. Es el
     * dato por el que se abre esa pantalla, así que enseñarlo mal es peor que
     * no enseñarlo.
     */
    public function test_el_estado_de_la_lista_es_el_del_ultimo_mensaje(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'outbound', ['status' => 'sent']);

        $this->assertSame('sent', $this->campoDe($this->pedirLista(), $conv->id, 'last_message_status'));
    }

    /** Y sigue al mensaje: si Meta confirma la lectura, la lista lo dice. */
    public function test_el_estado_sigue_al_mensaje(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'outbound', ['status' => 'read']);

        $this->assertSame('read', $this->campoDe($this->pedirLista(), $conv->id, 'last_message_status'));
    }

    /**
     * Sobre un mensaje del cliente no se pinta nada: en WhatsApp los chulitos
     * son de quien envía, y sobre lo que llega no significarían nada.
     */
    public function test_sin_estado_cuando_el_ultimo_mensaje_es_del_cliente(): void
    {
        $conv = $this->conversacion();
        $this->mensaje($conv, 'inbound', ['status' => 'read']);

        $this->assertNull($this->campoDe($this->pedirLista(), $conv->id, 'last_message_status'));
    }

    private function campoDe(array $data, int $conversationId, string $campo): mixed
    {
        foreach ($data as $fila) {
            if ($fila['id'] === $conversationId) {
                return $fila[$campo] ?? null;
            }
        }

        return null;
    }
}
