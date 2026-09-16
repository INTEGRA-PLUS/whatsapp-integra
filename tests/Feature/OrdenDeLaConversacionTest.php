<?php

namespace Tests\Feature;

use App\Models\BusinessHour;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppMenu;
use App\Support\OrdenDeLaConversacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que las pantallas usan para contar qué le pasa a un cliente que escribe.
 *
 * La pregunta que lo motivó —«¿lo primero que sale es un menú o la IA?»— no
 * tenía respuesta en ninguna pantalla. La respuesta es que **manda el menú y la
 * IA es el último recurso**, y quien no lo sabe enciende la IA, escribe «hola»,
 * recibe un menú y concluye que la IA no funciona.
 *
 * La distinción que más cuenta, y la que se prueba con más cuidado abajo, es la
 * de **menú activo** contra **menú con palabras clave**: un menú sin palabras
 * clave no se dispara nunca por su cuenta, así que existir no basta.
 */
class OrdenDeLaConversacionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();

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
            'access_token' => 'token',
            'active' => true,
        ]);
    }

    /**
     * Una empresa recién creada YA tiene menús, pero ninguno salta solo.
     *
     * `CompanyObserver` le siembra el menú por defecto al crearla. El principal
     * nace **apagado** —a propósito— y el submenú nace activo pero sin
     * disparadores, así que un cliente que escriba no recibe nada automático.
     *
     * Que esto esté probado importa: si mañana el menú por defecto naciera
     * encendido, media base de clientes empezaría a recibir un menú al primer
     * «hola» sin que nadie lo hubiera decidido, y este test es lo único que lo
     * diría.
     */
    public function test_una_empresa_recien_creada_no_responde_sola(): void
    {
        $orden = OrdenDeLaConversacion::de($this->company->id);

        $this->assertFalse($orden['hay_disparadores'], 'Nada dispara un menú por su cuenta.');
        $this->assertFalse($orden['saluda_con_menu'], 'Y el de bienvenida nace apagado.');
        $this->assertFalse($orden['ia_chat']);
        $this->assertFalse($orden['horarios']);
    }

    /**
     * El menú de bienvenida salta sin ninguna palabra clave.
     *
     * Es lo que más confunde de todo el sistema: el tipo `welcome` responde al
     * primer mensaje de cada conversación escriba el cliente lo que escriba.
     * Alguien enciende la IA, manda «hola» para probarla, recibe un menú y
     * concluye que la IA no funciona.
     *
     * @test
     */
    public function el_menu_de_bienvenida_salta_sin_palabras_clave(): void
    {
        $menu = $this->menu(trigger: '');
        $menu->update(['match_types' => ['welcome']]);

        $orden = OrdenDeLaConversacion::de($this->company->id);

        $this->assertTrue($orden['saluda_con_menu']);
        // Y sigue sin tener palabras clave: son dos cosas distintas.
        $this->assertFalse($orden['hay_disparadores']);
    }

    /**
     * Un menú sin palabras clave existe pero no se dispara.
     *
     * Es la diferencia entre «tus clientes verán un menú» y «tus clientes
     * hablarán con la IA», y se decide en un campo que casi nadie relaciona con
     * eso. Si la pantalla no las distinguiera, le diría a media base de clientes
     * que sus menús responden cuando no responde ninguno.
     *
     * @test
     */
    public function un_menu_sin_palabras_clave_no_cuenta_como_disparador(): void
    {
        $this->menu(trigger: '');

        $orden = OrdenDeLaConversacion::de($this->company->id);

        $this->assertTrue($orden['hay_menus'], 'El menú existe…');
        $this->assertFalse($orden['hay_disparadores'], '…pero nada lo dispara.');
        $this->assertFalse($orden['saluda_con_menu']);
    }

    /** @test */
    public function un_menu_con_palabras_clave_si_cuenta(): void
    {
        $this->menu(trigger: 'hola, buenas, menu');

        $orden = OrdenDeLaConversacion::de($this->company->id);

        $this->assertTrue($orden['hay_menus']);
        $this->assertTrue($orden['hay_disparadores']);
    }

    /** Un menú apagado no dispara nada, tenga las palabras clave que tenga. */
    public function test_un_menu_inactivo_no_cuenta(): void
    {
        $this->menu(trigger: 'hola', activo: false);

        $this->assertFalse(OrdenDeLaConversacion::de($this->company->id)['hay_disparadores']);
    }

    /**
     * Una IA encendida pero sin plan que la cubra NO se pinta como activa.
     *
     * La integración pudo quedar en `enabled` de cuando la empresa tenía el
     * complemento. Pintarla como activa manda al admin a buscar por qué su IA
     * «no responde» justo donde no está el problema.
     *
     * @test
     */
    public function la_ia_encendida_sin_plan_no_se_pinta_como_activa(): void
    {
        CompanyIntegration::create([
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_AI_CHAT,
            'enabled' => true,
        ]);

        $this->assertTrue(OrdenDeLaConversacion::de($this->company->id)['ia_chat']);

        // Se le acaba el complemento: la fila sigue encendida y ya no responde.
        $this->company->update(['ia' => 'ninguno']);

        $this->assertFalse(OrdenDeLaConversacion::de($this->company->id)['ia_chat']);
    }

    /** Los menús de otra empresa no cuentan: aquí el aislamiento es manual. */
    public function test_no_mira_los_menus_de_otra_empresa(): void
    {
        $otra = Company::create([
            'name' => 'Otra ISP', 'slug' => 'otra-isp', 'active' => true, 'plan' => 'basico',
        ]);

        $suInstancia = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea ajena',
            'phone_number_id' => '999888777',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'access_token' => 'token',
            'active' => true,
        ]);

        WhatsAppMenu::create([
            'company_id' => $otra->id,
            'instance_id' => $suInstancia->id,
            'name' => 'Menú ajeno',
            'body_text' => '¿En qué te ayudo?',
            'trigger_text' => 'hola',
            'type' => 'button',
            'active' => true,
        ]);

        // El menú con palabras clave es de la otra empresa: aquí no dispara.
        $this->assertFalse(OrdenDeLaConversacion::de($this->company->id)['hay_disparadores']);
    }

    /** @test */
    public function detecta_los_horarios(): void
    {
        $this->assertFalse(OrdenDeLaConversacion::de($this->company->id)['horarios']);

        BusinessHour::create([
            'company_id' => $this->company->id,
            'name' => 'Horario de oficina',
            'active' => true,
            'out_of_hours_message' => 'Estamos cerrados; te escribimos mañana.',
        ]);

        $this->assertTrue(OrdenDeLaConversacion::de($this->company->id)['horarios']);
    }

    private function menu(string $trigger, bool $activo = true): WhatsAppMenu
    {
        return WhatsAppMenu::create([
            'company_id' => $this->company->id,
            'instance_id' => $this->instance->id,
            'name' => 'Menú principal',
            'body_text' => '¿En qué te ayudo?',
            'trigger_text' => $trigger,
            'type' => 'button',
            'active' => $activo,
        ]);
    }
}
