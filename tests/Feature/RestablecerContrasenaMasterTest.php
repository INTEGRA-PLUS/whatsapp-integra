<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las dos vías de rescate que no dependen del correo.
 *
 * El comando de consola es la última red: por encima del master no hay nadie
 * que pueda cambiarle la contraseña, y hasta que el SMTP del servidor funcione
 * tampoco hay recuperación por email. Sin él, la única salida era escribir un
 * UPDATE a mano contra producción (8-sep-2026).
 *
 * El botón del panel maestro cubre el otro hueco: soporte podía cambiar la
 * contraseña del admin de una empresa, pero no la de un agente, y si quien se
 * había quedado fuera era el admin, no había a quién pedírselo.
 */
class RestablecerContrasenaMasterTest extends TestCase
{
    use RefreshDatabase;

    /** El comando cambia la contraseña preguntándola dos veces. */
    public function test_el_comando_cambia_la_contrasena(): void
    {
        $user = $this->usuario();

        $this->artisan('usuarios:contrasena', ['email' => $user->email])
            ->expectsQuestion('Contraseña nueva (no se ve al escribir)', 'clavenueva123')
            ->expectsQuestion('Repítela', 'clavenueva123')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('clavenueva123', $user->fresh()->password));
    }

    /** Si las dos no coinciden, no cambia nada. */
    public function test_el_comando_no_cambia_nada_si_no_coinciden(): void
    {
        $user = $this->usuario();

        $this->artisan('usuarios:contrasena', ['email' => $user->email])
            ->expectsQuestion('Contraseña nueva (no se ve al escribir)', 'clavenueva123')
            ->expectsQuestion('Repítela', 'otradistinta456')
            ->assertFailed();

        $this->assertTrue(Hash::check('secreto123', $user->fresh()->password));
    }

    /** Ni con una contraseña que el propio panel rechazaría. */
    public function test_el_comando_exige_la_longitud_minima_del_panel(): void
    {
        $user = $this->usuario();

        $this->artisan('usuarios:contrasena', ['email' => $user->email])
            ->expectsQuestion('Contraseña nueva (no se ve al escribir)', 'corta')
            ->assertFailed();

        $this->assertTrue(Hash::check('secreto123', $user->fresh()->password));
    }

    public function test_el_comando_avisa_si_el_correo_no_existe(): void
    {
        $this->artisan('usuarios:contrasena', ['email' => 'nadie@ejemplo.test'])
            ->expectsOutputToContain('No existe ningún usuario')
            ->assertFailed();
    }

    /** Con --generar la inventa y la muestra, sin preguntar nada. */
    public function test_el_comando_puede_generar_la_contrasena(): void
    {
        $user = $this->usuario();
        $anterior = $user->password;

        $this->artisan('usuarios:contrasena', ['email' => $user->email, '--generar' => true])
            ->expectsOutputToContain('Contraseña generada')
            ->assertSuccessful();

        $this->assertNotSame($anterior, $user->fresh()->password);
    }

    /** El master restablece la de cualquier usuario, de cualquier empresa. */
    public function test_el_master_restablece_la_contrasena_de_un_agente(): void
    {
        $agente = $this->usuario(['email' => 'agente@otra.test', 'role' => 'agent']);

        $respuesta = $this->actingAs($this->master())
            ->post(route('master.users.password', $agente->id));

        $respuesta->assertRedirect()->assertSessionHas('temp_password');
        $this->assertSame('agente@otra.test', session('temp_password_for'));

        // La temporal que se muestra es la que queda guardada.
        $this->assertTrue(Hash::check(session('temp_password'), $agente->fresh()->password));
    }

    /** Y nadie que no sea master puede usar esa ruta. */
    public function test_un_admin_no_puede_restablecer_contrasenas_ajenas(): void
    {
        $victima = $this->usuario(['email' => 'victima@otra.test']);
        $admin = $this->usuario(['email' => 'admin@suya.test']);

        $this->actingAs($admin)
            ->post(route('master.users.password', $victima->id))
            ->assertForbidden();

        $this->assertTrue(Hash::check('secreto123', $victima->fresh()->password));
    }

    /** El modal del panel trae los usuarios sólo de la empresa que se pide. */
    public function test_el_panel_lista_los_usuarios_de_una_empresa(): void
    {
        $master = $this->master();
        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $suyo = $this->usuario(['email' => 'suyo@otra.test', 'company_id' => $otra->id]);
        $this->usuario(['email' => 'ajeno@tercera.test']);

        $this->actingAs($master)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Master/Index',
                'X-Inertia-Partial-Data' => 'company_users',
            ])
            ->get('/master?users_of='.$otra->id)
            ->assertOk()
            ->assertJsonCount(1, 'props.company_users')
            ->assertJsonPath('props.company_users.0.email', 'suyo@otra.test');
    }

    private function usuario(array $campos = []): User
    {
        $companyId = $campos['company_id'] ?? Company::create([
            'name' => 'Empresa '.uniqid(),
            'slug' => 'empresa-'.uniqid(),
            'active' => true,
        ])->id;

        return User::create(array_merge([
            'company_id' => $companyId,
            'name' => 'Usuario',
            'email' => 'usuario@ejemplo.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ], $campos));
    }

    private function master(): User
    {
        $company = Company::create(['name' => 'Integra', 'slug' => 'integra', 'active' => true]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Master',
            'email' => 'master@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        $user->assignRole(Role::firstOrCreate([
            'name' => 'master',
            'company_id' => $company->id,
            'guard_name' => 'web',
        ]));

        return $user->fresh();
    }
}
