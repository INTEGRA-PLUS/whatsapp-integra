<?php

namespace Tests\Feature;

use App\Models\AutoResponse;
use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El bot vuelve a contestarle a un cliente que ya atendió un asesor.
 *
 * 2026-10-06: «no se está disparando ninguna respuesta automática». Desde el
 * 2026-09-18 contestar asigna el chat al asesor, y nada lo desasignaba: ni
 * cerrarlo ni que el cliente volviera días después. Con el chat asignado el
 * menú y la respuesta automática se callan, así que con todo cliente atendido
 * alguna vez el bot no volvía a hablar. Se reprodujo antes de arreglarlo:
 * cerrar dejaba `status=closed, assigned_to=<asesor>` y la regla no salía.
 *
 * Todo pasa por el webhook real, porque es él quien decide reabrir y soltar.
 */
class ElBotVuelveAContestarTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $luis;

    /** @var array<int, string> Textos que llegaron a Meta. */
    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.webhook_app_secrets' => 'secreto']);

        Http::fake(function ($request) {
            $this->enviados[] = $request['text']['body'] ?? '';

            return Http::response(['messages' => [['id' => 'wamid.'.Str::uuid()]]], 200);
        });

        $company = Company::create(['name' => 'Fibra', 'slug' => 'fibra-'.Str::random(4), 'active' => true]);

        // El menú de fábrica se adelanta a la respuesta automática y la
        // sustituye; aquí se prueba la respuesta automática.
        WhatsAppMenu::where('company_id', $company->id)->update(['active' => false]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea', 'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1', 'type' => 'meta',
            'access_token' => 'token', 'active' => true,
        ]);

        $this->luis = User::create([
            'company_id' => $company->id, 'name' => 'Luis', 'email' => Str::uuid().'@x.test',
            'password' => 'secret', 'active' => true, 'role' => 'admin',
        ]);

        AutoResponse::create([
            'company_id' => $company->id, 'name' => 'Precio', 'trigger_text' => 'precio',
            'match_types' => ['contains'], 'response_message' => 'El plan vale 50.000', 'active' => true,
        ]);
    }

    /** El caso del reporte: atendido, cerrado, y el cliente vuelve. */
    public function test_al_reabrir_un_chat_cerrado_se_suelta_y_responde(): void
    {
        $conversation = $this->atendidaPorLuis();

        $this->actingAs($this->luis)
            ->postJson("/api/chat/conversations/{$conversation->id}/close")
            ->assertOk();
        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to);

        $this->travel(2)->hours();
        $this->entrante('quiero saber el precio del plan')->assertOk();

        $conversation->refresh();
        $this->assertSame('open', $conversation->status);
        $this->assertNull($conversation->assigned_to, 'Reabrir debía soltar al asesor.');
        $this->assertContains('El plan vale 50.000', $this->enviados);

        $aviso = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('metadata->evento', 'liberada')
            ->first();
        $this->assertNotNull($aviso, 'El hilo debía contar por qué el chat dejó de ser de Luis.');
        $this->assertStringContainsString('Luis', $aviso->content);
    }

    /** Sin cerrar: 1 h sin que el asesor le escriba y el chat se suelta. */
    public function test_tras_una_hora_sin_que_el_asesor_escriba_se_suelta(): void
    {
        $conversation = $this->atendidaPorLuis();

        $this->travel(61)->minutes();
        $this->entrante('y el precio?')->assertOk();

        $this->assertNull($conversation->refresh()->assigned_to);
        $this->assertNull($conversation->assigned_at);
        $this->assertContains('El plan vale 50.000', $this->enviados);
    }

    /** Con el asesor contestando dentro de la hora, lo conserva y el bot calla. */
    public function test_en_plena_conversacion_el_asesor_la_conserva(): void
    {
        $conversation = $this->atendidaPorLuis();

        $this->travel(40)->minutes();
        $this->entrante('y el precio?')->assertOk();

        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to);
        $this->assertNotContains('El plan vale 50.000', $this->enviados, 'El bot no le habla encima al asesor.');
    }

    /** Lo que escribe el cliente no para el reloj: insistir sin respuesta es el caso. */
    public function test_el_cliente_insistiendo_no_le_da_mas_tiempo_al_asesor(): void
    {
        $conversation = $this->atendidaPorLuis();

        $this->travel(40)->minutes();
        $this->entrante('hola?')->assertOk();
        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to);

        $this->travel(30)->minutes();
        $this->entrante('me dicen el precio por favor')->assertOk();

        $this->assertNull($conversation->refresh()->assigned_to, '70 min sin que Luis escriba.');
        $this->assertContains('El plan vale 50.000', $this->enviados);
    }

    /** Un administrador que le escribe al cliente también cuenta. */
    public function test_si_le_escribe_un_administrador_el_chat_sigue_con_el_asesor(): void
    {
        $conversation = $this->atendidaPorLuis();
        $marta = $this->administradora();

        $this->travel(50)->minutes();
        $this->actingAs($marta)
            ->postJson("/api/chat/conversations/{$conversation->id}/send", ['message' => 'Ya lo revisamos'])
            ->assertOk();

        $this->travel(30)->minutes();
        $this->entrante('y el precio?')->assertOk();

        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to, 'Marta escribió hace 30 min.');
    }

    /** Quien acaba de tomar el chat aún no ha escrito, y no se le quita por eso. */
    public function test_a_quien_acaba_de_tomarlo_no_se_le_quita(): void
    {
        $this->entrante('hola')->assertOk();
        $conversation = WhatsAppConversation::where('instance_id', $this->instance->id)->firstOrFail();

        $this->travel(3)->hours();
        $this->actingAs($this->luis)
            ->postJson("/api/chat/conversations/{$conversation->id}/assign-me")
            ->assertOk();
        $this->assertNotNull($conversation->refresh()->assigned_at);

        $this->travel(5)->minutes();
        $this->entrante('el precio?')->assertOk();

        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to);
    }

    /** Las asignaciones de antes de `assigned_at` se miden solo por lo escrito. */
    public function test_una_asignacion_antigua_sin_mensajes_se_suelta(): void
    {
        $this->entrante('hola')->assertOk();
        $conversation = WhatsAppConversation::where('instance_id', $this->instance->id)->firstOrFail();
        WhatsAppConversation::where('id', $conversation->id)
            ->update(['assigned_to' => $this->luis->id, 'assigned_at' => null]);

        $this->travel(5)->minutes();
        $this->entrante('el precio?')->assertOk();

        $this->assertNull($conversation->refresh()->assigned_to);
    }

    /** El saludo al reabrir ya no se pierde por el aviso interno de reapertura. */
    public function test_el_saludo_al_reabrir_sale_en_un_chat_que_estaba_cerrado(): void
    {
        AutoResponse::create([
            'company_id' => $this->instance->company_id, 'name' => 'Bienvenido de vuelta', 'trigger_text' => '*',
            'match_types' => ['reopen'], 'reopen_hours' => 24,
            'response_message' => 'Hola de nuevo', 'active' => true,
        ]);
        $conversation = $this->atendidaPorLuis();

        $this->actingAs($this->luis)
            ->postJson("/api/chat/conversations/{$conversation->id}/close")
            ->assertOk();

        $this->travel(3)->days();
        $this->entrante('buenas')->assertOk();

        $this->assertContains('Hola de nuevo', $this->enviados);
    }

    private function administradora(): User
    {
        $marta = User::create([
            'company_id' => $this->instance->company_id, 'name' => 'Marta', 'email' => Str::uuid().'@x.test',
            'password' => 'secret', 'active' => true, 'role' => 'admin',
        ]);

        setPermissionsTeamId($this->instance->company_id);
        $marta->assignRole(Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $this->instance->company_id, 'guard_name' => 'web',
        ]));

        return $marta;
    }

    /** El cliente escribe y Luis contesta: el chat queda suyo, como en producción. */
    private function atendidaPorLuis(): WhatsAppConversation
    {
        $this->entrante('hola, buenas tardes')->assertOk();
        $conversation = WhatsAppConversation::where('instance_id', $this->instance->id)->firstOrFail();

        $this->actingAs($this->luis)
            ->postJson("/api/chat/conversations/{$conversation->id}/send", ['message' => 'Buenas, le ayudo'])
            ->assertOk();

        $this->assertSame($this->luis->id, $conversation->refresh()->assigned_to);
        $this->enviados = [];

        return $conversation;
    }

    private function entrante(string $texto)
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $this->instance->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $this->instance->phone_number_id],
                        'contacts' => [['wa_id' => '573102517949', 'profile' => ['name' => 'Danny']]],
                        'messages' => [[
                            'id' => 'wamid.'.Str::random(12),
                            'from' => '573102517949',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'secreto'),
        ]), $body);
    }
}
