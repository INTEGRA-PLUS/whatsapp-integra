<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lo que una auditoría de las plantillas encontró abierto (01-oct-2026).
 *
 * - Al editar se descargaba la URL del encabezado que mandaba el navegador:
 *   cualquiera con permiso de editar podía hacer al servidor pedir una URL
 *   interna (SSRF).
 * - `show()` leía cualquier id que alcanzara el token, aunque fuera de otra
 *   empresa.
 * - Las reglas que Meta rechaza sin explicar (variables al principio o al
 *   final, botones intercalados, autenticación con la forma vieja) llegaban
 *   a Meta en vez de pararse aquí con un mensaje claro.
 * - El catálogo sólo leía la primera página.
 *
 * Cada test lleva un solo `Http::fake` con un closure que decide por URL.
 */
class PlantillasSeguridadYReglasTest extends TestCase
{
    use RefreshDatabase;

    private const MUESTRA_DE_META = 'https://scontent.whatsapp.net/v/t61/factura.pdf?oh=abc&oe=123';

    private User $usuario;

    private Instance $linea;

    /** @var array<int, array<string, mixed>> */
    private array $catalogo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-'.Str::random(4), 'active' => true]);

        $this->linea = $this->linea($empresa, 'Principal', 'waba-1');

        $this->usuario = $this->usuarioCon($empresa, ['templates.view', 'templates.create', 'templates.update', 'templates.delete']);

        $this->catalogo = [
            $this->plantilla('111', 'aviso_factura', 'APPROVED'),
            [
                'id' => '222',
                'name' => 'factura_pdf',
                'language' => 'es',
                'status' => 'APPROVED',
                'category' => 'UTILITY',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => [self::MUESTRA_DE_META]]],
                    ['type' => 'BODY', 'text' => 'Hola {{1}}, adjuntamos tu factura.', 'example' => ['body_text' => [['Ana']]]],
                ],
            ],
        ];
    }

    // ── SSRF ────────────────────────────────────────────────────────────────

    public function test_al_editar_no_descarga_la_url_que_manda_el_navegador(): void
    {
        $this->fingirMeta();

        $this->actingAs($this->usuario)->postJson('/api/templates/222', [
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => ['http://169.254.169.254/latest/meta-data/']]],
                ['type' => 'BODY', 'text' => 'Hola {{1}}, adjuntamos tu factura del mes.', 'example' => ['body_text' => [['Ana']]]],
            ],
        ])->assertOk();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254'));

        // Se descargó la muestra que Meta tiene guardada, y se mandó el handle nuevo.
        Http::assertSent(fn (Request $r) => $r->url() === self::MUESTRA_DE_META);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/222')
            && ($r->data()['components'][0]['example']['header_handle'][0] ?? null) === 'handle-nuevo');
    }

    public function test_no_descarga_una_muestra_guardada_fuera_de_los_cdn_de_meta(): void
    {
        $this->catalogo[1]['components'][0]['example']['header_handle'] = ['https://intranet.local/secreto.pdf'];
        $this->fingirMeta();

        $this->actingAs($this->usuario)->postJson('/api/templates/222', [
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => ['https://intranet.local/secreto.pdf']]],
                ['type' => 'BODY', 'text' => 'Hola {{1}}, adjuntamos tu factura del mes.', 'example' => ['body_text' => [['Ana']]]],
            ],
        ])->assertStatus(422);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'intranet.local'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/222'));
    }

    // ── Fuga entre empresas ─────────────────────────────────────────────────

    public function test_show_no_ensena_una_plantilla_que_no_es_del_waba_de_la_linea(): void
    {
        $this->fingirMeta();

        // La 999 existe en Meta (el token la alcanza), pero es de otra empresa.
        $this->actingAs($this->usuario)->getJson('/api/templates/999')->assertNotFound();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/999'));
    }

    public function test_show_ensena_la_plantilla_de_la_linea(): void
    {
        $this->fingirMeta();

        $this->actingAs($this->usuario)->getJson('/api/templates/111')
            ->assertOk()
            ->assertJsonPath('data.id', '111');
    }

    // ── Permisos ────────────────────────────────────────────────────────────

    public function test_activar_la_analitica_pide_permiso_de_escritura(): void
    {
        $this->fingirMeta();
        $miron = $this->usuarioCon($this->linea->company, ['templates.view'], 'miron');

        $this->actingAs($miron)->postJson('/api/templates/analytics/enable')->assertForbidden();

        $this->actingAs($this->usuario)->postJson('/api/templates/analytics/enable')->assertOk();
    }

    // ── Instancia ───────────────────────────────────────────────────────────

    public function test_con_varias_lineas_exige_decir_cual(): void
    {
        $this->fingirMeta();
        $this->linea($this->linea->company, 'Secundaria', 'waba-2');

        $this->actingAs($this->usuario)->getJson('/api/templates')
            ->assertStatus(422)
            ->assertJsonPath('code', 'instance_required');

        $this->actingAs($this->usuario)->getJson('/api/templates?instance_id='.$this->linea->id)->assertOk();
    }

    // ── Paginación ──────────────────────────────────────────────────────────

    public function test_encuentra_una_plantilla_de_la_segunda_pagina(): void
    {
        $this->fingirMeta(paginas: true);

        $this->actingAs($this->usuario)->getJson('/api/templates/222')->assertOk();

        $this->actingAs($this->usuario)->getJson('/api/templates?todas=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── Borrado ─────────────────────────────────────────────────────────────

    public function test_borra_una_sola_plantilla_por_nombre_e_id(): void
    {
        $this->fingirMeta();
        Cache::put('wa:templates:waba-1', ['viejo'], 600);

        $this->actingAs($this->usuario)
            ->deleteJson('/api/templates/111?instance_id='.$this->linea->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/waba-1/message_templates')
            && str_contains($r->url(), 'hsm_id=111')
            && str_contains($r->url(), 'name=aviso_factura'));

        // El guardarraíl de envíos no puede seguir validando contra lo borrado.
        $this->assertNull(Cache::get('wa:templates:waba-1'));
    }

    public function test_no_borra_una_plantilla_de_otro_waba(): void
    {
        $this->fingirMeta();

        $this->actingAs($this->usuario)->deleteJson('/api/templates/999')->assertNotFound();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_borrar_pide_el_permiso_de_borrar(): void
    {
        $this->fingirMeta();
        $editor = $this->usuarioCon($this->linea->company, ['templates.view', 'templates.update'], 'editor');

        $this->actingAs($editor)->deleteJson('/api/templates/111')->assertForbidden();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    // ── Crear: categoría y caché ────────────────────────────────────────────

    public function test_no_manda_allow_category_change_y_avisa_si_meta_recategoriza(): void
    {
        $this->fingirMeta(categoriaAsignada: 'MARKETING');
        Cache::put('wa:templates:waba-1', ['viejo'], 600);

        $this->crear([
            ['type' => 'BODY', 'text' => 'Hola {{1}}, tu pedido ya salió.', 'example' => ['body_text' => [['Ana']]]],
        ], extra: ['allow_category_change' => false])
            ->assertCreated()
            ->assertJsonPath('categoria_cambiada.pedida', 'UTILITY')
            ->assertJsonPath('categoria_cambiada.asignada', 'MARKETING');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/waba-1/message_templates')
            && ! array_key_exists('allow_category_change', $r->data()));

        $this->assertNull(Cache::get('wa:templates:waba-1'));
    }

    // ── Reglas del cuerpo y los botones ─────────────────────────────────────

    public function test_rechaza_variables_al_principio_al_final_o_seguidas(): void
    {
        $this->fingirMeta();

        foreach ([
            '{{1}}, tu pedido ya salió.' => 'empezar',
            'Tu pedido ya salió, {{1}}' => 'terminar',
            'Hola {{1}} {{2}}, tu pedido ya salió.' => 'seguidas',
        ] as $texto => $motivo) {
            $vars = preg_match_all('/\{\{\d+\}\}/', $texto);
            $this->crear([
                ['type' => 'BODY', 'text' => $texto, 'example' => ['body_text' => [array_fill(0, $vars, 'x')]]],
            ])->assertStatus(422);
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_boton_url_una_variable_al_final_y_con_ejemplo(): void
    {
        $this->fingirMeta();
        $cuerpo = ['type' => 'BODY', 'text' => 'Hola, revisa tu pedido.'];

        // Variable en medio de la URL.
        $this->crear([$cuerpo, ['type' => 'BUTTONS', 'buttons' => [
            ['type' => 'URL', 'text' => 'Ver', 'url' => 'https://a.co/{{1}}/detalle', 'example' => ['https://a.co/9/detalle']],
        ]]])->assertStatus(422);

        // Con nombre y sin ejemplo.
        $this->crear([$cuerpo, ['type' => 'BUTTONS', 'buttons' => [
            ['type' => 'URL', 'text' => 'Ver', 'url' => 'https://a.co/{{pedido}}'],
        ]]])->assertStatus(422);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        $this->crear([$cuerpo, ['type' => 'BUTTONS', 'buttons' => [
            ['type' => 'URL', 'text' => 'Ver', 'url' => 'https://a.co/{{1}}', 'example' => ['https://a.co/9']],
        ]]])->assertCreated();
    }

    public function test_respuestas_rapidas_intercaladas_se_rechazan(): void
    {
        $this->fingirMeta();
        $cuerpo = ['type' => 'BODY', 'text' => 'Hola, ¿te ayudamos?'];

        $this->crear([$cuerpo, ['type' => 'BUTTONS', 'buttons' => [
            ['type' => 'QUICK_REPLY', 'text' => 'Sí'],
            ['type' => 'URL', 'text' => 'Web', 'url' => 'https://a.co'],
            ['type' => 'QUICK_REPLY', 'text' => 'No'],
        ]]])->assertStatus(422);

        $this->crear([$cuerpo, ['type' => 'BUTTONS', 'buttons' => [
            ['type' => 'QUICK_REPLY', 'text' => 'Sí'],
            ['type' => 'QUICK_REPLY', 'text' => 'No'],
            ['type' => 'URL', 'text' => 'Web', 'url' => 'https://a.co'],
        ]]])->assertCreated();
    }

    // ── Autenticación ───────────────────────────────────────────────────────

    public function test_autenticacion_copiar_codigo_con_la_forma_de_meta(): void
    {
        $this->fingirMeta(categoriaAsignada: 'AUTHENTICATION');

        $this->crear([
            ['type' => 'BODY', 'add_security_recommendation' => true],
            ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copiar código']]],
        ], categoria: 'AUTHENTICATION')->assertCreated()->assertJsonPath('categoria_cambiada', null);

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/waba-1/message_templates')) {
                return false;
            }
            $c = $r->data()['components'];

            return $c[0] === ['type' => 'BODY', 'add_security_recommendation' => true]
                && $c[1] === ['type' => 'FOOTER', 'code_expiration_minutes' => 10]
                && $c[2]['buttons'][0]['otp_type'] === 'COPY_CODE';
        });
    }

    public function test_autenticacion_un_toque_con_el_formato_viejo_se_convierte(): void
    {
        $this->fingirMeta(categoriaAsignada: 'AUTHENTICATION');

        $this->crear([
            ['type' => 'BODY'],
            ['type' => 'BUTTONS', 'buttons' => [[
                'type' => 'OTP', 'otp_type' => 'ONE_TAP',
                'package_name' => 'com.integra.app', 'signature_hash' => 'K8a/AINcGX7',
            ]]],
        ], categoria: 'AUTHENTICATION')->assertCreated();

        Http::assertSent(function (Request $r) {
            $boton = $r->data()['components'][1]['buttons'][0] ?? null;

            return $r->method() === 'POST'
                && $boton !== null
                && $boton['supported_apps'] === [['package_name' => 'com.integra.app', 'signature_hash' => 'K8a/AINcGX7']]
                && ! array_key_exists('package_name', $boton);
        });
    }

    public function test_autenticacion_rechaza_lo_que_meta_no_acepta(): void
    {
        $this->fingirMeta();
        $app = [['package_name' => 'com.integra.app', 'signature_hash' => 'K8a/AINcGX7']];

        $casos = [
            'sin aceptar los términos de sin toque' => [
                ['type' => 'BODY'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'ZERO_TAP', 'supported_apps' => $app]]],
            ],
            'hash de 10 caracteres' => [
                ['type' => 'BODY'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'ONE_TAP', 'supported_apps' => [['package_name' => 'com.a', 'signature_hash' => '0123456789']]]]],
            ],
            'con encabezado' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Código'],
                ['type' => 'BODY'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE']]],
            ],
            'con texto propio en el cuerpo' => [
                ['type' => 'BODY', 'text' => 'Tu código es {{1}} y caduca pronto.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE']]],
            ],
            'con un botón que no es OTP' => [
                ['type' => 'BODY'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE'], ['type' => 'QUICK_REPLY', 'text' => 'No fui yo']]],
            ],
            'caducidad fuera de rango' => [
                ['type' => 'BODY'],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 120],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE']]],
            ],
        ];

        foreach ($casos as $caso => $componentes) {
            $this->assertSame(422, $this->crear($componentes, categoria: 'AUTHENTICATION')->status(), $caso);
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        // Con los términos aceptados, sí.
        $this->crear([
            ['type' => 'BODY'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'ZERO_TAP', 'supported_apps' => $app, 'zero_tap_terms_accepted' => true]]],
        ], categoria: 'AUTHENTICATION')->assertCreated();
    }

    // ── Copiar a otra línea ─────────────────────────────────────────────────

    public function test_copiar_conserva_las_variables_con_nombre_y_explica_el_rechazo(): void
    {
        $destino = $this->linea($this->linea->company, 'Nueva', 'waba-2');
        $this->catalogo = [[
            'id' => '555',
            'name' => 'saludo',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'parameter_format' => 'NAMED',
            'components' => [['type' => 'BODY', 'text' => 'Hola {{nombre}}, bienvenido.', 'example' => ['body_text_named_params' => [['param_name' => 'nombre', 'example' => 'Ana']]]]],
        ], [
            'id' => '556',
            'name' => 'rechazada',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
        ]];

        Http::fake(function (Request $r) {
            if ($r->method() === 'GET' && str_contains($r->url(), '/waba-1/message_templates')) {
                return Http::response(['data' => $this->catalogo], 200);
            }
            if ($r->method() === 'GET' && str_contains($r->url(), '/waba-2/message_templates')) {
                return Http::response(['data' => []], 200);
            }
            if ($r->method() === 'POST' && ($r->data()['name'] ?? null) === 'rechazada') {
                return Http::response(['error' => [
                    'message' => 'Invalid parameter',
                    'error_user_msg' => 'El cuerpo tiene muy poco texto.',
                ]], 400);
            }

            return Http::response(['id' => '1', 'status' => 'PENDING', 'category' => 'UTILITY'], 200);
        });

        $this->actingAs($this->usuario)->postJson('/api/templates/duplicar', [
            'origen_instance_id' => $this->linea->id,
            'destino_instance_id' => $destino->id,
        ])->assertOk()
            ->assertJsonPath('copiadas', 1)
            ->assertJsonFragment(['error' => 'El cuerpo tiene muy poco texto.']);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && ($r->data()['name'] ?? null) === 'saludo'
            && ($r->data()['parameter_format'] ?? null) === 'NAMED');
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /**
     * Meta de mentira: el listado del WABA de la línea (opcionalmente en dos
     * páginas), el detalle por id sólo de lo que está en el listado y no de
     * la 999 —que es «de otra empresa»—, la descarga del CDN y la subida.
     */
    private function fingirMeta(bool $paginas = false, string $categoriaAsignada = 'UTILITY'): void
    {
        Http::fake(function (Request $r) use ($paginas, $categoriaAsignada) {
            $url = $r->url();

            if ($r->method() === 'GET' && str_contains($url, '/waba-1/message_templates')) {
                if (! $paginas) {
                    return Http::response(['data' => $this->catalogo], 200);
                }

                if (! str_contains($url, 'after=pag2')) {
                    return Http::response([
                        'data' => [$this->catalogo[0]],
                        'paging' => ['cursors' => ['after' => 'pag2'], 'next' => 'https://graph.facebook.com/siguiente'],
                    ], 200);
                }

                return Http::response(['data' => [$this->catalogo[1]], 'paging' => ['cursors' => ['before' => 'pag1']]], 200);
            }

            if ($r->method() === 'GET' && str_contains($url, '/waba-2/message_templates')) {
                return Http::response(['data' => []], 200);
            }

            if ($url === self::MUESTRA_DE_META) {
                return Http::response('%PDF-1.4 muestra', 200, ['Content-Type' => 'application/pdf']);
            }

            if (str_contains($url, '/debug_token')) {
                return Http::response(['data' => ['app_id' => 'app-1', 'is_valid' => true]], 200);
            }

            if (str_contains($url, '/app-1/uploads')) {
                return Http::response(['id' => 'upload:1'], 200);
            }

            if (str_contains($url, '/upload:1')) {
                return Http::response(['h' => 'handle-nuevo'], 200);
            }

            if ($r->method() === 'GET' && preg_match('#/(\d+)(\?|$)#', $url, $m)) {
                foreach ($this->catalogo as $p) {
                    if ($p['id'] === $m[1]) {
                        return Http::response($p, 200);
                    }
                }

                return Http::response($m[1] === '999' ? $this->plantilla('999', 'de_otra_empresa', 'APPROVED') : [], $m[1] === '999' ? 200 : 404);
            }

            if ($r->method() === 'POST' && str_ends_with($url, '/message_templates')) {
                return Http::response(['id' => '777', 'status' => 'PENDING', 'category' => $categoriaAsignada], 200);
            }

            return Http::response(['success' => true], 200);
        });
    }

    private function crear(array $componentes, string $categoria = 'UTILITY', array $extra = [])
    {
        return $this->actingAs($this->usuario)->postJson('/api/templates', [
            'name' => 'prueba_'.Str::lower(Str::random(5)),
            'language' => 'es',
            'category' => $categoria,
            'components' => $componentes,
            ...$extra,
        ]);
    }

    /** @return array<string, mixed> */
    private function plantilla(string $id, string $nombre, string $estado): array
    {
        return [
            'id' => $id,
            'name' => $nombre,
            'language' => 'es',
            'status' => $estado,
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu factura está lista.', 'example' => ['body_text' => [['Ana']]]]],
        ];
    }

    private function linea(Company $empresa, string $nombre, string $waba): Instance
    {
        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => $nombre,
            'phone_number_id' => 'pnid-'.Str::random(6),
            'waba_id' => $waba,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-'.$waba,
        ]);
    }

    /** @param  list<string>  $permisos */
    private function usuarioCon(Company $empresa, array $permisos, string $rol = 'admin'): User
    {
        $usuario = User::create([
            'company_id' => $empresa->id,
            'name' => ucfirst($rol),
            'email' => $rol.'-'.Str::random(4).'@cmnet.test',
            'password' => 'secret',
            'active' => true,
        ]);

        setPermissionsTeamId($empresa->id);
        $r = Role::firstOrCreate(['name' => $rol, 'company_id' => $empresa->id, 'guard_name' => 'web']);
        foreach ($permisos as $permiso) {
            $r->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }
        $usuario->assignRole($r);

        return $usuario->fresh();
    }
}
