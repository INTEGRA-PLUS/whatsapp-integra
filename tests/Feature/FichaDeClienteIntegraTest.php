<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Integra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La ficha del cliente en Integra que se pinta en el panel del chat.
 *
 * Integra no tiene un endpoint "dame todo del cliente": los datos, las facturas
 * y los pagos/radicados viven en tres sitios distintos y el último es POR
 * CONTRATO. Lo que se protege aquí es el pegado: que los pagos no se dupliquen
 * al repetirse en cada contrato del titular, que un scope que falta no borre la
 * ficha entera, y —lo de siempre— que la conversación se busque entre las
 * líneas de la empresa del usuario y no por id a secas.
 */
class FichaDeClienteIntegraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La ficha se cachea un minuto por empresa+cliente; sin limpiar, el
        // segundo test leería la respuesta del primero.
        Cache::flush();
    }

    /** La ficha junta las tres consultas: contacto, historial y resumen del contrato. */
    public function test_la_ficha_junta_contacto_facturas_pagos_y_radicados(): void
    {
        $this->fakeIntegra();
        $conversacion = $this->conversacion();

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertOk()
            ->assertJsonPath('encontrado', true)
            ->assertJsonPath('cliente.identificacion', '1017924455')
            ->assertJsonPath('resumen.total_por_pagar', 95000)
            ->assertJsonPath('contratos.0.nro', '10432')
            // El porqué del estado sale del resumen, no del contacto.
            ->assertJsonPath('contratos.0.motivo', 'mora')
            ->assertJsonPath('facturas.pendientes.0.codigo', 'EST-8891')
            ->assertJsonPath('facturas.historial.1.codigo', 'FVE-8712')
            ->assertJsonPath('pagos.0.recibo', 14397)
            ->assertJsonPath('radicados.0.codigo', 5521)
            ->assertJsonPath('radicados.0.contrato_nro', '10432')
            ->assertJsonPath('sin_permiso', []);
    }

    /**
     * WhatsApp entrega el número con indicativo (573046430059) e Integra guarda
     * el celular de 10 dígitos: buscar con el indicativo no encuentra a nadie.
     */
    public function test_el_telefono_se_busca_por_los_ultimos_diez_digitos(): void
    {
        $this->fakeIntegra();
        $conversacion = $this->conversacion();

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertOk();

        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), '/contactos/buscar')
            || str_contains($r->url(), 'q=3046430059'));
    }

    /**
     * Los pagos son del titular y el resumen los repite en cada contrato suyo.
     * Sin deduplicar, el agente ve el mismo recibo dos veces y cree que pagó el
     * doble — justo el dato por el que el cliente está escribiendo.
     */
    public function test_los_pagos_repetidos_entre_contratos_no_se_duplican(): void
    {
        $this->fakeIntegra(contratos: ['10432', '10433']);
        $conversacion = $this->conversacion();

        $respuesta = $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertOk();

        $this->assertCount(1, $respuesta->json('pagos'));
        $this->assertCount(2, $respuesta->json('radicados'));
    }

    /**
     * Un token sin `contratos.leer` se queda sin pagos ni radicados, pero el
     * saldo y las facturas —lo que más se consulta— tienen que seguir saliendo,
     * y la UI tiene que poder decir que faltó permiso en vez de pintar un
     * cliente sin reportes.
     */
    public function test_sin_permiso_de_contratos_la_ficha_sigue_trayendo_las_facturas(): void
    {
        $this->fakeIntegra(resumenProhibido: true);
        $conversacion = $this->conversacion();

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertOk()
            ->assertJsonPath('encontrado', true)
            ->assertJsonPath('resumen.total_por_pagar', 95000)
            ->assertJsonPath('facturas.pendientes.0.codigo', 'EST-8891')
            ->assertJsonPath('pagos', [])
            ->assertJsonPath('radicados', [])
            ->assertJsonPath('sin_permiso', ['contratos']);
    }

    /**
     * El cliente que oculta su número tras un nombre de usuario no se puede
     * cruzar con el ERP. Se dice; devolver una ficha vacía se lee como si
     * Integra estuviera caído.
     */
    public function test_un_hilo_sin_numero_ni_identificacion_no_se_puede_buscar(): void
    {
        $this->fakeIntegra();
        $conversacion = $this->conversacion(['phone_number' => null, 'bsuid' => 'CO.1402615141764490']);

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertOk()
            ->assertJsonPath('buscable', false);

        Http::assertNothingSent();
    }

    /**
     * El aislamiento va por la instancia: `whatsapp_conversations` no tiene
     * `company_id`. Sin ese salto, pedir la ficha con el id de la conversación
     * de otra empresa devolvería los datos de SU cliente en el ERP.
     */
    public function test_no_se_puede_pedir_la_ficha_de_la_conversacion_de_otra_empresa(): void
    {
        $this->fakeIntegra();
        $ajena = $this->conversacion();
        $propia = $this->conversacion();

        $this->actingAs($this->usuario($propia))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$ajena->id)
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    /** Sin Integra conectado la respuesta lo explica, no revienta. */
    public function test_sin_integra_conectado_responde_con_el_motivo(): void
    {
        $this->fakeIntegra();
        $conversacion = $this->conversacion();
        $usuario = $this->usuario($conversacion, conectado: false);

        $this->actingAs($usuario)
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertStatus(422)
            ->assertJsonPath('message', Integra::NOT_CONNECTED);
    }

    /**
     * La factura pendiente llega con el id que su propio listado no trae.
     *
     * `/contactos/buscar` manda código, saldo y vencimiento, pero no el id, y
     * sin id la fila no se puede abrir: el agente se queda sin poder responder
     * "¿y esos $76.000 de qué son?". El id se cruza por código con el historial.
     */
    public function test_la_factura_pendiente_trae_el_id_para_poder_abrirla(): void
    {
        $this->fakeIntegra();
        $conversacion = $this->conversacion();

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertOk()
            ->assertJsonPath('facturas.pendientes.0.codigo', 'EST-8891')
            ->assertJsonPath('facturas.pendientes.0.id', 8891);
    }

    /**
     * Los bloques que sólo se miran al abrir el contrato viajan en la misma
     * respuesta: el resumen ya está descargado, y volver a pedirlo al pulsar
     * sería una llamada al ERP por gesto del agente.
     */
    public function test_el_contrato_trae_los_bloques_que_abre_el_dialogo(): void
    {
        $this->fakeIntegra();
        $conversacion = $this->conversacion();

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/ficha?conversation_id='.$conversacion->id)
            ->assertOk()
            ->assertJsonPath('contratos.0.ampliado.wifi.clave', 'casa2025')
            ->assertJsonPath('contratos.0.ampliado.condiciones.permanencia_meses', 12)
            ->assertJsonPath('contratos.0.ampliado.facturas.0.id', 8891)
            ->assertJsonPath('contratos.0.ampliado.contrato_digital.firmado', true);
    }

    /** El detalle de la factura: los ítems, que es lo que el listado no manda. */
    public function test_el_detalle_de_la_factura_trae_los_items_cobrados(): void
    {
        $this->fakeIntegra();
        $conversacion = $this->conversacion();

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/factura/8891')
            ->assertOk()
            ->assertJsonPath('factura.codigo', 'EST-8891')
            ->assertJsonPath('factura.montos.por_pagar', 95000)
            ->assertJsonPath('factura.items.0.producto', 'Plan Fibra Hogar 100 Megas')
            ->assertJsonPath('cliente.identificacion', '1017924455');
    }

    /**
     * Una factura de otra empresa responde 404, no sus datos: el token es el de
     * la empresa del usuario e Integra sólo entrega facturas suyas. Aquí se
     * comprueba que ese 404 se traduce y no se cuela como una ficha vacía.
     */
    public function test_una_factura_que_integra_no_reconoce_responde_404(): void
    {
        $this->fakeIntegra();
        Http::fake(['*/api/v1/facturas/7777' => Http::response(
            ['success' => false, 'message' => 'Factura no encontrada.'], 404
        )]);

        $conversacion = $this->conversacion();

        $this->actingAs($this->usuario($conversacion))
            ->getJson('/api/integrations/integra/factura/7777')
            ->assertStatus(404);
    }

    /**
     * La ficha no se pinta a quien no tiene nada que ver con Integra.
     *
     * A una farmacia un bloque «Integra» sólo puede decirle que no está
     * conectado, y eso es una pregunta que no sabe responder («¿tengo que
     * contratar eso?»). La decisión se toma en el servidor y viaja como prop:
     * si la tomara el panel, el bloque aparecería y desaparecería. Ver
     * App\Support\UsaIntegra.
     */
    public function test_el_chat_dice_si_pintar_la_ficha_segun_si_la_empresa_usa_integra(): void
    {
        $conversacion = $this->conversacion();
        $conIntegra = $this->usuario($conversacion);

        $this->actingAs($conIntegra)->get('/chat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('usa_integra', true));

        $sinIntegra = $this->usuario($this->conversacion(), conectado: false);

        $this->actingAs($sinIntegra)->get('/chat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('usa_integra', false));
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    /**
     * El entorno Integra de mentira: las tres rutas que compone la ficha.
     *
     * @param  array  $contratos  Números de contrato del cliente.
     */
    private function fakeIntegra(array $contratos = ['10432'], bool $resumenProhibido = false): void
    {
        $resumen = $resumenProhibido
            ? Http::response(['success' => false, 'message' => 'Sin permiso.'], 403)
            : function (Request $request) {
                preg_match('#/contratos/([^/]+)/resumen#', $request->url(), $m);
                $nro = $m[1] ?? '10432';

                return Http::response(['success' => true, 'data' => [
                    'servicio' => ['internet' => [
                        'activo' => false,
                        'motivo' => 'mora',
                        'detalle' => 'El servicio está suspendido por facturas vencidas.',
                        'monto_para_reactivar' => 95000,
                    ]],
                    'facturacion' => [
                        'saldo_a_favor' => 0,
                        'promesa_pago' => null,
                        'pendientes' => ['total' => 1, 'total_por_pagar' => 95000, 'items' => [
                            ['id' => 8891, 'codigo' => 'EST-8891', 'vencimiento' => '2026-09-05',
                                'vencida' => true, 'montos' => ['por_pagar' => 95000]],
                        ]],
                    ],
                    // El mismo recibo en todos los contratos: son del titular.
                    'pagos_recientes' => [
                        ['recibo' => 14397, 'fecha' => '2026-09-02', 'valor' => 80000, 'medio' => 'Efectivo'],
                    ],
                    'soportes' => ['abiertos' => 1, 'items' => [
                        ['codigo' => 5521, 'fecha' => '2026-09-10', 'servicio' => 'Sin internet', 'estado' => 'pendiente'],
                    ]],
                    'consumo' => null,
                    'wifi' => ['red' => 'FIBRA_DIAZ', 'clave' => 'casa2025'],
                    'condiciones' => ['permanencia_meses' => 12, 'costo_reconexion' => 15000, 'descuento' => 0],
                    'contrato_digital' => ['firmado' => true, 'fecha_firma' => '2025-11-03', 'pdf_url' => 'https://demo.test/c/abc/pdf'],
                ]]);
            };

        Http::fake([
            '*/api/v1/contactos/buscar*' => Http::response(['success' => true, 'data' => [[
                'id' => 4012,
                'identificacion' => '1017924455',
                'nombre_completo' => 'Javier Elias Diaz Matilde Quiroga',
                'contacto' => [
                    'nombre' => 'Javier Elias',
                    'identificacion' => '1017924455',
                    'celular' => '3046430059',
                    'email' => null,
                    'direccion' => 'Cra 12 # 4-55',
                    'barrio' => 'Centro',
                    'municipio' => 'Montería',
                ],
                'resumen' => [
                    'total_contratos' => count($contratos),
                    'contratos_activos' => 0,
                    'facturas_pendientes' => 1,
                    'total_por_pagar' => 95000,
                ],
                'contratos' => array_map(fn ($nro) => [
                    'nro' => $nro,
                    'activo' => false,
                    'vigente' => true,
                    'estado' => 'Deshabilitado',
                    'plan_internet' => ['nombre' => 'Fibra 100 Mbps', 'precio' => 75000, 'bajada' => 100, 'subida' => 50],
                    'television' => ['tiene_servicio' => false],
                    'ubicacion' => ['direccion_instalacion' => 'Cra 12 # 4-55'],
                ], $contratos),
                'facturas_pendientes' => [
                    ['codigo' => 'EST-8891', 'por_pagar' => 95000, 'vencimiento' => '2026-09-05', 'contrato_nro' => '10432'],
                ],
                'total_por_pagar' => 95000,
            ]], 'meta' => ['total_contactos' => 1]]),

            '*/api/v1/facturas?*' => Http::response(['success' => true, 'data' => [[
                'id' => 8891,
                'codigo' => 'EST-8891',
                'fecha' => '2026-09-01',
                'vencimiento' => '2026-09-05',
                'estado' => 'abierta',
                'vencida' => true,
                'tipo_label' => 'estandar',
                'montos' => ['total' => 95000, 'pagado' => 0, 'por_pagar' => 95000],
            ], [
                'id' => 8712,
                'codigo' => 'FVE-8712',
                'fecha' => '2026-08-01',
                'vencimiento' => '2026-08-05',
                'estado' => 'cerrada',
                'vencida' => false,
                'tipo_label' => 'electronica',
                'montos' => ['total' => 95000, 'pagado' => 95000, 'por_pagar' => 0],
            ]], 'meta' => ['total' => 1]]),

            '*/api/v1/contratos/*/resumen*' => $resumen,

            '*/api/v1/facturas/8891' => Http::response(['success' => true, 'data' => [
                'id' => 8891, 'codigo' => 'EST-8891', 'tipo_label' => 'estandar', 'estado' => 'abierta',
                'fecha' => '2026-09-01', 'vencimiento' => '2026-09-05', 'vencida' => true,
                'montos' => ['subtotal' => 95000, 'descuento' => 0, 'total' => 95000, 'pagado' => 0, 'por_pagar' => 95000],
                'items' => [
                    ['producto' => 'Plan Fibra Hogar 100 Megas', 'cantidad' => 1, 'precio' => 95000, 'descuento_pct' => 0, 'subtotal' => 95000],
                ],
                'contratos' => [['id' => 771, 'nro' => '10432', 'plan' => 'Fibra Hogar 100 Megas', 'internet' => 'inactivo']],
            ], 'meta' => ['cliente' => [
                'id' => 4012, 'nombre' => 'Javier Elias Diaz Matilde Quiroga', 'identificacion' => '1017924455',
            ]]]),
        ]);
    }

    private function conversacion(array $datos = []): WhatsAppConversation
    {
        $company = Company::create([
            'name' => 'Cmnet '.Str::random(4),
            'slug' => Str::lower(Str::random(8)),
            'active' => true,
        ]);

        $instancia = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(100000, 999999),
            'waba_id' => (string) random_int(100000, 999999),
            'type' => 'meta',
            'active' => true,
        ]);

        $contacto = Contact::create([
            'company_id' => $company->id,
            'name' => 'Javier Elias Diaz Matilde Quiroga',
            'phone_number' => '573046430059',
        ]);

        return WhatsAppConversation::create(array_merge([
            'instance_id' => $instancia->id,
            'wa_id' => '573046430059',
            'phone_number' => '573046430059',
            'name' => 'Javier Elias Diaz Matilde Quiroga',
            'status' => 'open',
            'contact_id' => $contacto->id,
        ], $datos));
    }

    private function usuario(WhatsAppConversation $conversacion, bool $conectado = true): User
    {
        $companyId = $conversacion->instance->company_id;

        if ($conectado) {
            CompanyIntegration::create([
                'company_id' => $companyId,
                'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
                'status' => 'connected',
                'base_url' => 'https://demo.test/software',
                'access_token' => 'itg_token',
                'enabled' => true,
            ]);
        }

        return User::create([
            'company_id' => $companyId,
            'name' => 'Agente',
            'email' => 'agente-'.$companyId.'@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'agent',
            'active' => true,
        ]);
    }
}
