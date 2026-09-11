<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppCampaign;
use App\Jobs\SendCampaignMessage;
use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\CampaignPacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El ritmo de envío es del número, no de la campaña.
 *
 * Meta cuenta los mensajes por `phone_number_id`. Mientras cada campaña llevaba
 * su propio reloj, tres campañas a 60/min sobre la misma línea eran 180/min
 * reales contra Meta y ninguna sabía de las otras: el `rate_per_minute` daba una
 * sensación de control que no existía. Lo que se prueba aquí es que ahora se
 * turnan.
 */
class CampaignPacingTest extends TestCase
{
    use RefreshDatabase;

    private const CUERPO = 'Hola {{1}}, tu factura está lista.';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-1',
                    'name' => 'aviso_factura',
                    'language' => 'es',
                    'status' => 'APPROVED',
                    'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => self::CUERPO]],
                ]]], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.'.Str::random(6)]]], 200);
        });
    }

    public function test_dos_campanas_del_mismo_numero_se_turnan(): void
    {
        Queue::fake([SendCampaignMessage::class]);

        $instance = $this->instancia();

        // 60/min: un turno por segundo. La primera ocupa diez segundos.
        $primera = $this->campanaCon($instance, 10, 60);
        $segunda = $this->campanaCon($instance, 5, 60);

        (new ProcessWhatsAppCampaign($primera->id))->handle();
        (new ProcessWhatsAppCampaign($segunda->id))->handle();

        $retrasos = $this->retrasosEnSegundos();

        $this->assertCount(15, $retrasos);

        // La segunda campaña no pisa los turnos de la primera: su primer envío
        // cae después del último de aquélla, no a la vez.
        $ultimoDeLaPrimera = $retrasos[9];
        $primeroDeLaSegunda = $retrasos[10];

        $this->assertGreaterThanOrEqual(
            $ultimoDeLaPrimera,
            $primeroDeLaSegunda,
            'La segunda campaña arrancó encima de la primera: el número estaría enviando al doble de ritmo.'
        );
    }

    public function test_cada_numero_lleva_su_propio_reloj(): void
    {
        Queue::fake([SendCampaignMessage::class]);

        $una = $this->instancia();
        $otra = $this->instancia();

        $this->campanaCon($una, 5, 60);
        $segunda = $this->campanaCon($otra, 5, 60);

        (new ProcessWhatsAppCampaign(WhatsAppCampaign::first()->id))->handle();
        (new ProcessWhatsAppCampaign($segunda->id))->handle();

        $retrasos = $this->retrasosEnSegundos();

        // Números distintos no compiten entre sí: los dos primeros envíos de
        // cada campaña salen a la vez, porque son líneas separadas ante Meta.
        $this->assertLessThanOrEqual(1, $retrasos[5], 'La campaña del segundo número esperó turno sin motivo.');
    }

    public function test_cancelar_libera_el_reloj_del_numero(): void
    {
        Queue::fake([SendCampaignMessage::class]);

        $instance = $this->instancia();
        $grande = $this->campanaCon($instance, 200, 60);

        (new ProcessWhatsAppCampaign($grande->id))->handle();

        // Con 200 destinatarios a 60/min el número queda reservado más de tres
        // minutos. Cancelada, esa reserva no corresponde a nada.
        $grande->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        app(CampaignPacer::class)->releaseIfIdle($instance->id);

        [$inicio] = app(CampaignPacer::class)->reserve($instance->id, 1, 60);

        $this->assertLessThanOrEqual(
            2,
            (int) round(now()->diffInSeconds($inicio, false)),
            'Tras cancelar, la siguiente campaña seguía esperando un turno que ya no usaba nadie.'
        );
    }

    /**
     * Los retrasos de los envíos encolados, en segundos y en el orden en que se
     * repartieron.
     *
     * @return array<int, int>
     */
    private function retrasosEnSegundos(): array
    {
        return Queue::pushed(SendCampaignMessage::class)
            ->map(function ($job) {
                if ($job->delay === null) {
                    return 0;
                }

                return is_numeric($job->delay)
                    ? (int) $job->delay
                    : (int) round(now()->diffInSeconds($job->delay, false));
            })
            ->values()
            ->all();
    }

    private function instancia(): Instance
    {
        $company = Company::create([
            'name' => 'Cmnet',
            'slug' => 'cmnet-'.Str::random(6),
            'active' => true,
        ]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '11779625154'.Str::random(5),
            'waba_id' => 'waba-'.Str::random(4),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba',
        ]);
    }

    private function campanaCon(Instance $instance, int $destinatarios, int $ritmo): WhatsAppCampaign
    {
        $campaign = WhatsAppCampaign::create([
            'company_id' => $instance->company_id,
            'instance_id' => $instance->id,
            'name' => 'Aviso',
            'message' => null,
            'message_type' => 'template',
            'template_name' => 'aviso_factura',
            'template_language' => 'es',
            'template_components' => [['type' => 'BODY', 'text' => self::CUERPO]],
            'variable_map' => ['body' => [['source' => 'field', 'field' => 'name']]],
            'rate_per_minute' => $ritmo,
            'status' => 'queued',
            'schedule_type' => 'manual',
            'total_recipients' => $destinatarios,
        ]);

        for ($i = 0; $i < $destinatarios; $i++) {
            WhatsAppCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'phone_number' => '5730078'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => 'Socio '.$i,
                'status' => 'pending',
            ]);
        }

        return $campaign;
    }
}
