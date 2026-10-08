<?php

namespace Tests\Feature;

use App\Events\WhatsAppMessageEvent;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\EstadosDeMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los acuses de Meta (enviado, entregado, leído, fallido) sobre la burbuja.
 *
 * Cuatro agujeros cerrados el 1-oct-2026: el acuse que llegaba antes de que se
 * guardara el wamid se tiraba; un `delivered` tardío borraba un `read`; el
 * tiempo real se emitía al canal de la instancia del webhook y no al de la
 * dueña del mensaje; y un fallo al guardar respondía 200, con lo que Meta no
 * reintentaba y el estado se perdía.
 */
class AcusesDeMensajeTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_entregado_tardio_no_borra_la_lectura(): void
    {
        [$instancia, $mensaje] = $this->mensaje();

        $this->acuse($instancia, $mensaje->wamid, 'read')->assertOk();
        $this->acuse($instancia, $mensaje->wamid, 'delivered')->assertOk();
        $this->acuse($instancia, $mensaje->wamid, 'sent')->assertOk();

        $mensaje->refresh();
        $this->assertSame('read', $mensaje->status);
        $this->assertNotNull($mensaje->read_at);
        // Si se leyó, también se entregó, aunque el `delivered` llegara tarde.
        $this->assertNotNull($mensaje->delivered_at);
    }

    public function test_un_fallo_si_puede_llegar_despues_del_enviado(): void
    {
        [$instancia, $mensaje] = $this->mensaje();

        $this->acuse($instancia, $mensaje->wamid, 'sent')->assertOk();
        $this->acuse($instancia, $mensaje->wamid, 'failed', [
            'errors' => [['code' => 131049, 'title' => 'Not delivered', 'message' => 'Not delivered for ecosystem health']],
        ])->assertOk();

        $mensaje->refresh();
        $this->assertSame('failed', $mensaje->status);
        $this->assertSame('131049', (string) $mensaje->error_code);

        // Y un `sent` retrasado no lo resucita.
        $this->acuse($instancia, $mensaje->wamid, 'sent')->assertOk();
        $this->assertSame('failed', $mensaje->fresh()->status);
    }

    /**
     * La carrera: Meta manda el acuse antes de que quien envió guarde el
     * wamid. Antes se tiraba y la burbuja se quedaba en «enviado» para siempre.
     */
    public function test_el_acuse_que_llega_antes_que_el_wamid_se_aplica_al_guardarlo(): void
    {
        [$instancia, , $conversacion] = $this->mensaje();
        $wamid = 'wamid.'.Str::random(12);

        $this->acuse($instancia, $wamid, 'delivered')->assertOk();
        $this->acuse($instancia, $wamid, 'read')->assertOk();
        $this->assertTrue(Cache::has(EstadosDeMensaje::clavePendiente($wamid)));

        // Lo que haría DeliverWhatsAppMessage al volver Meta con el wamid.
        $mensaje = WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'wamid' => $wamid,
            'type' => 'text',
            'content' => 'Tu factura',
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertTrue(WhatsAppWebhookController::aplicarEstadoPendiente($mensaje));

        $this->assertSame('read', $mensaje->fresh()->status);
        $this->assertFalse(Cache::has(EstadosDeMensaje::clavePendiente($wamid)));
        // Consumido: llamarlo otra vez no hace nada.
        $this->assertFalse(WhatsAppWebhookController::aplicarEstadoPendiente($mensaje));
    }

    /**
     * Dos empresas con el mismo phone_number_id: el webhook se resuelve a la
     * primera, pero el check azul es de un mensaje de la segunda.
     */
    public function test_el_tiempo_real_va_al_canal_de_la_duena_del_mensaje(): void
    {
        $primera = $this->instancia('Primera', 'pnid-compartido');
        [$segunda, $mensaje] = $this->mensaje('Segunda', 'pnid-compartido');
        $this->assertLessThan($segunda->id, $primera->id);

        Event::fake([WhatsAppMessageEvent::class]);

        $this->acuse($primera, $mensaje->wamid, 'delivered')->assertOk();

        Event::assertDispatched(WhatsAppMessageEvent::class, fn ($e) => $e->instanceId === $segunda->id);
        Event::assertNotDispatched(WhatsAppMessageEvent::class, fn ($e) => $e->instanceId === $primera->id);
    }

    /** Si guardar el estado falla, Meta tiene que reintentar. */
    public function test_un_fallo_al_guardar_el_estado_hace_reintentar_a_meta(): void
    {
        [$instancia, $mensaje] = $this->mensaje();

        WhatsAppMessage::updating(function () {
            throw new \RuntimeException('la base de datos se fue');
        });

        $this->acuse($instancia, $mensaje->wamid, 'delivered')->assertStatus(500);
    }

    /** Un estado que no cabe en el enum se ignora sin hacer reintentar. */
    public function test_un_estado_desconocido_no_hace_reintentar(): void
    {
        [$instancia, $mensaje] = $this->mensaje();

        $this->acuse($instancia, $mensaje->wamid, 'deleted')->assertOk();
        $this->acuse($instancia, null, 'delivered')->assertOk();

        $this->assertSame('sent', $mensaje->fresh()->status);
    }

    public function test_guarda_lo_que_meta_cobra_por_el_mensaje(): void
    {
        [$instancia, $mensaje] = $this->mensaje();

        $this->acuse($instancia, $mensaje->wamid, 'delivered', [
            'conversation' => ['id' => 'conv-1', 'origin' => ['type' => 'utility']],
            'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'utility', 'type' => 'regular'],
        ])->assertOk();

        $pricing = $mensaje->fresh()->metadata['pricing'];
        $this->assertTrue($pricing['billable']);
        $this->assertSame('utility', $pricing['category']);
        $this->assertSame('PMP', $pricing['pricing_model']);
    }

    /**
     * El respaldo fuera de ventana vuelve con «plantilla pausada»: el estado
     * guardado de la plantilla miente y hay que volver a preguntarle a Meta.
     */
    public function test_un_respaldo_rechazado_por_plantilla_pausada_invalida_su_estado(): void
    {
        [$instancia, $mensaje] = $this->mensaje();
        $instancia->mergeFallbackTemplate(['name' => 'aviso_automatico_cliente', 'status' => 'APPROVED', 'checked_at' => now()->toIso8601String()]);
        $instancia->save();
        $mensaje->update(['metadata' => ['window_guard' => 'fallback_template', 'template' => 'aviso_automatico_cliente']]);

        $this->acuse($instancia, $mensaje->wamid, 'failed', [
            'errors' => [['code' => 132015, 'title' => 'Template is paused', 'message' => 'Template is paused']],
        ])->assertOk();

        $this->assertNull($instancia->fresh()->fallbackTemplateSettings()['checked_at']);
    }

    /* ------------------------------------------------------------------ */

    private function acuse(Instance $instancia, ?string $wamid, string $estado, array $extra = [])
    {
        return $this->postSignedWebhook([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $instancia->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $instancia->phone_number_id],
                        'statuses' => [array_merge(array_filter([
                            'id' => $wamid,
                            'status' => $estado,
                            'timestamp' => (string) now()->timestamp,
                            'recipient_id' => '573007852081',
                        ]), $extra)],
                    ],
                ]],
            ]],
        ]);
    }

    private function instancia(string $empresa = 'Fibra', string $phoneId = 'pnid-1'): Instance
    {
        $company = Company::create(['name' => $empresa, 'slug' => Str::slug($empresa), 'active' => true]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => $phoneId,
            'waba_id' => 'waba-'.Str::slug($empresa),
            'access_token' => 'token',
            'type' => 'meta',
            'active' => true,
        ]);
    }

    /** @return array{0: Instance, 1: WhatsAppMessage, 2: WhatsAppConversation} */
    private function mensaje(string $empresa = 'Fibra', string $phoneId = 'pnid-1'): array
    {
        $instancia = $this->instancia($empresa, $phoneId);

        $conversacion = WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Daniela',
            'status' => 'open',
        ]);

        $mensaje = WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'text',
            'content' => 'Tu factura',
            'direction' => 'outbound',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        return [$instancia, $mensaje, $conversacion];
    }
}
