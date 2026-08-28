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
    private ?int $estadoContratoFalla = null;

    /**
     * Lo que cada prueba quiera cambiar del resumen, sólo la rama que le
     * importa: el resto del payload sigue siendo el de fábrica.
     *
     * @var array<string, mixed>
     */
    private array $resumenExtra = [];

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

            if (str_contains($url, '/resumen')) {
                if ($this->estadoContratoFalla !== null) {
                    return Http::response(
                        ['success' => false, 'message' => 'El token no tiene permiso para esta operación.'],
                        $this->estadoContratoFalla
                    );
                }

                return Http::response($this->contractSummaryResponse(
                    $this->servicioActivo,
                    $this->servicioActivo ? 0 : 70000,
                    $this->resumenExtra
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
        $this->postSignedWebhook($this->inbound($instance, '40389154', 'wamid.IN3'))->assertOk();

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
            $this->postSignedWebhook($this->inbound($instance, $intento, 'wamid.INT' . $i))->assertOk();
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

    /**
     * El cliente que no ve avance vuelve a reportar, y el técnico acaba con
     * tres radicados de la misma falla. Enseñarle el que ya tiene, con su
     * número y su estado, le da algo concreto por lo que preguntar.
     */
    public function test_no_se_abre_un_segundo_radicado_si_ya_tiene_uno_en_curso(): void
    {
        $this->servicioActivo = true;
        $this->resumenExtra = ['soportes' => [
            'abiertos' => 1,
            'items' => [
                ['codigo' => 5601, 'fecha' => '2026-08-26', 'servicio' => 'Sin internet', 'estado' => 'en_proceso'],
            ],
        ]];

        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Reportar falla', 'reportar_falla', [
            'config' => ['radicado_servicio' => 3, 'radicado_prioridad' => 2],
        ]);

        $this->tap($instance, $option);

        $reply = $this->lastText();
        $this->assertStringContainsString('#5601', $reply);
        $this->assertStringContainsString('en proceso', $reply);
        $this->assertStringContainsString('no hace falta que lo reportes otra vez', $reply);

        $this->assertSame(0, $this->requestsTo('/api/v1/radicados'));
        // Y tampoco se le pide describir una falla que ya describió.
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

        $this->postSignedWebhook($this->inbound(
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
     * Revisar el estado del contrato es una conveniencia, no un requisito.
     *
     * Es el fallo que se vio en producción: el token no tenía el scope
     * `contratos.leer`, la consulta previa reventaba y el cliente se quedaba
     * sin poder reportar su falla. Perder el atajo es aceptable; perder el
     * reporte no.
     */
    public function test_si_no_se_puede_revisar_el_contrato_el_reporte_sigue_adelante(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Reportar falla', 'reportar_falla', [
            'config' => ['radicado_servicio' => 3, 'radicado_prioridad' => 2],
        ]);

        // 403: el token no tiene scope contratos.leer.
        $this->estadoContratoFalla = 403;

        $this->tap($instance, $option);

        $this->assertStringContainsString('qué está pasando', $this->lastText());

        $this->postSignedWebhook($this->inbound($instance, 'Sin internet', 'wamid.IN9'))->assertOk();

        $this->assertStringContainsString('#5601', $this->lastText());
        $this->assertSame('Sin internet', $this->lastBodyTo('/api/v1/radicados')['reporte']);
    }

    /** Y lo mismo si el entorno Integra ni siquiera tiene esa ruta. */
    public function test_un_entorno_integra_sin_la_ruta_de_estado_tampoco_bloquea_el_reporte(): void
    {
        $instance = $this->connectedInstance();
        $option = $this->option($instance, 'Reportar falla', 'reportar_falla', [
            'config' => ['radicado_servicio' => 3],
        ]);

        $this->estadoContratoFalla = 404;

        $this->tap($instance, $option);
        $this->postSignedWebhook($this->inbound($instance, 'Se corta a ratos', 'wamid.IN9'))->assertOk();

        $this->assertStringContainsString('#5601', $this->lastText());
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
        $this->postSignedWebhook($this->inbound($instance, 'Se corta a ratos', 'wamid.IN9'))->assertOk();

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
    /**
     * "Suspendido" a secas deja al cliente igual de perdido. Lo que cambia la
     * conversación es el motivo y, cuando es mora, el monto exacto con el que
     * vuelve a navegar.
     */
    public function test_segmento_internet_explica_por_que_esta_suspendido(): void
    {
        $reply = $this->segmentReply('internet');

        $this->assertStringContainsString('Suspendido', $reply);
        $this->assertStringContainsString('por facturas pendientes', $reply);
        $this->assertStringContainsString('$45.000', $reply);
        $this->assertStringContainsString('se hace solo en cuanto registremos el pago', $reply);
        // El detalle que redacta Integra viaja tal cual: sabe cosas del caso
        // que aquí no tenemos.
        $this->assertStringContainsString('10/08/2026', $reply);
    }

    /**
     * Reactivar puede costar menos que la deuda entera —basta con cubrir el
     * ciclo vencido—, y cobrar de más ahuyenta justo al que iba a pagar.
     */
    public function test_el_monto_para_reactivar_no_se_confunde_con_la_deuda_total(): void
    {
        $reply = $this->segmentReply('internet');

        $this->assertStringContainsString('Con *$45.000* se reactiva', $reply);
        $this->assertStringContainsString('Tu deuda total es de $70.000', $reply);
    }

    /**
     * Pausa y retiro no se arreglan pagando, así que ofrecerle pagar sería
     * mandarlo a un callejón sin salida.
     */
    public function test_una_pausa_no_se_cuenta_como_una_mora(): void
    {
        $this->resumenExtra = ['servicio' => ['internet' => [
            'motivo' => 'pausado',
            'monto_para_reactivar' => null,
        ]]];

        $reply = $this->segmentReply('internet');

        $this->assertStringContainsString('pausado a petición tuya', $reply);
        $this->assertStringNotContainsString('se reactiva', $reply);
    }

    /** Suspendido sin deuda es una falla, no un cobro: hay que mandarlo a soporte. */
    public function test_una_suspension_sin_deuda_manda_a_reportar_y_no_a_pagar(): void
    {
        $this->resumenExtra = ['servicio' => ['internet' => [
            'motivo' => 'suspendido',
            'monto_para_reactivar' => null,
        ]]];

        $reply = $this->segmentReply('internet');

        $this->assertStringContainsString('la suspensión no es por pago', $reply);
        $this->assertStringContainsString('Repórtalo desde el menú', $reply);
    }

    /** Con el servicio activo, el cliente necesita saber qué hacer a continuación. */
    public function test_segmento_internet_activo_dice_como_seguir(): void
    {
        $this->servicioActivo = true;

        $reply = $this->segmentReply('internet');

        $this->assertStringContainsString('Activo', $reply);
        $this->assertStringContainsString('repórtalo desde el menú', $reply);
    }

    public function test_segmento_plan_responde_megas_y_precio(): void
    {
        $reply = $this->segmentReply('plan');

        $this->assertStringContainsString('ZAFIRO GOLD 3.0', $reply);
        $this->assertStringContainsString('300 Mbps', $reply);
        $this->assertStringContainsString('150 Mbps', $reply);
        $this->assertStringContainsString('$60.000', $reply);
    }

    /**
     * El ciclo va por días del mes, no por una fecha suelta: el cliente que
     * pregunta "¿cuándo me cortan?" quiere saber su día, no el del mes que
     * viene.
     */
    public function test_segmento_corte_responde_el_ciclo_completo(): void
    {
        $reply = $this->segmentReply('corte');

        $this->assertStringContainsString('PERIODO 1 AL 30', $reply);
        $this->assertStringContainsString('día 1 de cada mes', $reply);
        $this->assertStringContainsString('día 15', $reply);
        $this->assertStringContainsString('día 20', $reply);
        $this->assertStringContainsString('para no perder el servicio', $reply);
    }

    /**
     * Quien ya tiene una prórroga aprobada y lee "te cortan el día 20" vuelve
     * a escribir asustado. La promesa vigente manda sobre la fecha de corte.
     */
    public function test_una_prorroga_vigente_manda_sobre_la_fecha_de_corte(): void
    {
        $this->resumenExtra = ['facturacion' => ['promesa_pago' => [
            'fecha' => '2026-08-20',
            'vence' => '2026-09-02',
            'vigente' => true,
        ]]];

        $reply = $this->segmentReply('corte');

        $this->assertStringContainsString('Tienes una prórroga vigente', $reply);
        $this->assertStringContainsString('02/09/2026', $reply);
        $this->assertStringNotContainsString('para no perder el servicio', $reply);
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
    // ------------------------------------------------------------------
    // Segmentos que abrió /resumen
    // ------------------------------------------------------------------

    /**
     * "Yo ya pagué y no me lo han registrado" se lleva un asesor entero. Con el
     * recibo delante, la discusión se acaba sola.
     */
    public function test_segmento_pagos_lista_los_ultimos_recibos(): void
    {
        $reply = $this->segmentReply('pagos');

        $this->assertStringContainsString('05/07/2026', $reply);
        $this->assertStringContainsString('$60.000', $reply);
        $this->assertStringContainsString('Efecty', $reply);
        $this->assertStringContainsString('RC-8891', $reply);
    }

    /** Sin pagos registrados hay que decirle qué hacer, no dejarlo en blanco. */
    public function test_sin_pagos_registrados_se_le_pide_el_comprobante(): void
    {
        $this->resumenExtra = ['pagos_recientes' => []];

        $reply = $this->segmentReply('pagos');

        $this->assertStringContainsString('No tengo pagos registrados', $reply);
        $this->assertStringContainsString('comprobante', $reply);
    }

    public function test_segmento_soportes_muestra_los_reportes_abiertos_con_su_estado(): void
    {
        $this->resumenExtra = ['soportes' => [
            'abiertos' => 2,
            'items' => [
                ['codigo' => 5601, 'fecha' => '2026-08-26', 'servicio' => 'Sin internet', 'estado' => 'en_proceso'],
                ['codigo' => 5622, 'fecha' => '2026-08-27', 'servicio' => 'Intermitencia', 'estado' => 'pendiente'],
            ],
        ]];

        $reply = $this->segmentReply('soportes');

        $this->assertStringContainsString('2 reportes abiertos', $reply);
        $this->assertStringContainsString('#5601', $reply);
        $this->assertStringContainsString('en proceso', $reply);
        $this->assertStringContainsString('pendiente de asignar', $reply);
    }

    /**
     * `consumo` en null NO es consumo cero: decirle "llevas 0 GB" a quien lleva
     * el mes navegando tira por tierra la confianza en todo lo demás.
     */
    public function test_sin_muestras_de_consumo_no_se_inventa_un_cero(): void
    {
        $this->resumenExtra = ['consumo' => null];

        $reply = $this->segmentReply('consumo');

        $this->assertStringContainsString('Todavía no tengo mediciones', $reply);
        $this->assertStringNotContainsString('0 GB', $reply);
    }

    public function test_segmento_consumo_responde_el_mes_y_el_detalle_por_dia(): void
    {
        $reply = $this->segmentReply('consumo');

        $this->assertStringContainsString('141 GB', $reply);
        $this->assertStringContainsString('27/08/2026', $reply);
    }

    public function test_segmento_wifi_entrega_la_clave_registrada(): void
    {
        $reply = $this->segmentReply('wifi');

        $this->assertStringContainsString('INTERSOLAR_5G', $reply);
        $this->assertStringContainsString('casa1234', $reply);
    }

    /** Sin credenciales guardadas hay que ofrecer la salida real: el asesor. */
    public function test_sin_clave_wifi_guardada_se_ofrece_un_asesor(): void
    {
        $this->resumenExtra = ['wifi' => null];

        $reply = $this->segmentReply('wifi');

        $this->assertStringContainsString('No tengo guardadas las credenciales', $reply);
    }

    public function test_segmento_contrato_responde_permanencia_reconexion_y_pdf(): void
    {
        $reply = $this->segmentReply('contrato');

        $this->assertStringContainsString('12 meses', $reply);
        $this->assertStringContainsString('$15.000', $reply);
        $this->assertStringContainsString('https://demo.integra.test/contratos/15.pdf', $reply);
    }

    /** Sin permanencia, decirlo es la respuesta: es lo que vino a preguntar. */
    public function test_sin_permanencia_se_dice_que_puede_cancelar(): void
    {
        $this->resumenExtra = ['condiciones' => ['permanencia_meses' => null]];

        $reply = $this->segmentReply('contrato');

        $this->assertStringContainsString('Sin cláusula de permanencia', $reply);
    }

    /**
     * El saldo a favor invisible es la fuente número uno de "yo pagué de más y
     * nadie me lo abonó".
     */
    public function test_el_saldo_a_favor_sale_en_las_facturas(): void
    {
        $this->resumenExtra = ['facturacion' => ['saldo_a_favor' => 95000]];

        $reply = $this->segmentReply('facturas');

        $this->assertStringContainsString('$95.000', $reply);
        $this->assertStringContainsString('Alcanza para cubrir lo pendiente', $reply);
    }

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
        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inboundReply($instance, $option))->assertOk();
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

    /**
     * El resumen que devuelve `/contratos/{nro}/resumen`.
     *
     * `$extra` se mezcla rama a rama para que cada prueba toque sólo lo suyo
     * —la promesa de pago, el consumo, los soportes— y el resto del contrato
     * siga siendo el de fábrica. Copiar el payload entero en cada prueba lo
     * volvería ilegible y escondería justo el dato que se está probando.
     *
     * @param array<string, mixed> $extra
     */
    private function contractSummaryResponse(bool $activo = false, float $deuda = 70000, array $extra = []): array
    {
        return [
            'success' => true,
            'data' => $this->mergeSummary([
                'contrato' => [
                    'nro' => '15',
                    'servicio' => 'Internet Hogar',
                    'direccion' => 'CRA 5 # 12-34, El Prado',
                    'desde' => '2024-03-01',
                    'plan' => ['nombre' => 'ZAFIRO GOLD 3.0', 'descarga' => 300, 'subida' => 150, 'precio' => 60000],
                ],
                'titular' => ['nombre' => 'Katherine Ospina', 'identificacion' => '40389154'],
                'servicio' => [
                    'internet' => [
                        'activo' => $activo,
                        'estado' => $activo ? 'activo' : 'suspendido',
                        'motivo' => $activo ? null : 'mora',
                        'detalle' => $activo ? 'Servicio activo.' : 'Suspendido el 10/08/2026 por facturas vencidas.',
                        'monto_para_reactivar' => $activo ? null : 45000,
                    ],
                    'television' => ['tiene_servicio' => false, 'activo' => false],
                ],
                'facturacion' => [
                    'pendientes' => [
                        'total' => $deuda > 0 ? 1 : 0,
                        'total_por_pagar' => $deuda,
                        // El endpoint del contrato anida los montos; el del
                        // contacto los manda planos. Las dos formas tienen que
                        // pintarse igual.
                        'items' => $deuda > 0 ? [[
                            'id' => 9911,
                            'codigo' => 'FV-1001',
                            'vencimiento' => '2026-07-30',
                            'vencida' => true,
                            'montos' => ['total' => $deuda, 'pagado' => 0, 'por_pagar' => $deuda],
                        ]] : [],
                    ],
                    'saldo_a_favor' => 0,
                    'ciclo' => [
                        'grupo' => 'PERIODO 1 AL 30',
                        'dia_factura' => 1,
                        'dia_pago' => 15,
                        'dia_corte' => 20,
                    ],
                    'promesa_pago' => null,
                ],
                'pagos_recientes' => [
                    ['recibo' => 'RC-8891', 'fecha' => '2026-07-05', 'valor' => 60000, 'medio' => 'Efecty'],
                    ['recibo' => 'RC-8420', 'fecha' => '2026-06-04', 'valor' => 60000, 'medio' => 'PSE'],
                ],
                'soportes' => ['abiertos' => 0, 'items' => []],
                'consumo' => [
                    'mes_actual' => ['descarga_gb' => 128.4, 'subida_gb' => 12.6, 'total_gb' => 141.0, 'desde' => '2026-08-01'],
                    'por_dia' => [
                        ['dia' => '2026-08-26', 'descarga_gb' => 6.2, 'subida_gb' => 0.8],
                        ['dia' => '2026-08-27', 'descarga_gb' => 7.1, 'subida_gb' => 0.9],
                    ],
                ],
                'contrato_digital' => [
                    'firmado' => true,
                    'fecha_firma' => '2024-03-01',
                    'tiene_documento' => true,
                    'pdf_url' => 'https://demo.integra.test/contratos/15.pdf',
                ],
                'wifi' => ['red' => 'INTERSOLAR_5G', 'clave' => 'casa1234', 'editable_remotamente' => false],
                'condiciones' => [
                    'permanencia_meses' => 12,
                    'costo_reconexion' => 15000,
                    'descuento' => 0,
                    'descuento_hasta' => null,
                ],
            ], $extra),
        ];
    }

    /**
     * Mezcla lo que pide la prueba sobre el resumen de fábrica.
     *
     * No sirve array_replace_recursive a secas: fusiona las listas índice a
     * índice, así que pedir `['pagos_recientes' => []]` dejaba intactos los
     * pagos de fábrica y la prueba del caso vacío pasaba en verde probando el
     * caso lleno. Las listas se sustituyen enteras; sólo los mapas se recorren.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function mergeSummary(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)
                ? $this->mergeSummary($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
