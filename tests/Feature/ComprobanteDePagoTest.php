<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\ComprobanteDePago;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Documentos\ImagenDelCliente;
use App\Support\Pagos\LectorDeComprobante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * La captura de un pago que manda el cliente: se lee sola y la aprueba una
 * persona.
 *
 * Antes del 30-sep-2026 no pasaba nada con ella: la visión la describía en
 * prosa sólo si el chat IA estaba encendido, la descripción no se guardaba, y
 * si el modelo contestaba «tu pago quedó registrado» eso le llegaba al cliente
 * sin que nadie hubiera registrado nada. El pago lo seguía escribiendo un
 * asesor a mano en el modal, con doble clic = dos pagos.
 */
class ComprobanteDePagoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private CompanyIntegration $pagos;

    /** @var array<int, array{url:string, body:mixed}> */
    private array $llamadas = [];

    private array $vision = [
        'es_comprobante' => true,
        'monto' => '$89.900',
        'fecha' => '29/09/2026',
        'referencia' => '00123456',
        'banco' => 'Bancolombia',
        'destino' => 'Fibra XYZ SAS',
        'estado' => 'aprobado',
    ];

    private float $porPagar = 89900;

    private bool $integraSeCae = false;

    private int $bytesDistintos = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 10:00:00');
        Storage::fake('s3_media');
        Permission::firstOrCreate(['name' => 'pagos.aprobar', 'guard_name' => 'web']);

        $this->company = Company::create([
            'name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true,
            'plan' => 'basico', 'ia' => 'completa',
        ]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'access_token' => 'token-meta',
            'active' => true,
        ]);

        $this->pagos = CompanyIntegration::create([
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://erp.test/software',
            'access_token' => 'itg_token',
            'enabled' => true,
            'settings' => ['leer_comprobantes' => true],
        ]);

        config([
            'services.vision.url' => 'https://ollama.test',
            'services.vision.token' => 'llave_de_vision',
            'services.vision.model' => 'deepseek-v4.1-flash',
        ]);

        Http::fake(function (Request $r) {
            $url = $r->url();
            $this->llamadas[] = ['url' => $url, 'body' => $r->data()];

            if (preg_match('#graph\.facebook\.com/[^/]+/MEDIA\w+$#', $url)) {
                return Http::response(['url' => 'https://lookaside.fbsbx.com/media/abc', 'mime_type' => 'image/jpeg'], 200);
            }
            if (str_contains($url, 'lookaside.fbsbx.com') || str_contains($url, 'whatsapp/media/')) {
                return Http::response($this->unJpeg($this->bytesDistintos), 200);
            }
            if (str_contains($url, 'ollama.test')) {
                return Http::response(['message' => ['content' => json_encode($this->vision)]], 200);
            }
            if (preg_match('#/api/v1/facturas/(\d+)/pagos$#', $url)) {
                if ($this->integraSeCae) {
                    return Http::failedConnection();
                }

                return Http::response(['success' => true, 'data' => [
                    'ingreso_id' => 51204, 'recibo_caja' => 14397,
                    'monto_aplicado' => $r['monto'], 'factura_estado' => 'cerrada',
                ]], 200);
            }
            if (preg_match('#/api/v1/facturas/(\d+)$#', $url, $m)) {
                return Http::response(['success' => true, 'data' => [
                    'id' => (int) $m[1], 'codigo' => 'EST7253',
                    'montos' => ['total' => 89900, 'pagado' => 89900 - $this->porPagar, 'por_pagar' => $this->porPagar],
                ], 'meta' => ['cliente' => ['id' => 77, 'nombre' => 'Katherine']]], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(6)]]], 200);
        });
    }

    // ─── La lectura ──────────────────────────────────────────────────────────

    public function test_los_montos_se_leen_como_los_escriben_los_bancos(): void
    {
        $this->assertSame(89900.0, LectorDeComprobante::monto('$89.900'));
        $this->assertSame(89900.0, LectorDeComprobante::monto('89.900,00'));
        $this->assertSame(89900.0, LectorDeComprobante::monto('89,900.00'));
        $this->assertSame(89900.0, LectorDeComprobante::monto('$ 89 900'));
        $this->assertSame(89900.0, LectorDeComprobante::monto(89900));
        $this->assertSame(1234567.0, LectorDeComprobante::monto('1.234.567'));
        $this->assertSame(12.5, LectorDeComprobante::monto('12,50'));
        $this->assertNull(LectorDeComprobante::monto('no se ve'));
        $this->assertNull(LectorDeComprobante::monto(0));
    }

    public function test_una_fecha_futura_es_un_error_de_lectura(): void
    {
        $this->assertSame('2026-09-29', LectorDeComprobante::fecha('29/09/2026'));
        $this->assertSame('2026-09-29', LectorDeComprobante::fecha('2026-09-29'));
        $this->assertNull(LectorDeComprobante::fecha('2026-10-15'));
        $this->assertNull(LectorDeComprobante::fecha('ayer'));
    }

    // ─── Del webhook a la bandeja ────────────────────────────────────────────

    public function test_la_captura_queda_pendiente_con_los_datos_leidos_y_sin_pagar_nada(): void
    {
        $this->postSignedWebhook($this->imagen())->assertOk();

        $c = ComprobanteDePago::sole();
        $this->assertSame(ComprobanteDePago::PENDIENTE, $c->estado);
        $this->assertSame($this->company->id, $c->company_id);
        $this->assertSame(89900.0, $c->monto);
        $this->assertSame('2026-09-29', $c->fecha->toDateString());
        $this->assertSame('00123456', $c->referencia);
        $this->assertSame('Bancolombia', $c->banco);

        $this->assertFalse($this->seLlamo('/pagos'), 'Leer una captura nunca registra un pago');
    }

    public function test_apagado_ni_siquiera_se_mira_la_foto(): void
    {
        $this->pagos->update(['settings' => ['leer_comprobantes' => false]]);

        $this->postSignedWebhook($this->imagen())->assertOk();

        $this->assertFalse($this->seLlamo('ollama.test'));
        $this->assertSame(0, ComprobanteDePago::count());
    }

    public function test_sin_la_integracion_de_pagos_activa_no_se_lee(): void
    {
        $this->pagos->update(['enabled' => false]);

        $this->postSignedWebhook($this->imagen())->assertOk();

        $this->assertSame(0, ComprobanteDePago::count());
    }

    public function test_una_foto_que_no_es_un_comprobante_no_llega_a_la_bandeja(): void
    {
        $this->vision = ['es_comprobante' => false, 'monto' => null, 'fecha' => null,
            'referencia' => null, 'banco' => null, 'destino' => null, 'estado' => 'desconocido'];

        $this->postSignedWebhook($this->imagen())->assertOk();

        $this->assertTrue($this->seLlamo('ollama.test'));
        $this->assertSame(0, ComprobanteDePago::count());
    }

    public function test_la_misma_captura_dos_veces_se_marca_como_duplicada(): void
    {
        $this->postSignedWebhook($this->imagen())->assertOk();
        $this->postSignedWebhook($this->imagen())->assertOk();

        [$primera, $segunda] = ComprobanteDePago::orderBy('id')->get();
        $this->assertNull($primera->duplicado_de_id);
        $this->assertSame($primera->id, $segunda->duplicado_de_id);
    }

    public function test_otra_foto_con_la_misma_referencia_tambien_se_marca(): void
    {
        $this->postSignedWebhook($this->imagen())->assertOk();
        $this->bytesDistintos = 1;
        $this->vision['referencia'] = '123-456';  // «00123456» escrito de otra forma
        $this->postSignedWebhook($this->imagen())->assertOk();

        [$primera, $segunda] = ComprobanteDePago::orderBy('id')->get();
        $this->assertSame($primera->id, $segunda->duplicado_de_id);
    }

    public function test_la_ia_no_puede_confirmar_un_pago_que_no_se_ha_aprobado(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Comprobante Nequi por $50.000.']], 200)]);

        $texto = ImagenDelCliente::comoTexto(['type' => 'image', 'media_url' => 'https://s3.test/x.jpg']);

        $this->assertStringContainsString('NO digas que el pago quedó registrado', $texto);
    }

    // ─── Aprobar ─────────────────────────────────────────────────────────────

    public function test_sin_el_permiso_no_se_puede_aprobar(): void
    {
        $c = $this->comprobante();
        $this->aprobador(); // el primero de la empresa, admin
        $agente = $this->usuario(permiso: false);

        $this->actingAs($agente)
            ->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())
            ->assertForbidden();

        $this->assertFalse($this->seLlamo('/pagos'));
        $this->assertSame(ComprobanteDePago::PENDIENTE, $c->fresh()->estado);
    }

    public function test_aprobar_registra_el_pago_con_la_referencia_y_deja_rastro(): void
    {
        $c = $this->comprobante();
        $yo = $this->aprobador();

        $this->actingAs($yo)
            ->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())
            ->assertOk()
            ->assertJsonPath('comprobante.estado', ComprobanteDePago::APROBADO)
            ->assertJsonPath('result.recibo_caja', 14397);

        $pago = $this->primera('/facturas/9001/pagos');
        $this->assertSame('00123456', $pago['body']['comprobante_pago']);
        $this->assertSame('2026-09-29', $pago['body']['fecha']);
        $this->assertEquals(89900, $pago['body']['monto']);
        $this->assertStringContainsString($yo->name, $pago['body']['observaciones']);

        $c->refresh();
        $this->assertSame($yo->id, $c->revisado_por);
        $this->assertSame('EST7253', $c->factura_codigo);
        $this->assertNotNull($c->revisado_at);

        $nota = WhatsAppMessage::where('type', 'note')->sole();
        $this->assertTrue((bool) $nota->is_internal);
        $this->assertStringContainsString('14397', $nota->content);
        $this->assertSame($c->conversation_id, $nota->conversation_id);
    }

    public function test_doble_clic_no_paga_dos_veces(): void
    {
        $c = $this->comprobante();
        $this->actingAs($this->aprobador());

        $this->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())->assertOk();
        $this->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())->assertStatus(409);

        $this->assertSame(1, $this->cuantas('/pagos'));
    }

    public function test_otro_comprobante_con_una_referencia_ya_aprobada_no_se_paga(): void
    {
        $primero = $this->comprobante();
        $segundo = $this->comprobante(['referencia' => '123456', 'referencia_normalizada' => '123456']);
        $this->actingAs($this->aprobador());

        $this->postJson("/api/comprobantes-de-pago/{$primero->id}/aprobar", $this->aprobacion())->assertOk();
        $this->postJson("/api/comprobantes-de-pago/{$segundo->id}/aprobar", $this->aprobacion())
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ya se aprobó'));

        $this->assertSame(1, $this->cuantas('/pagos'));
        $this->assertSame(ComprobanteDePago::PENDIENTE, $segundo->fresh()->estado);
    }

    public function test_no_se_aprueba_por_encima_del_saldo(): void
    {
        $this->porPagar = 50000;
        $c = $this->comprobante();
        $this->actingAs($this->aprobador());

        $this->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'supera el saldo'));

        $this->assertFalse($this->seLlamo('/pagos'));
        $this->assertSame(ComprobanteDePago::PENDIENTE, $c->fresh()->estado, 'La reserva se suelta');
    }

    public function test_una_factura_ya_pagada_no_se_vuelve_a_pagar(): void
    {
        $this->porPagar = 0;
        $c = $this->comprobante();
        $this->actingAs($this->aprobador());

        $this->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())->assertStatus(422);

        $this->assertFalse($this->seLlamo('/pagos'));
    }

    public function test_si_integra_no_contesta_queda_para_revisar_y_no_pendiente(): void
    {
        $this->integraSeCae = true;
        $c = $this->comprobante();
        $this->actingAs($this->aprobador());

        $this->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())->assertStatus(504);

        $c->refresh();
        $this->assertSame(ComprobanteDePago::REVISAR, $c->estado);
        $this->assertStringContainsString('pudo quedar registrado', $c->error);
    }

    public function test_otra_empresa_no_ve_ni_aprueba_el_comprobante(): void
    {
        $c = $this->comprobante();

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $intruso = User::create([
            'company_id' => $otra->id, 'name' => 'Intruso', 'email' => 'intruso@example.test',
            'password' => bcrypt('secreto123'), 'role' => 'admin', 'active' => true,
        ]); // primero de su empresa: admin con todos los permisos

        $this->actingAs($intruso)
            ->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())
            ->assertNotFound();

        $this->assertFalse($this->seLlamo('/pagos'));
    }

    // ─── Rechazar ────────────────────────────────────────────────────────────

    public function test_rechazar_pide_motivo_y_ya_no_se_puede_aprobar(): void
    {
        $c = $this->comprobante();
        $this->actingAs($this->aprobador());

        $this->postJson("/api/comprobantes-de-pago/{$c->id}/rechazar", [])->assertStatus(422);
        $this->postJson("/api/comprobantes-de-pago/{$c->id}/rechazar", ['motivo' => 'La referencia no aparece en el extracto'])
            ->assertOk()
            ->assertJsonPath('comprobante.estado', ComprobanteDePago::RECHAZADO);

        $this->postJson("/api/comprobantes-de-pago/{$c->id}/aprobar", $this->aprobacion())->assertStatus(409);
        $this->assertFalse($this->seLlamo('/pagos'));
        $this->assertStringContainsString('extracto', WhatsAppMessage::where('type', 'note')->sole()->content);
    }

    // ─── Borrados ────────────────────────────────────────────────────────────

    /** Sin FK (ver la migración): borrar la instancia tiene que llevárselos a mano. */
    public function test_borrar_la_instancia_se_lleva_sus_comprobantes(): void
    {
        $this->comprobante();
        $admin = $this->aprobador();
        Permission::firstOrCreate(['name' => 'instances.delete', 'guard_name' => 'web']);
        setPermissionsTeamId($this->company->id);
        $admin->givePermissionTo('instances.delete');

        $this->actingAs($admin)
            ->delete(route('instances.destroy', $this->instance->id), ['confirmacion' => $this->instance->name])
            ->assertRedirect();

        $this->assertSame(0, ComprobanteDePago::count());
        $this->assertSame(0, WhatsAppMessage::count());
    }

    // ─── La casilla ──────────────────────────────────────────────────────────

    public function test_la_casilla_se_guarda_sin_pisar_el_resto_de_settings(): void
    {
        $this->pagos->update(['settings' => ['otra_cosa' => 'x']]);
        $admin = $this->aprobador();
        foreach (['integrations.view', 'integrations.update'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        setPermissionsTeamId($this->company->id);
        $admin->givePermissionTo(['integrations.view', 'integrations.update']);

        $this->actingAs($admin)
            ->postJson('/api/integrations/invoice_payments/activate', [
                'enabled' => true, 'trigger_type' => 'slash', 'trigger_command' => 'pagos',
                'read_payment_receipts' => true,
            ])
            ->assertOk()
            ->assertJsonPath('read_payment_receipts', true);

        $this->assertSame(['otra_cosa' => 'x', 'leer_comprobantes' => true], $this->pagos->fresh()->settings);
    }

    // ─── /pendientes en el chat ──────────────────────────────────────────────

    /** El modal de `/pendientes` trae sólo los que esperan a alguien. */
    public function test_pendientes_trae_los_abiertos_y_nada_mas(): void
    {
        $pendiente = $this->comprobante();
        $revisar = $this->comprobante(['estado' => ComprobanteDePago::REVISAR]);
        $this->comprobante(['estado' => ComprobanteDePago::APROBADO]);
        $this->comprobante(['estado' => ComprobanteDePago::RECHAZADO]);

        $ids = $this->actingAs($this->aprobador())
            ->getJson('/api/comprobantes-de-pago/pendientes')
            ->assertOk()
            ->assertJsonPath('leyendo', true)
            ->assertJsonPath('comprobantes.0.conversacion.phone_number', '573007852081')
            ->json('comprobantes.*.id');

        $this->assertEqualsCanonicalizing([$pendiente->id, $revisar->id], $ids);
    }

    /** Sin `pagos.aprobar` no hay lista, aunque el chat no ofrezca el comando. */
    public function test_pendientes_pide_el_permiso_de_aprobar(): void
    {
        $this->aprobador();
        $this->comprobante();

        $this->actingAs($this->usuario(permiso: false))
            ->getJson('/api/comprobantes-de-pago/pendientes')
            ->assertForbidden();
    }

    /** Los de otra empresa no se cuelan. */
    public function test_pendientes_no_mezcla_empresas(): void
    {
        $aprobador = $this->aprobador();
        $mio = $this->comprobante();

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra-'.Str::random(4), 'active' => true]);
        $this->comprobante(['company_id' => $otra->id]);

        $ids = $this->actingAs($aprobador)
            ->getJson('/api/comprobantes-de-pago/pendientes')
            ->assertOk()
            ->json('comprobantes.*.id');

        $this->assertSame([$mio->id], $ids);
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function comprobante(array $extra = []): ComprobanteDePago
    {
        $conversation = WhatsAppConversation::firstOrCreate(
            ['instance_id' => $this->instance->id, 'wa_id' => '573007852081'],
            ['phone_number' => '573007852081', 'status' => 'open', 'last_message_at' => now()]
        );

        $mensaje = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.'.Str::random(12),
            'direction' => 'inbound',
            'type' => 'image',
            'media_url' => 'https://s3.test/whatsapp/media/x.jpg',
            'status' => 'delivered',
        ]);

        return ComprobanteDePago::create(array_merge([
            'company_id' => $this->company->id,
            'instance_id' => $this->instance->id,
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $mensaje->id,
            'estado' => ComprobanteDePago::PENDIENTE,
            'monto' => 89900,
            'fecha' => '2026-09-29',
            'referencia' => '00123456',
            'referencia_normalizada' => '123456',
            'imagen_sha256' => hash('sha256', Str::random()),
        ], $extra));
    }

    private function aprobacion(): array
    {
        return ['factura_id' => 9001, 'cuenta' => 3, 'metodo_pago' => 1, 'monto' => 89900];
    }

    /** El primer usuario de la empresa nace admin con todos los permisos. */
    private function aprobador(): User
    {
        return User::where('company_id', $this->company->id)->oldest('id')->first()
            ?? $this->usuario(permiso: true);
    }

    private function usuario(bool $permiso): User
    {
        $u = User::create([
            'company_id' => $this->company->id,
            'name' => $permiso ? 'Aprobadora' : 'Agente',
            'email' => Str::random(6).'@example.test',
            'password' => bcrypt('secreto123'),
            'role' => $permiso ? 'admin' : 'agent',
            'active' => true,
        ]);

        setPermissionsTeamId($this->company->id);
        if (! $permiso) {
            $u->syncRoles([]);
            $u->syncPermissions([]);
        }

        return $u->fresh();
    }

    private function imagen(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1022301494026392',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '573104047030', 'phone_number_id' => '1177962515404155'],
                        'contacts' => [['profile' => ['name' => 'Katherine'], 'wa_id' => '573007852081']],
                        'messages' => [[
                            'from' => '573007852081',
                            'id' => 'wamid.'.Str::random(16),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'image',
                            'image' => ['id' => 'MEDIA123', 'mime_type' => 'image/jpeg'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function seLlamo(string $trozo): bool
    {
        return $this->primera($trozo) !== null;
    }

    private function cuantas(string $trozo): int
    {
        return collect($this->llamadas)->filter(fn ($l) => str_contains($l['url'], $trozo))->count();
    }

    private function primera(string $trozo): ?array
    {
        return collect($this->llamadas)->first(fn ($l) => str_contains($l['url'], $trozo));
    }

    private function unJpeg(int $variante = 0): string
    {
        $im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 240 - $variante * 40, 240));
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }
}
