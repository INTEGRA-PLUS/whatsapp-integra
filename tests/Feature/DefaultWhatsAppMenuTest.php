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

        $menu = WhatsAppMenu::where('company_id', $company->id)->with('options')->first();

        $this->assertNotNull($menu, 'La empresa nueva no recibió su menú por defecto.');
        $this->assertSame(DefaultWhatsAppMenu::NAME, $menu->name);
        $this->assertCount(5, $menu->options);
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

        $this->assertFalse(
            WhatsAppMenu::where('company_id', $company->id)->first()->active
        );
    }

    public function test_apagado_no_le_responde_a_nadie(): void
    {
        $instance = $this->metaInstance($this->company());

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();

        $this->assertSame(0, WhatsAppMessage::where('direction', 'outbound')->count());
    }

    /** Y en cuanto lo encienden, funciona sin tocar nada más. */
    public function test_al_encenderlo_responde_con_las_cinco_opciones(): void
    {
        $company = $this->company();
        $instance = $this->metaInstance($company);

        WhatsAppMenu::where('company_id', $company->id)->update(['active' => true]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();

        $outbound = WhatsAppMessage::where('direction', 'outbound')->first();
        $this->assertNotNull($outbound);

        foreach (['Consultar factura', 'Pagar en línea', 'Cambiar clave WiFi', 'Reportar falla', 'Hablar con un asesor'] as $title) {
            $this->assertStringContainsString($title, $outbound->content);
        }
    }

    /** Y también con la palabra clave, no sólo con el primer mensaje. */
    public function test_la_palabra_menu_lo_vuelve_a_traer(): void
    {
        $company = $this->company();
        $instance = $this->metaInstance($company);

        WhatsAppMenu::where('company_id', $company->id)->update(['active' => true, 'cooldown_minutes' => 0]);

        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'Hola'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $this->inbound($instance, 'menu', 'wamid.IN2'))->assertOk();

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

        $menu = WhatsAppMenu::where('company_id', $company->id)->with('options')->first();
        $this->assertNotNull($menu);
        $this->assertCount(5, $menu->options);
        $this->assertFalse($menu->active);
    }

    /** Correr la migración dos veces no duplica el menú. */
    public function test_crearlo_dos_veces_no_duplica_nada(): void
    {
        $company = $this->company();

        DefaultWhatsAppMenu::createFor($company);
        DefaultWhatsAppMenu::createFor($company);

        $this->assertSame(1, WhatsAppMenu::where('company_id', $company->id)->count());
        $this->assertSame(5, WhatsAppMenuOption::whereIn(
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
        $menu = WhatsAppMenu::where('company_id', $this->company()->id)->with('options')->first();

        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_HEADER, mb_strlen($menu->header_text));
        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_FOOTER, mb_strlen($menu->footer_text));
        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_BODY, mb_strlen($menu->body_text));
        $this->assertLessThanOrEqual(WhatsAppMenu::MAX_BUTTON_TITLE, mb_strlen($menu->list_button_text));

        foreach ($menu->options as $option) {
            $this->assertLessThanOrEqual(WhatsAppMenu::MAX_ROW_TITLE, mb_strlen($option->title), "Título largo: {$option->title}");
            $this->assertLessThanOrEqual(WhatsAppMenu::MAX_ROW_DESCRIPTION, mb_strlen($option->description));
        }
    }

    /** Las acciones configuradas de fábrica son las que el módulo sabe ejecutar. */
    public function test_las_acciones_son_todas_del_catalogo(): void
    {
        $menu = WhatsAppMenu::where('company_id', $this->company()->id)->with('options')->first();

        foreach ($menu->options as $option) {
            $this->assertContains($option->action_type, WhatsAppMenuOption::ACTION_TYPES);
        }

        // "Hablar con un asesor" reparte por carga, que es lo que se pidió: sin
        // esto caería en la bandeja general y nadie lo atendería.
        $handoff = $menu->options->firstWhere('action_type', 'handoff');
        $this->assertSame(WhatsAppMenuOption::ASSIGN_LEAST_BUSY, $handoff->assignStrategy());
    }

    // ------------------------------------------------------------------

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
