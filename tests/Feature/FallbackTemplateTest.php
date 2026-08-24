<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppFallbackTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Plantilla de respaldo automática fuera de la ventana de 24h.
 *
 * El ERP manda sus avisos como texto libre y no sabe —ni tiene por qué saber—
 * cuándo escribió por última vez cada cliente. Fuera de la ventana ese texto
 * muere con "Re-engagement" después de que Meta haya respondido 200, así que el
 * aviso se pierde sin que nadie se entere. Aquí se comprueba que el mismo
 * texto sale como plantilla aprobada de la propia empresa, y que la plantilla
 * se crea sola en el WABA de cada tenant la primera vez que hace falta.
 */
class FallbackTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const CUERPO = "Hola, te compartimos un aviso de *{{1}}*:\n\n{{2}}\n\nSi tienes alguna consulta, responde a este mensaje y con gusto te atenderemos.";

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.window_guard.mode' => 'shadow', 'whatsapp.window_guard.enforce_companies' => []]);
    }

    private function instancia(string $empresa = 'Cmnet', string $waba = 'waba-1', string $phoneId = '1177962515404155'): Instance
    {
        $company = Company::create(['name' => $empresa, 'slug' => Str::slug($empresa), 'active' => true]);

        return Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => $phoneId,
            'waba_id'         => $waba,
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-' . $waba,
        ]);
    }

    /** Conversación cuyo último entrante es de hace 2 días: ventana cerrada. */
    private function conversacionVencida(Instance $instance, string $phone = '573007852081'): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::create([
            'instance_id'  => $instance->id,
            'wa_id'        => $phone,
            'phone_number' => $phone,
            'name'         => $phone,
            'status'       => 'open',
        ]);

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid'           => 'wamid.' . Str::random(10),
            'type'            => 'text',
            'content'         => 'Buenas',
            'direction'       => 'inbound',
            'status'          => 'delivered',
            'sent_at'         => now()->subDays(2),
        ]);

        return $conversation->fresh();
    }

    /**
     * Graph falso que distingue las tres llamadas que importan: consultar la
     * familia de plantillas, crearla, y enviar el mensaje.
     *
     * @param array $familia Lo que devuelve Meta al consultar la plantilla por nombre.
     */
    private function fakeGraph(array $familia = [], array $altaResponse = ['id' => 'tpl-1', 'status' => 'PENDING']): void
    {
        Http::fake(function ($request) use ($familia, $altaResponse) {
            if (str_contains($request->url(), '/message_templates')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => $familia], 200)
                    : Http::response($altaResponse, 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(8)]]], 200);
        });
    }

    private function familiaAprobada(string $language = 'es', string $status = 'APPROVED'): array
    {
        return [[
            'id' => 'tpl-1',
            'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'language' => $language,
            'status' => $status,
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => self::CUERPO]],
        ]];
    }

    private function enviarAviso(Instance $instance, string $texto, string $phone = '573007852081')
    {
        return $this->withHeader('X-Instance-Token', $instance->phone_number_id)
            ->postJson('/api/v1/messages/send', ['to' => $phone, 'message' => $texto]);
    }

    /** Payload de la última plantilla enviada a la Cloud API. */
    private function plantillaEnviada(): ?array
    {
        foreach (collect(Http::recorded())->reverse() as [$request, $response]) {
            if (($request['type'] ?? null) === 'template') {
                return $request->data();
            }
        }

        return null;
    }

    /* ------------------------- El caso de la captura ------------------------- */

    public function test_fuera_de_ventana_el_texto_del_erp_sale_como_plantilla(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia('MEGASTORE');
        $this->conversacionVencida($instance);

        $aviso = 'MEGASTORE, le informa que su soporte de pago ha sido generado bajo el Nro. 6780';

        $this->enviarAviso($instance, $aviso)
            ->assertOk()
            ->assertJson(['success' => true, 'sent_as' => 'template']);

        $plantilla = $this->plantillaEnviada();

        $this->assertNotNull($plantilla, 'El aviso debió salir como plantilla, no como texto libre.');
        $this->assertSame(WhatsAppFallbackTemplateService::CATALOG_KEY, $plantilla['template']['name']);
        $this->assertSame('es', $plantilla['template']['language']['code']);

        $parametros = array_column($plantilla['template']['components'][0]['parameters'], 'text');
        $this->assertSame(['MEGASTORE', $aviso], $parametros);
    }

    public function test_el_hilo_guarda_lo_que_el_cliente_vio_y_no_el_texto_suelto(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia('MEGASTORE');
        $this->conversacionVencida($instance);

        $aviso = 'Su soporte de pago ha sido generado bajo el Nro. 6780';
        $this->enviarAviso($instance, $aviso)->assertOk();

        $message = WhatsAppMessage::where('direction', 'outbound')->firstOrFail();

        // Si el agente lee en el chat algo distinto de lo que llegó al teléfono,
        // la conversación siguiente arranca torcida.
        $this->assertStringContainsString('Hola, te compartimos un aviso de *MEGASTORE*', $message->content);
        $this->assertStringContainsString($aviso, $message->content);
        $this->assertSame('fallback_template', $message->metadata['window_guard']);
        $this->assertSame($aviso, $message->metadata['original_text']);
        $this->assertSame('text', $message->type);
    }

    public function test_dentro_de_la_ventana_sigue_siendo_texto_libre(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia();
        $conversation = $this->conversacionVencida($instance);
        $conversation->messages()->update(['sent_at' => now()->subHour()]);

        $this->enviarAviso($instance, 'Su soporte de pago ha sido generado.')->assertOk();

        // La plantilla cuesta dinero y suena a robot: dentro de la ventana no
        // hay ninguna razón para usarla.
        $this->assertNull($this->plantillaEnviada());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/message_templates'));
    }

    /* ---------------------------- Alta automática ---------------------------- */

    public function test_si_la_empresa_no_tiene_la_plantilla_se_crea_en_su_waba(): void
    {
        $this->fakeGraph([]); // el WABA no tiene ninguna plantilla con ese nombre

        $instance = $this->instancia('MEGASTORE', 'waba-mega');
        $this->conversacionVencida($instance);

        $this->enviarAviso($instance, 'Su soporte de pago ha sido generado.')->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'waba-mega/message_templates')
                && $request['name'] === WhatsAppFallbackTemplateService::CATALOG_KEY
                && $request['category'] === 'UTILITY';
        });

        // Meta tarda en aprobarla, así que este aviso todavía no se salva: lo
        // que se gana es que el siguiente sí.
        $this->assertSame(
            WhatsAppFallbackTemplateService::STATUS_PENDING,
            $instance->fresh()->fallbackTemplateSettings()['status']
        );

        $message = WhatsAppMessage::where('direction', 'outbound')->firstOrFail();
        $this->assertSame('shadow_pass', $message->metadata['window_guard']);
        $this->assertSame('PENDING', $message->metadata['fallback_status']);
    }

    public function test_una_plantilla_pendiente_no_se_intenta_enviar(): void
    {
        $this->fakeGraph($this->familiaAprobada(status: 'PENDING'));

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        $this->enviarAviso($instance, 'Su factura fue generada.')->assertOk();

        // Enviar una plantilla no aprobada es un 132001 seguro: peor que el
        // texto libre, porque además confunde el diagnóstico.
        $this->assertNull($this->plantillaEnviada());
    }

    public function test_una_plantilla_rechazada_se_registra_y_no_se_usa(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-1',
                    'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
                    'language' => 'es',
                    'status' => 'REJECTED',
                    'rejected_reason' => 'INVALID_FORMAT',
                    'components' => [['type' => 'BODY', 'text' => self::CUERPO]],
                ]]], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(8)]]], 200);
        });

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        $this->enviarAviso($instance, 'Su factura fue generada.')->assertOk();

        $settings = $instance->fresh()->fallbackTemplateSettings();

        $this->assertSame(WhatsAppFallbackTemplateService::STATUS_REJECTED, $settings['status']);
        $this->assertStringContainsString('INVALID_FORMAT', $settings['last_error']);
        $this->assertNull($this->plantillaEnviada());
    }

    public function test_no_se_vuelve_a_preguntar_a_meta_en_cada_aviso(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        $this->enviarAviso($instance, 'Primer aviso.')->assertOk();
        $this->enviarAviso($instance, 'Segundo aviso.')->assertOk();
        $this->enviarAviso($instance, 'Tercer aviso.')->assertOk();

        // Una consulta al Graph por aviso agotaría el rate limit del tenant en
        // cuanto la facturación mensual dispare unos miles de avisos de golpe.
        $consultas = collect(Http::recorded())
            ->filter(fn ($par) => $par[0]->method() === 'GET' && str_contains($par[0]->url(), '/message_templates'))
            ->count();

        $this->assertSame(1, $consultas);
        $this->assertCount(3, WhatsAppMessage::where('direction', 'outbound')->get());
    }

    /* ------------------------------ Multitenant ------------------------------ */

    public function test_cada_empresa_usa_su_propio_waba_su_token_y_su_nombre(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $mega = $this->instancia('MEGASTORE', 'waba-mega', '111');
        $cmnet = $this->instancia('Cmnet', 'waba-cmnet', '222');

        $this->conversacionVencida($mega, '573001111111');
        $this->conversacionVencida($cmnet, '573002222222');

        $this->enviarAviso($mega, 'Aviso de Megastore.', '573001111111')->assertOk();
        $this->enviarAviso($cmnet, 'Aviso de Cmnet.', '573002222222')->assertOk();

        // Cada plantilla se consulta en el WABA de su empresa, con su token.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'waba-mega/message_templates')
            && $r->hasHeader('Authorization', 'Bearer token-waba-mega'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'waba-cmnet/message_templates')
            && $r->hasHeader('Authorization', 'Bearer token-waba-cmnet'));

        // Y el cliente ve el nombre de su proveedor, no el de otro tenant.
        $firmas = collect(Http::recorded())
            ->filter(fn ($par) => ($par[0]['type'] ?? null) === 'template')
            ->map(fn ($par) => $par[0]['template']['components'][0]['parameters'][0]['text'])
            ->values()
            ->all();

        $this->assertSame(['MEGASTORE', 'Cmnet'], $firmas);
    }

    public function test_alternar_tenants_en_el_mismo_proceso_no_mezcla_los_nombres(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $abc = $this->instancia('ABC', 'waba-abc', '111');
        $cba = $this->instancia('CBA', 'waba-cba', '222');

        $this->conversacionVencida($abc, '573001111111');
        $this->conversacionVencida($cba, '573002222222');

        // Alternados y repetidos: si el servicio guardara estado entre llamadas
        // (una propiedad, un caché sin la instancia en la clave), el segundo
        // envío de cada tanda saldría firmado con el nombre del otro tenant.
        $this->enviarAviso($abc, 'Aviso 1 de ABC.', '573001111111')->assertOk();
        $this->enviarAviso($cba, 'Aviso 1 de CBA.', '573002222222')->assertOk();
        $this->enviarAviso($abc, 'Aviso 2 de ABC.', '573001111111')->assertOk();
        $this->enviarAviso($cba, 'Aviso 2 de CBA.', '573002222222')->assertOk();

        // Se comprueba sobre lo que quedó en la BD, que es lo que el cliente
        // leyó, y no sólo sobre lo que se envió.
        $porEmpresa = WhatsAppMessage::where('direction', 'outbound')
            ->join('whatsapp_conversations as c', 'c.id', '=', 'whatsapp_messages.conversation_id')
            ->join('instances as i', 'i.id', '=', 'c.instance_id')
            ->orderBy('whatsapp_messages.id')
            ->get(['whatsapp_messages.content', 'i.company_id']);

        $this->assertCount(4, $porEmpresa);

        foreach ($porEmpresa as $fila) {
            $propio = $fila->company_id === $abc->company_id ? 'ABC' : 'CBA';
            $ajeno  = $propio === 'ABC' ? 'CBA' : 'ABC';

            $this->assertStringContainsString("*{$propio}*", $fila->content);
            $this->assertStringNotContainsString("*{$ajeno}*", $fila->content);
        }
    }

    public function test_un_token_que_apunta_a_dos_empresas_activas_no_autentica(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        // El índice único es (company_id, phone_number_id): nada impide que dos
        // empresas registren el mismo número. `first()` se quedaba con la de id
        // más bajo y el aviso de una salía firmado con el nombre de la otra.
        $abc = $this->instancia('ABC', 'waba-abc', '900900900');
        $this->instancia('CBA', 'waba-cba', '900900900');

        $this->conversacionVencida($abc);

        $this->enviarAviso($abc, 'Su factura fue generada.')
            ->assertStatus(409)
            ->assertJson(['code' => 'ambiguous_instance']);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    public function test_una_duplicada_inactiva_no_bloquea_a_la_empresa_buena(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $abc = $this->instancia('ABC', 'waba-abc', '900900900');
        $this->instancia('CBA', 'waba-cba', '900900900')->update(['active' => false]);

        $this->conversacionVencida($abc);

        // Desactivar la duplicada es justo la salida que se le pide al
        // administrador: tiene que desbloquear el envío.
        $this->enviarAviso($abc, 'Su factura fue generada.')
            ->assertOk()
            ->assertJson(['sent_as' => 'template']);

        $parametros = array_column($this->plantillaEnviada()['template']['components'][0]['parameters'], 'text');
        $this->assertSame('ABC', $parametros[0]);
    }

    public function test_el_estado_de_una_empresa_no_contamina_a_la_otra(): void
    {
        // El WABA de Megastore tiene la plantilla aprobada; el de Cmnet no la
        // tiene. Un estado global habría dado por buena la de Cmnet.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'waba-mega/message_templates')) {
                return Http::response(['data' => $this->familiaAprobada()], 200);
            }
            if (str_contains($request->url(), '/message_templates')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => []], 200)
                    : Http::response(['id' => 'tpl-2', 'status' => 'PENDING'], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(8)]]], 200);
        });

        $mega = $this->instancia('MEGASTORE', 'waba-mega', '111');
        $cmnet = $this->instancia('Cmnet', 'waba-cmnet', '222');

        $this->conversacionVencida($mega, '573001111111');
        $this->conversacionVencida($cmnet, '573002222222');

        $this->enviarAviso($mega, 'Aviso de Megastore.', '573001111111')
            ->assertOk()->assertJson(['sent_as' => 'template']);

        $this->enviarAviso($cmnet, 'Aviso de Cmnet.', '573002222222')
            ->assertOk()->assertJsonMissing(['sent_as' => 'template']);

        $this->assertSame('APPROVED', $mega->fresh()->fallbackTemplateSettings()['status']);
        $this->assertSame('PENDING', $cmnet->fresh()->fallbackTemplateSettings()['status']);
    }

    /* --------------------- Saneado del texto del ERP ------------------------- */

    public function test_el_texto_multilinea_se_aplana_para_que_meta_no_lo_rechace(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        // Meta rechaza con 132007 los parámetros con saltos de línea,
        // tabuladores o más de cuatro espacios seguidos.
        $this->enviarAviso($instance, "Su factura fue generada.\n\nTotal:    120.000\tVence: 10/09")->assertOk();

        $texto = $this->plantillaEnviada()['template']['components'][0]['parameters'][1]['text'];

        $this->assertSame('Su factura fue generada. Total: 120.000 Vence: 10/09', $texto);
    }

    public function test_un_aviso_larguisimo_se_recorta_para_caber_en_la_plantilla(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        $this->enviarAviso($instance, str_repeat('detalle de la factura ', 100))->assertOk();

        $message = WhatsAppMessage::where('direction', 'outbound')->firstOrFail();

        // 1024 es el tope de Meta para el cuerpo ya renderizado.
        $this->assertLessThanOrEqual(1024, mb_strlen($message->content));
        $this->assertStringEndsWith('te atenderemos.', $message->content);
    }

    public function test_adopta_el_idioma_con_el_que_la_empresa_aprobo_la_plantilla(): void
    {
        $this->fakeGraph($this->familiaAprobada(language: 'es_ES'));

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        $this->enviarAviso($instance, 'Su factura fue generada.')->assertOk();

        // Insistir en "es" habría creado un duplicado que Meta rechaza por
        // nombre repetido, dejando al tenant sin respaldo para siempre.
        $this->assertSame('es_ES', $this->plantillaEnviada()['template']['language']['code']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/message_templates'));
    }

    /* ------------------------ Plantilla propia del tenant -------------------- */

    public function test_la_empresa_puede_usar_su_propia_plantilla_con_su_mapeo(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-9',
                    'name' => 'aviso_megastore',
                    'language' => 'es',
                    'status' => 'APPROVED',
                    'components' => [['type' => 'BODY', 'text' => 'Estimado/a {{1}}, {{2}} le informa: {{3}}. Gracias.']],
                ]]], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(8)]]], 200);
        });

        $instance = $this->instancia('MEGASTORE');
        $instance->mergeFallbackTemplate([
            'name' => 'aviso_megastore',
            'language' => 'es',
            'variables' => ['customer_name', 'business_name', 'message'],
        ]);
        $instance->save();

        $conversation = $this->conversacionVencida($instance);
        $conversation->update(['name' => 'Gonzalo Rueda']);

        $this->enviarAviso($instance, 'su pago fue registrado')->assertOk();

        $parametros = array_column($this->plantillaEnviada()['template']['components'][0]['parameters'], 'text');

        $this->assertSame(['Gonzalo Rueda', 'MEGASTORE', 'su pago fue registrado'], $parametros);
    }

    public function test_un_mapeo_que_no_cuadra_no_se_envia(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-9',
                    'name' => 'aviso_megastore',
                    'language' => 'es',
                    'status' => 'APPROVED',
                    'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, {{2}} le informa: {{3}}.']],
                ]]], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(8)]]], 200);
        });

        $instance = $this->instancia();
        $instance->mergeFallbackTemplate([
            'name' => 'aviso_megastore',
            'language' => 'es',
            'variables' => ['business_name', 'message'], // faltan datos para {{3}}
        ]);
        $instance->save();

        $this->conversacionVencida($instance);

        $this->enviarAviso($instance, 'su pago fue registrado')->assertOk();

        // Mandarla igual sería un 132000 de Meta: el aviso se pierde lo mismo,
        // pero encima pagando la plantilla.
        $this->assertNull($this->plantillaEnviada());
    }

    public function test_una_plantilla_propia_que_no_existe_no_se_crea_sola(): void
    {
        $this->fakeGraph([]);

        $instance = $this->instancia();
        $instance->mergeFallbackTemplate(['name' => 'plantilla_del_tenant', 'language' => 'es']);
        $instance->save();

        $this->conversacionVencida($instance);
        $this->enviarAviso($instance, 'Su factura fue generada.')->assertOk();

        // Crear en el WABA ajeno una plantilla que el tenant borró a propósito
        // sería pisarle su decisión.
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/message_templates'));
        $this->assertSame(
            WhatsAppFallbackTemplateService::STATUS_MISSING,
            $instance->fresh()->fallbackTemplateSettings()['status']
        );
    }

    public function test_la_empresa_puede_apagar_el_respaldo(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia();
        $instance->mergeFallbackTemplate(['disabled' => true]);
        $instance->save();

        $this->conversacionVencida($instance);
        $this->enviarAviso($instance, 'Su factura fue generada.')->assertOk();

        // Apagado significa apagado: ni consulta a Meta ni alta silenciosa.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/message_templates'));
        $this->assertNull($this->plantillaEnviada());
    }

    /* ------------------------------ Guardarraíl ------------------------------ */

    public function test_en_enforce_el_rechazo_explica_en_que_va_la_plantilla(): void
    {
        config(['whatsapp.window_guard.mode' => 'enforce']);
        $this->fakeGraph($this->familiaAprobada(status: 'PENDING'));

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        $respuesta = $this->enviarAviso($instance, 'Su factura fue generada.')
            ->assertStatus(422)
            ->assertJson(['code' => 'window_closed', 'template_status' => 'PENDING']);

        $this->assertStringContainsString('espera aprobación de Meta', $respuesta->json('error'));
        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    public function test_con_plantilla_aprobada_el_modo_enforce_ya_no_rechaza_nada(): void
    {
        config(['whatsapp.window_guard.mode' => 'enforce']);
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia();
        $this->conversacionVencida($instance);

        // Es el estado al que se quiere llegar: el guardarraíl deja de ser un
        // rechazo y pasa a ser una entrega por otro camino.
        $this->enviarAviso($instance, 'Su factura fue generada.')
            ->assertOk()
            ->assertJson(['sent_as' => 'template']);
    }

    public function test_apagarlo_con_la_plantilla_ya_aprobada_lo_apaga_de_verdad(): void
    {
        $this->fakeGraph($this->familiaAprobada());

        $instance = $this->instancia();
        // Estado del que se parte en producción: la plantilla lleva tiempo
        // aprobada y guardada, y sólo entonces la empresa decide apagar.
        $instance->mergeFallbackTemplate([
            'name' => WhatsAppFallbackTemplateService::CATALOG_KEY,
            'language' => 'es',
            'source' => 'catalog',
            'status' => 'APPROVED',
            'body' => self::CUERPO,
            'checked_at' => now()->toIso8601String(),
            'disabled' => true,
        ]);
        $instance->save();

        $this->conversacionVencida($instance);
        $this->enviarAviso($instance, 'Su factura fue generada.')->assertOk();

        $this->assertNull($this->plantillaEnviada());
    }

    public function test_reactivar_el_respaldo_no_borra_la_plantilla_propia(): void
    {
        $this->fakeGraph([[
            'id' => 'tpl-9',
            'name' => 'aviso_megastore',
            'language' => 'es',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Hola, {{1}} le informa: {{2}}.']],
        ]]);

        $instance = $this->instancia('MEGASTORE');
        $instance->mergeFallbackTemplate([
            'name' => 'aviso_megastore',
            'language' => 'es',
            'variables' => ['business_name', 'message'],
        ]);
        $instance->save();

        // User::booted() le da el rol admin con todos los permisos existentes al
        // primer usuario de la empresa: el permiso debe existir antes que él.
        Permission::firstOrCreate(['name' => 'instances.update', 'guard_name' => 'web']);

        $admin = User::create([
            'name' => 'Dueño',
            'email' => Str::random(8) . '@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $instance->company_id,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/settings/whatsapp/fallback-template', [
                'instance_id' => $instance->id, 'disabled' => true,
            ])->assertOk();

        $this->actingAs($admin)
            ->postJson('/api/settings/whatsapp/fallback-template', [
                'instance_id' => $instance->id, 'disabled' => false,
            ])->assertOk();

        // Apagar y volver a encender no es lo mismo que "vuelve al catálogo":
        // la empresa eligió su plantilla y debe seguir ahí.
        $settings = $instance->fresh()->fallbackTemplateSettings();

        $this->assertSame('aviso_megastore', $settings['name']);
        $this->assertSame(['business_name', 'message'], $settings['variables']);
        $this->assertFalse($settings['disabled']);
    }

    /* -------------------------------- Comando -------------------------------- */

    public function test_el_comando_aprovisiona_la_plantilla_de_todos_los_tenants(): void
    {
        $this->fakeGraph([]);

        $mega = $this->instancia('MEGASTORE', 'waba-mega', '111');
        $cmnet = $this->instancia('Cmnet', 'waba-cmnet', '222');

        // Dar de alta la plantilla antes de que haga falta evita perder el
        // primer aviso de cada tenant mientras Meta la revisa.
        $this->artisan('whatsapp:fallback-template')->assertExitCode(0);

        foreach ([$mega, $cmnet] as $instance) {
            $this->assertSame(
                WhatsAppFallbackTemplateService::STATUS_PENDING,
                $instance->fresh()->fallbackTemplateSettings()['status']
            );
        }

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'waba-mega/message_templates'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'waba-cmnet/message_templates'));
    }

    public function test_el_comando_en_dry_no_toca_meta(): void
    {
        $this->fakeGraph([]);
        $this->instancia();

        $this->artisan('whatsapp:fallback-template --dry')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
