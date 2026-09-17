<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cuál de los menús encendidos responde de verdad.
 *
 * Se pueden tener varios menús raíz activos a la vez, y la pantalla los enseñaba
 * todos con la misma etiqueta «Activo» — sin decir que, al llegar un mensaje, se
 * prueban en orden y contesta el primero que encaja. Con dos menús de bienvenida
 * activos, uno se dispara siempre y el otro no se dispara nunca: desde fuera los
 * dos parecían estar funcionando, y quien editaba el que no contesta no entendía
 * por qué sus cambios no se notaban.
 *
 * El orden lo decide `WhatsAppMenu::ordenDeDisparo()`, el mismo que usa el
 * servicio al recibir el mensaje. Lo que se protege aquí es que la pantalla siga
 * diciendo lo que de verdad va a pasar.
 */
class MenuQueRespondeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Con dos menús de bienvenida activos, sólo uno queda marcado como el que
     * responde, y el otro dice quién le gana.
     */
    public function test_entre_dos_bienvenidas_solo_una_responde(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();

        // Con fechas distintas a propósito: el desempate es por `created_at` y
        // en segundos, así que dos menús creados en el mismo instante empatan y
        // el test no probaría la regla que dice probar.
        $viejo = $this->menu($empresa, 'Menú viejo', ['welcome']);
        $viejo->forceFill(['created_at' => now()->subDay()])->save();

        $nuevo = $this->menu($empresa, 'Menú nuevo', ['welcome']);

        $menus = collect($this->pantalla($admin))->keyBy('name');

        $ganan = $menus->filter(fn ($m) => $m['responde_al_saludo'])->count();

        $this->assertSame(1, $ganan, 'Dos menús no pueden responder al mismo saludo.');

        // El más reciente gana, que es la regla del servicio.
        $this->assertTrue($menus['Menú nuevo']['responde_al_saludo']);
        $this->assertSame('Menú nuevo', $menus['Menú viejo']['tapado_por']);
        $this->assertNotNull($viejo->id.$nuevo->id);
    }

    /**
     * Dos menús con palabras clave distintas NO se tapan.
     *
     * Cada uno se dispara con lo suyo, así que avisar de un conflicto que no
     * existe enseñaría a ignorar el aviso.
     */
    public function test_dos_menus_con_palabras_distintas_no_se_tapan(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();

        $this->menu($empresa, 'Con bienvenida', ['welcome']);
        $this->menu($empresa, 'Con palabra', ['exact'], 'facturas');

        $menus = collect($this->pantalla($admin))->keyBy('name');

        $this->assertNull($menus['Con palabra']['tapado_por'], 'No compiten: cada uno se dispara con lo suyo.');
        $this->assertTrue($menus['Con bienvenida']['responde_al_saludo']);
    }

    /** Un menú apagado no tapa a nadie ni responde. */
    public function test_un_menu_apagado_no_cuenta(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();

        $this->menu($empresa, 'Encendido', ['welcome']);
        $apagado = $this->menu($empresa, 'Apagado', ['welcome']);
        $apagado->update(['active' => false]);

        $menus = collect($this->pantalla($admin))->keyBy('name');

        $this->assertTrue($menus['Encendido']['responde_al_saludo']);
        $this->assertFalse($menus['Apagado']['responde_al_saludo']);
        $this->assertNull($menus['Apagado']['tapado_por'], 'Un menú apagado no está tapado: está apagado.');
    }

    /**
     * El menú de una instancia concreta gana al genérico.
     *
     * Es la primera regla del orden, y la que hace que atar un menú a una línea
     * signifique algo.
     */
    public function test_el_menu_de_una_instancia_gana_al_generico(): void
    {
        [$empresa, $admin] = $this->empresaConAdmin();

        $instancia = Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        // El genérico se crea DESPUÉS: si sólo mandara la fecha, ganaría éste.
        $suyo = $this->menu($empresa, 'De la línea', ['welcome']);
        $suyo->forceFill(['instance_id' => $instancia->id, 'created_at' => now()->subDay()])->save();
        $this->menu($empresa, 'Genérico', ['welcome']);

        $menus = collect($this->pantalla($admin))->keyBy('name');

        $this->assertTrue($menus['De la línea']['responde_al_saludo'], 'El menú de la línea tiene que ganar.');
        $this->assertSame('De la línea', $menus['Genérico']['tapado_por']);
    }

    /** @return array<int, array<string, mixed>> */
    private function pantalla(User $admin): array
    {
        return $this->actingAs($admin)
            ->get('/whatsapp-menus')
            ->assertOk()
            ->viewData('page')['props']['menus'];
    }

    private function menu(Company $empresa, string $nombre, array $tipos, ?string $trigger = null): WhatsAppMenu
    {
        $menu = WhatsAppMenu::create([
            'company_id' => $empresa->id,
            'name' => $nombre,
            'body_text' => 'Hola',
            'is_root' => true,
            'active' => true,
            'match_types' => $tipos,
            'trigger_text' => $trigger,
        ]);

        // Sin opciones no entra en el reparto: el servicio pide `has('options')`.
        WhatsAppMenuOption::create([
            'menu_id' => $menu->id,
            'title' => 'Una opción',
            'action_type' => 'text',
            'order' => 1,
        ]);

        return $menu->fresh();
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

        setPermissionsTeamId($empresa->id);
        $rol = Role::firstOrCreate(['name' => 'admin', 'company_id' => $empresa->id, 'guard_name' => 'web']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'whatsapp_menus.view', 'guard_name' => 'web']));
        $admin->assignRole($rol);

        return [$empresa, $admin->fresh()];
    }
}
