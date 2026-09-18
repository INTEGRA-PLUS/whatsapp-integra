<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Support\PuertaDeEntrada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Que el cliente elija: el menú, o preguntar con sus palabras.
 *
 * Es el patrón del WhatsApp de Bancolombia. Ya se podía armar a mano —un menú
 * de bienvenida con una opción «Abrir otro menú» y otra «Que responda la IA»—
 * así que lo que se prueba aquí no es que sea posible, sino las dos cosas que
 * lo harían inútil: que se ofrezca a quien no tiene IA, y que rompa el menú que
 * la empresa ya tenía montado.
 */
class PuertaDeEntradaTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function sin_ia_ni_se_ofrece(): void
    {
        $company = $this->empresa();
        $this->menuQueSaluda($company);

        $this->assertFalse(
            PuertaDeEntrada::seLePuedeOfrecer($company),
            'Sin IA no hay dos caminos: la puerta sería una pregunta con una sola respuesta.'
        );
    }

    /** @test */
    public function con_ia_y_un_menu_que_salude_se_ofrece(): void
    {
        $company = $this->empresa();
        $this->menuQueSaluda($company);
        $this->encenderIa($company);

        $this->assertTrue(PuertaDeEntrada::seLePuedeOfrecer($company));
    }

    /**
     * Arma los dos caminos y **no rompe el menú de antes**.
     *
     * Es la condición que la hace aceptable: la empresa lleva meses con su menú
     * y sus palabras clave, y esto se pone delante, no encima.
     *
     * @test
     */
    public function arma_los_dos_caminos_y_respeta_el_menu_de_antes(): void
    {
        $company = $this->empresa();
        $antiguo = $this->menuQueSaluda($company);
        $this->encenderIa($company);

        $puerta = PuertaDeEntrada::armar($company);

        $this->assertNotNull($puerta);
        $this->assertContains('welcome', (array) $puerta->match_types, 'La puerta es la que saluda ahora.');

        $acciones = $puerta->options->pluck('action_type')->all();
        $this->assertContains('submenu', $acciones);
        $this->assertContains(WhatsAppMenuOption::ACTION_IA, $acciones);

        $abre = $puerta->options->firstWhere('action_type', 'submenu');
        $this->assertSame($antiguo->id, (int) $abre->target_menu_id, 'El primer camino abre el menú de siempre.');

        $antiguo->refresh()->load('options');

        $this->assertNotContains('welcome', (array) $antiguo->match_types, 'Deja de saludar…');
        $this->assertContains('contains', (array) $antiguo->match_types, '…pero conserva sus palabras clave.');
        $this->assertSame('menu, ayuda', $antiguo->trigger_text);
        $this->assertTrue((bool) $antiguo->active, 'Y sigue encendido: no se apaga ni se borra.');
        $this->assertCount(2, $antiguo->options, 'Sus opciones no se tocan.');
    }

    /**
     * Un menú de bienvenida con opciones de IA dentro NO es una puerta.
     *
     * Es el caso real que escondió el botón a quien lo pidió: cinco opciones,
     * dos de ellas contestadas por la IA. Eso no le da a elegir al cliente
     * entre dos caminos — le da cinco, como cualquier menú.
     *
     * @test
     */
    public function un_menu_con_opciones_de_ia_dentro_no_es_una_puerta(): void
    {
        $company = $this->empresa();
        $menu = $this->menuQueSaluda($company);
        $this->encenderIa($company);

        $menu->options()->create([
            'position' => 2, 'title' => 'Nuestros servicios',
            'action_type' => WhatsAppMenuOption::ACTION_IA,
        ]);

        $this->assertTrue(
            PuertaDeEntrada::seLePuedeOfrecer($company),
            'Sigue sin tener puerta: la opción de IA es una más de su lista.'
        );
    }

    /** Y no se ofrece dos veces: la señal es la forma —dos opciones, menú e IA—. */
    public function test_no_se_ofrece_si_ya_esta_armada(): void
    {
        $company = $this->empresa();
        $this->menuQueSaluda($company);
        $this->encenderIa($company);

        PuertaDeEntrada::armar($company);

        $this->assertFalse(PuertaDeEntrada::seLePuedeOfrecer($company));
        $this->assertNull(PuertaDeEntrada::armar($company), 'Y armarla otra vez no crea una segunda.');
    }

    /**
     * Borrar la puerta devuelve el saludo al menú que abría.
     *
     * Sin esto, deshacerla dejaba a la empresa **sin ningún menú de bienvenida**
     * y sin decírselo: el cliente escribe «hola» y no recibe nada, y la pantalla
     * tampoco vuelve a ofrecer armarla porque ya no hay a quién abrirle.
     *
     * @test
     */
    public function borrar_la_puerta_devuelve_el_saludo(): void
    {
        $company = $this->empresa();
        $antiguo = $this->menuQueSaluda($company);
        $this->encenderIa($company);

        $user = User::create([
            'company_id' => $company->id, 'name' => 'Admin',
            'email' => 'admin-puerta@x.test', 'password' => 'secret', 'active' => true,
        ]);

        $puerta = PuertaDeEntrada::armar($company);

        $this->assertNotContains('welcome', (array) $antiguo->refresh()->match_types);

        $this->actingAs($user)
            ->delete(route('whatsapp-menus.destroy', $puerta->id))
            ->assertRedirect();

        $this->assertContains(
            'welcome',
            (array) $antiguo->refresh()->match_types,
            'El saludo vuelve a quien lo tenía: deshacer la puerta deja las cosas como estaban.'
        );

        // Y se puede volver a ofrecer, que es la otra mitad del problema.
        $this->assertTrue(PuertaDeEntrada::seLePuedeOfrecer($company->refresh()));
    }

    /** El botón exige el permiso de menús, como todo lo que toca menús. */
    public function test_hace_falta_el_permiso(): void
    {
        $company = $this->empresa();
        $this->menuQueSaluda($company);
        $this->encenderIa($company);

        $user = User::create([
            'company_id' => $company->id, 'name' => 'Sin permisos',
            'email' => 'sin@x.test', 'password' => 'secret', 'active' => true,
        ]);

        // El primer usuario de una empresa se lleva el rol admin con los
        // permisos que existan (User::booted), así que hace falta un segundo.
        $otro = User::create([
            'company_id' => $company->id, 'name' => 'Segundo',
            'email' => 'segundo@x.test', 'password' => 'secret', 'active' => true,
        ]);

        $this->actingAs($otro)
            ->post(route('whatsapp-menus.puerta-de-entrada'))
            ->assertForbidden();
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function empresa(): Company
    {
        $company = Company::create([
            'name' => 'Cootramed', 'slug' => 'cootramed-'.uniqid(), 'active' => true,
            'plan' => 'basico', 'ia' => 'completa',
        ]);

        // Toda empresa nace con el menú genérico sembrado; aquí se monta el
        // escenario a mano.
        WhatsAppMenu::where('company_id', $company->id)->get()->each->delete();

        return $company;
    }

    private function menuQueSaluda(Company $company): WhatsAppMenu
    {
        $menu = WhatsAppMenu::create([
            'company_id' => $company->id,
            'name' => 'Menú principal',
            'body_text' => '¿En qué te ayudo?',
            'is_root' => true,
            'match_types' => ['welcome', 'contains'],
            'trigger_text' => 'menu, ayuda',
            'active' => true,
        ]);

        foreach (['Horarios', 'Hablar con alguien'] as $i => $titulo) {
            $menu->options()->create([
                'position' => $i, 'title' => $titulo,
                'action_type' => 'reply_text', 'reply_text' => 'Texto',
            ]);
        }

        return $menu->load('options');
    }

    private function encenderIa(Company $company): void
    {
        CompanyIntegration::updateOrCreate(
            ['company_id' => $company->id, 'key' => CompanyIntegration::KEY_AI_CHAT],
            ['enabled' => true]
        );

        config([
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'n8n_llave',
        ]);
    }
}
