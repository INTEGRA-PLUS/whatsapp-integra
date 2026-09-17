<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La pantalla de dar de alta a alguien del equipo.
 *
 * Enseñaba una «Guía de permisos» escrita a mano en el JSX: seis filas —«Ver
 * Chats», «Borrar Datos»— por tres columnas (Adm/Age/Usr), con las marcas
 * puestas a ojo. No salía de ningún sitio: los roles no son los mismos en todas
 * las empresas, porque cada una define los suyos, y las marcas no correspondían
 * a los permisos de nadie.
 *
 * Lo que se protege aquí es que lo que se enseña al elegir un rol sean sus
 * permisos de verdad. Equivocarse repartiendo permisos se paga caro en un
 * producto donde los borrados no tienen papelera.
 */
class AltaDeUsuarioTest extends TestCase
{
    use RefreshDatabase;

    /** El resumen del rol sale de sus permisos, no de una tabla escrita a mano. */
    public function test_la_pantalla_trae_los_permisos_reales_del_rol(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();

        $mirón = $this->rol($empresa, 'mirón', ['chat.view']);
        $operador = $this->rol($empresa, 'operador', ['chat.view', 'chat.update']);
        $jefe = $this->rol($empresa, 'jefe', ['chat.view', 'chat.update', 'chat.delete']);

        $roles = collect($this->actingAs($admin)
            ->get('/users/create')
            ->assertOk()
            ->viewData('page')['props']['roles'])
            ->keyBy('name');

        $this->assertSame('Solo ver', $this->nivelDe($roles['mirón'], 'Chat'));
        $this->assertSame('Operar', $this->nivelDe($roles['operador'], 'Chat'));
        $this->assertSame('Control total', $this->nivelDe($roles['jefe'], 'Chat'));

        $this->assertSame(1, $roles['mirón']['permisos']);
        $this->assertSame(3, $roles['jefe']['permisos']);

        $this->assertNotNull($mirón->id.$operador->id.$jefe->id);
    }

    /**
     * Un rol sin permisos se dice, no se calla.
     *
     * Quien lo elija crea una cuenta que entra al CRM y no ve ni una pantalla, y
     * el que la usa cree que la aplicación está rota.
     */
    public function test_un_rol_sin_permisos_llega_con_el_resumen_vacio(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();
        $this->rol($empresa, 'vacío', []);

        $roles = collect($this->actingAs($admin)
            ->get('/users/create')
            ->assertOk()
            ->viewData('page')['props']['roles'])
            ->keyBy('name');

        $this->assertSame([], $roles['vacío']['resumen']);
        $this->assertSame(0, $roles['vacío']['permisos']);
    }

    /**
     * Y sólo los roles de su empresa.
     *
     * El aislamiento no lo da ningún scope global: es el `where('company_id')`
     * del controlador. Si alguien lo quita, esta prueba lo dice — y mientras
     * tanto se estarían repartiendo los roles de otro cliente.
     */
    public function test_no_ve_los_roles_de_otra_empresa(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();
        $this->rol($empresa, 'suyo', ['chat.view']);

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $this->rol($otra, 'ajeno', ['chat.view']);

        $nombres = collect($this->actingAs($admin)
            ->get('/users/create')
            ->assertOk()
            ->viewData('page')['props']['roles'])
            ->pluck('name');

        $this->assertTrue($nombres->contains('suyo'));
        $this->assertFalse($nombres->contains('ajeno'), 'Se están ofreciendo roles de otra empresa.');
    }

    /** @return array{0: Company, 1: User} */
    private function empresaConAdmin(): array
    {
        $empresa = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true]);

        $admin = User::create([
            'company_id' => $empresa->id,
            'name' => 'Admin',
            'email' => 'admin@fibra.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);

        $admin->assignRole($this->rol($empresa, 'admin', ['users.create', 'users.view']));

        return [$empresa, $admin->fresh()];
    }

    /** @param  list<string>  $permisos */
    private function rol(Company $empresa, string $nombre, array $permisos): Role
    {
        setPermissionsTeamId($empresa->id);

        $rol = Role::firstOrCreate([
            'name' => $nombre,
            'company_id' => $empresa->id,
            'guard_name' => 'web',
        ]);

        foreach ($permisos as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        return $rol->fresh();
    }

    /** @param  array<string, mixed>  $rol */
    private function nivelDe(array $rol, string $modulo): ?string
    {
        foreach ($rol['resumen'] as $area) {
            if ($area['modulo'] === $modulo) {
                return $area['etiqueta'];
            }
        }

        return null;
    }

    /**
     * No se puede asignar un rol de otra empresa.
     *
     * La validación era `exists:roles,id` a secas: aceptaba el id de CUALQUIER
     * rol del sistema. Bastaba con cambiar ese número en la petición para darle
     * a un usuario propio los permisos de un rol de otro cliente —incluido uno
     * con control total— sin pasar por ninguna pantalla.
     *
     * El aislamiento aquí no lo da ningún scope global: lo da el `where` del
     * controlador, y por eso se prueba desde fuera, mandando el id a mano.
     */
    public function test_no_se_puede_crear_un_usuario_con_un_rol_de_otra_empresa(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();
        $this->rol($empresa, 'agente', ['chat.view']);

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $ajeno = $this->rol($otra, 'dios', ['chat.view', 'chat.delete', 'users.create']);

        $this->actingAs($admin)
            ->post('/users', [
                'name' => 'Infiltrado',
                'email' => 'infiltrado@fibra.test',
                'password' => 'secreto123',
                'role_id' => $ajeno->id,
                'active' => true,
            ])
            ->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'infiltrado@fibra.test']);
    }

    /** Y tampoco cambiándoselo a uno que ya existe. */
    public function test_no_se_puede_cambiar_a_un_rol_de_otra_empresa(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();
        $suyo = $this->rol($empresa, 'agente', ['chat.view']);

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);
        $ajeno = $this->rol($otra, 'dios', ['chat.view', 'chat.delete']);

        $victima = User::create([
            'company_id' => $empresa->id,
            'name' => 'Agente',
            'email' => 'agente@fibra.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'agent',
        ]);
        // El team activo lo dejó `rol()` en la otra empresa al crear el rol
        // ajeno: sin volver al suyo, la asignación se guardaría con el team
        // equivocado y el test mediría otra cosa.
        setPermissionsTeamId($empresa->id);
        $victima->assignRole($suyo);

        $this->actingAs($admin)
            ->put('/users/'.$victima->id, [
                'name' => 'Agente',
                'email' => 'agente@fibra.test',
                'role_id' => $ajeno->id,
                'active' => true,
            ])
            ->assertSessionHasErrors('role_id');

        // Spatie lee los roles del «team» activo, y el último que se fijó al
        // crear el rol ajeno fue el de la otra empresa: sin esto, la relación
        // vuelve vacía y el test diría que se quedó sin rol.
        setPermissionsTeamId($empresa->id);

        $this->assertSame('agente', $victima->fresh()->roles->first()?->name);
    }
}
