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
 * Una campaña no le escribe dos veces a nadie, ni se queda colgada, ni quema la
 * lista cuando el problema es la plantilla o el ritmo.
 *
 * Los fallos que se previenen (1-oct-2026):
 * - dos jobs del mismo destinatario leían "pending" a la vez y los dos enviaban;
 * - un worker muerto a medio envío dejaba al destinatario en "sending" para
 *   siempre y la campaña recurrente no volvía a salir nunca;
 * - un 130429 de Meta («más despacio») se apuntaba como fallo definitivo;
 * - con la plantilla pausada por Meta se seguía enviando a toda la lista, un
 *   rechazo por cabeza;
 * - «reintentar fallidos» volvía a escribir a quien se dio de baja (131050).
 */
class CampanaSinEnviosRepetidosTest extends TestCase
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
                    'status' => 'APPROVED',
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

    private function correr(int $recipientId): void
    {
        (new SendCampaignMessage($recipientId))->handle(
            app(MetaWhatsAppService::class),
            app(CampaignTemplateBuilder::class),
            app(TemplateParameterGuard::class)
        );
    }

    private function rechazoDeMeta(int $code, string $mensaje): \Closure
    {
        return fn () => Http::response(['error' => ['message' => $mensaje, 'code' => $code]], 400);
    }

    public function test_un_destinatario_que_ya_tiene_otro_job_no_se_envia_dos_veces(): void
    {
        $enviados = $this->fakeGraph();
        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        $recipient = $this->destinatario($campaign);

        // Otro worker se adelantó y lo reclamó: este job llega tarde.
        $recipient->update(['status' => 'sending']);
        $this->correr($recipient->id);

        // La carrera de verdad: este job lee "pending" y, antes de que escriba,
        // otro worker reclama la fila. Con un update() sobre el modelo leído
        // los dos enviaban; con el condicional, éste se retira.
        $carrera = $this->destinatario($campaign);
        $yaReclamado = false;
        WhatsAppCampaignRecipient::retrieved(function ($fila) use ($carrera, &$yaReclamado) {
            if (! $yaReclamado && $fila->id === $carrera->id) {
                $yaReclamado = true;
                DB::table('whatsapp_campaign_recipients')->where('id', $fila->id)->update(['status' => 'sending']);
            }
        });
        $this->correr($carrera->id);

        $this->assertCount(0, $enviados, 'Dos workers con el mismo destinatario le escribieron dos veces.');

        // Y en serie, un reintento tras el envío tampoco repite.
        $otro = $this->destinatario($campaign);
        $this->correr($otro->id);
        $this->correr($otro->id);

        $this->assertCount(1, $enviados, 'El destinatario recibió la campaña más de una vez.');
        $this->assertSame(1, (int) $otro->fresh()->attempts);
    }

    public function test_un_fallo_antes_de_llamar_a_meta_devuelve_el_destinatario_a_pendiente(): void
    {
        $this->fakeGraph();
        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        $recipient = $this->destinatario($campaign);

        $builder = $this->createMock(CampaignTemplateBuilder::class);
        $builder->method('components')->willThrowException(new \RuntimeException('se cayó la base'));

        try {
            (new SendCampaignMessage($recipient->id))->handle(
                app(MetaWhatsAppService::class),
                $builder,
                app(TemplateParameterGuard::class)
            );
            $this->fail('La excepción debía subir para que la cola reintente.');
        } catch (\RuntimeException) {
        }

        // Si se quedara en "sending", el reintento lo vería reclamado y se iría
        // sin enviar: la campaña no terminaría nunca.
        $this->assertSame('pending', $recipient->fresh()->status);
    }

    public function test_un_envio_atascado_en_sending_se_rescata_y_la_campana_se_cierra(): void
    {
        $instance = $this->instancia();
        $campaign = $this->campana($instance, ['status' => 'sending']);

        $perdido = $this->destinatario($campaign, ['status' => 'sending']);

        // Éste sí lo aceptó Meta: la burbuja tiene wamid y sólo faltó apuntarlo.
        $conversacion = WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => '573001112233',
            'phone_number' => '573001112233',
            'name' => 'Andrés',
            'status' => 'open',
        ]);
        $burbuja = WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'campaign_id' => $campaign->id,
            'wamid' => 'wamid.ACEPTADO',
            'type' => 'template',
            'content' => 'Hola',
            'direction' => 'outbound',
            'status' => 'sent',
        ]);
        $aceptado = $this->destinatario($campaign, ['status' => 'sending', 'message_id' => $burbuja->id]);

        // Uno reciente sigue en manos de su worker: no se toca.
        $enCurso = $this->destinatario($campaign, ['status' => 'sending']);

        DB::table('whatsapp_campaign_recipients')
            ->whereIn('id', [$perdido->id, $aceptado->id])
            ->update(['updated_at' => now()->subMinutes(20)]);

        $this->artisan('campaigns:run-scheduled')->assertSuccessful();

        $this->assertSame('failed', $perdido->fresh()->status);
        $this->assertStringContainsString('Revisa el chat', $perdido->fresh()->error_message);
        $this->assertSame('sent', $aceptado->fresh()->status);
        $this->assertSame('wamid.ACEPTADO', $aceptado->fresh()->wamid);
        $this->assertSame('sending', $enCurso->fresh()->status);
        $this->assertSame('sending', $campaign->fresh()->status, 'Con uno aún en curso la campaña no termina.');

        DB::table('whatsapp_campaign_recipients')->where('id', $enCurso->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        $this->artisan('campaigns:run-scheduled')->assertSuccessful();

        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_si_meta_pide_bajar_el_ritmo_el_destinatario_espera_turno_y_no_falla(): void
    {
        $this->fakeGraph($this->rechazoDeMeta(130429, 'Rate limit hit'));
        Queue::fake([SendCampaignMessage::class]);

        $instance = $this->instancia();
        $campaign = $this->campana($instance, ['status' => 'sending']);
        $recipient = $this->destinatario($campaign);

        $this->correr($recipient->id);

        $recipient->refresh();
        $this->assertSame('pending', $recipient->status, 'Un «más despacio» no es un fallo definitivo.');
        $this->assertSame('130429', (string) $recipient->error_code);
        $this->assertSame(0, WhatsAppMessage::where('campaign_id', $campaign->id)->count(),
            'No debe quedar en el chat un "fallido" de algo que se va a reintentar.');

        Queue::assertPushed(SendCampaignMessage::class, function ($job) use ($recipient) {
            $this->assertNotNull($job->delay, 'El reintento tiene que esperar, no salir ya.');

            return $job->recipientId === $recipient->id;
        });

        // El turno se pidió al reloj del número, no por su cuenta.
        $this->assertNotNull(DB::table('campaign_send_slots')->where('instance_id', $instance->id)->value('next_slot_at'));
    }

    public function test_con_la_plantilla_pausada_por_meta_la_campana_se_pausa_y_no_quema_la_lista(): void
    {
        $enviados = $this->fakeGraph($this->rechazoDeMeta(132015, 'Template is paused'));

        $instance = $this->instancia();
        $campaign = $this->campana($instance);
        foreach (range(1, 3) as $i) {
            $this->destinatario($campaign);
        }

        ProcessWhatsAppCampaign::dispatch($campaign->id);

        $campaign->refresh();
        $this->assertCount(1, $enviados, 'Tras el primer rechazo por plantilla no debía salir ni uno más.');
        $this->assertSame('paused', $campaign->status);
        $this->assertNotNull($campaign->paused_at);
        $this->assertSame(1, $campaign->recipients()->where('status', 'failed')->count());
        $this->assertSame(2, $campaign->recipients()->where('status', 'pending')->count(),
            'Los demás tienen que quedar pendientes para cuando se reanude.');
    }

    public function test_una_plantilla_frenada_por_el_guardarrail_deja_el_motivo_legible(): void
    {
        $this->fakeGraph();
        $instance = $this->instancia();
        // Sin variables: la plantilla pide dos datos y no se manda ninguno.
        $campaign = $this->campana($instance, ['variable_map' => ['body' => []]]);
        $recipient = $this->destinatario($campaign);

        $this->correr($recipient->id);

        $recipient->refresh();
        $this->assertSame('failed', $recipient->status);
        $this->assertNull($recipient->error_code);
        $this->assertStringNotContainsString('template_', (string) $recipient->error_details,
            'error_details es lo que enseña la pantalla: no puede ser el código interno.');
        $this->assertNotEmpty($recipient->error_details);
    }

    public function test_reintentar_fallidos_no_vuelve_a_escribir_a_quien_se_dio_de_baja(): void
    {
        Queue::fake([ProcessWhatsAppCampaign::class]);

        $instance = $this->instancia();
        $user = User::create([
            'name' => 'Admin',
            'email' => Str::random(8).'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $instance->company_id,
            'active' => true,
        ]);
        setPermissionsTeamId($instance->company_id);
        Permission::firstOrCreate(['name' => 'campaigns.update', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $instance->company_id, 'guard_name' => 'web']);
        $role->syncPermissions(['campaigns.update']);
        $user->assignRole($role);

        $campaign = $this->campana($instance, ['status' => 'completed']);
        $baja = $this->destinatario($campaign, ['status' => 'failed', 'error_code' => '131050']);
        $saturadoHoy = $this->destinatario($campaign, ['status' => 'failed', 'error_code' => '131049']);
        $saturadoAyer = $this->destinatario($campaign, ['status' => 'failed', 'error_code' => '131049']);
        $otro = $this->destinatario($campaign, ['status' => 'failed', 'error_code' => '131026']);
        $sinCodigo = $this->destinatario($campaign, ['status' => 'failed']);

        DB::table('whatsapp_campaign_recipients')->where('id', $saturadoAyer->id)
            ->update(['updated_at' => now()->subHours(25)]);

        $this->actingAs($user)
            ->post("/campaigns/{$campaign->id}/retry-failed")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('failed', $baja->fresh()->status, 'Una baja de marketing no se reintenta nunca.');
        $this->assertSame('failed', $saturadoHoy->fresh()->status, '131049 no se reintenta antes de 24 h.');
        $this->assertSame('pending', $saturadoAyer->fresh()->status);
        $this->assertSame('pending', $otro->fresh()->status);
        $this->assertSame('pending', $sinCodigo->fresh()->status);
        Queue::assertPushed(ProcessWhatsAppCampaign::class);
    }

    public function test_una_recurrente_no_vuelve_a_escribir_a_los_dados_de_baja_ni_se_relanza_pausada(): void
    {
        Queue::fake([ProcessWhatsAppCampaign::class]);

        $instance = $this->instancia();
        $recurrente = $this->campana($instance, [
            'status' => 'completed',
            'schedule_type' => 'recurring',
            'next_run_at' => now()->subMinute(),
        ]);
        $omitido = $this->destinatario($recurrente, ['status' => 'skipped']);
        $baja = $this->destinatario($recurrente, ['status' => 'failed', 'error_code' => '131050']);
        $normal = $this->destinatario($recurrente, ['status' => 'sent', 'wamid' => 'wamid.X']);

        $pausada = $this->campana($instance, [
            'status' => 'paused',
            'paused_at' => now(),
            'schedule_type' => 'recurring',
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('campaigns:run-scheduled')->assertSuccessful();

        $this->assertSame('skipped', $omitido->fresh()->status);
        $this->assertSame('failed', $baja->fresh()->status);
        $this->assertSame('pending', $normal->fresh()->status);
        $this->assertSame('paused', $pausada->fresh()->status, 'Una pausada no se relanza sola.');
        Queue::assertPushed(ProcessWhatsAppCampaign::class, 1);
    }
}
