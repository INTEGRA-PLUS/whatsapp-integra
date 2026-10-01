<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que ve el asesor cuando la plantilla la manda el ERP.
 *
 * El chat guardaba «[Plantilla: facturacion]» en todo lo que entra por la API
 * v1, mientras que los envíos desde el chat y las campañas sí guardan el texto
 * compuesto — el propio código de campañas lo dice: «el agente debe leer lo
 * mismo que le llegó al cliente».
 *
 * La diferencia no era cosmética. En Conecta Comunicaciones el ERP llevaba días
 * mandando los parámetros descolocados, así que a los clientes les llegaba «tu
 * factura ha sido generada bajo el número **y la fecha de vencimiento es
 * 2026-09-27** por un monto de **$50.000 el dia 2026-09-15**». Los mensajes
 * salían entregados y en el CRM no se veía nada raro, porque la burbuja sólo
 * decía el nombre de la plantilla. Con el cuerpo compuesto, un error así se ve
 * el primer día con sólo abrir el chat.
 */
class PlantillaDelErpEnElChatTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Conecta', 'slug' => 'conecta-'.Str::random(4), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '829243676946998',
            'waba_id' => 'waba-conecta',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba',
        ]);

        $this->token = $this->instance->generarApiToken();
    }

    /** El mensaje se guarda con el texto que va a leer el cliente. */
    public function test_el_chat_guarda_el_cuerpo_compuesto(): void
    {
        $this->fakeGraph();

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/template', [
                'to' => '573001112233',
                'template_name' => 'facturacion',
                'language_code' => 'es',
                'components' => [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => 'CONECTA COMUNICACIONES SAS'],
                        ['type' => 'text', 'text' => 'FE13922'],
                        ['type' => 'text', 'text' => '50.000'],
                    ],
                ]],
            ])
            ->assertOk();

        $mensaje = WhatsAppMessage::latest('id')->first();

        $this->assertSame(
            'Estimad@ usuari@, CONECTA COMUNICACIONES SAS te informa que tu factura FE13922 es de $50.000.',
            $mensaje->content,
            'La burbuja tiene que decir lo mismo que recibió el cliente.'
        );

        // Y la conversación, para que se lea igual desde la lista de chats.
        $this->assertStringContainsString('FE13922', $mensaje->conversation->last_message);
    }

    /**
     * Un parámetro descolocado se lee en el chat.
     *
     * Es el caso de Conecta: el ERP manda una frase entera donde va el número de
     * factura. No se rechaza —la plantilla es válida para Meta y el mensaje se
     * entrega— pero deja de ser invisible.
     */
    public function test_un_parametro_descolocado_queda_a_la_vista(): void
    {
        $this->fakeGraph();

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/template', [
                'to' => '573001112233',
                'template_name' => 'facturacion',
                'language_code' => 'es',
                'components' => [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => 'CONECTA COMUNICACIONES SAS'],
                        ['type' => 'text', 'text' => 'y la fecha de vencimiento es 2026-09-27'],
                        ['type' => 'text', 'text' => '50.000 el dia 2026-09-15'],
                    ],
                ]],
            ])
            ->assertOk();

        $this->assertStringContainsString(
            'tu factura y la fecha de vencimiento es 2026-09-27 es de',
            WhatsAppMessage::latest('id')->first()->content
        );
    }

    /**
     * Si Graph no contesta, se guarda el nombre de la plantilla como antes.
     *
     * Una caída del catálogo no puede impedir el envío ni inventar un texto: el
     * mensaje sale igual y la burbuja dice lo justo.
     */
    public function test_sin_catalogo_se_guarda_el_nombre_como_antes(): void
    {
        Http::fake([
            '*/message_templates*' => Http::response([], 500),
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/template', [
                'to' => '573001112233',
                'template_name' => 'facturacion',
                'language_code' => 'es',
                'components' => [],
            ])
            ->assertOk();

        $this->assertSame('[Plantilla: facturacion]', WhatsAppMessage::latest('id')->first()->content);
    }

    /**
     * Si el ERP manda menos datos de los que pide la plantilla, el error dice
     * dónde se arregla. El ERP lo enseña tal cual en la pantalla de facturas.
     */
    public function test_si_faltan_datos_el_error_dice_donde_se_asignan_las_variables(): void
    {
        $this->fakeGraph();

        $respuesta = $this->withHeader('X-Instance-Token', $this->token)
            ->postJson('/api/v1/messages/template', [
                'to' => '573001112233',
                'template_name' => 'facturacion',
                'language_code' => 'es',
                'components' => [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => 'CONECTA COMUNICACIONES SAS'],
                        ['type' => 'text', 'text' => 'FE13922'],
                    ],
                ]],
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('necesita 3 datos en el cuerpo y el envío manda 2', $respuesta->json('error'));
        $this->assertStringContainsString('Integraciones → Envíos automáticos → Variables', $respuesta->json('error'));
    }

    private function fakeGraph(): void
    {
        Http::fake([
            '*/message_templates*' => Http::response(['data' => [[
                'id' => 'tpl-facturacion',
                'name' => 'facturacion',
                'language' => 'es',
                'status' => 'APPROVED',
                'category' => 'UTILITY',
                'components' => [[
                    'type' => 'BODY',
                    'text' => 'Estimad@ usuari@, {{1}} te informa que tu factura {{2}} es de ${{3}}.',
                ]],
            ]]], 200),
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]], 200),
        ]);
    }
}
