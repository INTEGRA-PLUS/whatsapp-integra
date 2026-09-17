<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Support\MenuGenerico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El menú con el que arranca cualquier negocio.
 *
 * Antes toda empresa nueva nacía con el menú de ISP —consultar factura, pagar en
 * línea, reportar falla, estado del contrato—. Para un ISP con Integra es
 * perfecto; para una peluquería o un consultorio son cuatro opciones que no
 * hacen nada y que hay que borrar a mano antes de poder usar la pantalla.
 *
 * Lo que se prueba aquí es sobre todo que **no depende de nada de fuera**: si
 * una sola de sus opciones consultara Integra, volveríamos al problema que esto
 * viene a arreglar.
 */
class MenuGenericoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ninguna de sus opciones consulta nada de fuera.
     *
     * Es la condición que da sentido a todo: el menú tiene que responder desde
     * el primer minuto sin conectar ningún software.
     *
     * @test
     */
    public function el_menu_de_fabrica_no_consulta_integra(): void
    {
        $company = Company::create(['name' => 'Peluquería', 'slug' => 'peluqueria', 'active' => true]);

        $menu = WhatsAppMenu::where('company_id', $company->id)->where('is_root', true)->with('options')->first();

        $this->assertNotNull($menu, 'Toda empresa nace con su menú armado.');

        foreach ($menu->options as $opcion) {
            $this->assertArrayNotHasKey(
                $opcion->action_type,
                WhatsAppMenuOption::INTEGRA_ACTIONS,
                "«{$opcion->title}» consulta Integra y no debería."
            );
        }
    }

    /**
     * Nace apagado, y con los textos sin rellenar a la vista.
     *
     * Un menú que ya trae escrito «Atendemos de lunes a viernes de 8 a 6» se
     * enciende tal cual, y entonces le promete a los clientes de la empresa un
     * horario que nadie comprobó. Los corchetes hacen imposible encenderlo sin
     * leerlo.
     *
     * @test
     */
    public function nace_apagado_y_con_los_textos_por_rellenar(): void
    {
        $company = Company::create(['name' => 'Consultorio', 'slug' => 'consultorio', 'active' => true]);

        $menu = WhatsAppMenu::where('company_id', $company->id)->where('is_root', true)->with('options')->first();

        $this->assertFalse((bool) $menu->active);
        $this->assertStringContainsString('[', (string) $menu->options->first()->reply_text);
    }

    /** Y siempre tiene salida hacia una persona. */
    public function test_siempre_deja_hablar_con_alguien(): void
    {
        $company = Company::create(['name' => 'Tienda', 'slug' => 'tienda', 'active' => true]);

        $menu = WhatsAppMenu::where('company_id', $company->id)->where('is_root', true)->with('options')->first();

        $this->assertTrue(
            $menu->options->contains(fn ($o) => $o->action_type === 'handoff'),
            'Un menú sin salida humana deja atrapado a quien necesita algo que no está en la lista.'
        );
    }

    /** No pisa los menús de una empresa que ya tiene los suyos. */
    public function test_no_siembra_encima_de_lo_que_ya_existe(): void
    {
        $company = Company::create(['name' => 'Con menús', 'slug' => 'con-menus', 'active' => true]);

        $cuantos = WhatsAppMenu::where('company_id', $company->id)->count();

        $this->assertNull(MenuGenerico::createFor($company));
        $this->assertSame($cuantos, WhatsAppMenu::where('company_id', $company->id)->count());
    }

    // ─── La plantilla de ISP ─────────────────────────────────────────────────

    /**
     * Un ISP recupera las opciones de autoservicio con un botón.
     *
     * Es la otra mitad del cambio: la plantilla de Integra deja de venir puesta
     * y pasa a ofrecerse, así que tiene que poder aplicarse sin escribir las
     * cuatro opciones a mano.
     *
     * @test
     */
    public function un_isp_puede_traerse_las_opciones_de_autoservicio(): void
    {
        $company = Company::create(['name' => 'Fibra ISP', 'slug' => 'fibra-isp', 'active' => true]);
        $this->comoAdmin($company);

        $menu = WhatsAppMenu::where('company_id', $company->id)->where('is_root', true)->with('options')->first();
        $textosAntes = $menu->body_text;

        $this->assertFalse($this->usaIntegra($menu));

        $this->post(route('whatsapp-menus.plantilla-isp'))->assertRedirect();

        $menu->refresh()->load('options');

        $this->assertTrue($this->usaIntegra($menu), 'Ya tiene las opciones de autoservicio.');
        // Los textos del menú son de la empresa: llevan su nombre y su tono.
        $this->assertSame($textosAntes, $menu->body_text);
    }

    /**
     * Una barbería no ve ni una palabra sobre Integra.
     *
     * Ni los permisos de la IA que lo consultan, ni si está conectado, ni la
     * plantilla. Integra es un ERP de ISPs: para quien no lo usa, cada mención
     * es una pregunta que no sabe responder («¿tengo que contratar eso?»).
     *
     * La llave es una sola —`integra.usa`— y vale lo mismo en toda la pantalla:
     * lo tiene conectado, o tiene puestas las opciones que lo consultan.
     */
    public function test_una_barberia_no_ve_nada_de_integra(): void
    {
        $company = Company::create(['name' => 'Barbería', 'slug' => 'barberia', 'active' => true]);
        $this->comoAdmin($company);

        $integra = $this->get(route('whatsapp-menus.index'))
            ->assertOk()
            ->viewData('page')['props']['integra'];

        $this->assertFalse($integra['usa'], 'Sin Integra conectado y sin opciones suyas, no se menciona.');
        $this->assertFalse($integra['puede_aplicar_plantilla'], 'Ni se le ofrece la plantilla.');
    }

    /** Y en cuanto tiene las opciones puestas, sí: ahí el aviso le sirve. */
    public function test_con_opciones_de_autoservicio_integra_si_aparece(): void
    {
        $company = Company::create(['name' => 'Fibra', 'slug' => 'fibra-usa', 'active' => true]);
        $this->comoAdmin($company);

        $this->post(route('whatsapp-menus.plantilla-isp'))->assertRedirect();

        $integra = $this->get(route('whatsapp-menus.index'))
            ->assertOk()
            ->viewData('page')['props']['integra'];

        $this->assertTrue($integra['usa']);
        // Y ya no se le ofrece la plantilla: ya la tiene.
        $this->assertFalse($integra['puede_aplicar_plantilla']);
    }

    private function usaIntegra(WhatsAppMenu $menu): bool
    {
        return $menu->options->contains(
            fn ($o) => array_key_exists((string) $o->action_type, WhatsAppMenuOption::INTEGRA_ACTIONS)
        );
    }

    private function comoAdmin(Company $company): User
    {
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin', 'email' => 'admin-'.$company->id.'@x.test',
            'password' => 'secret', 'active' => true,
        ]);

        setPermissionsTeamId($company->id);

        $rol = Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web',
        ]);
        $rol->givePermissionTo(Permission::firstOrCreate([
            'name' => 'whatsapp_menus.update', 'guard_name' => 'web',
        ]));
        $user->assignRole($rol);

        $this->actingAs($user);

        return $user;
    }
}
