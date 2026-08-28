<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMenuSession;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menús interactivos: el cliente elige tocando en vez de escribir.
 *
 * Se prueba el recorrido completo contra el webhook real, porque las dos mitades
 * del módulo sólo se sostienen juntas: el id que se manda a Meta al enviar el
 * menú es el mismo que hay que reconocer cuando vuelve en la respuesta.
 */
class WhatsAppMenuTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '573007852081';

    protected function setUp(): void
    {
        parent::setUp();
        // Un wamid distinto por envío: la columna es única y Meta nunca repite
        // el identificador de dos mensajes salientes.
        $n = 0;
        Http::fake(function () use (&$n) {
            return Http::response(['messages' => [['id' => 'wamid.OUT' . (++$n)]]], 200);
        });
    }

    private function metaInstance(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-meta',
        ]);
    }

    private function menu(Instance $instance, array $titles, array $attributes = []): WhatsAppMenu
    {
        $menu = WhatsAppMenu::create(array_merge([
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'name' => 'Menú principal',
            'body_text' => '¡Hola! ¿En qué puedo ayudarte hoy?',
            'is_root' => true,
            'match_types' => ['welcome'],
            'active' => true,
            'cooldown_minutes' => 0,
        ], $attributes));

        foreach (array_values($titles) as $i => $title) {
            $menu->options()->create([
                'position' => $i,
                'title' => $title,
                'action_type' => 'reply_text',
                'reply_text' => "Respuesta de {$title}",
            ]);
        }

        return $menu->load('options');
    }

    /** Mensaje de texto entrante, tal como lo entrega Meta. */
    private function inbound(Instance $instance, string $text, string $wamid = 'wamid.IN1'): array
    {
        return $this->envelope($instance, [
            'from' => self::PHONE,
            'id' => $wamid,
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => $text],
        ]);
    }

    /** El cliente toca una opción: Meta devuelve el id que nosotros mandamos. */
    private function inboundReply(Instance $instance, WhatsAppMenuOption $option, string $wamid = 'wamid.IN2'): array
    {
        return $this->envelope($instance, [
            'from' => self::PHONE,
            'id' => $wamid,
            'timestamp' => (string) now()->timestamp,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list_reply',
                'list_reply' => ['id' => $option->payloadId(), 'title' => $option->title],
            ],
        ]);
    }

    private function envelope(Instance $instance, array $message): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '2212436902867081',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '573104047030',
                            'phone_number_id' => $instance->phone_number_id,
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Katherine'],
                            'wa_id' => self::PHONE,
                        ]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    /** Los cuerpos enviados a Meta que llevan un bloque interactivo. */
    private function interactivePayloads(): array
    {
        $sent = [];

        foreach (Http::recorded() as [$request]) {
            $body = $request->data();

            if (($body['type'] ?? null) === 'interactive') {
                $sent[] = $body['interactive'];
            }
        }

        return $sent;
    }

    private function textsSent(): array
    {
        $sent = [];

        foreach (Http::recorded() as [$request]) {
            $body = $request->data();

            if (($body['type'] ?? null) === 'text') {
                $sent[] = $body['text']['body'];
            }
        }

        return $sent;
    }

    public function test_hasta_tres_opciones_el_menu_sale_como_botones(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Consultar factura', 'Pagar en línea', 'Hablar con un asesor']);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();

        $payloads = $this->interactivePayloads();
        $this->assertCount(1, $payloads);
        $this->assertSame('button', $payloads[0]['type']);
        $this->assertCount(3, $payloads[0]['action']['buttons']);
        $this->assertSame('Consultar factura', $payloads[0]['action']['buttons'][0]['reply']['title']);
    }

    public function test_a_partir_de_cuatro_opciones_el_menu_sale_como_lista(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, [
            'Consultar factura', 'Pagar en línea', 'Cambiar clave WiFi', 'Reportar falla', 'Hablar con un asesor',
        ], ['list_button_text' => 'Ver opciones']);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();

        $payloads = $this->interactivePayloads();
        $this->assertCount(1, $payloads);
        $this->assertSame('list', $payloads[0]['type']);
        $this->assertSame('Ver opciones', $payloads[0]['action']['button']);
        $this->assertCount(5, $payloads[0]['action']['sections'][0]['rows']);
    }

    /**
     * Los botones sólo muestran 20 caracteres. Un menú al que le quitan la
     * cuarta opción pasa de lista a botones sin que nadie revise los títulos,
     * y sin recortar aquí ese cambio se convierte en un 400 de Meta.
     */
    public function test_el_titulo_se_recorta_al_limite_del_boton(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Consultar mi factura hoy']);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();

        $title = $this->interactivePayloads()[0]['action']['buttons'][0]['reply']['title'];
        $this->assertSame(20, mb_strlen($title));
    }

    public function test_al_tocar_una_opcion_se_envia_su_respuesta(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Consultar factura', 'Pagar en línea']);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $option = $menu->options->first();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $option))->assertOk();

        $this->assertContains('Respuesta de Consultar factura', $this->textsSent());
    }

    /**
     * Mucha gente no toca: escribe "1" o copia el título. Sin esto el menú se
     * queda esperando un toque que nunca llega.
     */
    public function test_el_cliente_puede_responder_escribiendo_el_numero(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Consultar factura', 'Pagar en línea']);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, '2', 'wamid.IN2'))->assertOk();

        $this->assertContains('Respuesta de Pagar en línea', $this->textsSent());
    }

    public function test_el_cliente_puede_responder_escribiendo_el_titulo(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Consultar factura', 'Pagar en línea']);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'PAGAR EN LINEA', 'wamid.IN2'))->assertOk();

        $this->assertContains('Respuesta de Pagar en línea', $this->textsSent());
    }

    public function test_una_opcion_de_submenu_abre_el_otro_menu(): void
    {
        $instance = $this->metaInstance();
        $sub = $this->menu($instance, ['Sin internet', 'Internet lento'], [
            'name' => 'Tipos de falla',
            'body_text' => '¿Qué está pasando?',
            'is_root' => false,
            'match_types' => [],
        ]);
        $menu = $this->menu($instance, ['Reportar falla']);
        $menu->options->first()->update(['action_type' => 'submenu', 'target_menu_id' => $sub->id, 'reply_text' => null]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $menu->options->first()))->assertOk();

        $payloads = $this->interactivePayloads();
        $this->assertCount(2, $payloads);
        $this->assertSame('¿Qué está pasando?', $payloads[1]['body']['text']);

        // La sesión debe apuntar ya al submenú: si el cliente ahora escribe "1"
        // se refiere a "Sin internet", no a la opción del menú anterior.
        $conversation = WhatsAppConversation::first();
        $this->assertSame($sub->id, WhatsAppMenuSession::where('conversation_id', $conversation->id)->value('menu_id'));
    }

    public function test_la_opcion_de_asesor_asigna_la_conversacion_y_calla_al_bot(): void
    {
        $instance = $this->metaInstance();
        $agent = User::create([
            'name' => 'Laura',
            'email' => 'laura@cmnet.test',
            'password' => bcrypt('secret'),
            'company_id' => $instance->company_id,
        ]);

        $menu = $this->menu($instance, ['Hablar con un asesor']);
        $menu->options->first()->update([
            'action_type' => 'handoff',
            'assign_to_user_id' => $agent->id,
            'reply_text' => 'Te comunico con un asesor.',
        ]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $menu->options->first()))->assertOk();

        $conversation = WhatsAppConversation::first();
        $this->assertSame($agent->id, $conversation->fresh()->assigned_to);
        $this->assertContains('Te comunico con un asesor.', $this->textsSent());

        // Con la conversación ya en manos de un agente, el menú no vuelve a salir.
        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola', 'wamid.IN3'))->assertOk();
        $this->assertCount(1, $this->interactivePayloads());
    }

    /**
     * El texto que llega al tocar es el título de la opción. Si ese título
     * contiene la palabra clave del menú, reevaluar los disparadores reenviaría
     * el menú una y otra vez.
     */
    public function test_la_respuesta_a_una_opcion_no_vuelve_a_disparar_el_menu(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Ver el menú de opciones'], [
            'match_types' => ['contains'],
            'trigger_text' => 'menu',
        ]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'menu'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $menu->options->first()))->assertOk();

        $this->assertCount(1, $this->interactivePayloads());
    }

    /** Un submenú no puede dispararse por su cuenta aunque tenga disparadores. */
    public function test_un_submenu_no_se_dispara_solo(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Sin internet'], [
            'is_root' => false,
            'match_types' => ['contains'],
            'trigger_text' => 'hola',
        ]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();

        $this->assertCount(0, $this->interactivePayloads());
    }

    /** El agente ve en el chat el menú que recibió el cliente, no una burbuja vacía. */
    public function test_el_menu_queda_registrado_en_el_chat(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Consultar factura', 'Pagar en línea']);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();

        $outbound = WhatsAppMessage::where('direction', 'outbound')->first();
        $this->assertNotNull($outbound);
        $this->assertStringContainsString('1. Consultar factura', $outbound->content);
        $this->assertStringContainsString('2. Pagar en línea', $outbound->content);
        $this->assertSame($outbound->content, WhatsAppConversation::first()->last_message);
    }

    /**
     * Sin Integra conectado, una acción de autoservicio no puede callar: el
     * silencio se lee como un sistema roto y termina en el chat de un agente
     * preguntando qué pasó. Sin texto configurado, el chat pasa a una persona.
     */
    public function test_una_accion_sin_integra_conectado_deriva_a_un_asesor(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Consultar factura']);
        $menu->options->first()->update(['action_type' => 'consultar_factura', 'reply_text' => null]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $menu->options->first()))->assertOk();

        $this->assertStringContainsString('Te comunico con un asesor', implode("\n", $this->textsSent()));

        // La opción queda consumida: el siguiente "1" del cliente no reejecuta
        // el aviso, igual que con cualquier otra acción.
        $this->assertSame(0, WhatsAppMenuSession::count());
    }

    public function test_una_accion_sin_integra_puede_usar_un_aviso_a_medida(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Pagar en línea']);
        $menu->options->first()->update([
            'action_type' => 'pagar_en_linea',
            'reply_text' => 'Todavía no tenemos pagos por aquí, {name}. Escríbenos y te ayudamos.',
        ]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $menu->options->first()))->assertOk();

        // El aviso a medida admite las mismas variables que el resto del módulo.
        $this->assertContains(
            'Todavía no tenemos pagos por aquí, Katherine. Escríbenos y te ayudamos.',
            $this->textsSent()
        );

        // Y aun con texto propio el chat pasa a una persona: ese campo se
        // ofrece como "texto adicional al final de la respuesta", así que
        // dejarlo como respuesta única sería un callejón sin salida para quien
        // pidió su factura.
        $this->assertDatabaseHas('whatsapp_messages', [
            'conversation_id' => WhatsAppConversation::first()->id,
            'type' => 'system',
            'content' => 'El bot no pudo resolver la solicitud del cliente y derivó el chat',
        ]);
    }

    /** "Sin acción" es un marcador de trabajo: registra la elección y calla. */
    public function test_una_opcion_sin_accion_no_responde_nada(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Todavía por definir']);
        $menu->options->first()->update([
            'action_type' => WhatsAppMenuOption::ACTION_NONE,
            'reply_text' => null,
        ]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $menu->options->first()))->assertOk();

        $this->assertSame([], $this->textsSent());
        $this->assertSame(0, WhatsAppMenuSession::count());
    }

    /**
     * MySQL guardaba '' en silencio cuando el valor no estaba en el ENUM: la
     * opción se creaba sin acción y el menú dejaba de funcionar sin un error.
     */
    public function test_la_columna_de_accion_acepta_todos_los_tipos_del_catalogo(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Opción']);
        $option = $menu->options->first();

        foreach (WhatsAppMenuOption::ACTION_TYPES as $type) {
            $option->update(['action_type' => $type]);
            $this->assertSame($type, $option->fresh()->action_type);
        }
    }
}
