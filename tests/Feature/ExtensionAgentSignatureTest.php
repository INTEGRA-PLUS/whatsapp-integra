<?php

namespace Tests\Feature;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extensión «Firma automática del agente», probada contra el job de entrega.
 *
 * La prueba que sostiene el diseño entero es la primera: sin ninguna extensión
 * instalada el mensaje tiene que salir con el MISMO texto que salía antes de que
 * existiera este módulo. Sacar una decisión de un job compartido sólo es seguro
 * si se puede demostrar que no cambia nada para quien no la toque.
 */
class ExtensionAgentSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $agente;

    private WhatsAppConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(6)]]], 200));

        $this->company = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true]);

        $this->agente = User::create([
            'company_id' => $this->company->id,
            'name' => 'Laura',
            'email' => 'laura@fibra.test',
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

        $this->conversation = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Cliente',
            'status' => 'open',
            'last_message' => 'Hola',
            'last_message_at' => now(),
        ]);
    }

    private function instalar(array $settings = []): CompanyExtension
    {
        return CompanyExtension::create([
            'company_id' => $this->company->id,
            'slug' => 'agent_signature',
            'enabled' => true,
            'settings' => array_merge([
                'template' => '*{agente}:*',
                'position' => 'before',
                'separator' => 'newline',
            ], $settings),
            'installed_by' => $this->agente->id,
            'installed_at' => now(),
        ]);
    }

    private function enviar(string $contenido, ?int $sentBy = null): void
    {
        $message = WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id,
            'type' => 'text',
            'content' => $contenido,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $sentBy ?? $this->agente->id,
            'sent_at' => now(),
        ]);

        (new DeliverWhatsAppMessage($message->id))->handle(app(MetaWhatsAppService::class));
    }

    /** El cuerpo del texto que se le mandó a Meta en el último envío. */
    private function textoEnviado(): string
    {
        $cuerpo = null;

        Http::assertSent(function ($request) use (&$cuerpo) {
            if (($request['type'] ?? null) === 'text') {
                $cuerpo = $request['text']['body'] ?? null;
            }

            return true;
        });

        $this->assertNotNull($cuerpo, 'No se envió ningún mensaje de texto a Meta.');

        return $cuerpo;
    }

    public function test_sin_extension_sale_el_formato_de_siempre(): void
    {
        $this->enviar('Ya reviso tu caso');

        $this->assertSame("*Laura:*\nYa reviso tu caso", $this->textoEnviado());
    }

    public function test_instalarla_de_fabrica_no_cambia_nada(): void
    {
        $this->instalar();
        $this->enviar('Ya reviso tu caso');

        $this->assertSame("*Laura:*\nYa reviso tu caso", $this->textoEnviado());
    }

    public function test_firma_al_final_con_plantilla_propia(): void
    {
        $this->instalar([
            'template' => '— {agente}, {empresa}',
            'position' => 'after',
            'separator' => 'blank_line',
        ]);

        $this->enviar('Ya reviso tu caso');

        $this->assertSame("Ya reviso tu caso\n\n— Laura, Fibra Sur", $this->textoEnviado());
    }

    /**
     * Una plantilla vacía es la única manera de quitar el prefijo, que es
     * justamente lo que antes no se podía hacer.
     */
    public function test_una_plantilla_vacia_deja_el_mensaje_limpio(): void
    {
        $this->instalar(['template' => '']);
        $this->enviar('Ya reviso tu caso');

        $this->assertSame('Ya reviso tu caso', $this->textoEnviado());
    }

    /**
     * Los menús, las respuestas automáticas y las campañas crean sus mensajes
     * sin remitente: no los escribió nadie, así que no los firma nadie.
     */
    public function test_no_firma_lo_que_manda_el_sistema(): void
    {
        $this->instalar(['template' => '*{agente}:*']);

        $message = WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id,
            'type' => 'text',
            'content' => 'Este es un aviso automático',
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_at' => now(),
        ]);

        (new DeliverWhatsAppMessage($message->id))->handle(app(MetaWhatsAppService::class));

        $this->assertSame('Este es un aviso automático', $this->textoEnviado());
    }

    public function test_apagada_vuelve_al_formato_de_siempre(): void
    {
        $this->instalar(['template' => 'FIRMA'])->update(['enabled' => false]);
        $this->enviar('Ya reviso tu caso');

        $this->assertSame("*Laura:*\nYa reviso tu caso", $this->textoEnviado());
    }

    /** La burbuja del CRM guarda lo que el agente escribió, no lo que salió. */
    public function test_la_firma_no_se_guarda_en_el_historial(): void
    {
        $this->instalar(['template' => '*{agente} de {empresa}:*']);
        $this->enviar('Ya reviso tu caso');

        $this->assertSame(
            'Ya reviso tu caso',
            WhatsAppMessage::where('conversation_id', $this->conversation->id)->latest('id')->value('content')
        );
    }

    /** La firma de una empresa no puede colarse en los mensajes de otra. */
    public function test_la_firma_no_alcanza_a_otra_empresa(): void
    {
        $this->instalar(['template' => 'FIRMA DEL SUR', 'position' => 'after']);

        $otra = Company::create(['name' => 'Fibra Norte', 'slug' => 'fibra-norte', 'active' => true]);
        $vecino = User::create([
            'company_id' => $otra->id, 'name' => 'Pedro', 'email' => 'pedro@norte.test',
            'password' => 'secret', 'active' => true, 'role' => 'agent',
        ]);
        $instanciaVecina = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Norte',
            'phone_number_id' => '2222222222',
            'waba_id' => '3333333333',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);
        $this->conversation = WhatsAppConversation::create([
            'instance_id' => $instanciaVecina->id,
            'wa_id' => '573001112233',
            'phone_number' => '573001112233',
            'name' => 'Cliente del vecino',
            'status' => 'open',
            'last_message' => 'Hola',
            'last_message_at' => now(),
        ]);

        $this->enviar('Buenas tardes', $vecino->id);

        $this->assertSame("*Pedro:*\nBuenas tardes", $this->textoEnviado());
    }
}
