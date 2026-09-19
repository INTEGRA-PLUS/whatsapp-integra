<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Por qué línea sale lo que manda el ERP.
 *
 * El token dice **qué empresa** llama; la línea la decide la empresa en «Por
 * dónde envía Integra». Antes mandaba el token y punto, así que esa pantalla no
 * servía de nada: Transinternet llevaba nueve días con una línea elegida y 552
 * facturas saliendo por la otra, porque el ERP llama con la credencial de
 * siempre y no cambia por mucho que le respondamos cuál usar.
 */
class LineaDeSalidaDelErpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $principal;

    private Instance $elegida;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.enviado']]], 200)]);

        $this->company = Company::create(['name' => 'Transinternet', 'slug' => 'transinternet', 'active' => true]);
        $this->principal = $this->linea('Instancia Principal', '878757941991852');
        $this->elegida = $this->linea('Transintermet', '2253367844691746');
    }

    /**
     * Con la línea elegida, el envío sale por ella aunque el token sea de otra.
     *
     * @test
     */
    public function el_envio_sale_por_la_linea_elegida(): void
    {
        $this->company->elegirInstanciaDelErp($this->elegida->id);

        $this->postJson('/api/v1/messages/send', [
            'to' => '573001112233',
            'message' => 'Su factura de septiembre',
        ], ['X-Instance-Token' => $this->principal->phone_number_id])
            ->assertOk();

        $conversacion = WhatsAppConversation::first();

        $this->assertSame($this->elegida->id, $conversacion->instance_id, 'Tenía que salir por la elegida.');
    }

    /**
     * Y la línea que envía queda marcada como usada.
     *
     * `validateInstance()` sólo marca la del token —que es quien se autenticó—
     * y sin esto la pantalla diría que la elegida lleva días muda justo mientras
     * sale todo por ella.
     *
     * @test
     */
    public function la_linea_que_envia_queda_marcada(): void
    {
        $this->company->elegirInstanciaDelErp($this->elegida->id);

        $this->postJson('/api/v1/messages/send', [
            'to' => '573001112233',
            'message' => 'Hola',
        ], ['X-Instance-Token' => $this->principal->phone_number_id])
            ->assertOk();

        $this->assertNotNull($this->elegida->refresh()->api_last_seen_at);
    }

    /**
     * Sin elección a mano no se mueve nada.
     *
     * La línea por defecto es la primera por id, y redirigir por eso sería
     * cambiarle el número a quien nunca pidió cambiarlo.
     *
     * @test
     */
    public function sin_eleccion_manual_sale_por_la_del_token(): void
    {
        $this->postJson('/api/v1/messages/send', [
            'to' => '573001112233',
            'message' => 'Hola',
        ], ['X-Instance-Token' => $this->elegida->phone_number_id])
            ->assertOk();

        $this->assertSame($this->elegida->id, WhatsAppConversation::first()->instance_id);
    }

    /**
     * Y nunca cruza de empresa.
     *
     * Es la garantía que hace segura la redirección: el token sigue decidiendo
     * de quién es el mensaje, y lo elegido sólo puede mover la línea dentro de
     * esa misma empresa.
     *
     * @test
     */
    public function no_cruza_de_empresa(): void
    {
        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $suya = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'La de la otra',
            'phone_number_id' => '999888777',
            'type' => 'meta',
            'access_token' => 'token',
            'active' => true,
        ]);

        $this->company->elegirInstanciaDelErp($this->elegida->id);

        $this->postJson('/api/v1/messages/send', [
            'to' => '573001112233',
            'message' => 'Hola',
        ], ['X-Instance-Token' => $suya->phone_number_id])
            ->assertOk();

        $this->assertSame($suya->id, WhatsAppConversation::first()->instance_id);
    }

    /** El registro de un mensaje ya enviado no se redirige: ya salió. */
    public function test_registrar_no_se_redirige(): void
    {
        $this->company->elegirInstanciaDelErp($this->elegida->id);

        $this->postJson('/api/v1/messages/register', [
            'to' => '573001112233',
            'wamid' => 'wamid.yaSalio',
            'content' => 'Su factura',
        ], ['X-Instance-Token' => $this->principal->phone_number_id])
            ->assertOk();

        $mensaje = WhatsAppMessage::where('wamid', 'wamid.yaSalio')->first();

        $this->assertSame(
            $this->principal->id,
            $mensaje->conversation->instance_id,
            'Registrar cuenta lo que YA pasó: redirigirlo sería escribir una historia falsa.'
        );
    }

    private function linea(string $nombre, string $phoneNumberId): Instance
    {
        return Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => $nombre,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => '1311984867684767',
            'type' => 'meta',
            'access_token' => 'token-meta',
            'active' => true,
        ]);
    }
}
