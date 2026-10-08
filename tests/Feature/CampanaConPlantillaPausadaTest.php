<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Jobs\SendCampaignMessage;
use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CampaignTemplateBuilder;
use App\Services\MetaWhatsAppService;
use App\Services\TemplateParameterGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Una plantilla que Meta ya pausó pausa la campaña, aunque el webhook de
 * plantillas no haya llegado.
 *
 * Fallo que se previene (1-oct-2026): el guardarraíl frena la plantilla no
 * aprobada antes de llegar a Meta, así que el 132015 que pausa la campaña no
 * aparecía nunca. Cada destinatario quedaba fallido uno a uno —la lista entera
 * quemada— y cada uno volvía a leer el catálogo completo de Graph.
 */
class CampanaConPlantillaPausadaTest extends TestCase
{
    use RefreshDatabase;

    private const CUERPO = 'Hola {{1}}, tu factura de {{2}} está lista.';

    private function instancia(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-'.Str::random(4), 'active' => true]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(1000000000000, 9999999999999),
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);
    }

    /**
     * Graph falso: el catálogo con la plantilla y, para los envíos, lo que diga
     * `$envio`. Devuelve el contador de envíos por referencia.
     */
    private function fakeGraph(?\Closure $envio = null): \ArrayObject
    {
        $enviados = new \ArrayObject;

        Http::fake(function ($request) use ($envio, $enviados) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-1',
                    'name' => 'aviso_factura',
                    'language' => 'es',
                    'status' => 'PAUSED',
                    'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => self::CUERPO]],
                ]]], 200);
            }

            $enviados->append($request->data());

            return $envio
                ? $envio(count($enviados))
                : Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]], 200);
        });

        return $enviados;
    }

    private function campana(Instance $instance, array $overrides = []): WhatsAppCampaign
    {
        return WhatsAppCampaign::create(array_merge([
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'name' => 'Facturación octubre',
            'message_type' => 'template',
            'template_name' => 'aviso_factura',
            'template_language' => 'es',
            'template_components' => [['type' => 'BODY', 'text' => self::CUERPO]],
            'variable_map' => ['body' => [
                ['source' => 'field', 'field' => 'name'],
                ['source' => 'fixed', 'value' => 'octubre'],
            ]],
            'status' => 'queued',
            'schedule_type' => 'manual',
            'total_recipients' => 0,
        ], $overrides));
    }

    private function destinatario(WhatsAppCampaign $campaign, array $overrides = []): WhatsAppCampaignRecipient
    {
        return WhatsAppCampaignRecipient::create(array_merge([
            'campaign_id' => $campaign->id,
            'phone_number' => '57300'.random_int(1000000, 9999999),
            'name' => 'Daniela',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_la_plantilla_pausada_pausa_la_campana_sin_quemar_la_lista(): void
    {
        $instance = $this->instancia();
        $enviados = $this->fakeGraph();
        $campaign = $this->campana($instance);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->destinatario($campaign)->id;
        }

        foreach ($ids as $id) {
            (new SendCampaignMessage($id))->handle(
                app(MetaWhatsAppService::class),
                app(CampaignTemplateBuilder::class),
                app(TemplateParameterGuard::class)
            );
        }

        $lecturasDelCatalogo = collect(Http::recorded())
            ->filter(fn ($par) => str_contains($par[0]->url(), '/message_templates'))
            ->count();

        $campaign->refresh();
        $this->assertCount(0, $enviados);
        $this->assertSame('paused', $campaign->status);
        $this->assertNotNull($campaign->paused_at);
        $this->assertSame(5, WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count());
        $this->assertSame(0, WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'failed')->count());
        $this->assertLessThanOrEqual(2, $lecturasDelCatalogo);
    }
}
