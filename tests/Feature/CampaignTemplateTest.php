<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CampaignTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Campañas enviadas con plantilla aprobada.
 *
 * Una campaña de texto libre solo llega a quien escribió en las últimas 24h;
 * a todos los demás Meta la rechaza. La plantilla es la única vía para una
 * campaña de verdad, y trae dos riesgos que aquí se fijan:
 *
 *   1. Un dato mal puesto (encabezado que no cuadra con el formato aprobado)
 *      no falla al crear, sino en Meta, destinatario por destinatario, con un
 *      error permanente. Por eso se valida contra la plantilla real ANTES de
 *      guardar la campaña.
 *   2. El media_id del encabezado caduca a los 30 días, así que en una campaña
 *      recurrente hay que rehacerlo en cada corrida y no guardarlo.
 */
class CampaignTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const CUERPO = 'Hola {{1}}, tu contrato vence el {{2}}.';

    private function instancia(): Instance
    {
        $company = Company::create(['name' => 'Partequipos', 'slug' => 'partequipos', 'active' => true]);

        return Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id'         => 'waba-1',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-waba-1',
        ]);
    }

    private function usuario(Instance $instance): User
    {
        setPermissionsTeamId($instance->company_id);

        foreach (['campaigns.view', 'campaigns.create', 'campaigns.update'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
            'company_id' => $instance->company_id,
        ]);
        $role->givePermissionTo(['campaigns.view', 'campaigns.create', 'campaigns.update']);

        $user = User::create([
            'company_id' => $instance->company_id,
            'name' => 'Juan',
            'email' => 'juan@partequipos.com',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Graph falso: consulta de plantillas, subida de media y envío.
     *
     * @param array|null $header Componente HEADER de la plantilla, o null si no tiene.
     */
    private function fakeGraph(?array $header = null, string $status = 'APPROVED'): void
    {
        $components = [['type' => 'BODY', 'text' => self::CUERPO]];
        if ($header) {
            array_unshift($components, $header);
        }

        Http::fake(function ($request) use ($components, $status) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-1',
                    'name' => 'vencimiento_contrato',
                    'language' => 'es',
                    'status' => $status,
                    'category' => 'UTILITY',
                    'components' => $components,
                ]]], 200);
            }

            if (str_ends_with($request->url(), '/media')) {
                return Http::response(['id' => 'media-' . Str::random(6)], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.' . Str::random(8)]]], 200);
        });
    }

    private function crearCampana(User $user, Instance $instance, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('campaigns.store'), array_merge([
            'name' => 'Vencimientos septiembre',
            'instance_id' => $instance->id,
            'message_type' => 'template',
            'template_name' => 'vencimiento_contrato',
            'template_language' => 'es',
            'template_payload' => [
                'body_vars' => ['{{nombre}}', '15 de septiembre'],
                'header' => null,
            ],
            'manual_recipients' => [['phone' => '573245637786', 'name' => 'Daniela Galindo']],
            'schedule_type' => 'manual',
        ], $overrides));
    }

    /* --------------------------- Creación y validación --------------------------- */

    public function test_crea_una_campana_de_plantilla_con_sus_variables(): void
    {
        $this->fakeGraph();
        $instance = $this->instancia();

        $this->crearCampana($this->usuario($instance), $instance)
            ->assertRedirect(route('campaigns.index'));

        $campaign = WhatsAppCampaign::first();

        $this->assertSame('template', $campaign->message_type);
        $this->assertSame('vencimiento_contrato', $campaign->template_name);
        $this->assertSame(['{{nombre}}', '15 de septiembre'], $campaign->template_payload['body_vars']);
        // El cuerpo aprobado se guarda para pintar la campaña sin volver a Meta.
        $this->assertSame(self::CUERPO, $campaign->template_payload['body_text']);
    }

    public function test_rechaza_la_campana_si_falta_el_encabezado_que_la_plantilla_exige(): void
    {
        // La plantilla tiene encabezado IMAGE y la campaña no adjunta nada: es
        // exactamente el "Format mismatch, expected IMAGE, received UNKNOWN".
        $this->fakeGraph(['type' => 'HEADER', 'format' => 'IMAGE']);
        $instance = $this->instancia();

        $this->crearCampana($this->usuario($instance), $instance)
            ->assertSessionHasErrors('template_header');

        $this->assertSame(0, WhatsAppCampaign::count());
    }

    public function test_rechaza_la_campana_si_faltan_variables(): void
    {
        $this->fakeGraph();
        $instance = $this->instancia();

        $this->crearCampana($this->usuario($instance), $instance, [
            'template_payload' => ['body_vars' => ['{{nombre}}'], 'header' => null],
        ])->assertSessionHasErrors('template_payload');

        $this->assertSame(0, WhatsAppCampaign::count());
    }

    public function test_rechaza_una_plantilla_que_no_esta_aprobada(): void
    {
        $this->fakeGraph(null, 'PENDING');
        $instance = $this->instancia();

        $this->crearCampana($this->usuario($instance), $instance)
            ->assertSessionHasErrors('template_name');
    }

    /* ------------------------------- Envío ------------------------------- */

    private function campanaConDestinatarios(Instance $instance, array $payload, array $personas): WhatsAppCampaign
    {
        $campaign = WhatsAppCampaign::create([
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'name' => 'Vencimientos',
            'message' => self::CUERPO,
            'message_type' => 'template',
            'template_name' => 'vencimiento_contrato',
            'template_language' => 'es',
            'template_payload' => $payload,
            'status' => 'queued',
            'total_recipients' => count($personas),
        ]);

        foreach ($personas as $p) {
            WhatsAppCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'phone_number' => $p['phone'],
                'name' => $p['name'],
                'status' => 'pending',
            ]);
        }

        return $campaign;
    }

    /** Payloads de plantilla enviados a la Cloud API, en orden. */
    private function plantillasEnviadas(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request, $response]) {
            if (($request['type'] ?? null) === 'template') {
                $out[] = $request->data();
            }
        }
        return $out;
    }

    public function test_cada_destinatario_recibe_la_plantilla_con_su_propio_nombre(): void
    {
        $this->fakeGraph();
        $instance = $this->instancia();

        $campaign = $this->campanaConDestinatarios(
            $instance,
            ['body_vars' => ['{{nombre}}', '15 de septiembre'], 'header' => null, 'body_text' => self::CUERPO],
            [
                ['phone' => '573245637786', 'name' => 'Daniela Galindo'],
                ['phone' => '573001112233', 'name' => null],
            ]
        );

        (new ProcessWhatsAppCampaign($campaign->id, 0))->handle(
            app(\App\Services\MetaWhatsAppService::class),
            app(CampaignTemplateService::class)
        );

        $enviadas = $this->plantillasEnviadas();
        $this->assertCount(2, $enviadas);

        $primeraVar = fn ($p) => $p['template']['components'][0]['parameters'][0]['text'];
        $this->assertSame('Daniela Galindo', $primeraVar($enviadas[0]));
        // Sin nombre guardado no se manda un parámetro vacío (Meta lo rechaza).
        $this->assertSame(CampaignTemplateService::NAME_FALLBACK, $primeraVar($enviadas[1]));

        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertSame(2, $campaign->fresh()->sent_count);
    }

    public function test_el_envio_queda_registrado_en_el_hilo_del_chat(): void
    {
        $this->fakeGraph();
        $instance = $this->instancia();

        $campaign = $this->campanaConDestinatarios(
            $instance,
            ['body_vars' => ['{{nombre}}', '15 de septiembre'], 'header' => null, 'body_text' => self::CUERPO],
            [['phone' => '573245637786', 'name' => 'Daniela Galindo']]
        );

        (new ProcessWhatsAppCampaign($campaign->id, 0))->handle(
            app(\App\Services\MetaWhatsAppService::class),
            app(CampaignTemplateService::class)
        );

        $conversation = WhatsAppConversation::where('instance_id', $instance->id)->first();
        $this->assertNotNull($conversation, 'La campaña debe abrir el hilo del cliente.');

        $message = WhatsAppMessage::where('conversation_id', $conversation->id)->first();
        $this->assertSame('Hola Daniela Galindo, tu contrato vence el 15 de septiembre.', $message->content);
        $this->assertSame($campaign->id, $message->metadata['campaign_id']);

        $recipient = $campaign->recipients()->first();
        $this->assertSame($message->id, $recipient->message_id);
    }

    public function test_el_encabezado_se_vuelve_a_subir_a_meta_en_cada_corrida(): void
    {
        // El media_id de Meta caduca a los 30 días: guardarlo rompería las
        // campañas recurrentes, así que se rehace desde nuestra copia.
        Storage::fake('s3_media');
        Storage::disk('s3_media')->put('campaign-templates/1/foto.jpg', 'binario');

        $this->fakeGraph(['type' => 'HEADER', 'format' => 'IMAGE']);
        $instance = $this->instancia();

        $campaign = $this->campanaConDestinatarios(
            $instance,
            [
                'body_vars' => ['{{nombre}}', '15 de septiembre'],
                'header' => [
                    'format' => 'IMAGE',
                    'path' => 'campaign-templates/1/foto.jpg',
                    'filename' => 'foto.jpg',
                    'mime_type' => 'image/jpeg',
                ],
                'body_text' => self::CUERPO,
            ],
            [['phone' => '573245637786', 'name' => 'Daniela Galindo']]
        );

        (new ProcessWhatsAppCampaign($campaign->id, 0))->handle(
            app(\App\Services\MetaWhatsAppService::class),
            app(CampaignTemplateService::class)
        );

        // Una sola subida para toda la corrida, no una por destinatario.
        $subidas = collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/media') && $pair[0]->method() === 'POST')
            ->count();
        $this->assertSame(1, $subidas);

        $header = $this->plantillasEnviadas()[0]['template']['components'][0];
        $this->assertSame('header', $header['type']);
        $this->assertSame('image', $header['parameters'][0]['type']);
        $this->assertStringStartsWith('media-', $header['parameters'][0]['image']['id']);

        // Y el id efímero no se guarda en la campaña.
        $this->assertArrayNotHasKey('media_id', $campaign->fresh()->template_payload['header']);
    }

    public function test_la_campana_de_texto_libre_sigue_funcionando(): void
    {
        $this->fakeGraph();
        $instance = $this->instancia();

        $campaign = WhatsAppCampaign::create([
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'name' => 'Aviso',
            'message' => 'Hola, estamos en mantenimiento.',
            'message_type' => 'text',
            'status' => 'queued',
            'total_recipients' => 1,
        ]);
        WhatsAppCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'phone_number' => '573245637786',
            'name' => 'Daniela',
            'status' => 'pending',
        ]);

        (new ProcessWhatsAppCampaign($campaign->id, 0))->handle(
            app(\App\Services\MetaWhatsAppService::class),
            app(CampaignTemplateService::class)
        );

        $this->assertCount(0, $this->plantillasEnviadas());
        $this->assertSame('sent', $campaign->recipients()->first()->status);
    }
}
