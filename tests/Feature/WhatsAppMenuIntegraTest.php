<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las acciones de autoservicio del menú contra Integra 2.0.
 *
 * Se prueban de punta a punta desde el webhook de Meta —con Integra simulado a
 * nivel HTTP— porque lo que se quiere verificar no es una llamada suelta sino
 * la conversación: el bot pregunta, el cliente contesta en otro mensaje, y la
 * acción tiene que terminar donde se quedó. Esa costura entre dos webhooks es
 * exactamente lo que ningún test unitario del servicio cubriría.
 */
class WhatsAppMenuIntegraTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '573007852081';
    private const INTEGRA = 'https://demo-integra.test/software';

    private int $sent = 0;

    /**
     * Escenario que simula Integra. Se toca desde cada test antes de que el
     * cliente escriba, en vez de volver a llamar a Http::fake(): un segundo
     * fake no sustituye al primero —los stubs se apilan y gana el más antiguo—
     * y el test acabaría probando el escenario del setUp sin enterarse.
     */
    private bool $servicioActivo = false;
    private bool $contactoEnIntegra = true;
    private bool $contactoBuscablePorCelular = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = 0;

        Http::fake(function ($request) {
            $url = $request->url();

            // Meta: un wamid distinto por envío (la columna es única).
            if (str_contains($url, 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid.OUT' . (++$this->sent)]]], 200);
            }

            if (str_contains($url, '/contactos/buscar')) {
                return Http::response($this->contactResponse($request));
            }

            if (str_contains($url, '/estado')) {
                return Http::response($this->contractStatusResponse(
                    $this->servicioActivo,
                    $this->servicioActivo ? 0 : 70000
                ));
            }

            if (str_contains($url, '/radicados')) {
                return Http::response([
                    'success' => true,
                    'data' => ['id' => 5601, 'codigo' => 5601, 'estatus' => 0, 'cliente_id' => 1245, 'fecha' => '2026-08-27'],
                ], 201);
            }

            // El endpoint de webhooks salientes de la empresa.
            return Http::response(['ok' => true], 200);
        });
    }

    // ------------------------------------------------------------------
    // Consultar factura
    // ------------------------------------------------------------------

    public function test_consultar_factura_responde_las_pendientes_del_cliente(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Consultar factura', 'consultar_factura');

        $this->tap($instance, $option);

        $reply = $this->lastText();
        $this->assertStringContainsString('FV-1001', $reply);
        $this->assertStringContainsString('$70.000', $reply);
        $this->assertStringContainsString('Total por pagar', $reply);
    }

    /**
     * El cliente se identifica solo por su número de WhatsApp. Sin ese atajo
     * cada consulta empezaría pidiendo la cédula, que es la fricción por la que
     * la gente abandona el menú y escribe "necesito hablar con alguien".
     */
    public function test_el_cliente_se_identifica_por_su_numero_de_whatsapp(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Consultar factura', 'consultar_factura');

        $this->tap($instance, $option);

        $this->assertSame('40389154', data_get(
            WhatsAppConversation::first()->metadata,
            'integra.identificacion'
        ));
    }

    /**
     * Cuando el celular no está en Integra hay que preguntar, y la respuesta
     * llega en un mensaje aparte: entre los dos webhooks el bot tiene que
     * recordar qué estaba haciendo.
     */
    public function test_si_el_celular_no_esta_en_integra_pide_el_documento_y_continua(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Consultar factura', 'consultar_factura');

        // Integra no conoce el celular, pero sí la cédula.
        $this->contactoBuscablePorCelular = false;

        $this->tap($instance, $option);

        $this->assertStringContainsString('número de documento', $this->lastText());
        $this->assertSame(
            WhatsAppBotFlow::STEP_IDENTIFICATION,
            WhatsAppBotFlow::first()?->step
        );

        // El cliente escribe su cédula: la acción termina donde se quedó.
        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, '40389154', 'wamid.IN3'))->assertOk();

        $this->assertStringContainsString('FV-1001', $this->lastText());
        // La pregunta se cierra: el siguiente mensaje del cliente vuelve a ser
        // un mensaje normal y no la respuesta a algo que ya no se pregunta.
        $this->assertSame(0, WhatsAppBotFlow::count());
    }

    /**
     * A la tercera el cliente no está escribiendo mal el documento: no está en
     * Integra. Seguir preguntando lo mismo es lo que convierte un menú en una
     * trampa, así que el chat pasa a una persona.
     */
    public function test_tras_varios_intentos_fallidos_el_chat_pasa_a_un_asesor(): void
    {
        $instance = $this->connectedInstance();
        $agent = $this->agent($instance, 'Laura');
        $option = $this->option($instance, 'Consultar factura', 'consultar_factura');

        $this->contactoEnIntegra = false;

        $this->tap($instance, $option);

        foreach (['111', '222', '333'] as $i => $intento) {
            $this->postJson('/webhooks/whatsapp', $this->inbound($instance, $intento, 'wamid.INT' . $i))->assertOk();
        }

        $this->assertStringContainsString('asesor', $this->lastText());
        $this->assertSame($agent->id, WhatsAppConversation::first()->assigned_to);
    }

    // ------------------------------------------------------------------
    // Pagar en línea
    // ------------------------------------------------------------------

    public function test_pagar_en_linea_dispara_el_webhook_y_entrega_el_enlace(): void
    {
        $instance = $this->connectedInstance();
        $endpoint = WebhookEndpoint::create([
            'company_id' => $instance->company_id,
            'name' => 'ERP',
            'url' => 'https://erp.cliente.test/pagos',
            'events' => ['payment.requested'],
            'active' => true,
        ]);

        $option = $this->option($instance, 'Pagar en línea', 'pagar_en_linea', [
            'config' => ['payment_url' => 'https://pagos.test/?nit={nit}&valor={total}'],
        ]);

        $this->tap($instance, $option);

        $this->assertStringContainsString('https://pagos.test/?nit=40389154&valor=70000', $this->lastText());

        $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->first();
        $this->assertNotNull($delivery, 'El webhook de pagos no se disparó.');
        $this->assertSame('payment.requested', $delivery->event);
    }

    // ------------------------------------------------------------------
    // Reportar falla
    // ------------------------------------------------------------------

    /**
     * El pre-chequeo del contrato: si el servicio está cortado por mora la
     * falla no existe, y el radicado sólo le haría perder el viaje a un técnico.
     */
    public function test_reportar_falla_no_crea_radicado_si_el_servicio_esta_suspendido_por_mora(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Reportar falla', 'reportar_falla', [
            'config' => ['radicado_servicio' => 3, 'radicado_prioridad' => 2],
        ]);

        $this->tap($instance, $option);

        $reply = $this->lastText();
        $this->assertStringContainsString('suspendido', $reply);
        $this->assertStringContainsString('$70.000', $reply);

        $this->assertSame(0, $this->requestsTo('/api/v1/radicados'));
        // Y no se queda esperando la descripción de una falla que no hay.
        $this->assertSame(0, WhatsAppBotFlow::count());
    }

    public function test_reportar_falla_pide_la_descripcion_y_crea_el_radicado(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Reportar falla', 'reportar_falla', [
            'config' => ['radicado_servicio' => 3, 'radicado_prioridad' => 3],
        ]);

        // Con el servicio al día sí hay una falla real que reportar.
        $this->servicioActivo = true;

        $this->tap($instance, $option);

        $this->assertStringContainsString('qué está pasando', $this->lastText());
        $this->assertSame(WhatsAppBotFlow::STEP_REPORT, WhatsAppBotFlow::first()?->step);

        $this->postJson('/webhooks/whatsapp', $this->inbound(
            $instance,
            'No tengo internet desde anoche',
            'wamid.IN9'
        ))->assertOk();

        $this->assertStringContainsString('#5601', $this->lastText());

        // El radicado se crea con lo que configuró el admin y con el contrato y
        // la IP del cliente, que es lo que el técnico necesita para salir.
        $body = $this->lastBodyTo('/api/v1/radicados');
        $this->assertSame(3, $body['servicio']);
        $this->assertSame(3, $body['prioridad']);
        $this->assertSame('15', $body['contrato']);
        $this->assertSame('10.80.1.59', $body['ip']);
        $this->assertSame('No tengo internet desde anoche', $body['reporte']);
        $this->assertSame('WhatsApp', $body['medio']);
    }

    /**
     * Sin tipo de falla configurado no hay radicado posible. Lo que no puede
     * pasar es que el cliente escriba su problema y el bot lo tire a la basura.
     */
    public function test_sin_servicio_configurado_el_reporte_pasa_a_un_asesor(): void
    {
        $instance = $this->connectedInstance();
        $agent = $this->agent($instance, 'Laura');
        $option = $this->option($instance, 'Reportar falla', 'reportar_falla');

        $this->servicioActivo = true;

        $this->tap($instance, $option);
        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Se corta a ratos', 'wamid.IN9'))->assertOk();

        $this->assertStringContainsString('asesor', $this->lastText());
        $this->assertSame($agent->id, WhatsAppConversation::first()->assigned_to);
    }

    // ------------------------------------------------------------------
    // Estado del servicio
    // ------------------------------------------------------------------

    public function test_estado_del_servicio_responde_internet_plan_y_deuda(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Estado de mi servicio', 'estado_servicio');

        $this->tap($instance, $option);

        $reply = $this->lastText();
        $this->assertStringContainsString('suspendido', $reply);
        $this->assertStringContainsString('ZAFIRO GOLD 3.0', $reply);
        $this->assertStringContainsString('$70.000', $reply);
    }

    // ------------------------------------------------------------------
    // Segmentos del contrato
    // ------------------------------------------------------------------

    /**
     * Decir sólo "suspendido" deja al cliente igual de perdido: lo que resuelve
     * la consulta es la causa y qué tiene que hacer para salir de ahí.
     */
    public function test_segmento_internet_explica_por_que_esta_suspendido(): void
    {
        $reply = $this->segmentReply('internet');

        $this->assertStringContainsString('Suspendido', $reply);
        $this->assertStringContainsString('10/08/2026', $reply);
        $this->assertStringContainsString('$70.000', $reply);
        $this->assertStringContainsString('se reactiva automáticamente', $reply);
    }

    /** Con el servicio activo, el cliente necesita saber qué hacer a continuación. */
    public function test_segmento_internet_activo_dice_como_seguir(): void
    {
        $this->servicioActivo = true;

        $reply = $this->segmentReply('internet');

        $this->assertStringContainsString('Activo', $reply);
        $this->assertStringContainsString('repórtalo desde el menú', $reply);
    }

    public function test_segmento_plan_responde_megas_tecnologia_y_precio(): void
    {
        $reply = $this->segmentReply('plan');

        $this->assertStringContainsString('ZAFIRO GOLD 3.0', $reply);
        $this->assertStringContainsString('300 Mbps', $reply);
        $this->assertStringContainsString('150 Mbps', $reply);
        $this->assertStringContainsString('Fibra óptica', $reply);
        $this->assertStringContainsString('$60.000', $reply);
    }

    public function test_segmento_corte_responde_periodo_y_fecha(): void
    {
        $reply = $this->segmentReply('corte');

        $this->assertStringContainsString('PERIODO 1 AL 30', $reply);
        $this->assertStringContainsString('05/09/2026', $reply);
        $this->assertStringContainsString('para no perder el servicio', $reply);
    }

    /** Sin deuda no hay corte que anunciar, y decirlo tranquiliza. */
    public function test_segmento_corte_sin_deuda_no_alarma(): void
    {
        $this->servicioActivo = true;

        $reply = $this->segmentReply('corte');

        $this->assertStringContainsString('no hay riesgo de corte', $reply);
    }

    /**
     * Las facturas del contrato llegan con los montos anidados; las del
     * contacto, planos. Las dos formas tienen que pintar la misma línea.
     */
    public function test_segmento_facturas_lee_los_montos_anidados_del_contrato(): void
    {
        $reply = $this->segmentReply('facturas');

        $this->assertStringContainsString('FV-1001', $reply);
        $this->assertStringContainsString('$70.000', $reply);
        $this->assertStringContainsString('30/07/2026', $reply);
    }

    /** El resumen completo sigue siendo lo que reciben las opciones sin segmento. */
    public function test_sin_segmento_configurado_responde_el_resumen_completo(): void
    {
        $reply = $this->segmentReply(null);

        $this->assertStringContainsString('Estado de tu servicio', $reply);
        $this->assertStringContainsString('ZAFIRO GOLD 3.0', $reply);
    }

    // ------------------------------------------------------------------
    // Hablar con un asesor
    // ------------------------------------------------------------------

    /**
     * El reparto por carga: gana quien menos chats abiertos tiene, no quien
     * lleve más tiempo en la lista.
     */
    public function test_hablar_con_un_asesor_asigna_al_que_menos_chats_tiene(): void
    {
        $instance = $this->connectedInstance();
        $ocupada = $this->agent($instance, 'Ana');
        $libre = $this->agent($instance, 'Beto');

        foreach (range(1, 3) as $i) {
            WhatsAppConversation::create([
                'instance_id' => $instance->id,
                'wa_id' => '5730000000' . $i,
                'phone_number' => '5730000000' . $i,
                'status' => 'open',
                'assigned_to' => $ocupada->id,
            ]);
        }

        $option = $this->option($instance, 'Hablar con un asesor', 'handoff', [
            'reply_text' => 'Te comunico con un asesor.',
            'config' => ['assign_strategy' => WhatsAppMenuOption::ASSIGN_LEAST_BUSY],
        ]);

        $this->tap($instance, $option);

        $this->assertSame($libre->id, WhatsAppConversation::where('wa_id', self::PHONE)->first()->assigned_to);
    }

    /**
     * Sin nadie a quien asignar el chat se queda en la bandeja, pero con una
     * nota: es lo único que distingue "pidió un asesor" de un chat cualquiera
     * sin asignar.
     */
    public function test_sin_asesores_disponibles_el_chat_queda_en_la_bandeja_con_nota(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Hablar con un asesor', 'handoff', [
            'config' => ['assign_strategy' => WhatsAppMenuOption::ASSIGN_LEAST_BUSY],
        ]);

        $this->tap($instance, $option);

        $conversation = WhatsAppConversation::where('wa_id', self::PHONE)->first();
        $this->assertNull($conversation->assigned_to);
        $this->assertDatabaseHas('whatsapp_messages', [
            'conversation_id' => $conversation->id,
            'type' => 'system',
            'content' => 'El cliente pidió hablar con un asesor desde el menú',
        ]);
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    /** Instancia de Meta con la integración de Integra ya conectada. */
    private function connectedInstance(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        CompanyIntegration::create([
            'company_id' => $company->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => self::INTEGRA,
            'access_token' => 'itg_token',
            'enabled' => true,
        ]);

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

    private function agent(Instance $instance, string $name): User
    {
        setPermissionsTeamId($instance->company_id);

        $user = User::create([
            'company_id' => $instance->company_id,
            'name' => $name,
            'email' => Str::slug($name) . '@cmnet.test',
            'password' => 'secret',
            'active' => true,
        ]);

        $role = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'agent',
            'company_id' => $instance->company_id,
            'guard_name' => 'web',
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }

    /** Toca una opción "Estado del contrato" con el segmento indicado. */
    private function segmentReply(?string $segment): string
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Estado', 'estado_servicio', [
            'config' => $segment ? ['segmento' => $segment] : null,
        ]);

        $this->tap($instance, $option);

        return $this->lastText();
    }

    /** Un menú de una sola opción con la acción bajo prueba. */
    private function option(Instance $instance, string $title, string $actionType, array $attributes = []): WhatsAppMenuOption
    {
        $menu = WhatsAppMenu::create([
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'name' => 'Menú principal',
            'body_text' => '¿En qué puedo ayudarte?',
            'is_root' => true,
            'match_types' => ['welcome'],
            'active' => true,
            'cooldown_minutes' => 0,
        ]);

        return $menu->options()->create(array_merge([
            'position' => 0,
            'title' => $title,
            'action_type' => $actionType,
        ], $attributes));
    }

    /** El cliente saluda (sale el menú) y toca la opción. */
    private function tap(Instance $instance, WhatsAppMenuOption $option): void
    {
        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inboundReply($instance, $option))->assertOk();
    }

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
                        'contacts' => [['profile' => ['name' => 'Katherine'], 'wa_id' => self::PHONE]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    /** El último texto que se le mandó al cliente. */
    private function lastText(): string
    {
        $texts = [];

        foreach (Http::recorded() as [$request]) {
            if (($request->data()['type'] ?? null) === 'text') {
                $texts[] = $request->data()['text']['body'];
            }
        }

        return (string) end($texts);
    }

    private function requestsTo(string $path): int
    {
        $count = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), $path)) {
                $count++;
            }
        }

        return $count;
    }

    private function lastBodyTo(string $path): array
    {
        $body = [];

        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), $path)) {
                $body = $request->data();
            }
        }

        return $body;
    }

    // ------------------------------------------------------------------
    // Respuestas de Integra
    // ------------------------------------------------------------------

    /**
     * Lo que Integra devuelve al buscar el contacto, según el escenario: puede
     * no existir, o existir pero no estar asociado al celular desde el que
     * escribe el cliente (que es el caso que obliga a preguntar la cédula).
     */
    private function contactResponse($request): array
    {
        $criterio = $request->data()['q'] ?? '';
        $porCelular = str_contains((string) $criterio, '3007852081');

        if (!$this->contactoEnIntegra || ($porCelular && !$this->contactoBuscablePorCelular)) {
            return ['success' => true, 'data' => [], 'meta' => ['total_contactos' => 0]];
        }

        return [
            'success' => true,
            'data' => [[
                'id' => 1245,
                'identificacion' => '40389154',
                'nombre_completo' => 'MARIA TERESA SANCHEZ',
                'contacto' => [
                    'nombre' => 'MARIA TERESA',
                    'apellidos' => 'SANCHEZ',
                    'identificacion' => '40389154',
                    'celular' => '3007852081',
                ],
                'resumen' => [
                    'total_contratos' => 1,
                    'contratos_activos' => 1,
                    'facturas_pendientes' => 1,
                    'total_por_pagar' => 70000,
                ],
                'contratos' => [[
                    'nro' => '15',
                    'id' => 320,
                    'vigente' => true,
                    'activo' => true,
                    'estado_internet' => 'disabled',
                    'red' => ['ip' => '10.80.1.59', 'mac' => 'AA:BB:CC:DD:EE:FF'],
                    'plan_internet' => ['id' => 7, 'nombre' => 'ZAFIRO GOLD 3.0', 'precio' => 60000],
                ]],
                'facturas_pendientes' => [[
                    'codigo' => 'FV-1001',
                    'por_pagar' => 70000,
                    'vencimiento' => '2026-07-30',
                    'contrato_nro' => '15',
                ]],
                'total_por_pagar' => 70000,
            ]],
            'meta' => ['criterio' => '40389154', 'total_contactos' => 1, 'moneda' => 'COP'],
        ];
    }

    private function contractStatusResponse(bool $activo = false, float $deuda = 70000): array
    {
        return [
            'success' => true,
            'data' => [
                'contrato' => [
                    'id' => 320,
                    'nro' => '15',
                    'servicio' => 'Internet Hogar',
                    'direccion' => 'CRA 5 # 12-34, El Prado',
                    'plan' => ['nombre' => 'ZAFIRO GOLD 3.0', 'descarga' => 300, 'subida' => 150, 'precio' => 60000],
                    'tecnologia' => 'fibra',
                    'conexion' => 'pppoe',
                    'grupo_corte' => 'PERIODO 1 AL 30',
                    'fecha_corte' => '2026-09-05',
                    'fecha_suspension' => $activo ? null : '2026-08-10',
                ],
                'estado' => [
                    'internet' => ['activo' => $activo, 'estado' => $activo ? 'activo' : 'suspendido'],
                    'television' => ['tiene_servicio' => false, 'plan' => null, 'activo' => false, 'estado' => 'inactivo'],
                ],
                'facturas_pendientes' => [
                    'total' => $deuda > 0 ? 1 : 0,
                    'total_por_pagar' => $deuda,
                    // El endpoint del contrato anida los montos; el del contacto
                    // los manda planos. Las dos formas tienen que pintarse igual.
                    'items' => $deuda > 0 ? [[
                        'codigo' => 'FV-1001',
                        'vencimiento' => '2026-07-30',
                        'vencida' => true,
                        'montos' => ['total' => $deuda, 'pagado' => 0, 'por_pagar' => $deuda],
                    ]] : [],
                ],
            ],
        ];
    }
}
