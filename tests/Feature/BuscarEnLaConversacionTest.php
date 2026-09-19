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
 * La lupa de la cabecera del chat.
 *
 * Era un botón sin `onClick`: se pintaba y no hacía nada. Y buscar sólo en lo
 * que el navegador tiene cargado tampoco habría servido — el chat trae los cien
 * últimos mensajes, y lo que se busca suele estar más atrás, que es justo por lo
 * que se busca.
 *
 * Aquí se protege lo que hace que la búsqueda sea usable: que encuentre en todo
 * el historial, que se pueda saltar a un mensaje viejo con su contexto alrededor
 * y —lo de siempre en este proyecto— que no se cuele la conversación de otra
 * empresa.
 */
class BuscarEnLaConversacionTest extends TestCase
{
    use RefreshDatabase;

    private User $agente;

    private WhatsAppConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->agente, $this->conversacion] = $this->empresaConChat('Fibra Sur', 'fibra-sur');
    }

    /** Encuentra un mensaje que quedó fuera de la ventana cargada. */
    public function test_busca_en_todo_el_historial(): void
    {
        $viejo = $this->mensaje('El código del router es ABC-9981', now()->subMonths(8));

        // Y 150 mensajes más recientes, que son los que el chat tendría cargados.
        for ($i = 0; $i < 150; $i++) {
            $this->mensaje('Mensaje de relleno '.$i, now()->subDays(150 - $i));
        }

        $resultados = $this->buscar('ABC-9981');

        $this->assertCount(1, $resultados);
        $this->assertSame($viejo->id, $resultados[0]['id']);
    }

    /** Del más reciente al más antiguo: lo último dicho es lo que más se busca. */
    public function test_los_resultados_vienen_del_mas_reciente_al_mas_antiguo(): void
    {
        $this->mensaje('pago pendiente', now()->subDays(5));
        $nuevo = $this->mensaje('pago recibido', now()->subDay());

        $resultados = $this->buscar('pago');

        $this->assertSame($nuevo->id, $resultados[0]['id']);
    }

    /**
     * Un `%` escrito por el agente es un carácter, no un comodín.
     *
     * Sin escaparlo, buscar «100%» devuelve la conversación entera y el buscador
     * parece roto justo cuando se usa para algo concreto.
     */
    public function test_el_porcentaje_no_es_un_comodin(): void
    {
        $este = $this->mensaje('Te aplicamos el 100% de descuento', now()->subDay());
        $este2 = $this->mensaje('Nada que ver', now());

        $resultados = $this->buscar('100%');

        $this->assertCount(1, $resultados);
        $this->assertSame($este->id, $resultados[0]['id']);
        $this->assertNotSame($este2->id, $resultados[0]['id']);
    }

    /** Con una letra no se busca: media conversación coincidiría. */
    public function test_una_sola_letra_no_busca(): void
    {
        $this->mensaje('hola', now());

        $this->assertSame([], $this->buscar('h'));
    }

    /**
     * Saltar a un mensaje viejo trae contexto a los dos lados.
     *
     * Un resultado sin lo que se dijo antes y después no se entiende, y es el
     * motivo por el que se busca: leer esa parte de la conversación.
     */
    public function test_saltar_a_un_mensaje_trae_su_contexto(): void
    {
        $ids = [];

        for ($i = 0; $i < 60; $i++) {
            $ids[] = $this->mensaje('Mensaje '.$i, now()->subDays(60 - $i))->id;
        }

        $enMedio = $ids[30];

        $datos = $this->actingAs($this->agente)
            ->getJson("/api/chat/conversations/{$this->conversacion->id}/messages?around_id={$enMedio}")
            ->assertOk()
            ->json();

        $devueltos = collect($datos['messages'])->pluck('id');

        $this->assertTrue($devueltos->contains($enMedio), 'El mensaje buscado tiene que venir en la ventana.');
        $this->assertTrue($devueltos->contains($ids[25]), 'Falta el contexto anterior.');
        $this->assertTrue($devueltos->contains($ids[35]), 'Falta el contexto posterior.');
        $this->assertSame($enMedio, $datos['around_id']);
    }

    /** Y pedir un mensaje que no es de esta conversación no revienta. */
    public function test_saltar_a_un_mensaje_inexistente_no_revienta(): void
    {
        $datos = $this->actingAs($this->agente)
            ->getJson("/api/chat/conversations/{$this->conversacion->id}/messages?around_id=999999")
            ->assertOk()
            ->json();

        $this->assertTrue($datos['not_found']);
        $this->assertSame([], $datos['messages']);
    }

    /**
     * El aislamiento de siempre.
     *
     * `whatsapp_conversations` no tiene `company_id`: se llega a él saltando por
     * la instancia, y si alguien quita ese salto la búsqueda deja leer los
     * mensajes de otro cliente mandando un id a mano.
     */
    public function test_no_se_puede_buscar_en_la_conversacion_de_otra_empresa(): void
    {
        [, $ajena] = $this->empresaConChat('Otra', 'otra');

        $this->actingAs($this->agente)
            ->getJson("/api/chat/conversations/{$ajena->id}/messages/search?q=hola")
            ->assertStatus(403);
    }

    /** @return array<int, mixed> */
    private function buscar(string $texto): array
    {
        return $this->actingAs($this->agente)
            ->getJson("/api/chat/conversations/{$this->conversacion->id}/messages/search?q=".urlencode($texto))
            ->assertOk()
            ->json('results');
    }

    private function mensaje(string $texto, $cuando): WhatsAppMessage
    {
        $mensaje = WhatsAppMessage::create([
            'conversation_id' => $this->conversacion->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'text',
            'content' => $texto,
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => $cuando,
        ]);

        // `created_at` no es fillable y la ventana ordena por él.
        return $mensaje->forceFill(['created_at' => $cuando])->fresh();
    }

    /** @return array{0: User, 1: WhatsAppConversation} */
    private function empresaConChat(string $nombre, string $slug): array
    {
        $empresa = Company::create(['name' => $nombre, 'slug' => $slug, 'active' => true]);

        $usuario = User::create([
            'company_id' => $empresa->id,
            'name' => 'Agente',
            'email' => 'agente@'.$slug.'.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'agent',
        ]);

        $instancia = Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '117796251540'.random_int(1000, 9999),
            'waba_id' => 'waba-'.$slug,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $conversacion = WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => '57300'.random_int(1000000, 9999999),
            'phone_number' => '573001112233',
            'name' => 'Cliente',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        return [$usuario, $conversacion];
    }
}
