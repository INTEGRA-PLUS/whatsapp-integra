<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Un chat se abre por su tramo final, no entero.
 *
 * Con la coexistencia trayendo hasta seis meses de historial, abrir una
 * conversación vieja significaba miles de filas en cada clic —para leer las
 * últimas, que es donde se abre el chat—. Aquí se prueba que la ventana
 * devuelve lo reciente, que el tramo anterior se puede pedir, y que el corte no
 * se salta el historial importado.
 */
class ChatMessageWindowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.x']]], 200));

        config(['whatsapp.chat.message_window' => 20]);

        $this->company = Company::create([
            'name' => 'Cooperativa',
            'slug' => 'coop-'.Str::random(6),
            'active' => true,
        ]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);

        $this->user = User::create([
            'name' => 'Agente',
            'email' => Str::random(8).'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);
    }

    public function test_abrir_un_chat_trae_solo_el_tramo_final(): void
    {
        $conversation = $this->conversacionCon(50);

        $res = $this->actingAs($this->user)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk();

        $mensajes = $res->json('messages');

        $this->assertCount(20, $mensajes, 'Se trajo el hilo entero en vez de la ventana.');
        $this->assertTrue($res->json('has_more'), 'No avisó de que quedaba historial por detrás.');

        // Lo último escrito es lo último de la lista: el chat se lee de arriba
        // abajo y se abre en el final.
        $this->assertSame('Mensaje 50', end($mensajes)['content']);
        $this->assertSame('Mensaje 31', $mensajes[0]['content']);
    }

    public function test_el_tramo_anterior_se_pide_con_before_id(): void
    {
        $conversation = $this->conversacionCon(50);

        $primera = $this->actingAs($this->user)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->json();

        $segunda = $this->actingAs($this->user)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?before_id={$primera['oldest_id']}")
            ->json();

        $this->assertCount(20, $segunda['messages']);
        $this->assertSame('Mensaje 11', $segunda['messages'][0]['content']);
        $this->assertSame('Mensaje 30', end($segunda['messages'])['content']);

        // Los dos tramos no se solapan ni dejan hueco.
        $ids = array_merge(
            array_column($segunda['messages'], 'id'),
            array_column($primera['messages'], 'id')
        );
        $this->assertSame($ids, array_unique($ids), 'Los dos tramos traen mensajes repetidos.');
    }

    /**
     * El corte va por fecha, no por id.
     *
     * El historial importado entra hoy —ids altos— con la fecha en que se
     * escribió, meses atrás. Con un cursor por id, pedir "lo anterior" se
     * saltaría justo esos mensajes: los que el agente está buscando.
     */
    public function test_el_historial_importado_no_se_pierde_al_pedir_lo_anterior(): void
    {
        $conversation = $this->conversacionCon(20);

        // Llega después (id más alto) pero es de hace tres meses.
        $importado = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.importado',
            'type' => 'text',
            'content' => 'Mensaje viejo importado',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now()->subMonths(3),
        ]);

        $this->fechar($importado->id, now()->subMonths(3));

        $primera = $this->actingAs($this->user)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->json();

        // No está en la ventana reciente pese a tener el id más alto de todos.
        $this->assertNotContains(
            $importado->id,
            array_column($primera['messages'], 'id'),
            'El mensaje importado se coló entre los recientes por tener el id más alto.'
        );

        $this->assertTrue($primera['has_more']);

        $segunda = $this->actingAs($this->user)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?before_id={$primera['oldest_id']}")
            ->json();

        $this->assertContains(
            $importado->id,
            array_column($segunda['messages'], 'id'),
            'Al pedir el tramo anterior se saltó el historial importado.'
        );
    }

    public function test_pedir_lo_anterior_no_marca_el_chat_como_leido(): void
    {
        $conversation = $this->conversacionCon(50);

        $primera = $this->actingAs($this->user)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->json();

        // Por consulta directa y no con `update()` sobre el modelo: la petición
        // anterior ya lo dejó en 0 en la base, pero esta instancia sigue
        // creyendo lo que tenía, y Eloquent no escribe lo que no ve sucio.
        WhatsAppConversation::whereKey($conversation->id)->update(['unread_count' => 7]);

        $this->actingAs($this->user)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?before_id={$primera['oldest_id']}")
            ->assertOk();

        $this->assertSame(
            7,
            $conversation->fresh()->unread_count,
            'Bajar en el historial borró el "no leído" de mensajes que el agente no ha visto.'
        );
    }

    private function conversacionCon(int $cuantos): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Socio',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        for ($i = 1; $i <= $cuantos; $i++) {
            $fecha = now()->subMinutes($cuantos - $i);

            $mensaje = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'wamid' => 'wamid.'.$i,
                'type' => 'text',
                'content' => 'Mensaje '.$i,
                'direction' => $i % 2 === 0 ? 'outbound' : 'inbound',
                'status' => 'delivered',
                'sent_at' => $fecha,
            ]);

            $this->fechar($mensaje->id, $fecha);
        }

        return $conversation;
    }

    /**
     * `created_at` no es asignable en masa, así que pasarlo a `create()` se
     * ignora en silencio y todos los mensajes nacen con la hora actual: la
     * ventana quedaría ordenada por id y estas pruebas no probarían nada.
     */
    private function fechar(int $mensajeId, CarbonInterface $fecha): void
    {
        WhatsAppMessage::whereKey($mensajeId)->update(['created_at' => $fecha]);
    }
}
