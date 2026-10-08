<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\CompanyIntegration;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extensiones «Cambio de clave WiFi» y «Prórroga de pago».
 *
 * Las dos ESCRIBEN en el ERP, y una de ellas tiene efecto en casa del cliente:
 * cambiar la clave desconecta todos sus dispositivos. Lo que se protege aquí,
 * por orden de gravedad:
 *
 * - **Que no se le pueda cambiar la clave al vecino.** Los contratos son
 *   secuenciales: el contrato tiene que ser del cliente de esa conversación,
 *   y la conversación de la empresa del usuario.
 * - **Que la clave no quede escrita en ningún log.**
 * - Que apagar la extensión la apague de verdad, no sólo esconda el botón.
 * - Que a Integra viaje la identificación del titular según la ficha del ERP,
 *   que es con la que Integra comprueba la titularidad.
 * - Que lo que se puede rechazar aquí (clave con ñ, fecha pasada) no gaste un
 *   viaje a Integra, y que lo que rechaza Integra llegue con su motivo.
 */
class WifiYProrrogaDelContratoTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'NuevaClave#2026';

    private Company $company;

    private Instance $instancia;

    private Contact $contacto;

    private WhatsAppConversation $conversacion;

    private User $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->company = Company::create([
            'name' => 'Cmnet '.Str::random(4),
            'slug' => Str::lower(Str::random(8)),
            'active' => true,
        ]);

        RateLimiter::clear('integra:wifi:'.$this->company->id);
        RateLimiter::clear('integra:prorroga:'.$this->company->id);

        $this->instancia = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(100000, 999999),
            'waba_id' => (string) random_int(100000, 999999),
            'type' => 'meta',
            'active' => true,
        ]);

        $this->contacto = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Javier Elias Diaz',
            'phone_number' => '573046430059',
            'identificacion' => '1017924455',
        ]);

        $this->conversacion = WhatsAppConversation::create([
            'instance_id' => $this->instancia->id,
            'wa_id' => '573046430059',
            'phone_number' => '573046430059',
            'name' => 'Javier Elias Diaz',
            'status' => 'open',
            'contact_id' => $this->contacto->id,
        ]);

        $this->asesor = User::create([
            'company_id' => $this->company->id,
            'name' => 'Agente',
            'email' => 'agente-'.Str::random(6).'@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'agent',
            'active' => true,
        ]);
    }

    // ── WiFi: leer ───────────────────────────────────────────────────────────

    public function test_el_asesor_ve_el_wifi_del_contrato_de_su_cliente(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        $this->actingAs($this->asesor)
            ->getJson('/api/integrations/integra/wifi?conversation_id='.$this->conversacion->id.'&contrato=10432')
            ->assertOk()
            ->assertJsonPath('wifi.automatico', true)
            ->assertJsonPath('wifi.redes.0.ssid', 'CMNET_DIAZ');

        // La titularidad la comprueba Integra con el documento del titular.
        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/api/v1/contratos/10432/wifi')
            && str_contains($r->url(), 'identificacion=1017924455'));
    }

    public function test_un_contrato_que_integra_no_reconoce_es_un_404(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra(wifi: Http::response(['success' => false, 'message' => 'Contrato no encontrado.'], 404));

        $this->actingAs($this->asesor)
            ->getJson('/api/integrations/integra/wifi?conversation_id='.$this->conversacion->id.'&contrato=10432')
            ->assertStatus(404);
    }

    // ── WiFi: cambiar la clave ───────────────────────────────────────────────

    public function test_el_asesor_cambia_la_clave_con_la_identificacion_de_la_ficha(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertOk()
            ->assertJsonPath('solicitud.estado', 'pendiente')
            ->assertJsonPath('solicitud.automatico', true);

        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/api/v1/contratos/10432/wifi')
            && $r['clave'] === self::CLAVE
            && $r['identificacion'] === '1017924455');
    }

    /**
     * El documento que viaja es el de Integra, no el tecleado en el contacto.
     * Con un contacto sin identificación —la ficha se encontró por teléfono—
     * el documento sólo puede salir de la ficha.
     */
    public function test_sin_documento_en_el_contacto_viaja_el_de_la_ficha(): void
    {
        $this->contacto->update(['identificacion' => null]);

        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertOk();

        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wifi')
            && $r['identificacion'] === '1017924455');
    }

    public function test_la_clave_no_aparece_en_ningun_log(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        $registrados = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$registrados) {
            $registrados[] = $e;
        });

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertOk();

        // Sí queda constancia de quién la cambió y en qué contrato…
        $cambio = collect($registrados)->first(
            fn (MessageLogged $e) => str_contains($e->message, 'clave WiFi cambiada')
        );
        $this->assertNotNull($cambio, 'El cambio de clave debe quedar registrado.');
        $this->assertSame('10432', $cambio->context['contrato']);
        $this->assertSame($this->asesor->id, $cambio->context['user_id']);

        // …pero nunca cuál es.
        foreach ($registrados as $e) {
            $this->assertStringNotContainsString(self::CLAVE, $e->message);
            $this->assertStringNotContainsString(self::CLAVE, json_encode($e->context));
        }
    }

    public function test_sin_la_extension_encendida_no_se_cambia_nada(): void
    {
        $this->conectarIntegra();
        $this->fakeIntegra();

        // Ni instalada.
        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertStatus(403);

        // Instalada pero apagada.
        $this->encender('wifi_password', enabled: false);

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertStatus(403);

        // La prórroga tiene su propio interruptor: el del WiFi no la enciende.
        $this->encender('wifi_password');

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/prorroga', $this->cuerpoProrroga())
            ->assertStatus(403);

        // Las dos primeras ni tocaron el ERP, y la prórroga tampoco.
        Http::assertNothingSent();
    }

    public function test_no_se_cambia_la_clave_de_un_contrato_de_otro_cliente(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        // 10433 existe en el ERP —es el del vecino—, pero no es de este cliente.
        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi(contrato: '10433'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Ese contrato no es del cliente de esta conversación.');

        $this->actingAs($this->asesor)
            ->getJson('/api/integrations/integra/wifi?conversation_id='.$this->conversacion->id.'&contrato=10433')
            ->assertStatus(403);

        // La ficha sí se consulta —es la que dice de quién es el contrato—,
        // pero ni la lectura ni el cambio llegan a pedirse.
        Http::assertNotSent(fn (PeticionHttp $r) => str_contains($r->url(), '/wifi'));
    }

    /**
     * La empresa ajena llega con todo en regla —Integra conectado y las dos
     * extensiones encendidas— para que lo único que pueda pararla sea el
     * cotejo de la conversación contra las líneas de su empresa.
     */
    public function test_no_se_toca_la_conversacion_de_otra_empresa(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->encender('payment_extension');
        $this->fakeIntegra();

        $otra = Company::create([
            'name' => 'Otra', 'slug' => Str::lower(Str::random(8)), 'active' => true,
        ]);

        CompanyIntegration::create([
            'company_id' => $otra->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://otra.test/software',
            'access_token' => 'itg_otro',
            'enabled' => true,
        ]);

        foreach (['wifi_password', 'payment_extension'] as $slug) {
            CompanyExtension::create([
                'company_id' => $otra->id, 'slug' => $slug, 'enabled' => true, 'settings' => [],
            ]);
        }

        $intruso = User::create([
            'company_id' => $otra->id,
            'name' => 'Ajeno',
            'email' => 'ajeno-'.Str::random(6).'@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'agent',
            'active' => true,
        ]);

        $this->actingAs($intruso)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Conversación no encontrada.');

        $this->actingAs($intruso)
            ->getJson('/api/integrations/integra/wifi?conversation_id='.$this->conversacion->id.'&contrato=10432')
            ->assertStatus(404);

        $this->actingAs($intruso)
            ->postJson('/api/integrations/integra/prorroga', $this->cuerpoProrroga())
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    /**
     * Integra exige 8–63 caracteres ASCII imprimibles. Se valida aquí lo mismo
     * para que el asesor vea el error antes del viaje.
     */
    /**
     * Sin redes elegidas no viaja `instancias`: Integra pone la clave en las
     * dos principales, el comportamiento de siempre.
     */
    public function test_sin_redes_elegidas_no_viaja_instancias(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertOk();

        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wifi')
            && ! array_key_exists('instancias', $r->data()));
    }

    /** Con redes elegidas, viajan sus números tal cual y vuelven los nombres. */
    public function test_las_redes_elegidas_viajan_a_integra(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra(wifiPost: Http::response(['success' => true, 'data' => ['solicitud' => [
            'id' => 56, 'contrato' => '10432', 'automatico' => true, 'estado' => 'en_cola',
            'redes' => 'Invitados', 'mensaje_cliente' => 'Tu clave cambiará en unos minutos.',
        ]]], 201));

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi() + ['instancias' => [2, '2', 6]])
            ->assertOk()
            ->assertJsonPath('solicitud.redes', 'Invitados');

        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wifi')
            && $r['instancias'] === [2, 6]);
    }

    public function test_redes_que_no_son_numeros_no_salen_del_crm(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        foreach ([['x'], [0], [65], range(1, 17)] as $instancias) {
            $this->actingAs($this->asesor)
                ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi() + ['instancias' => $instancias])
                ->assertStatus(422);
        }

        Http::assertNothingSent();
    }

    /** Si una red ya no está en la ONU, el motivo de Integra llega tal cual al asesor. */
    public function test_una_red_que_ya_no_esta_en_la_onu_llega_con_su_motivo(): void
    {
        $motivo = 'Alguna de las redes elegidas ya no aparece en el equipo del cliente. Consulta de nuevo las redes y vuelve a elegir.';
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra(wifiPost: Http::response(['success' => false, 'message' => $motivo], 422));

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi() + ['instancias' => [9]])
            ->assertStatus(422)
            ->assertJsonPath('message', $motivo);
    }

    public function test_una_clave_que_integra_rechazaria_no_sale_del_crm(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        foreach (['Contraseña2026', 'Clávesegura1', 'corta1', str_repeat('a', 64)] as $clave) {
            $this->actingAs($this->asesor)
                ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi(clave: $clave))
                ->assertStatus(422)
                ->assertJsonValidationErrors('clave');
        }

        Http::assertNothingSent();
    }

    public function test_sin_el_permiso_de_wifi_se_dice_cual_falta(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra(wifiPost: Http::response([
            'success' => false,
            'message' => 'El token no tiene permiso para esta operación.',
        ], 403));

        $respuesta = $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'sin_permiso');

        $this->assertStringContainsString('contratos.wifi', $respuesta->json('message'));
        $this->assertStringContainsString('Reconecta', $respuesta->json('message'));
    }

    public function test_el_cupo_de_cambios_de_clave_es_de_la_empresa(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('integra:wifi:'.$this->company->id);
        }

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/wifi', $this->cuerpoWifi())
            ->assertStatus(429);

        // La lectura no tiene tope: no toca el equipo.
        $this->actingAs($this->asesor)
            ->getJson('/api/integrations/integra/wifi?conversation_id='.$this->conversacion->id.'&contrato=10432')
            ->assertOk();

        Http::assertNotSent(fn (PeticionHttp $r) => $r->method() === 'POST');
    }

    // ── Prórroga ─────────────────────────────────────────────────────────────

    public function test_el_asesor_pide_la_prorroga_y_la_ficha_se_refresca(): void
    {
        $this->conectarIntegra();
        $this->encender('payment_extension');
        $this->fakeIntegra();

        $fecha = now()->addDays(5)->format('Y-m-d');
        $llave = 'integra:ficha:'.$this->company->id.':1017924455';

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/prorroga', $this->cuerpoProrroga($fecha, 'Paga el viernes'))
            ->assertOk()
            ->assertJsonPath('solicitud.id', 77)
            ->assertJsonPath('solicitud.estado', 'pendiente');

        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/api/v1/contratos/10432/prorroga')
            && $r['factura_id'] === 9001
            && $r['fecha'] === $fecha
            && $r['identificacion'] === '1017924455'
            && $r['comentario'] === 'Paga el viernes');

        // La promesa tiene que verse al refrescar el panel: la ficha cacheada
        // con el estado de antes se tira.
        $this->assertFalse(Cache::has($llave), 'La ficha cacheada debe invalidarse tras la prórroga.');
    }

    /**
     * Contraste del anterior: lo que invalida la ficha es la prórroga, no
     * cualquier paso por el helper. Si no, el test de arriba pasaría aunque
     * la ficha nunca se cacheara.
     */
    public function test_leer_el_wifi_deja_la_ficha_en_cache(): void
    {
        $this->conectarIntegra();
        $this->encender('wifi_password');
        $this->fakeIntegra();

        $this->actingAs($this->asesor)
            ->getJson('/api/integrations/integra/wifi?conversation_id='.$this->conversacion->id.'&contrato=10432')
            ->assertOk();

        $this->assertTrue(Cache::has('integra:ficha:'.$this->company->id.':1017924455'));
    }

    public function test_el_rechazo_de_integra_llega_con_su_motivo(): void
    {
        $this->conectarIntegra();
        $this->encender('payment_extension');
        $this->fakeIntegra(prorroga: Http::response([
            'success' => false,
            'message' => 'Ya tienes una promesa de pago sin atender.',
        ], 422));

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/prorroga', $this->cuerpoProrroga())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya tienes una promesa de pago sin atender.');
    }

    public function test_sin_el_permiso_de_prorroga_se_dice_cual_falta(): void
    {
        $this->conectarIntegra();
        $this->encender('payment_extension');
        $this->fakeIntegra(prorroga: Http::response([
            'success' => false,
            'message' => 'El token no tiene permiso para esta operación.',
        ], 403));

        $respuesta = $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/prorroga', $this->cuerpoProrroga())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'sin_permiso');

        $this->assertStringContainsString('contratos.prorroga', $respuesta->json('message'));
    }

    public function test_una_fecha_que_no_es_futura_no_sale_del_crm(): void
    {
        $this->conectarIntegra();
        $this->encender('payment_extension');
        $this->fakeIntegra();

        foreach ([now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'), '15/10/2026'] as $fecha) {
            $this->actingAs($this->asesor)
                ->postJson('/api/integrations/integra/prorroga', $this->cuerpoProrroga($fecha))
                ->assertStatus(422)
                ->assertJsonValidationErrors('fecha');
        }

        Http::assertNothingSent();
    }

    public function test_no_se_pide_prorroga_sobre_un_contrato_de_otro_cliente(): void
    {
        $this->conectarIntegra();
        $this->encender('payment_extension');
        $this->fakeIntegra();

        $cuerpo = $this->cuerpoProrroga();
        $cuerpo['contrato'] = '10433';

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/prorroga', $cuerpo)
            ->assertStatus(403);

        Http::assertNotSent(fn (PeticionHttp $r) => str_contains($r->url(), '/prorroga'));
    }

    public function test_el_cupo_de_prorrogas_es_de_la_empresa(): void
    {
        $this->conectarIntegra();
        $this->encender('payment_extension');
        $this->fakeIntegra();

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('integra:prorroga:'.$this->company->id);
        }

        $this->actingAs($this->asesor)
            ->postJson('/api/integrations/integra/prorroga', $this->cuerpoProrroga())
            ->assertStatus(429);

        Http::assertNothingSent();
    }

    // ── El chat ──────────────────────────────────────────────────────────────

    /** Cada botón con su interruptor: encender uno no pinta el otro. */
    public function test_el_chat_sabe_que_acciones_del_contrato_pintar(): void
    {
        $this->actingAs($this->asesor)
            ->get('/chat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Chat/Index')
                ->where('acciones_contrato', ['wifi' => false, 'prorroga' => false]));

        $this->encender('wifi_password');
        $this->encender('payment_extension', enabled: false);

        $this->actingAs($this->asesor)
            ->get('/chat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('acciones_contrato', ['wifi' => true, 'prorroga' => false]));

        $this->encender('payment_extension');

        $this->actingAs($this->asesor)
            ->get('/chat')
            ->assertInertia(fn ($page) => $page
                ->where('acciones_contrato', ['wifi' => true, 'prorroga' => true]));
    }

    public function test_las_extensiones_de_otra_empresa_no_pintan_botones_aqui(): void
    {
        $otra = Company::create([
            'name' => 'Otra', 'slug' => Str::lower(Str::random(8)), 'active' => true,
        ]);

        foreach (['wifi_password', 'payment_extension'] as $slug) {
            CompanyExtension::create([
                'company_id' => $otra->id, 'slug' => $slug, 'enabled' => true, 'settings' => [],
            ]);
        }

        $this->actingAs($this->asesor)
            ->get('/chat')
            ->assertInertia(fn ($page) => $page
                ->where('acciones_contrato', ['wifi' => false, 'prorroga' => false]));
    }

    // ── Andamiaje ────────────────────────────────────────────────────────────

    private function cuerpoWifi(string $contrato = '10432', string $clave = self::CLAVE): array
    {
        return [
            'conversation_id' => $this->conversacion->id,
            'contrato' => $contrato,
            'clave' => $clave,
        ];
    }

    private function cuerpoProrroga(?string $fecha = null, ?string $comentario = null): array
    {
        return array_filter([
            'conversation_id' => $this->conversacion->id,
            'contrato' => '10432',
            'factura_id' => 9001,
            'fecha' => $fecha ?? now()->addDays(3)->format('Y-m-d'),
            'comentario' => $comentario,
        ], fn ($v) => $v !== null);
    }

    private function conectarIntegra(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://demo.test/software',
            'access_token' => 'itg_token',
            'enabled' => true,
        ]);
    }

    private function encender(string $slug, bool $enabled = true): void
    {
        CompanyExtension::updateOrCreate(
            ['company_id' => $this->company->id, 'slug' => $slug],
            ['enabled' => $enabled, 'settings' => []]
        );
    }

    /**
     * El ERP: el contacto con su único contrato (10432) —que es lo que acota
     * sobre qué se puede actuar—, el WiFi y la prórroga.
     *
     * Las respuestas alternativas se pasan aquí y no con un segundo
     * `Http::fake()`: los stubs se encadenan y gana el primero que se
     * registró, así que el segundo no se usaría.
     */
    private function fakeIntegra($wifi = null, $wifiPost = null, $prorroga = null): void
    {
        $wifi ??= Http::response(['success' => true, 'data' => [
            'contrato' => '10432',
            'automatico' => true,
            'motivo_manual' => null,
            'redes' => [['banda' => '2.4GHz', 'ssid' => 'CMNET_DIAZ']],
            'solicitudes' => [],
        ]]);

        $wifiPost ??= Http::response(['success' => true, 'data' => ['solicitud' => [
            'id' => 55,
            'contrato' => '10432',
            'automatico' => true,
            'estado' => 'pendiente',
            'motivo_manual' => null,
            'mensaje_cliente' => 'Tu clave cambiará en unos minutos.',
        ]]], 201);

        $prorroga ??= Http::response(['success' => true, 'data' => ['solicitud' => [
            'id' => 77,
            'tipo' => 'prorroga',
            'estado' => 'pendiente',
            'fecha_propuesta' => now()->addDays(3)->format('Y-m-d'),
            'factura' => 'FV-9001',
        ]]], 201);

        Http::fake([
            '*/api/v1/contactos/buscar*' => Http::response(['success' => true, 'data' => [[
                'id' => 4012,
                'identificacion' => '1017924455',
                'nombre_completo' => 'Javier Elias Diaz',
                'contacto' => ['identificacion' => '1017924455', 'celular' => '3046430059'],
                'resumen' => ['total_contratos' => 1],
                'contratos' => [[
                    'nro' => '10432',
                    'activo' => true,
                    'vigente' => true,
                    'plan_internet' => ['nombre' => 'Fibra 100 Mbps'],
                ]],
                'facturas_pendientes' => [],
                'total_por_pagar' => 0,
            ]], 'meta' => ['total_contactos' => 1]]),

            '*/api/v1/facturas?*' => Http::response(['success' => true, 'data' => []]),

            '*/api/v1/contratos/*/resumen*' => Http::response(['success' => true, 'data' => [
                'facturacion' => [], 'servicio' => [], 'soportes' => [],
            ]]),

            '*/api/v1/contratos/*/wifi*' => fn (PeticionHttp $r) => $r->method() === 'POST' ? $wifiPost : $wifi,

            '*/api/v1/contratos/*/prorroga*' => $prorroga,
        ]);
    }
}
