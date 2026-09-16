<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extensión «Resumen de conversación con IA».
 *
 * Lo que se protege aquí, por orden de gravedad:
 *
 * - **Que no se resuma la conversación de otra empresa.** Es la más importante
 *   con diferencia: `whatsapp_conversations` no tiene `company_id`, el
 *   aislamiento es un `whereIn` escrito a mano, y aquí olvidarlo no devuelve
 *   datos de más en una lista — se los manda a un servicio externo.
 * - Que no se gaste una inferencia en reescribir el mismo resumen.
 * - Que un fallo de la IA no deje la conversación con medio resumen guardado.
 * - Que sin la extensión encendida no pase nada.
 */
class ExtensionResumenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.resumen.webhook_url', 'https://n8n.test/resumen');
        config()->set('services.resumen.api_key', 'clave');

        $this->company = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true, 'ia' => 'completa']);

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
            'slug' => 'conversation_summary',
            'enabled' => true,
            'settings' => array_merge(['mensajes' => 40, 'tono' => 'telegrama', 'minimo' => 8], $settings),
            'installed_by' => $this->admin->id,
            'installed_at' => now(),
        ]);
    }

    private function conversacionCon(int $mensajes, ?Instance $instance = null): WhatsAppConversation
    {
        $instance ??= $this->instance;

        $conv = WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => '57300'.random_int(1000000, 9999999),
            'name' => 'Cliente',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        for ($i = 0; $i < $mensajes; $i++) {
            WhatsAppMessage::create([
                'conversation_id' => $conv->id,
                'wamid' => 'wamid.'.Str::random(12),
                'type' => 'text',
                'content' => 'Mensaje '.$i,
                'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                'status' => 'delivered',
                'sent_at' => now()->subMinutes($mensajes - $i),
            ]);
        }

        return $conv->refresh();
    }

    private function fakeIa(?array $cuerpo = null): void
    {
        Http::fake(['n8n.test/*' => Http::response($cuerpo ?? [
            'resumen' => 'El cliente reporta una avería y pide visita técnica.',
            'puntos' => ['Sin servicio desde el martes', 'Ya reinició el router'],
            'pendientes' => ['Agendar la visita'],
        ], 200)]);
    }

    /**
     * El resumen empieza donde empezó la atención de ahora.
     *
     * Un cliente al que se le atendió en marzo, en julio y hoy tiene un hilo de
     * meses. Resumirlo entero devuelve un resumen de cosas ya resueltas, y
     * obliga a leer conversaciones viejas para encontrar la de ahora: lo que se
     * necesita al abrir el chat es qué está pasando esta vez.
     */
    public function test_resume_solo_desde_el_ultimo_cierre(): void
    {
        $this->instalar();
        $this->fakeIa();

        $conv = $this->conversacionCon(6);
        $this->cierre($conv);
        $nuevos = $this->mensajes($conv, 2, 'Lo de ahora');

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertOk()
            ->assertJsonPath('mensajes', 2)
            ->assertJsonPath('desde_la_reapertura', true);

        $enviados = $this->mensajesEnviadosALaIa();

        $this->assertCount(2, $enviados, 'Sólo los de después del cierre.');
        $this->assertStringContainsString('Lo de ahora', json_encode($enviados));
        $this->assertStringNotContainsString('Mensaje 0', json_encode($enviados));
        $this->assertNotEmpty($nuevos);
    }

    /** Sin cierres de por medio se resume el hilo entero, como siempre. */
    public function test_sin_cierres_resume_toda_la_conversacion(): void
    {
        $this->instalar();
        $this->fakeIa();

        $conv = $this->conversacionCon(5);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertOk()
            ->assertJsonPath('mensajes', 5)
            ->assertJsonPath('desde_la_reapertura', false);
    }

    /**
     * Un hilo cerrado ahora mismo se resume igual: el corte es el cierre
     * anterior, no el de arriba del todo.
     *
     * Es justo cuando más falta hace leerlo de un vistazo, y cortando por el
     * último cierre no habría quedado nada que resumir.
     */
    public function test_una_conversacion_cerrada_resume_el_ciclo_que_acaba_de_terminar(): void
    {
        $this->instalar();
        $this->fakeIa();

        $conv = $this->conversacionCon(4);
        $this->cierre($conv);
        $this->mensajes($conv, 3, 'La segunda vez');
        $this->cierre($conv);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertOk()
            ->assertJsonPath('mensajes', 3);
    }

    /** Los avisos del hilo no son parte de la charla y no se le mandan al modelo. */
    public function test_los_avisos_de_sistema_no_entran_en_el_resumen(): void
    {
        $this->instalar();
        $this->fakeIa();

        $conv = $this->conversacionCon(3);
        WhatsAppMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'system',
            'content' => 'Conversación reabierta: el cliente volvió a escribir',
            'direction' => 'internal',
            'is_internal' => false,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertOk()
            ->assertJsonPath('mensajes', 3);

        $this->assertStringNotContainsString(
            'Conversación reabierta',
            json_encode($this->mensajesEnviadosALaIa())
        );
    }

    /** Un cierre en el hilo, con el marcador que pone ConversationNotice. */
    private function cierre(WhatsAppConversation $conv): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'system',
            'content' => 'Conversación cerrada por Yohan',
            'direction' => 'internal',
            'is_internal' => false,
            'status' => 'sent',
            'metadata' => ['evento' => 'cierre'],
            'sent_at' => now(),
        ]);
    }

    /** @return list<WhatsAppMessage> */
    private function mensajes(WhatsAppConversation $conv, int $cuantos, string $texto): array
    {
        $creados = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $creados[] = WhatsAppMessage::create([
                'conversation_id' => $conv->id,
                'wamid' => 'wamid.'.Str::random(12),
                'type' => 'text',
                'content' => $texto.' '.$i,
                'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                'status' => 'delivered',
                'sent_at' => now(),
            ]);
        }

        return $creados;
    }

    /** Lo que de verdad viajó al modelo, leído de la petición capturada. */
    private function mensajesEnviadosALaIa(): array
    {
        $enviados = [];

        Http::assertSent(function ($request) use (&$enviados) {
            $cuerpo = $request->data();
            $enviados = $cuerpo['mensajes'] ?? $cuerpo['messages'] ?? [];

            return true;
        });

        return $enviados;
    }

    public function test_resume_una_conversacion_y_la_guarda(): void
    {
        $this->instalar();
        $this->fakeIa();
        $conv = $this->conversacionCon(10);

        $res = $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen");

        $res->assertOk()
            ->assertJsonPath('resumen', 'El cliente reporta una avería y pide visita técnica.')
            ->assertJsonPath('puntos.0', 'Sin servicio desde el martes')
            ->assertJsonPath('pendientes.0', 'Agendar la visita')
            ->assertJsonPath('cacheado', false);

        $conv->refresh();
        $this->assertNotNull($conv->summary);
        $this->assertSame($this->admin->id, $conv->summary_by);
        $this->assertSame(
            WhatsAppMessage::where('conversation_id', $conv->id)->max('id'),
            (int) $conv->summary_until_message_id
        );
    }

    /**
     * La razón de existir de `summary_until_message_id`: sin esto, abrir el
     * resumen dos veces cuesta dos inferencias y nadie lo abre dos veces.
     */
    public function test_si_nadie_ha_escrito_no_se_vuelve_a_pedir(): void
    {
        $this->instalar();
        $this->fakeIa();
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)->postJson("/api/chat/conversations/{$conv->id}/resumen")->assertOk();
        $segunda = $this->actingAs($this->admin)->postJson("/api/chat/conversations/{$conv->id}/resumen");

        $segunda->assertOk()->assertJsonPath('cacheado', true);
        Http::assertSentCount(1);
    }

    public function test_un_mensaje_nuevo_lo_deja_viejo_y_se_regenera(): void
    {
        $this->instalar();
        $this->fakeIa();
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)->postJson("/api/chat/conversations/{$conv->id}/resumen")->assertOk();

        WhatsAppMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'text',
            'content' => 'Sigo sin servicio',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertOk()
            ->assertJsonPath('cacheado', false);

        Http::assertSentCount(2);
    }

    /** El botón de «rehacer»: fuerza aunque esté al día. */
    public function test_refrescar_lo_pide_otra_vez(): void
    {
        $this->instalar();
        $this->fakeIa();
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)->postJson("/api/chat/conversations/{$conv->id}/resumen")->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen", ['refrescar' => true])
            ->assertOk()
            ->assertJsonPath('cacheado', false);

        Http::assertSentCount(2);
    }

    /**
     * La que de verdad importa. `whatsapp_conversations` no tiene `company_id`:
     * si el `whereIn` por instancias se cae, esto manda la conversación de otra
     * empresa a un servicio externo.
     */
    public function test_no_resume_la_conversacion_de_otra_empresa(): void
    {
        $this->instalar();
        $this->fakeIa();

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $suInstancia = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Suya',
            'phone_number_id' => '999',
            'waba_id' => '888',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $ajena = $this->conversacionCon(10, $suInstancia);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$ajena->id}/resumen")
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertNull($ajena->refresh()->summary);
    }

    public function test_sin_la_extension_encendida_no_hace_nada(): void
    {
        $this->fakeIa();
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_apagada_tampoco(): void
    {
        $this->instalar()->update(['enabled' => false]);
        $this->fakeIa();
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    /** Si la IA falla, la conversación se queda como estaba. Sin resúmenes a medias. */
    public function test_si_la_ia_falla_no_guarda_nada(): void
    {
        $this->instalar();
        Http::fake(['n8n.test/*' => Http::response(['error' => 'boom'], 500)]);
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertStatus(502);

        $this->assertNull($conv->refresh()->summary);
    }

    /** Una respuesta sin resumen es tan inútil como ninguna respuesta. */
    public function test_una_respuesta_vacia_se_trata_como_fallo(): void
    {
        $this->instalar();
        $this->fakeIa(['resumen' => '   ', 'puntos' => []]);
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertStatus(502);

        $this->assertNull($conv->refresh()->summary);
    }

    /** n8n envuelve en `output` según cómo esté montado el flujo. */
    public function test_acepta_la_respuesta_envuelta_por_n8n(): void
    {
        $this->instalar();
        $this->fakeIa(['output' => [
            'resumen' => 'Resumen envuelto.',
            // Y las listas como texto, que es como las devuelve a veces el modelo.
            'puntos' => "- Uno\n- Dos",
        ]]);
        $conv = $this->conversacionCon(10);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertOk()
            ->assertJsonPath('resumen', 'Resumen envuelto.')
            ->assertJsonPath('puntos.0', 'Uno')
            ->assertJsonPath('puntos.1', 'Dos');
    }

    public function test_solo_manda_los_ultimos_mensajes_que_se_configuren(): void
    {
        $this->instalar(['mensajes' => 10]);
        $this->fakeIa();
        $conv = $this->conversacionCon(30);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$conv->id}/resumen")
            ->assertOk();

        Http::assertSent(function ($request) {
            $mensajes = $request->data()['mensajes'];

            return count($mensajes) === 10
                // Del derecho: el más antiguo de la tanda primero.
                && $mensajes[0]['texto'] === 'Mensaje 20'
                && $mensajes[9]['texto'] === 'Mensaje 29';
        });
    }

    public function test_una_conversacion_sin_mensajes_no_llama_a_la_ia(): void
    {
        $this->instalar();
        $this->fakeIa();

        $vacia = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573001112233',
            'name' => 'Cliente',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/chat/conversations/{$vacia->id}/resumen")
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
