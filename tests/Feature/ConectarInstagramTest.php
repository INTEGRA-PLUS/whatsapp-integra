<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Conectar una cuenta profesional de Instagram con Business Login.
 *
 * Lo que más importa aquí no es el camino feliz sino el `state`: sin él, un
 * enlace preparado por un tercero conectaría **su** cuenta de Instagram a la
 * empresa de otro, y a partir de ahí los mensajes de sus clientes caerían en una
 * bandeja ajena.
 */
class ConectarInstagramTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '28822685693981719';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.instagram.app_id' => self::APP_ID,
            'services.meta.webhook_app_secrets' => self::APP_ID.':secreto-de-instagram',
        ]);
    }

    public function test_el_boton_manda_a_instagram_con_los_dos_permisos(): void
    {
        $respuesta = $this->actingAs($this->usuario())->get(route('instagram.conectar'));

        $destino = $respuesta->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $destino);
        parse_str(parse_url($destino, PHP_URL_QUERY), $parametros);

        $this->assertSame(self::APP_ID, $parametros['client_id']);
        $this->assertSame('code', $parametros['response_type']);
        $this->assertSame(route('instagram.callback'), $parametros['redirect_uri']);
        $this->assertNotEmpty($parametros['state']);

        // Sólo los dos que usamos: pedir de más alarga la revisión y da motivos
        // para rechazarla.
        $this->assertSame(
            'instagram_business_basic,instagram_business_manage_messages',
            $parametros['scope']
        );
    }

    public function test_conecta_la_cuenta_y_guarda_el_token_de_sesenta_dias(): void
    {
        $this->respuestasDeMeta();

        $usuario = $this->usuario();
        $estado = $this->arrancar($usuario);

        $this->actingAs($usuario)
            ->get(route('instagram.callback', ['code' => 'AQBx-hBsH3', 'state' => $estado]))
            ->assertRedirect(route('instances.index'));

        $linea = Instance::canal(Instance::CANAL_INSTAGRAM)->firstOrFail();

        $this->assertSame($usuario->company_id, $linea->company_id);
        $this->assertSame('17841400008460056', $linea->external_account_id);
        $this->assertSame('@integracolombia', $linea->name);
        $this->assertSame('integracolombia', $linea->usuarioDeInstagram());
        $this->assertSame('el-token-largo', $linea->access_token);
        $this->assertTrue($linea->active);

        // 60 días, no una hora: si se guardara el token corto, la cuenta dejaría
        // de funcionar antes de que nadie la mire.
        $this->assertEqualsWithDelta(60, now()->diffInDays($linea->token_expires_at), 1);

        // Y ahora la línea cuenta como configurada, que es lo que la bandeja mira.
        $this->assertTrue($linea->isMetaConfigured());
    }

    /**
     * El ataque que el `state` existe para parar.
     */
    public function test_un_state_que_no_coincide_no_conecta_nada(): void
    {
        $this->respuestasDeMeta();

        $usuario = $this->usuario();
        $this->arrancar($usuario);

        $this->actingAs($usuario)
            ->get(route('instagram.callback', ['code' => 'AQBx-hBsH3', 'state' => 'el-state-de-un-impostor']))
            ->assertRedirect(route('instances.index'));

        $this->assertSame(0, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
    }

    public function test_sin_haber_arrancado_el_flujo_tampoco_conecta(): void
    {
        $this->respuestasDeMeta();

        $this->actingAs($this->usuario())
            ->get(route('instagram.callback', ['code' => 'AQBx-hBsH3', 'state' => 'inventado']))
            ->assertRedirect(route('instances.index'));

        $this->assertSame(0, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
    }

    public function test_si_el_usuario_cancela_se_le_dice_y_no_se_crea_nada(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('instagram.callback', ['error' => 'user_denied', 'error_reason' => 'user_denied']))
            ->assertRedirect(route('instances.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
    }

    /**
     * La forma REAL en que Meta devuelve los permisos: una lista, no la cadena
     * separada por comas que enseña la documentación.
     *
     * Esta prueba existe porque la otra falseaba la respuesta con un string, así
     * que pasaba en verde mientras producción devolvía un 500 («Array to string
     * conversion») justo después de que el cliente autorizara (11-sep-2026).
     */
    public function test_conecta_aunque_los_permisos_lleguen_como_lista(): void
    {
        $this->respuestasDeMeta(permisosComoLista: true);

        $usuario = $this->usuario();
        $estado = $this->arrancar($usuario);

        $this->actingAs($usuario)
            ->get(route('instagram.callback', ['code' => 'AQBx-hBsH3', 'state' => $estado]))
            ->assertRedirect(route('instances.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
    }

    /**
     * El caso REAL de producción (11-sep-2026): Business Login entrega un token
     * que ya es de sesenta días, y pedirle `ig_exchange_token` devuelve
     * «Unsupported request - method type: get», que no explica nada.
     *
     * La renovación confirma la hipótesis y además da la caducidad de verdad.
     */
    public function test_conecta_cuando_el_token_ya_era_de_larga_duracion(): void
    {
        // Sin respuestasDeMeta(): Laravel NO reemplaza los stubs, el primero que
        // coincide gana, y el del canje correcto tapaba el fallo que se quiere
        // reproducir aquí.
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'data' => [['access_token' => 'IGAGel-token-largo-de-entrada', 'user_id' => '17841400008460056', 'permissions' => ['instagram_business_basic']]],
            ]),
            // Lo que Meta devuelve de verdad cuando el token no es de una hora.
            'graph.instagram.com/access_token*' => Http::response([
                'error' => ['message' => 'Unsupported request - method type: get', 'type' => 'IGApiException', 'code' => 100],
            ], 400),
            'graph.instagram.com/refresh_access_token*' => Http::response([
                'access_token' => 'el-token-renovado',
                'expires_in' => 5_184_000,
            ]),
            'graph.instagram.com/*/me*' => Http::response([
                'user_id' => '17841400008460056',
                'username' => 'integracolombia',
            ]),
        ]);

        $usuario = $this->usuario();
        $estado = $this->arrancar($usuario);

        $this->actingAs($usuario)
            ->get(route('instagram.callback', ['code' => 'AQBx-hBsH3', 'state' => $estado]))
            ->assertSessionHas('success');

        $linea = Instance::canal(Instance::CANAL_INSTAGRAM)->firstOrFail();
        $this->assertSame('el-token-renovado', $linea->access_token);
        $this->assertEqualsWithDelta(60, now()->diffInDays($linea->token_expires_at), 1);
    }

    /**
     * Y si ni el canje ni la renovación funcionan —la renovación exige que el
     * token tenga 24 horas— se sigue con el que hay. No es rendirse: el perfil
     * se pide acto seguido, así que un token inservible no llega a guardarse.
     */
    public function test_si_no_hay_canje_ni_renovacion_se_usa_el_token_que_hay(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'data' => [['access_token' => 'IGAGel-token-de-entrada', 'user_id' => '17841400008460056', 'permissions' => []]],
            ]),
            'graph.instagram.com/access_token*' => Http::response(['error' => ['message' => 'Unsupported request']], 400),
            'graph.instagram.com/refresh_access_token*' => Http::response(['error' => ['message' => 'too new']], 400),
            'graph.instagram.com/*/me*' => Http::response([
                'user_id' => '17841400008460056',
                'username' => 'integracolombia',
            ]),
        ]);

        $usuario = $this->usuario();
        $estado = $this->arrancar($usuario);

        $this->actingAs($usuario)
            ->get(route('instagram.callback', ['code' => 'AQBx-hBsH3', 'state' => $estado]))
            ->assertSessionHas('success');

        $this->assertSame('IGAGel-token-de-entrada', Instance::canal(Instance::CANAL_INSTAGRAM)->firstOrFail()->access_token);
    }

    /**
     * Lo que NO debe pasar: si el token no sirve para nada, no se guarda una
     * línea que la bandeja daría por buena.
     */
    public function test_un_token_que_no_sirve_no_deja_linea_conectada(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'data' => [['access_token' => 'IGAGtoken-muerto', 'user_id' => '17841400008460056', 'permissions' => []]],
            ]),
            'graph.instagram.com/access_token*' => Http::response(['error' => ['message' => 'Unsupported request']], 400),
            'graph.instagram.com/refresh_access_token*' => Http::response(['error' => ['message' => 'too new']], 400),
            'graph.instagram.com/*/me*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400),
        ]);

        $usuario = $this->usuario();
        $estado = $this->arrancar($usuario);

        $this->actingAs($usuario)
            ->get(route('instagram.callback', ['code' => 'AQBx-hBsH3', 'state' => $estado]))
            ->assertSessionHas('error');

        $this->assertSame(0, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
    }

    /**
     * Reconectar es normal —al renovar permisos, o si el token se perdió— y no
     * debe partir en dos el historial del mismo cliente.
     */
    public function test_reconectar_la_misma_cuenta_no_crea_una_linea_nueva(): void
    {
        $this->respuestasDeMeta();
        $usuario = $this->usuario();

        foreach ([1, 2] as $vez) {
            $estado = $this->arrancar($usuario);
            $this->actingAs($usuario)->get(route('instagram.callback', [
                'code' => "codigo-{$vez}", 'state' => $estado,
            ]));
        }

        $this->assertSame(1, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
    }

    /**
     * Si Meta falla a mitad, no queda una línea a medio conectar que la bandeja
     * daría por buena.
     */
    public function test_si_falla_el_canje_no_queda_nada_a_medias(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['error_message' => 'Matching code was not found'], 400),
        ]);

        $usuario = $this->usuario();
        $estado = $this->arrancar($usuario);

        $this->actingAs($usuario)
            ->get(route('instagram.callback', ['code' => 'caducado', 'state' => $estado]))
            ->assertRedirect(route('instances.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
    }

    /**
     * El revisor del App Review entra con el navegador y sin código.
     */
    public function test_visitar_el_callback_a_pelo_contesta_200(): void
    {
        $this->get(route('instagram.callback'))->assertOk();
    }

    private function arrancar(User $usuario): string
    {
        $destino = $this->actingAs($usuario)
            ->get(route('instagram.conectar'))
            ->headers->get('Location');

        parse_str(parse_url($destino, PHP_URL_QUERY), $parametros);

        return $parametros['state'];
    }

    private function respuestasDeMeta(bool $permisosComoLista = false): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'data' => [[
                    'access_token' => 'el-token-corto',
                    'user_id' => '17841400008460056',
                    'permissions' => $permisosComoLista
                        ? ['instagram_business_basic', 'instagram_business_manage_messages']
                        : 'instagram_business_basic,instagram_business_manage_messages',
                ]],
            ]),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'el-token-largo',
                'expires_in' => 5_184_000,
            ]),
            'graph.instagram.com/*/me*' => Http::response([
                'user_id' => '17841400008460056',
                'username' => 'integracolombia',
                'profile_picture_url' => 'https://scontent.cdninstagram.com/foto.jpg',
            ]),
        ]);
    }

    private function usuario(): User
    {
        $empresa = Company::create([
            'name' => 'Integra Colombia',
            'slug' => 'e-'.Str::random(8),
            'active' => true,
        ]);

        $permiso = Permission::firstOrCreate(['name' => 'instances.create', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'admin-'.Str::random(6), 'guard_name' => 'web']);
        $rol->givePermissionTo($permiso);

        $usuario = User::create([
            'name' => 'Alejandro',
            'email' => Str::random(8).'@integracolombia.co',
            'password' => bcrypt('lo-que-sea'),
            'company_id' => $empresa->id,
        ]);

        setPermissionsTeamId($empresa->id);
        $usuario->assignRole($rol);

        return $usuario;
    }
}
