<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que el panel del tablero (/kanban) necesita de la respuesta de un envío.
 *
 * 2026-10-06: al contestar desde el panel no aparecía lo escrito; en su lugar
 * salía una burbuja «archivo · Documento · Invalid Date» y ningún error. El
 * panel leía `message`, que es el texto «Mensaje encolado», como si fuera el
 * mensaje guardado, que viene en `data`. Como la respuesta era 200, nadie se
 * enteraba.
 *
 * No hay runner de JavaScript en el proyecto, así que el contrato se fija aquí:
 * si alguien cambia la forma de la respuesta, o el panel vuelve a leer la
 * clave equivocada, esto se pone en rojo.
 */
class PanelDelTableroEnviaTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.x']]], 200));
        Storage::fake('s3_media');

        $company = Company::create(['name' => 'Fibra', 'slug' => 'fibra-'.uniqid(), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea', 'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392', 'type' => 'meta',
            'access_token' => 'token', 'active' => true,
        ]);

        $this->agente = User::create([
            'company_id' => $company->id,
            'name' => 'Luis',
            'email' => Str::uuid().'@x.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);
    }

    /** El texto enviado vuelve en `data`, con lo que la burbuja necesita para pintarse. */
    public function test_el_texto_enviado_vuelve_en_data_con_fecha_y_tipo(): void
    {
        $conversation = $this->conversacion();

        $respuesta = $this->actingAs($this->agente)
            ->postJson("/api/chat/conversations/{$conversation->id}/send", ['message' => 'hola doña Danny'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.content', 'hola doña Danny')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.conversation_id', $conversation->id);

        // `message` es un aviso, no el mensaje: tratarlo como tal fue el fallo.
        $this->assertIsString($respuesta->json('message'));
        $this->assertNotNull($respuesta->json('data.id'));
        $this->assertNotFalse(
            strtotime((string) $respuesta->json('data.sent_at')),
            'Sin una fecha válida el panel pinta «Invalid Date».'
        );

        $this->assertDatabaseHas('whatsapp_messages', [
            'id' => $respuesta->json('data.id'),
            'content' => 'hola doña Danny',
            'direction' => 'outbound',
        ]);
    }

    /** Lo mismo para un documento: el nombre del archivo viene en `data`. */
    public function test_el_documento_enviado_vuelve_en_data_con_su_nombre(): void
    {
        $conversation = $this->conversacion();

        $respuesta = $this->actingAs($this->agente)
            ->post("/api/chat/conversations/{$conversation->id}/send-document", [
                'document' => UploadedFile::fake()->create('Recibo_15979.pdf', 40, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.type', 'document')
            ->assertJsonPath('data.direction', 'outbound');

        $this->assertIsString($respuesta->json('message'));
        $this->assertNotFalse(strtotime((string) $respuesta->json('data.sent_at')));
    }

    /** Con la ventana cerrada no se crea nada y el panel recibe un error que enseñar. */
    public function test_con_la_ventana_cerrada_responde_un_error_legible(): void
    {
        $conversation = $this->conversacion(haceHoras: 30);

        $this->actingAs($this->agente)
            ->postJson("/api/chat/conversations/{$conversation->id}/send", ['message' => 'hola'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'window_closed')
            ->assertJsonStructure(['error']);

        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    /**
     * El panel lee `data`, no `message`.
     *
     * Es una comprobación sobre el código fuente porque el proyecto no tiene
     * runner de JavaScript. Burda, pero es exactamente el error que se coló.
     */
    public function test_el_panel_lee_la_clave_data_de_la_respuesta(): void
    {
        $panel = file_get_contents(resource_path('js/pages/Chat/PanelConversacion.jsx'));

        $this->assertStringNotContainsString('[...prev, datos.message]', $panel);
        $this->assertSame(2, substr_count($panel, '[...prev, datos.data]'), 'Texto y archivos deben leer `data`.');
    }

    private function conversacion(int $haceHoras = 0): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573102517949',
            'phone_number' => '573102517949',
            'name' => 'Danny Palacios',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        // Sin un entrante reciente la ventana de 24 h está cerrada y el envío
        // se rechaza antes de crear nada.
        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.'.Str::uuid(),
            'type' => 'text',
            'content' => 'Más luego estoy ocupada',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now()->subHours($haceHoras)->subMinutes(9),
        ]);

        return $conversation;
    }
}
