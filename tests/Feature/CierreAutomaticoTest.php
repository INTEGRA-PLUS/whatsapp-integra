<?php

namespace Tests\Feature;

use App\Extensions\CierreAutomaticoExtension;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\SeDespide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cierra solas las conversaciones que ya terminaron.
 *
 * Una bandeja de WhatsApp se llena de hilos que acabaron hace días y que nadie
 * cerró, y entonces «9 abiertas» deja de significar nada — ni para el equipo ni
 * para el reparto por carga, que cuenta chats abiertos para decidir a quién le
 * toca el siguiente.
 */
class CierreAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private CompanyExtension $extension;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(8)]]], 200));

        $company = Company::create(['name' => 'Cootramed', 'slug' => 'coop-'.uniqid(), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $company->id, 'uuid' => (string) Str::uuid(),
            'name' => 'Línea', 'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392', 'type' => 'meta',
            'access_token' => 'token', 'active' => true,
        ]);

        $this->extension = CompanyExtension::create([
            'company_id' => $company->id,
            'slug' => 'cierre_automatico',
            'enabled' => true,
            'settings' => [
                'minutos_para_preguntar' => 30,
                'pregunta' => '¿Necesitas algo más, {name}?',
                'minutos_para_cerrar' => 30,
                'mensaje_de_cierre' => 'Cierro por ahora. 👋',
                'cerrar_si_se_despide' => true,
                'incluir_chats_con_asesor' => false,
            ],
        ]);
    }

    /** @test */
    public function a_una_conversacion_parada_se_le_pregunta(): void
    {
        $conv = $this->conversacionParada(45);

        (new CierreAutomaticoExtension)->runScheduled($this->extension);

        $this->assertStringContainsString('¿Necesitas algo más', $this->ultimoTextoEnviado());
        $this->assertSame('open', $conv->refresh()->status, 'Preguntar no cierra: primero se espera.');
    }

    /** Y una que lleva poco tiempo parada no se toca. */
    public function test_una_conversacion_reciente_no_se_toca(): void
    {
        $conv = $this->conversacionParada(5);

        (new CierreAutomaticoExtension)->runScheduled($this->extension);

        $this->assertNull($this->ultimoTextoEnviado());
        $this->assertSame('open', $conv->refresh()->status);
    }

    /**
     * Si no contesta a la pregunta, se cierra.
     *
     * @test
     */
    public function sin_respuesta_a_la_pregunta_se_cierra(): void
    {
        $conv = $this->conversacionParada(45);

        // Primera pasada: pregunta.
        (new CierreAutomaticoExtension)->runScheduled($this->extension);

        // Pasa el tiempo de espera sin que el cliente conteste.
        $this->envejecerTodo(60);

        (new CierreAutomaticoExtension)->runScheduled($this->extension);

        $this->assertSame('closed', $conv->refresh()->status);
        $this->assertNull($conv->closed_by, 'No lo cerró nadie del equipo, y decir lo contrario haría creer que alguien lo revisó.');
    }

    /**
     * Pero si contesta, la pregunta queda respondida y no se cierra por ella.
     *
     * @test
     */
    public function si_contesta_no_se_cierra(): void
    {
        $conv = $this->conversacionParada(45);

        (new CierreAutomaticoExtension)->runScheduled($this->extension);

        $this->envejecerTodo(60);

        // El cliente vuelve: la conversación está viva otra vez.
        WhatsAppMessage::create([
            'conversation_id' => $conv->id, 'wamid' => 'wamid.VUELVE',
            'direction' => 'inbound', 'type' => 'text', 'content' => 'Sí, una cosa más',
            'status' => 'delivered', 'sent_at' => now(),
        ]);

        (new CierreAutomaticoExtension)->runScheduled($this->extension);

        $this->assertSame('open', $conv->refresh()->status);
    }

    /** @test */
    public function despedirse_cierra_sin_esperar(): void
    {
        $conv = $this->conversacionParada(1);

        $mensaje = WhatsAppMessage::create([
            'conversation_id' => $conv->id, 'wamid' => 'wamid.ADIOS',
            'direction' => 'inbound', 'type' => 'text', 'content' => 'No, muchas gracias',
            'status' => 'delivered', 'sent_at' => now(),
        ]);

        (new CierreAutomaticoExtension)->onInboundMessage($conv, $mensaje, $this->extension);

        $this->assertSame('closed', $conv->refresh()->status);
        $this->assertStringContainsString('Cierro por ahora', $this->ultimoTextoEnviado());
    }

    /**
     * Un chat que ya lleva un asesor no se toca.
     *
     * Alguien a mitad de gestión no necesita que un robot le pregunte a su
     * cliente si quiere algo más, ni que le cierre el hilo mientras busca un
     * dato.
     *
     * @test
     */
    public function no_toca_los_chats_que_lleva_una_persona(): void
    {
        $asesor = User::create([
            'company_id' => $this->instance->company_id, 'name' => 'Asesor',
            'email' => 'asesor-cierre@x.test', 'password' => 'secret', 'active' => true,
        ]);

        $conv = $this->conversacionParada(120);
        $conv->update(['assigned_to' => $asesor->id]);

        (new CierreAutomaticoExtension)->runScheduled($this->extension);

        $this->assertNull($this->ultimoTextoEnviado());
        $this->assertSame('open', $conv->refresh()->status);
    }

    /** Una pregunta nunca cierra, por muchas gracias que lleve delante. */
    public function test_una_pregunta_no_es_una_despedida(): void
    {
        $this->assertFalse(SeDespide::loDice('gracias, ¿y el horario del sábado?'));
        $this->assertFalse(SeDespide::loDice('gracias'));
        $this->assertTrue(SeDespide::loDice('No, muchas gracias'));
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function conversacionParada(int $minutos): WhatsAppConversation
    {
        $conv = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '5730024571'.rand(10, 99),
            'phone_number' => '573002457118',
            'name' => 'Johan',
            'status' => 'open',
            'last_message_at' => now()->subMinutes($minutos),
        ]);

        // La ventana de 24 h se mide con el último entrante: sin él, el envío
        // se salta por creer que Meta ya no deja escribir.
        WhatsAppMessage::create([
            'conversation_id' => $conv->id, 'wamid' => 'wamid.IN'.Str::random(6),
            'direction' => 'inbound', 'type' => 'text', 'content' => 'Gracias por la info',
            'status' => 'delivered', 'sent_at' => now()->subMinutes($minutos),
        ]);

        return $conv;
    }

    /** Retrasa lo ya ocurrido, para simular que pasó el tiempo. */
    private function envejecerTodo(int $minutos): void
    {
        WhatsAppMessage::all()->each(fn (WhatsAppMessage $m) => $m->forceFill([
            'sent_at' => $m->sent_at?->subMinutes($minutos),
            'created_at' => $m->created_at?->subMinutes($minutos),
        ])->saveQuietly());

        WhatsAppConversation::all()->each(fn (WhatsAppConversation $c) => $c->forceFill([
            'last_message_at' => $c->last_message_at?->subMinutes($minutos),
            'cierre_preguntado_at' => $c->cierre_preguntado_at?->subMinutes($minutos),
        ])->saveQuietly());
    }

    private function ultimoTextoEnviado(): ?string
    {
        $textos = [];

        foreach (Http::recorded() as [$request]) {
            $body = $request->data();

            if (($body['type'] ?? null) === 'text') {
                $textos[] = $body['text']['body'];
            }
        }

        return $textos ? end($textos) : null;
    }
}
