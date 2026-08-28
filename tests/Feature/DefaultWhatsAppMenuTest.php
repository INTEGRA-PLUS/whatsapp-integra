<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMessage;
use App\Support\DefaultWhatsAppMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El menú con el que arranca toda empresa.
 *
 * Lo que se protege aquí no es el contenido del menú —los textos cambiarán—
 * sino las dos promesas que lo hacen seguro: que nace apagado, y que no le pasa
 * por encima a quien ya configuró los suyos.
 */
class DefaultWhatsAppMenuTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '573007852081';

    /** La plantilla anterior: la que recibieron las empresas que sembraron antes del submenú. */
    private const PREVIOUS_TEMPLATE = [
        ['📄 Consultar factura', 'consultar_factura'],
        ['💳 Pagar en línea', 'pagar_en_linea'],
        ['📶 Cambiar clave WiFi', 'cambiar_clave'],
        ['🛠️ Reportar falla', 'reportar_falla'],
        ['👤 Hablar con un asesor', 'handoff'],
    ];

    /** Y la siguiente, ya con submenú: dos menús que hay que comprobar enteros. */
    private const SUBMENU_TEMPLATE_NAME = 'Estado de mi contrato';

    private const PREVIOUS_ROOT_WITH_SUBMENU = [
        ['📄 Consultar factura', 'consultar_factura'],
        ['💳 Pagar en línea', 'pagar_en_linea'],
        ['📶 Estado de mi contrato', 'submenu'],
        ['🛠️ Reportar falla', 'reportar_falla'],
        ['👤 Hablar con un asesor', 'handoff'],
    ];

    private const PREVIOUS_SUBMENU = [
        ['🌐 Estado de internet', 'estado_servicio'],
        ['📄 Facturas pendientes', 'estado_servicio'],
        ['⚡ Mi plan y velocidad', 'estado_servicio'],
        ['📅 Cuándo me cortan', 'estado_servicio'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $n = 0;
        Http::fake(function () use (&$n) {
            return Http::response(['messages' => [['id' => 'wamid.OUT' . (++$n)]]], 200);
        });
    }

    public function test_una_empresa_nueva_nace_con_el_menu_armado(): void
    {
        $company = $this->company();

        $menu = $this->rootMenu($company);

        $this->assertNotNull($menu, 'La empresa nueva no recibió su menú por defecto.');
        $this->assertSame(DefaultWhatsAppMenu::NAME, $menu->name);
        $this->assertCount(8, $menu->options);
        $this->assertSame('list', $menu->format());

        // El nombre de la empresa encabeza el menú, y las variables del módulo
        // funcionan desde el primer día.
        $this->assertSame('Cmnet', $menu->header_text);
        $this->assertStringContainsString('{name}', $menu->body_text);
    }

    /**
     * La promesa que hace segura la migración: ninguna empresa empieza a
     * responderle a sus clientes sin que alguien de esa empresa lo apruebe.
     */
    public function test_nace_apagado(): void
    {
        $company = $this->company();

        $this->assertFalse($this->rootMenu($company)->active);
    }

    public function test_apagado_no_le_responde_a_nadie(): void
    {
        $instance = $this->metaInstance($this->company());

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();

        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    /** Y en cuanto lo encienden, funciona sin tocar nada más. */
    public function test_al_encenderlo_responde_con_todas_las_opciones(): void
    {
        $company = $this->company();
        $instance = $this->metaInstance($company);

        $this->rootMenu($company)->update(['active' => true]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();

        $outbound = WhatsAppMessage::where('direction', 'outbound')->first();
        $this->assertNotNull($outbound);

        foreach ([
            'Mis facturas', 'Pagar en línea', 'Estado de mi servicio', 'Mis últimos pagos',
            'Reportar una falla', 'Mi consumo', 'Mi plan y contrato', 'Hablar con un asesor',
        ] as $title) {
            $this->assertStringContainsString($title, $outbound->content);
        }
    }

    /** Y también con la palabra clave, no sólo con el primer mensaje. */
    public function test_la_palabra_menu_lo_vuelve_a_traer(): void
    {
        $company = $this->company();
        $instance = $this->metaInstance($company);

        $this->rootMenu($company)->update(['active' => true, 'cooldown_minutes' => 0]);

        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->postSignedWebhook($this->inbound($instance, 'menu', 'wamid.IN2'))->assertOk();

        $this->assertSame(2, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    /**
     * Quien ya configuró sus menús no necesita que le aparezca uno más, y
     * sobrescribir su trabajo sería mucho peor que no hacer nada.
     */
    public function test_no_se_le_añade_a_quien_ya_tiene_menus(): void
    {
        $company = $this->company();

        // La empresa borra el menú por defecto y arma el suyo.
        WhatsAppMenu::where('company_id', $company->id)->delete();
        WhatsAppMenu::create([
            'company_id' => $company->id,
            'name' => 'El mío',
            'body_text' => 'Hola',
            'is_root' => true,
            'match_types' => ['welcome'],
            'active' => true,
        ]);

        $this->assertNull(DefaultWhatsAppMenu::createFor($company));
        $this->assertSame(1, WhatsAppMenu::where('company_id', $company->id)->count());
        $this->assertSame('El mío', WhatsAppMenu::where('company_id', $company->id)->first()->name);
    }

    /**
     * El caso de la migración: una empresa que nació antes de que existiera el
     * menú por defecto. Se inserta a pelo para saltarse el observer, que es
     * justo la situación en la que están hoy las empresas en producción.
     */
    public function test_la_migracion_alcanza_a_las_empresas_que_ya_existian(): void
    {
        DB::table('companies')->insert([
            'name' => 'Empresa vieja',
            'slug' => 'empresa-vieja',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $company = Company::where('slug', 'empresa-vieja')->first();
        $this->assertSame(0, WhatsAppMenu::where('company_id', $company->id)->count());

        DefaultWhatsAppMenu::createFor($company);

        $menu = $this->rootMenu($company);
        $this->assertNotNull($menu);
        $this->assertCount(8, $menu->options);
        $this->assertFalse($menu->active);
    }

    /** Correr la migración dos veces no duplica el menú. */
    public function test_crearlo_dos_veces_no_duplica_nada(): void
    {
        $company = $this->company();

        DefaultWhatsAppMenu::createFor($company);
        DefaultWhatsAppMenu::createFor($company);

        $this->assertSame(2, WhatsAppMenu::where('company_id', $company->id)->count());
        $this->assertSame(15, WhatsAppMenuOption::whereIn(
            'menu_id',
            WhatsAppMenu::where('company_id', $company->id)->pluck('id')
        )->count());
    }

    /**
     * Meta rechaza el menú entero con un 400 si un título se pasa de largo, así
     * que el que mandamos de fábrica tiene que caber sin que nadie lo revise.
     */
    public function test_los_textos_caben_en_los_limites_de_meta(): void
    {
        $menu = $this->rootMenu($this->company());

        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_HEADER, mb_strlen($menu->header_text));
        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_FOOTER, mb_strlen($menu->footer_text));
        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_BODY, mb_strlen($menu->body_text));
        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_BUTTON_TITLE, mb_strlen($menu->list_button_text));

        foreach (WhatsAppMenuOption::all() as $option) {
            $this->assertLessThanOrEqual(WhatsAppMenu::MAX_ROW_TITLE, mb_strlen($option->title), "Título largo: {$option->title}");
            $this->assertLessThanOrEqual(WhatsAppMenu::MAX_ROW_DESCRIPTION, mb_strlen($option->description));
        }
    }

    /** Las acciones configuradas de fábrica son las que el módulo sabe ejecutar. */
    public function test_las_acciones_son_todas_del_catalogo(): void
    {
        $menu = $this->rootMenu($this->company());

        foreach ($menu->options as $option) {
            $this->assertContains($option->action_type, WhatsAppMenuOption::ACTION_TYPES);
        }

        // "Hablar con un asesor" reparte por carga, que es lo que se pidió: sin
        // esto caería en la bandeja general y nadie lo atendería.
        $handoff = $menu->options->firstWhere('action_type', 'handoff');
        $this->assertSame(WhatsAppMenuOption::ASSIGN_LEAST_BUSY, $handoff->assignStrategy());
    }

    /**
     * El submenú de contrato nace ACTIVO aunque el principal esté apagado.
     *
     * No es un descuido: al no ser menú raíz nunca se dispara solo, así que no
     * le llega a nadie mientras el principal siga apagado. Al revés sí sería
     * una trampa — el admin enciende el menú, el cliente toca "Estado de mi
     * contrato" y no recibe nada.
     */
    public function test_el_submenu_de_contrato_nace_activo_pero_no_se_dispara_solo(): void
    {
        $company = $this->company();
        $instance = $this->metaInstance($company);

        $submenu = WhatsAppMenu::where('company_id', $company->id)
            ->where('name', DefaultWhatsAppMenu::SUBMENU_NAME)
            ->with('options')
            ->first();

        $this->assertNotNull($submenu);
        $this->assertTrue($submenu->active);
        $this->assertFalse($submenu->is_root);
        $this->assertCount(7, $submenu->options);

        // Con el principal apagado, el cliente no recibe nada: un submenú activo
        // sigue siendo inalcanzable por su cuenta.
        $this->postSignedWebhook($this->inbound($instance, 'Hola'))->assertOk();
        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    public function test_la_opcion_de_contrato_lleva_al_submenu(): void
    {
        $company = $this->company();

        $option = $this->rootMenu($company)->options->firstWhere('action_type', 'submenu');
        $submenu = WhatsAppMenu::where('company_id', $company->id)
            ->where('name', DefaultWhatsAppMenu::SUBMENU_NAME)
            ->first();

        $this->assertNotNull($option, 'El menú principal no tiene la opción que abre el submenú.');
        $this->assertSame($submenu->id, $option->target_menu_id);
    }

    /**
     * Cada opción del submenú pide un trozo distinto del contrato. Si dos
     * compartieran segmento, el cliente recibiría lo mismo por dos caminos.
     */
    public function test_cada_opcion_del_submenu_muestra_un_segmento_distinto(): void
    {
        $company = $this->company();

        $submenu = WhatsAppMenu::where('company_id', $company->id)
            ->where('name', DefaultWhatsAppMenu::SUBMENU_NAME)
            ->with('options')
            ->firstOrFail();

        $segments = $submenu->options->map->statusSegment();

        $this->assertSame(['plan', 'corte', 'contrato', 'wifi', 'soportes', 'television', 'datos'], $segments->all());
        $this->assertCount(7, $segments->unique());

        foreach ($segments as $segment) {
            $this->assertArrayHasKey($segment, WhatsAppMenuOption::STATUS_SEGMENTS);
        }
    }

    /**
     * Una empresa que recibió la plantilla anterior se pone al día sola.
     *
     * Es el caso que de verdad pasó: sembrar se salta a quien ya tiene menús,
     * así que sin un camino de actualización esas empresas se quedaban con la
     * versión vieja para siempre.
     */
    public function test_un_menu_de_fabrica_intacto_se_pone_al_dia(): void
    {
        $company = $this->company();
        $this->downgradeToPreviousTemplate($company);

        DefaultWhatsAppMenu::refreshUntouched(self::PREVIOUS_TEMPLATE);

        $root = $this->rootMenu($company);
        $this->assertSame('📶 Estado de mi servicio', $root->options[2]->title);
        $this->assertSame('estado_servicio', $root->options[2]->action_type);
        $this->assertSame(2, WhatsAppMenu::where('company_id', $company->id)->count());
        // Y sigue apagado: ponerse al día no es motivo para empezar a responder.
        $this->assertFalse($root->active);
    }

    /**
     * La puesta al día cuando la plantilla anterior ya traía submenú.
     *
     * Es el caso que estaba roto por partida doble: mirando sólo el menú raíz,
     * una empresa con dos menús nunca se consideraba intacta y no se
     * actualizaba jamás; y si hubiera pasado el filtro, se borraba el raíz
     * dejando el submenú huérfano —y con él en pie, sembrar de nuevo no hace
     * nada porque la empresa "ya tiene menús"—, así que la empresa se habría
     * quedado sin menú principal.
     */
    public function test_una_plantilla_de_dos_menus_intacta_tambien_se_pone_al_dia(): void
    {
        $company = $this->company();
        $this->downgradeToSubmenuTemplate($company);

        DefaultWhatsAppMenu::refreshUntouched(
            self::PREVIOUS_ROOT_WITH_SUBMENU,
            [self::SUBMENU_TEMPLATE_NAME => self::PREVIOUS_SUBMENU]
        );

        $root = $this->rootMenu($company);

        $this->assertNotNull($root, 'La empresa se quedó sin menú principal.');
        $this->assertCount(8, $root->options);
        $this->assertSame('💵 Mis últimos pagos', $root->options[3]->title);
        $this->assertSame(2, WhatsAppMenu::where('company_id', $company->id)->count());
        $this->assertSame(
            DefaultWhatsAppMenu::SUBMENU_NAME,
            WhatsAppMenu::where('company_id', $company->id)->where('is_root', false)->value('name')
        );
        // Y sigue apagado: ponerse al día no es motivo para empezar a responder.
        $this->assertFalse($root->active);
    }

    /**
     * Y si el admin retocó el submenú —aunque el raíz siga de fábrica—, no se
     * toca nada. El trabajo suyo manda sobre la plantilla al día.
     */
    public function test_un_submenu_retocado_deja_toda_la_plantilla_en_paz(): void
    {
        $company = $this->company();
        $this->downgradeToSubmenuTemplate($company);

        $submenu = WhatsAppMenu::where('company_id', $company->id)->where('is_root', false)->with('options')->first();
        $submenu->options[1]->update(['title' => '📄 Lo que debo']);

        DefaultWhatsAppMenu::refreshUntouched(
            self::PREVIOUS_ROOT_WITH_SUBMENU,
            [self::SUBMENU_TEMPLATE_NAME => self::PREVIOUS_SUBMENU]
        );

        $this->assertCount(5, $this->rootMenu($company)->options);
        $this->assertSame('📄 Lo que debo', $submenu->fresh()->options[1]->title);
    }

    /**
     * Lo contrario, que importa más: en cuanto el admin cambia algo, la
     * actualización lo deja en paz. Vale más su trabajo que la plantilla al día.
     */
    public function test_un_menu_ya_configurado_no_se_toca(): void
    {
        $company = $this->company();
        $this->downgradeToPreviousTemplate($company);

        $menu = $this->rootMenu($company);
        $menu->options[2]->update(['title' => '📶 Mi WiFi']);

        DefaultWhatsAppMenu::refreshUntouched(self::PREVIOUS_TEMPLATE);

        $this->assertSame('📶 Mi WiFi', $this->rootMenu($company)->options[2]->title);
        $this->assertSame(1, WhatsAppMenu::where('company_id', $company->id)->count());
    }

    /** Un menú ya encendido tampoco: está atendiendo clientes ahora mismo. */
    public function test_un_menu_encendido_no_se_toca(): void
    {
        $company = $this->company();
        $this->downgradeToPreviousTemplate($company);
        $this->rootMenu($company)->update(['active' => true]);

        DefaultWhatsAppMenu::refreshUntouched(self::PREVIOUS_TEMPLATE);

        $this->assertSame('📶 Cambiar clave WiFi', $this->rootMenu($company)->options[2]->title);
    }

    // ------------------------------------------------------------------

    /** Deja a la empresa como si hubiera recibido la plantilla anterior. */
    /**
     * Deja a la empresa como si hubiera recibido la plantilla del submenú: dos
     * menús, el raíz apuntando al de contrato.
     */
    private function downgradeToSubmenuTemplate(Company $company): void
    {
        WhatsAppMenu::where('company_id', $company->id)->where('is_root', false)->delete();

        $submenu = WhatsAppMenu::create([
            'company_id' => $company->id,
            'name' => self::SUBMENU_TEMPLATE_NAME,
            'body_text' => '📶 ¿Qué quieres revisar de tu servicio?',
            'is_root' => false,
            'active' => true,
        ]);

        foreach (self::PREVIOUS_SUBMENU as $position => [$title, $action]) {
            $submenu->options()->create([
                'position' => $position,
                'title' => $title,
                'action_type' => $action,
            ]);
        }

        $menu = $this->rootMenu($company);
        $menu->options()->delete();

        foreach (self::PREVIOUS_ROOT_WITH_SUBMENU as $position => [$title, $action]) {
            $menu->options()->create([
                'position' => $position,
                'title' => $title,
                'action_type' => $action,
                'target_menu_id' => $action === 'submenu' ? $submenu->id : null,
            ]);
        }
    }

    private function downgradeToPreviousTemplate(Company $company): void
    {
        WhatsAppMenu::where('company_id', $company->id)->where('is_root', false)->delete();

        $menu = $this->rootMenu($company);
        $menu->options()->delete();

        foreach (self::PREVIOUS_TEMPLATE as $position => [$title, $action]) {
            $menu->options()->create([
                'position' => $position,
                'title' => $title,
                'action_type' => $action,
            ]);
        }
    }

    private function rootMenu(Company $company): ?WhatsAppMenu
    {
        return WhatsAppMenu::where('company_id', $company->id)
            ->where('is_root', true)
            ->with('options')
            ->first();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);
    }

    private function metaInstance(Company $company): Instance
    {
        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-meta',
        ]);
    }

    private function inbound(Instance $instance, string $text, string $wamid = 'wamid.IN1'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '2212436902867081',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '573104047030',
                            'phone_number_id' => $instance->phone_number_id,
                        ],
                        'contacts' => [['profile' => ['name' => 'Katherine'], 'wa_id' => self::PHONE]],
                        'messages' => [[
                            'from' => self::PHONE,
                            'id' => $wamid,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
