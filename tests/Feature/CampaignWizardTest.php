<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El asistente de campañas, del formulario a las filas en la base de datos.
 *
 * Lo que se prueba aquí es la frontera: que una campaña nazca ya atada a una
 * plantilla aprobada, que los destinatarios lleguen sin repetidos y con el
 * teléfono en la forma que entiende WhatsApp, y que un envío mal armado se
 * rechace en la pantalla en vez de morir después en un webhook.
 */
class CampaignWizardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Instance $instance;
    private User $user;

    /**
     * El catálogo que responde Meta. Es una propiedad y no un parámetro porque
     * Http::fake() encadena los stubs y gana el primero que registre la clase:
     * un segundo fake dentro de un test no reemplazaría a este.
     */
    private array $catalogo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-' . Str::random(4), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => Str::random(8) . '@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);

        setPermissionsTeamId($this->company->id);

        foreach (['campaigns.view', 'campaigns.create', 'campaigns.update', 'campaigns.delete'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web']);
        $role->syncPermissions(['campaigns.view', 'campaigns.create', 'campaigns.update', 'campaigns.delete']);
        $this->user->assignRole($role);

        $this->catalogo = [[
            'id' => 'tpl-1',
            'name' => 'aviso_factura',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu factura está lista.']],
        ]];

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => $this->catalogo], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(6)]]], 200);
        });
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Facturación septiembre',
            'instance_id' => $this->instance->id,
            'template_name' => 'aviso_factura',
            'template_language' => 'es',
            'template_components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu factura está lista.']],
            'variable_map' => ['body' => [['source' => 'field', 'field' => 'name']]],
            'manual_recipients' => [['phone' => '573007852081', 'name' => 'Daniela']],
            'launch_now' => false,
        ], $overrides);
    }

    public function test_el_asistente_se_abre_con_la_linea_ya_elegida(): void
    {
        $this->actingAs($this->user)
            ->get('/campaigns/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Campaigns/Create')
                ->where('defaultInstanceId', $this->instance->id));
    }

    public function test_se_crea_como_borrador_con_su_plantilla(): void
    {
        $this->actingAs($this->user)
            ->post('/campaigns', $this->payload())
            ->assertRedirect();

        $campaign = WhatsAppCampaign::first();

        $this->assertSame('template', $campaign->message_type);
        $this->assertSame('aviso_factura', $campaign->template_name);
        $this->assertSame('draft', $campaign->status);
        $this->assertTrue($campaign->usesTemplate());
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertSame('573007852081', $campaign->recipients()->first()->phone_number);
    }

    /**
     * Tres fuentes distintas pueden traer a la misma persona; se le escribe una
     * vez, no tres.
     */
    public function test_un_mismo_numero_en_dos_fuentes_solo_se_envia_una_vez(): void
    {
        $conversation = WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Daniela',
            'status' => 'open',
        ]);

        $contact = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Daniela Galindo',
            'phone_number' => '573007852081',
        ]);

        $this->actingAs($this->user)
            ->post('/campaigns', $this->payload([
                'conversation_ids' => [$conversation->id],
                'contact_ids' => [$contact->id],
                'manual_recipients' => [['phone' => '+57 300 785 2081', 'name' => 'Dani']],
            ]))
            ->assertRedirect();

        $this->assertSame(1, WhatsAppCampaign::first()->recipients()->count());
    }

    /**
     * Un BSUID (el identificador de quien oculta su número) es un destinatario
     * válido: quitarle las letras lo convertiría en un teléfono inventado.
     */
    public function test_un_bsuid_llega_intacto(): void
    {
        $this->actingAs($this->user)
            ->post('/campaigns', $this->payload([
                'manual_recipients' => [['phone' => 'CO.1402615141764490', 'name' => 'Anónimo']],
            ]))
            ->assertRedirect();

        $this->assertSame(
            'CO.1402615141764490',
            WhatsAppCampaign::first()->recipients()->first()->phone_number
        );
    }

    public function test_sin_destinatarios_no_se_crea_nada(): void
    {
        $this->actingAs($this->user)
            ->post('/campaigns', $this->payload(['manual_recipients' => []]))
            ->assertSessionHasErrors('recipients');

        $this->assertSame(0, WhatsAppCampaign::count());
    }

    /**
     * El caso del contrato #4311, atajado en la propia pantalla: la plantilla
     * lleva imagen en el encabezado y la campaña no trae ninguna.
     */
    public function test_una_plantilla_con_imagen_sin_archivo_se_rechaza_al_crearla(): void
    {
        $this->catalogo = [[
            'id' => 'tpl-2',
            'name' => 'aviso_con_imagen',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => 'Hola {{1}}.'],
            ],
        ]];

        $this->actingAs($this->user)
            ->post('/campaigns', $this->payload([
                'template_name' => 'aviso_con_imagen',
                'template_components' => [
                    ['type' => 'HEADER', 'format' => 'IMAGE'],
                    ['type' => 'BODY', 'text' => 'Hola {{1}}.'],
                ],
            ]))
            ->assertSessionHasErrors('template_name');

        $this->assertSame(0, WhatsAppCampaign::count());
    }

    public function test_el_buscador_encuentra_contactos_del_crm(): void
    {
        Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Daniela Galindo Rosario',
            'phone_number' => '573245637786',
            'identificacion' => '1017',
        ]);

        $this->actingAs($this->user)
            ->getJson(route('campaigns.contacts.search', [
                'instance_id' => $this->instance->id,
                'source' => 'contacts',
                'q' => 'Galindo',
            ]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('contacts.0.phone_number', '573245637786');
    }
}
