<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CampaignPacer;
use App\Services\CampaignTemplateBuilder;
use App\Services\MetaWhatsAppService;
use App\Services\TemplateParameterGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envía la plantilla de una campaña a **un** destinatario.
 *
 * Antes toda la campaña cabía en un solo job que iba llamando a Meta en bucle:
 * un fallo a mitad dejaba el resto sin enviar, no había forma de pausar, y el
 * envío no dejaba rastro en el chat, así que ni el agente veía lo que se le
 * había dicho a su cliente ni el acuse de Meta encontraba a quién actualizar.
 *
 * Un job por destinatario arregla las tres cosas: se puede reintentar solo lo
 * que falló, se puede parar en seco, y cada envío crea su burbuja en la
 * conversación —con `campaign_id`— para que el webhook la encuentre por wamid.
 */
class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 30;

    /**
     * Códigos con los que Meta dice «más despacio», no «esto está mal»: límite
     * de caudal del número (130429), demasiados mensajes al mismo cliente
     * (131056), límite de la cuenta (80007) y de la app (4). Marcarlos como
     * fallo definitivo era tirar a la basura destinatarios que habrían salido
     * un minuto después.
     */
    public const CODIGOS_DE_RITMO = ['130429', '131056', '80007', '4'];

    /**
     * La plantilla está pausada (132015), deshabilitada (132016) o ya no existe
     * en ese idioma (132001). Le pasa igual al siguiente destinatario y a todos
     * los demás: seguir es quemar la lista entera en rechazos.
     */
    public const CODIGOS_DE_PLANTILLA_PARADA = ['132015', '132016', '132001'];

    /**
     * Cuántas veces se le pide turno a un mismo destinatario por culpa del
     * ritmo antes de darlo por fallido. Con la espera creciente son más de dos
     * horas: si Meta sigue frenando, el problema ya no es de ritmo.
     */
    public const MAX_ESPERAS_POR_RITMO = 8;

    public function __construct(public int $recipientId)
    {
    }

    public function handle(
        MetaWhatsAppService $metaService,
        CampaignTemplateBuilder $builder,
        TemplateParameterGuard $guard
    ): void {
        $recipient = WhatsAppCampaignRecipient::with(['campaign.instance', 'contact'])->find($this->recipientId);

        if (!$recipient || !$recipient->campaign) {
            return;
        }

        $campaign = $recipient->campaign;
        $instance = $campaign->instance;

        // Idempotencia: un reintento de la cola no debe volver a escribirle al
        // cliente si el primero ya salió.
        if ($recipient->status !== 'pending' || $recipient->wamid) {
            return;
        }

        // Pausada o cancelada: el destinatario se queda pendiente, listo para
        // cuando se reanude.
        if ($campaign->paused_at || $campaign->cancelled_at || $campaign->status === 'cancelled') {
            return;
        }

        if (!$instance || !$instance->isMetaConfigured()) {
            $this->fail($recipient, 'La línea de WhatsApp de la empresa está sin configurar.');
            return;
        }

        if (!$campaign->usesTemplate()) {
            $this->fail($recipient, 'La campaña no tiene una plantilla aprobada asignada.');
            return;
        }

        // Pasar a "sending" con una actualización condicional, no con un
        // update() sobre el modelo ya leído: dos jobs del mismo destinatario
        // (un reparto repetido al reanudar, un reintento que se cruza con el
        // original) leían los dos "pending" y los dos enviaban. Ahora sólo uno
        // consigue la fila; el otro se va sin tocar nada.
        $reclamado = WhatsAppCampaignRecipient::whereKey($recipient->id)
            ->where('status', 'pending')
            ->whereNull('wamid')
            ->update([
                'status'     => 'sending',
                'attempts'   => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($reclamado === 0) {
            return;
        }

        $recipient->refresh();

        $message = null;
        $llamadaHecha = false;

        try {
            $to = WhatsAppConversation::normalizeRecipient($recipient->phone_number);

            $conversation = WhatsAppConversation::resolveFor($instance->id, $to, [
                'phone_number'    => $to,
                'name'            => $recipient->name ?: $to,
                'status'          => 'open',
                'last_message_at' => now(),
            ]);

            $components = $builder->components($campaign, $recipient);

            $checked = $guard->check($instance, $campaign->template_name, $campaign->template_language, $components);

            if (!$checked['ok'] && ($checked['code'] ?? null) === 'template_not_approved') {
                // La plantilla no está aprobada (pausada, deshabilitada, en
                // revisión): el guard la frena antes de llegar a Meta, así que
                // aquí nunca aparece el 132015/132016 que pausa la campaña más
                // abajo. Sin esto cada destinatario quedaba fallido uno a uno y
                // la lista entera se quemaba con la campaña sin pausar. Este
                // destinatario no ha salido: vuelve a la cola para cuando se
                // reanude.
                $this->pausarPorPlantilla($campaign, $checked['code'], $checked['error']);
                WhatsAppCampaignRecipient::whereKey($recipient->id)
                    ->where('status', 'sending')
                    ->whereNull('wamid')
                    ->update([
                        'status'     => 'pending',
                        'attempts'   => DB::raw('attempts - 1'),
                        'updated_at' => now(),
                    ]);
                return;
            }

            if (!$checked['ok']) {
                // El código interno va entre paréntesis; el texto que se
                // entiende, en `error_details`, que es lo que enseña la lista.
                // Antes iba el código ahí y la pantalla decía
                // «template_body_parameters» sin más.
                $this->fail(
                    $recipient,
                    "La plantilla se frenó antes de enviarla ({$checked['code']}).",
                    null,
                    $checked['error'],
                    $conversation->id
                );
                return;
            }

            $components = $checked['components'];
            $content = $builder->preview($campaign, $recipient);

            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'campaign_id'     => $campaign->id,
                'type'            => 'template',
                'content'         => $content,
                'direction'       => 'outbound',
                'status'          => 'pending',
                'sent_by'         => $campaign->created_by,
                'sent_at'         => now(),
                'metadata'        => [
                    'campaign'   => $campaign->name,
                    'template'   => $campaign->template_name,
                    'language'   => $campaign->template_language,
                    'components' => $components,
                ],
            ]);

            $recipient->forceFill(['conversation_id' => $conversation->id, 'message_id' => $message->id])->save();

            $llamadaHecha = true;

            $result = $metaService->sendTemplate(
                $instance,
                $conversation->recipientId(),
                $campaign->template_name,
                $campaign->template_language ?: 'es',
                $components
            );
        } catch (\Throwable $e) {
            // Si el fallo llegó antes de hablar con Meta no salió nada: el
            // destinatario vuelve a "pending" y el reintento de la cola lo
            // vuelve a intentar. Sin esto se quedaba en "sending" para siempre
            // —el reintento lo veía ya reclamado y se iba— y la campaña nunca
            // terminaba.
            if (!$llamadaHecha) {
                $message?->delete();
                WhatsAppCampaignRecipient::whereKey($recipient->id)
                    ->where('status', 'sending')
                    ->whereNull('wamid')
                    ->update(['status' => 'pending', 'updated_at' => now()]);
            }

            throw $e;
        }

        if (!($result['success'] ?? false)) {
            $error = $result['error']['error']['message']
                ?? (is_string($result['error'] ?? null) ? $result['error'] : 'Error al enviar');
            $code = $result['error']['error']['code'] ?? null;
            $details = $result['error']['error']['error_data']['details'] ?? null;

            if (in_array((string) $code, self::CODIGOS_DE_RITMO, true)
                && $recipient->attempts < self::MAX_ESPERAS_POR_RITMO) {
                $this->esperarTurno($recipient, $campaign, $message, $error, (string) $code);
                return;
            }

            $message->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => mb_substr($error, 0, 2000),
                'error_code'    => $code,
                'error_details' => $details,
            ]);

            if (in_array((string) $code, self::CODIGOS_DE_PLANTILLA_PARADA, true)) {
                $this->pausarPorPlantilla($campaign, (string) $code, $error);
            }

            $this->fail($recipient, $error, $code, $details, $conversation->id, $message->id);
            return;
        }

        $wamid = $result['data']['messages'][0]['id'] ?? null;

        // El wamid primero, en las dos filas, antes que nada más: es lo único
        // que impide que un reintento vuelva a enviar.
        $message->update(['wamid' => $wamid, 'status' => 'sent']);

        $recipient->update([
            'status'          => 'sent',
            'wamid'           => $wamid,
            'sent_at'         => now(),
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'error_message'   => null,
            'error_code'      => null,
            'error_details'   => null,
        ]);

        // Meta puede mandar el "delivered"/"read"/"failed" antes de que se
        // guarde el wamid: el webhook lo deja aparcado (EstadosDeMensaje) y se
        // aplica aquí, ya con la burbuja en "sent", para que no se pierda.
        \App\Services\EstadosDeMensaje::aplicarPendiente($message);

        $conversation->update([
            'last_message'    => $content,
            'last_message_at' => now(),
        ]);

        broadcast(new \App\Events\WhatsAppMessageEvent($message, $instance->id, 'new'));

        $campaign->fresh()?->cerrarSiTermino();
    }

    /**
     * Meta pidió bajar el ritmo: el destinatario vuelve a la cola, no a la
     * lista de fallidos.
     *
     * El turno se le pide otra vez al reloj del número (CampaignPacer) y no a
     * un `->delay()` propio: si cada reintento calculara su espera por su
     * cuenta, todos los frenados volverían a la vez y Meta los frenaría otra
     * vez. Se despacha un job nuevo en lugar de `release()` para que la espera
     * no gaste los intentos que la cola reserva a los fallos de verdad; el tope
     * lo pone `attempts` del destinatario.
     */
    private function esperarTurno(
        WhatsAppCampaignRecipient $recipient,
        \App\Models\WhatsAppCampaign $campaign,
        WhatsAppMessage $message,
        string $error,
        string $code
    ): void {
        // La burbuja no llegó a salir: se borra para que el reintento no deje
        // en el chat un "fallido" de algo que acabará entregándose.
        $message->delete();

        $recipient->forceFill([
            'status'        => 'pending',
            'message_id'    => null,
            'error_message' => mb_substr("Meta pidió bajar el ritmo ({$code}); se reintenta solo. {$error}", 0, 2000),
            'error_code'    => $code,
        ])->save();

        $rate = max(1, (int) ($campaign->rate_per_minute ?: 60));
        [$turno] = app(CampaignPacer::class)->reserve($campaign->instance_id, 1, $rate);

        // Y nunca antes de una espera mínima que crece con cada frenazo: el
        // reloj puede estar libre justo ahora, que es cuando Meta dijo que no.
        $minimo = now()->addSeconds(min(900, 60 * max(1, (int) $recipient->attempts)));
        $cuando = $turno->greaterThan($minimo) ? $turno : $minimo;

        Log::channel('whatsapp')->info('Campaña frenada por Meta: el destinatario espera turno', [
            'campaign_id'  => $campaign->id,
            'recipient_id' => $recipient->id,
            'code'         => $code,
            'intento'      => $recipient->attempts,
            'reintento_a'  => $cuando->toIso8601String(),
        ]);

        static::dispatch($recipient->id)->delay($cuando);
    }

    /**
     * La plantilla dejó de servir: se pausa la campaña entera, con el mismo
     * estado que el botón de pausar, para que reanudarla sea un clic cuando la
     * plantilla vuelva a estar aprobada. Los envíos ya encolados ven la pausa y
     * se quedan pendientes en vez de gastar un rechazo cada uno.
     */
    private function pausarPorPlantilla(\App\Models\WhatsAppCampaign $campaign, string $code, string $error): void
    {
        $pausada = \App\Models\WhatsAppCampaign::whereKey($campaign->id)
            ->whereIn('status', ['queued', 'sending'])
            ->whereNull('paused_at')
            ->update(['status' => 'paused', 'paused_at' => now(), 'updated_at' => now()]);

        if ($pausada) {
            Log::channel('whatsapp')->warning('Campaña pausada: Meta no acepta la plantilla', [
                'campaign_id' => $campaign->id,
                'template'    => $campaign->template_name,
                'code'        => $code,
                'error'       => $error,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $recipient = WhatsAppCampaignRecipient::find($this->recipientId);

        if ($recipient && in_array($recipient->status, ['pending', 'sending'], true)) {
            $this->fail($recipient, $e->getMessage());
        }
    }

    private function fail(
        WhatsAppCampaignRecipient $recipient,
        ?string $error,
        $code = null,
        ?string $details = null,
        ?int $conversationId = null,
        ?int $messageId = null
    ): void {
        $recipient->update([
            'status'          => 'failed',
            'error_message'   => mb_substr((string) $error, 0, 2000),
            'error_code'      => $code ? mb_substr((string) $code, 0, 20) : null,
            'error_details'   => $details,
            'conversation_id' => $conversationId ?: $recipient->conversation_id,
            'message_id'      => $messageId ?: $recipient->message_id,
        ]);

        Log::channel('whatsapp')->warning('Destinatario de campaña fallido', [
            'campaign_id'  => $recipient->campaign_id,
            'recipient_id' => $recipient->id,
            'error'        => $error,
        ]);

        $recipient->campaign?->fresh()?->cerrarSiTermino();
    }
}
