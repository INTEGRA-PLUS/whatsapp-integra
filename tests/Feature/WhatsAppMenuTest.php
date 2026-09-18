<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppChatAi;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMenuSession;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    /**
     * Interruptor para que Meta rechace las imágenes.
     *
     * Es una propiedad y no un segundo Http::fake() dentro de la prueba: los
     * stubs se apilan y gana el más antiguo, así que un fake posterior no
     * sustituye al del setUp y la prueba acabaría comprobando el caso feliz sin
     * enterarse.
     */
    private bool $imagenRechazada = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Un wamid distinto por envío: la columna es única y Meta nunca repite
        // el identificador de dos mensajes salientes.
        $n = 0;
        Http::fake(function ($request) use (&$n) {
            if ($this->imagenRechazada && ($request['type'] ?? null) === 'image') {
                return Http::response(['error' => ['message' => 'Media download failed']], 400);
            }

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();

        $title = $this->interactivePayloads()[0]['action']['buttons'][0]['reply']['title'];
        $this->assertSame(20, mb_strlen($title));
    }

    public function test_al_tocar_una_opcion_se_envia_su_respuesta(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Consultar factura', 'Pagar en línea']);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $option = $menu->options->first();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inbound($instance, '2', 'wamid.IN2'))->assertOk();

        $this->assertContains('Respuesta de Pagar en línea', $this->textsSent());
    }

    public function test_el_cliente_puede_responder_escribiendo_el_titulo(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Consultar factura', 'Pagar en línea']);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inbound($instance, 'PAGAR EN LINEA', 'wamid.IN2'))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first()))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first()))->assertOk();

        $conversation = WhatsAppConversation::first();
        $this->assertSame($agent->id, $conversation->fresh()->assigned_to);
        $this->assertContains('Te comunico con un asesor.', $this->textsSent());

        // Con la conversación ya en manos de un agente, el menú no vuelve a salir.
        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.IN3'))->assertOk();
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

        $this->postSignedWebhook($this->inbound($instance, 'menu'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first()))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();

        $this->assertCount(0, $this->interactivePayloads());
    }

    /** El agente ve en el chat el menú que recibió el cliente, no una burbuja vacía. */
    public function test_el_menu_queda_registrado_en_el_chat(): void
    {
        $instance = $this->metaInstance();
        $this->menu($instance, ['Consultar factura', 'Pagar en línea']);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first()))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first()))->assertOk();

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

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first()))->assertOk();

        $this->assertSame([], $this->textsSent());
        $this->assertSame(0, WhatsAppMenuSession::count());
    }

    // ------------------------------------------------------------------
    // Responder con una imagen
    // ------------------------------------------------------------------

    /**
     * Hay respuestas que son un cartel —los puntos de pago, la cobertura, la
     * tabla de planes—: en texto no se leen, y la empresa ya lo tiene diseñado.
     */
    public function test_una_opcion_de_imagen_envia_la_imagen_con_su_pie(): void
    {
        $instance = $this->metaInstance();
        $option = $this->imageOption($instance);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com')
                && ($request['type'] ?? null) === 'image'
                && ($request['image']['link'] ?? null) === 'https://cdn.test/puntos-de-pago.jpg'
                && ($request['image']['caption'] ?? null) === 'Paga en cualquiera de estos puntos 👆';
        });

        $message = WhatsAppMessage::where('direction', 'outbound')->where('type', 'image')->first();
        $this->assertNotNull($message);
        $this->assertSame('https://cdn.test/puntos-de-pago.jpg', $message->media_url);
        $this->assertSame('Paga en cualquiera de estos puntos 👆', $message->content);
    }

    /** El pie se escribe con las mismas variables que el resto del menú. */
    public function test_el_pie_de_foto_reemplaza_las_variables(): void
    {
        $instance = $this->metaInstance();
        $option = $this->imageOption($instance, '{name}, estos son tus puntos de pago');

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        $message = WhatsAppMessage::where('direction', 'outbound')->where('type', 'image')->first();
        $this->assertStringNotContainsString('{name}', $message->content);
    }

    /**
     * Si Meta no acepta la imagen, el cliente recibe al menos el pie. El pie
     * suele llevar lo esencial, así que llega mutilado pero llega: callar es lo
     * único que el menú no puede hacer.
     */
    public function test_si_la_imagen_no_se_puede_enviar_se_responde_el_pie(): void
    {
        $instance = $this->metaInstance();
        $option = $this->imageOption($instance);

        // Meta rechaza la imagen (no pudo descargarla) pero acepta texto.
        $this->imagenRechazada = true;

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        $this->assertContains('Paga en cualquiera de estos puntos 👆', $this->textsSent());
        $this->assertSame(0, WhatsAppMessage::where('type', 'image')->count());
    }

    /** Sin imagen configurada tampoco se calla: se responde el texto que haya. */
    public function test_una_opcion_de_imagen_sin_imagen_responde_el_texto(): void
    {
        $instance = $this->metaInstance();
        $option = $this->imageOption($instance);
        $option->update(['config' => []]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        $this->assertContains('Paga en cualquiera de estos puntos 👆', $this->textsSent());
    }

    /** Una opción de imagen sin imagen no debería poder guardarse. */
    public function test_guardar_una_opcion_de_imagen_exige_la_imagen(): void
    {
        $instance = $this->metaInstance();
        $user = $this->admin($instance);

        $this->actingAs($user)
            ->post(route('whatsapp-menus.store'), [
                'name' => 'Menú con cartel',
                'body_text' => '¿En qué te ayudo?',
                'is_root' => true,
                'match_types' => ['welcome'],
                'options' => [[
                    'title' => 'Puntos de pago',
                    'action_type' => WhatsAppMenuOption::ACTION_IMAGE,
                    'reply_text' => 'Paga aquí',
                    'config' => [],
                ]],
            ])
            ->assertSessionHasErrors('options.0.config.image_url');
    }

    /**
     * Y la imagen tiene que SEGUIR ahí después de guardar.
     *
     * Se subía bien, el formulario la enseñaba y el cliente recibía sólo el pie
     * de foto: al guardar, `optionConfig()` conservaba únicamente las claves que
     * el tipo entiende y «reply_image» no estaba en esa lista, así que
     * `image_url` se tiraba. La revisión acababa diciéndole «no tiene imagen» a
     * quien acababa de subirla.
     *
     * Las otras pruebas de imagen escriben la opción con `update()` directo, así
     * que ninguna pasaba por el guardado del formulario, que es donde se perdía.
     *
     * @test
     */
    public function la_imagen_sobrevive_al_guardado(): void
    {
        $instance = $this->metaInstance();

        $this->actingAs($this->admin($instance))
            ->post(route('whatsapp-menus.store'), [
                'name' => 'Menú con cartel',
                'body_text' => '¿En qué te ayudo?',
                'is_root' => true,
                'match_types' => ['contains'],
                'trigger_text' => 'puntos',
                'options' => [[
                    'title' => 'Puntos de pago',
                    'action_type' => WhatsAppMenuOption::ACTION_IMAGE,
                    'reply_text' => 'Paga en cualquiera de estos puntos 👆',
                    'config' => ['image_url' => 'https://cdn.test/puntos-de-pago.jpg'],
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $opcion = WhatsAppMenu::where('name', 'Menú con cartel')->firstOrFail()->options()->firstOrFail();

        $this->assertSame('https://cdn.test/puntos-de-pago.jpg', $opcion->imageUrl());
        $this->assertSame('Paga en cualquiera de estos puntos 👆', $opcion->reply_text);
    }

    /**
     * El primer usuario de una empresa recibe el rol admin con los permisos que
     * existan en ese momento (User::booted), así que hay que crearlos antes.
     */
    private function admin(Instance $instance): User
    {
        foreach (['whatsapp_menus.view', 'whatsapp_menus.create', 'whatsapp_menus.update'] as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        return User::create([
            'company_id' => $instance->company_id,
            'name' => 'Admin',
            'email' => 'admin-' . $instance->company_id . '@example.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'active' => true,
        ]);
    }

    /** Un menú de una opción que responde con imagen. */
    private function imageOption(Instance $instance, string $caption = 'Paga en cualquiera de estos puntos 👆'): WhatsAppMenuOption
    {
        $menu = $this->menu($instance, ['Puntos de pago']);
        $option = $menu->options->first();

        $option->update([
            'action_type' => WhatsAppMenuOption::ACTION_IMAGE,
            'reply_text' => $caption,
            'config' => ['image_url' => 'https://cdn.test/puntos-de-pago.jpg'],
        ]);

        return $option->fresh();
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

    // ------------------------------------------------------------------
    // «Que responda la IA»
    // ------------------------------------------------------------------

    /**
     * Hasta ahora la IA sólo entraba cuando NINGÚN menú reconocía el mensaje.
     *
     * Así que una empresa que había subido su tarifario y su reglamento no
     * tenía forma de decir «esta opción la contesta la IA con eso»: le tocaba
     * copiar la respuesta a mano en un `reply_text` y volver a copiarla cada
     * vez que cambiara el documento.
     */
    public function test_la_opcion_de_ia_le_pasa_la_pregunta_al_flujo_de_chat(): void
    {
        Queue::fake([ProcessWhatsAppChatAi::class]);

        $instance = $this->conChatIa();
        $menu = $this->menu($instance, ['Horarios de atención']);
        $option = $menu->options->first();
        $option->update(['action_type' => WhatsAppMenuOption::ACTION_IA, 'reply_text' => null]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        // Sin pregunta escrita se usa el título: «Horarios de atención» YA es la
        // pregunta, y obligar a escribirla dos veces sólo consigue que las dos
        // se desincronicen.
        Queue::assertPushed(
            ProcessWhatsAppChatAi::class,
            fn (ProcessWhatsAppChatAi $job) => $job->message === 'Horarios de atención'
                && $job->wamid !== ''
        );
    }

    /** Y si escribes qué debe resolver, manda eso y no el título. */
    public function test_la_pregunta_escrita_gana_al_titulo(): void
    {
        Queue::fake([ProcessWhatsAppChatAi::class]);

        $instance = $this->conChatIa();
        $menu = $this->menu($instance, ['Retiros']);
        $option = $menu->options->first();
        $option->update([
            'action_type' => WhatsAppMenuOption::ACTION_IA,
            'reply_text' => 'Explícale los requisitos para retirarse de la cooperativa.',
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        Queue::assertPushed(
            ProcessWhatsAppChatAi::class,
            fn (ProcessWhatsAppChatAi $job) => $job->message === 'Explícale los requisitos para retirarse de la cooperativa.'
        );
    }

    /**
     * Sin IA disponible la opción NO calla: pasa a un asesor.
     *
     * Misma regla que las acciones de Integra con el ERP caído. El cliente ya
     * tocó el botón, y el silencio se lee como un sistema roto.
     */
    public function test_sin_ia_la_opcion_pasa_a_un_asesor(): void
    {
        $instance = $this->metaInstance();
        User::create([
            'company_id' => $instance->company_id,
            'name' => 'Asesora', 'email' => 'asesora@x.test',
            'password' => 'secret', 'active' => true,
        ]);

        $menu = $this->menu($instance, ['Horarios de atención']);
        $option = $menu->options->first();
        $option->update(['action_type' => WhatsAppMenuOption::ACTION_IA, 'reply_text' => null]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        $this->assertNotNull(WhatsAppConversation::first()->assigned_to);
        $this->assertNotSame([], $this->textsSent(), 'Algo tiene que recibir: callarse es lo peor.');
    }

    /**
     * Y en ese traspaso el cliente NO ve la pregunta interna.
     *
     * Es la trampa del campo: `reply_text` en una opción de IA es la
     * instrucción —«explícale los requisitos»—, no un mensaje. Reutilizar el
     * handoff de siempre se la habría mandado tal cual.
     */
    public function test_el_traspaso_no_le_ensena_al_cliente_la_pregunta_de_la_ia(): void
    {
        $instance = $this->metaInstance();
        $menu = $this->menu($instance, ['Retiros']);
        $option = $menu->options->first();
        $option->update([
            'action_type' => WhatsAppMenuOption::ACTION_IA,
            'reply_text' => 'Explícale los requisitos para retirarse de la cooperativa.',
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();

        foreach ($this->textsSent() as $texto) {
            $this->assertStringNotContainsString('Explícale los requisitos', $texto);
        }
    }

    /**
     * La acción se ofrece SIEMPRE, encendida la IA o no.
     *
     * Primero se escondió a quien no tenía el chat IA, y salió mal de las dos
     * maneras: no aparecía, nada decía por qué, y la leyenda de colores seguía
     * nombrando un color que no se podía elegir. Se enseña bloqueada y con el
     * motivo, igual que las de Integra — quien no la ve no la va a pedir.
     */
    public function test_el_catalogo_siempre_ofrece_la_ia(): void
    {
        $valores = collect(WhatsAppMenuOption::catalog())->pluck('value');

        $this->assertTrue($valores->contains(WhatsAppMenuOption::ACTION_IA));
    }

    /** Y la pantalla dice si se puede elegir, que es lo que decide el desplegable. */
    public function test_la_pantalla_dice_si_la_ia_esta_disponible(): void
    {
        $instance = $this->conChatIa();
        $this->comoAdminDe($instance);

        $props = $this->get(route('whatsapp-menus.index'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['iaDisponible']);
        $this->assertTrue(
            collect($props['actionTypes'])->pluck('value')->contains(WhatsAppMenuOption::ACTION_IA)
        );
    }

    /** Sin IA encendida sigue apareciendo, pero marcada como no disponible. */
    public function test_sin_ia_la_accion_aparece_pero_no_disponible(): void
    {
        $instance = $this->metaInstance();
        $this->comoAdminDe($instance);

        $props = $this->get(route('whatsapp-menus.index'))->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['iaDisponible']);
        $this->assertTrue(
            collect($props['actionTypes'])->pluck('value')->contains(WhatsAppMenuOption::ACTION_IA),
            'Esconderla deja al admin buscando una opción que nadie le dijo que existe.'
        );
    }

    private function comoAdminDe(Instance $instance): void
    {
        $user = User::create([
            'company_id' => $instance->company_id,
            'name' => 'Admin', 'email' => 'admin-menus-'.$instance->company_id.'@x.test',
            'password' => 'secret', 'active' => true,
        ]);

        $this->actingAs($user);
    }

    /** Una instancia cuya empresa tiene el chat IA encendido y configurado. */
    private function conChatIa(): Instance
    {
        $instance = $this->metaInstance();

        $instance->company->update(['plan' => 'basico', 'ia' => 'completa']);

        CompanyIntegration::create([
            'company_id' => $instance->company_id,
            'key' => CompanyIntegration::KEY_AI_CHAT,
            'enabled' => true,
        ]);

        config([
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'n8n_llave',
        ]);

        return $instance->fresh();
    }

    // ------------------------------------------------------------------
    // Quién saluda: sólo uno, pero se puede relevar
    // ------------------------------------------------------------------

    /**
     * Sin confirmar, no se crea — y se dice CUÁL saluda hoy.
     *
     * El error era «Ya existe un menú de bienvenida para esta instancia», que
     * obliga a salir a mirar cuál de los seis es.
     *
     * @test
     */
    public function crear_otro_menu_de_bienvenida_pide_confirmar_y_nombra_al_que_saluda(): void
    {
        $instance = $this->metaInstance();
        $this->comoAdminDe($instance);

        // Toda empresa nace con el menú genérico sembrado, y ése también saluda:
        // sin barrerlo, la prueba comprobaría el relevo de OTRO menú.
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $this->menu($instance, ['Una'], ['name' => 'Menú principal', 'instance_id' => null]);

        $this->post(route('whatsapp-menus.store'), $this->nuevoMenuDeBienvenida())
            ->assertSessionHasErrors('match_types');

        $this->assertStringContainsString(
            'Menú principal',
            session('errors')->first('match_types'),
            'El aviso tiene que nombrar al menú que saluda hoy.'
        );

        $this->assertFalse(
            WhatsAppMenu::where('name', 'El nuevo')->exists(),
            'Y no se crea nada a medias mientras no se confirme.'
        );
    }

    /**
     * Confirmando, el nuevo saluda y el viejo sólo pierde la bienvenida.
     *
     * No se apaga ni se borra: si tenía palabras clave sigue respondiendo a
     * ellas. Apagarlo por nuestra cuenta sería una pérdida que no se ve venir.
     *
     * @test
     */
    public function confirmando_el_relevo_el_viejo_solo_pierde_la_bienvenida(): void
    {
        $instance = $this->metaInstance();
        $this->comoAdminDe($instance);

        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $viejo = $this->menu($instance, ['Una'], [
            'name' => 'Menú principal',
            'instance_id' => null,
            'match_types' => ['welcome', 'contains'],
            'trigger_text' => 'menu',
        ]);

        $this->post(
            route('whatsapp-menus.store'),
            $this->nuevoMenuDeBienvenida() + ['reemplazar_bienvenida' => true]
        )->assertSessionHasNoErrors()->assertRedirect();

        $viejo->refresh();

        $this->assertSame(['contains'], array_values((array) $viejo->match_types));
        $this->assertTrue((bool) $viejo->active, 'No se apaga: sólo deja de saludar.');

        $nuevo = WhatsAppMenu::where('name', 'El nuevo')->firstOrFail();
        $this->assertContains('welcome', (array) $nuevo->match_types);

        // Y queda uno solo saludando, que es la razón de toda esta regla.
        $this->assertSame(1, WhatsAppMenu::where('company_id', $instance->company_id)
            ->where('is_root', true)
            ->get()
            ->filter(fn ($m) => in_array('welcome', (array) $m->match_types, true))
            ->count());
    }

    /** @return array<string, mixed> */
    private function nuevoMenuDeBienvenida(): array
    {
        return [
            'name' => 'El nuevo',
            'instance_id' => null,
            'is_root' => true,
            'match_types' => ['welcome'],
            'trigger_text' => '',
            'body_text' => 'Hola, ¿en qué te ayudo?',
            'list_button_text' => 'Ver opciones',
            'active' => true,
            'cooldown_minutes' => 0,
            'options' => [[
                'title' => 'Hablar con alguien',
                'action_type' => 'handoff',
                'reply_text' => 'Te paso con una persona.',
                'config' => ['assign_strategy' => 'inbox'],
            ]],
        ];
    }

    // ------------------------------------------------------------------
    // Volver a saludar a quien regresa
    // ------------------------------------------------------------------

    /**
     * «Bienvenida» ya no es irrepetible.
     *
     * Era literal: el primer mensaje entrante de ese contacto contando desde
     * siempre. Quien escribió una vez hace meses no volvía a recibir el saludo
     * jamás, y probarlo con el propio número era imposible en cuanto lo habías
     * usado una vez — la causa número uno de «configuré el menú y no salta».
     *
     * @test
     */
    public function vuelve_a_saludar_a_quien_regresa_tras_el_silencio(): void
    {
        $instance = $this->metaInstance();
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $menu = $this->menu($instance, ['Una'], [
            'match_types' => ['welcome'],
            'saludar_de_nuevo_horas' => 24,
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.A'))->assertOk();
        $this->assertSame(1, (int) $menu->refresh()->fires_count, 'Su primer mensaje: saluda.');

        // Escribe otra vez seguido: no se le vuelve a saludar.
        $this->postSignedWebhook($this->inbound($instance, 'Ahí sigo', 'wamid.B'))->assertOk();
        $this->assertSame(1, (int) $menu->refresh()->fires_count, 'A mitad de conversación no se saluda.');

        // Se va y vuelve dos días después.
        $this->envejecerLoEntrante(48);

        $this->postSignedWebhook($this->inbound($instance, 'Buenas de nuevo', 'wamid.C'))->assertOk();
        $this->assertSame(2, (int) $menu->refresh()->fires_count, 'Vuelve tras el silencio: se le saluda otra vez.');
    }

    /**
     * Con 0 el reloj no saluda nunca, por mucho que tarde en volver.
     *
     * Se deja a mano porque hay negocios a los que saludar dos veces al mismo
     * cliente les parece un error del sistema. Lo que sigue saludando aun con 0
     * es la reapertura de un chat cerrado, que es una decisión de la empresa y
     * no un reloj.
     *
     * @test
     */
    public function con_cero_horas_no_saluda_por_tiempo(): void
    {
        $instance = $this->metaInstance();
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $menu = $this->menu($instance, ['Una'], [
            'match_types' => ['welcome'],
            'saludar_de_nuevo_horas' => 0,
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.A'))->assertOk();
        $this->assertSame(1, (int) $menu->refresh()->fires_count);

        $this->envejecerLoEntrante(24 * 30);

        $this->postSignedWebhook($this->inbound($instance, 'Buenas, un mes después', 'wamid.C'))->assertOk();
        $this->assertSame(1, (int) $menu->refresh()->fires_count, 'Con 0 no se saluda dos veces nunca.');
    }

    /** Retrasa lo ya recibido, para simular que el cliente se fue y volvió. */
    private function envejecerLoEntrante(int $horas): void
    {
        WhatsAppMessage::where('direction', 'inbound')->get()->each(function (WhatsAppMessage $m) use ($horas) {
            $m->forceFill([
                'sent_at' => $m->sent_at?->subHours($horas),
                'created_at' => $m->created_at?->subHours($horas),
            ])->saveQuietly();
        });
    }

    /**
     * Un chat cerrado que el cliente reabre escribiendo vuelve a saludarse.
     *
     * Es la señal más clara que hay, y mejor que cualquier reloj: cerrar es un
     * acto deliberado de la empresa que dice «esto se terminó», así que el
     * mensaje siguiente empieza otra conversación. Pasa aunque cerrara hace
     * cinco minutos y aunque las horas estén en 0.
     *
     * @test
     */
    public function un_chat_cerrado_que_el_cliente_reabre_vuelve_a_saludarse(): void
    {
        $instance = $this->metaInstance();
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $menu = $this->menu($instance, ['Una'], [
            'match_types' => ['welcome'],
            // En 0 el reloj no saluda nunca: lo que saluda aquí es la reapertura.
            'saludar_de_nuevo_horas' => 0,
            // Y con la espera entre envíos puesta, que es lo que trae de
            // fábrica: la reapertura tiene que poder saltársela. Sin esto el
            // saludo se disparaba y se quedaba «en cooldown», que es
            // exactamente lo que hace imposible probarlo —cerrar y reabrir es
            // lo que uno hace para probar.
            'cooldown_minutes' => 60,
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.A'))->assertOk();
        $this->assertSame(1, (int) $menu->refresh()->fires_count);

        // El asesor termina y cierra.
        WhatsAppConversation::first()->update(['status' => 'closed', 'assigned_to' => null]);

        // Y el cliente vuelve al rato: es una conversación nueva.
        $this->postSignedWebhook($this->inbound($instance, 'Buenas, otra cosa', 'wamid.B'))->assertOk();

        $this->assertSame(2, (int) $menu->refresh()->fires_count);
        $this->assertSame('open', WhatsAppConversation::first()->status, 'Y el chat queda abierto otra vez.');
    }

    // ------------------------------------------------------------------
    // Cómo se vuelve al menú
    // ------------------------------------------------------------------

    /**
     * Lo que contesta una opción dice cómo volver a ver la lista.
     *
     * El cliente toca «Horarios», lee la respuesta y se queda ahí: para volver
     * tiene que subir en el chat y desplegar un menú de hace cinco mensajes.
     *
     * @test
     */
    public function la_respuesta_de_una_opcion_dice_como_volver(): void
    {
        $instance = $this->metaInstance();
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $menu = $this->menu($instance, ['Horarios'], [
            'match_types' => ['welcome', 'contains'],
            'trigger_text' => 'menu, opciones',
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.A'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first(), 'wamid.B'))->assertOk();

        $respuesta = collect($this->textsSent())->last();

        $this->assertStringContainsString('Respuesta de Horarios', $respuesta);
        $this->assertStringContainsString('Escribe *menu* para volver a las opciones', $respuesta);
    }

    /**
     * Pero NO se promete si no hay ningún menú escuchando esa palabra.
     *
     * Decir «escribe menu» donde nadie contesta a «menu» es peor que no decir
     * nada: el cliente lo escribe, no pasa nada, y deja de creerse el resto.
     *
     * @test
     */
    public function sin_palabras_clave_no_se_promete_la_vuelta(): void
    {
        $instance = $this->metaInstance();
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $menu = $this->menu($instance, ['Horarios'], [
            'match_types' => ['welcome'],
            'trigger_text' => null,
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.A'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first(), 'wamid.B'))->assertOk();

        $this->assertStringNotContainsString('para volver a las opciones', collect($this->textsSent())->last());
    }

    /** Y un traspaso a una persona tampoco lo dice: ahí viene alguien detrás. */
    public function test_el_traspaso_no_dice_como_volver(): void
    {
        $instance = $this->metaInstance();
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $menu = $this->menu($instance, ['Hablar con alguien'], [
            'match_types' => ['welcome', 'contains'],
            'trigger_text' => 'menu',
        ]);
        $menu->options->first()->update([
            'action_type' => 'handoff',
            'reply_text' => 'Te paso con una persona.',
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.A'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $menu->options->first(), 'wamid.B'))->assertOk();

        $this->assertStringNotContainsString('para volver a las opciones', collect($this->textsSent())->last());
    }

    /**
     * `{empresa}` se reemplaza por el nombre de la empresa.
     *
     * Sale del menú y no de la conversación: el menú ya sabe de qué empresa es,
     * así que no hace falta saltar instancia → empresa en cada texto. Y así los
     * textos siguen siendo suyos si la empresa se renombra.
     *
     * @test
     */
    public function la_variable_de_empresa_se_reemplaza(): void
    {
        $instance = $this->metaInstance();
        WhatsAppMenu::where('company_id', $instance->company_id)->delete();

        $menu = $this->menu($instance, ['Horarios'], [
            'match_types' => ['welcome'],
            'body_text' => '¡Hola {name}! Soy el asistente de {empresa}.',
        ]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola', 'wamid.A'))->assertOk();

        $enviado = collect($this->interactiveSent())->last();

        $this->assertStringContainsString('Cmnet', $enviado, 'El nombre de la empresa entra en el texto.');
        $this->assertStringNotContainsString('{empresa}', $enviado, 'Y no queda la variable a la vista.');
    }

    /** Lo que se mandó a Meta como cuerpo de un menú interactivo. */
    private function interactiveSent(): array
    {
        $sent = [];

        foreach (Http::recorded() as [$request]) {
            $body = $request->data();

            if (($body['type'] ?? null) === 'interactive') {
                $sent[] = $body['interactive']['body']['text'] ?? '';
            }
        }

        return $sent;
    }
}
