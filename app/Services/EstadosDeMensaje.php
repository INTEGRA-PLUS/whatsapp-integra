<?php

namespace App\Services;

use App\Events\WhatsAppMessageEvent;
use App\Models\Instance;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Los acuses de Meta (enviado / entregado / leído / fallido) aplicados a los
 * mensajes y a los destinatarios de campaña.
 *
 * Vivía dentro del webhook y tenía cuatro agujeros, cerrados el 1-oct-2026:
 *
 * - **El acuse que llega antes que el mensaje se perdía.** Meta puede mandar el
 *   `sent` antes de que quien envió haya guardado el wamid (la respuesta HTTP y
 *   el webhook viajan por caminos distintos). Se descartaba en silencio y la
 *   burbuja se quedaba en «enviado» aunque el cliente lo hubiera leído, o peor,
 *   en «enviado» cuando había fallado. Ahora se guarda diez minutos y lo aplica
 *   `aplicarPendiente()` en cuanto alguien guarda ese wamid.
 * - **Un acuse viejo pisaba a uno nuevo.** Meta no garantiza el orden: un
 *   `delivered` que llegaba después del `read` borraba la lectura. Los
 *   destinatarios de campaña ya lo evitaban; la burbuja no.
 * - **El tiempo real iba al canal equivocado.** Se emitía al canal de la
 *   instancia que recibió el webhook, y si dos empresas comparten el
 *   phone_number_id esa es la primera de las dos, no la dueña del mensaje.
 * - **Un fallo al guardar no hacía reintentar a Meta**, y el estado quedaba
 *   perdido para siempre.
 *
 * Además se guarda en `metadata.pricing` lo que Meta cobra por el mensaje
 * (si es facturable y de qué categoría): es lo que permite explicar la
 * factura de Meta sin ir a su panel.
 */
class EstadosDeMensaje
{
    /** Los estados que caben en `whatsapp_messages.status` (es un enum). */
    public const CONOCIDOS = ['sent', 'delivered', 'read', 'failed'];

    private const RANGO = ['pending' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    /**
     * Cuánto se guarda un acuse de un wamid que aún no existe. Quien envía
     * guarda el wamid en milisegundos o segundos; pasado este rato ya no es
     * una carrera, es un mensaje enviado por fuera del CRM.
     */
    private const MINUTOS_PENDIENTE = 10;

    /** Para que un wamid que nunca aparece no acumule acuses sin fin. */
    private const MAX_PENDIENTES = 10;

    /**
     * Aplica un acuse que llega por webhook.
     *
     * Lo que no se entiende (sin wamid, o un estado que Meta inventó) se
     * registra y se ignora sin excepción: reintentarlo no lo arreglaría y un
     * 500 haría que Meta reenviara el lote entero durante días. Lo que sí
     * lanza excepción es un fallo real al guardar, y eso debe hacer reintentar.
     */
    public static function procesar(array $status): void
    {
        $wamid = $status['id'] ?? null;
        $nuevo = strtolower((string) ($status['status'] ?? ''));

        if (! $wamid || $nuevo === '') {
            Log::channel('whatsapp')->warning('⚠️ Acuse de Meta sin wamid o sin estado, se ignora', [
                'claves' => array_keys($status),
            ]);

            return;
        }

        if (! in_array($nuevo, self::CONOCIDOS, true)) {
            Log::channel('whatsapp')->info('ℹ️ Acuse con un estado que no se guarda', [
                'wamid' => $wamid,
                'status' => $nuevo,
            ]);

            return;
        }

        $datos = self::datos($status);

        if ($nuevo === 'failed') {
            Log::channel('whatsapp')->warning('⚠️ Mensaje fallido', [
                'wamid' => $wamid,
                'recipient_id' => $status['recipient_id'] ?? null,
                'error_code' => $datos['error_code'] ?? null,
                'error_title' => $status['errors'][0]['title'] ?? null,
                'error_message' => $datos['error_message'] ?? null,
                'error_details' => $datos['error_details'] ?? null,
                'all_errors' => json_encode($status['errors'] ?? [], JSON_UNESCAPED_UNICODE),
                'full_status' => json_encode($status, JSON_UNESCAPED_UNICODE),
            ]);
        }

        // El destinatario de campaña se actualiza aunque no exista la burbuja: los
        // envíos anteriores a que las campañas escribieran en el chat solo dejaron
        // el wamid en la fila del destinatario, y sin esto su estado se quedaba
        // congelado en "enviado" para siempre.
        self::actualizarDestinatario($wamid, $nuevo, $datos);

        $message = WhatsAppMessage::where('wamid', $wamid)->first();

        if (! $message) {
            self::guardarPendiente($wamid, $status);

            // Quien envía pudo guardar el wamid entre la consulta de arriba y
            // el guardado del pendiente; en ese caso ya no va a volver a
            // preguntar, así que se aplica aquí.
            $message = WhatsAppMessage::where('wamid', $wamid)->first();

            if (! $message) {
                return;
            }

            self::aplicarPendiente($message);

            return;
        }

        self::aplicar($message, $status);
    }

    /**
     * Aplica los acuses que llegaron antes de que existiera este wamid.
     *
     * Lo tiene que llamar todo código que guarde un wamid de un envío
     * (DeliverWhatsAppMessage, la API, campañas…), justo después de guardarlo
     * y de dejar el mensaje en `sent`: si se llama antes, el `sent` de quien
     * envía pisaría el `read` que ya se había aplicado.
     *
     * Devuelve si había algo pendiente.
     */
    public static function aplicarPendiente(WhatsAppMessage $message): bool
    {
        if (! $message->wamid) {
            return false;
        }

        $pendientes = Cache::pull(self::clavePendiente($message->wamid));

        if (! is_array($pendientes) || $pendientes === []) {
            return false;
        }

        foreach ($pendientes as $status) {
            self::aplicar($message, $status);
            // El destinatario ya se intentó con el acuse original, pero
            // podía no tener todavía el wamid; con el rango no se pisa nada.
            self::actualizarDestinatario($message->wamid, strtolower((string) ($status['status'] ?? '')), self::datos($status));
        }

        Log::channel('whatsapp')->info('✅ Acuses pendientes aplicados al guardarse el wamid', [
            'wamid' => $message->wamid,
            'estados' => array_column($pendientes, 'status'),
        ]);

        return true;
    }

    public static function clavePendiente(string $wamid): string
    {
        return "wa:estado-pendiente:{$wamid}";
    }

    /**
     * ¿Este acuse hace avanzar al mensaje? `read` no vuelve a `delivered` ni a
     * `sent`. `failed` sí puede llegar después de `sent` (es lo normal: Meta
     * acepta y luego no entrega), pero no después de `delivered` o `read`. Y
     * un `delivered`/`read` después de `failed` es la prueba de que sí llegó.
     */
    public static function avanza(?string $actual, string $nuevo): bool
    {
        if ($actual === $nuevo) {
            return false;
        }

        if ($nuevo === 'failed') {
            return ! in_array($actual, ['delivered', 'read'], true);
        }

        if ($actual === 'failed') {
            return in_array($nuevo, ['delivered', 'read'], true);
        }

        return (self::RANGO[$nuevo] ?? 0) > (self::RANGO[$actual] ?? 0);
    }

    /* ------------------------------------------------------------------ */

    private static function aplicar(WhatsAppMessage $message, array $status): void
    {
        $nuevo = strtolower((string) ($status['status'] ?? ''));

        if (! in_array($nuevo, self::CONOCIDOS, true)) {
            return;
        }

        $cambios = [];

        if (self::avanza($message->status, $nuevo)) {
            $cambios = self::datos($status);

            // Si el `read` llega sin que haya llegado el `delivered` (o llega
            // primero), también se entregó.
            if ($nuevo === 'read' && ! $message->delivered_at) {
                $cambios['delivered_at'] = $cambios['read_at'];
            }
        }

        // El precio se guarda aunque el estado no avance: suele venir en el
        // `sent`, y el `sent` es justo el que más llega tarde.
        $pricing = self::pricing($status);
        if ($pricing) {
            $metadata = $message->metadata ?? [];
            $fusionado = array_merge($metadata['pricing'] ?? [], $pricing);

            if (($metadata['pricing'] ?? null) !== $fusionado) {
                $metadata['pricing'] = $fusionado;
                $cambios['metadata'] = $metadata;
            }
        }

        if ($cambios === []) {
            return;
        }

        $message->update($cambios);

        if (! isset($cambios['status'])) {
            return;
        }

        $instanceId = $message->conversation()->value('instance_id');

        if ($nuevo === 'failed') {
            self::revisarRespaldo($message, $instanceId, $cambios['error_code'] ?? null);
        }

        // Al canal de la instancia dueña del mensaje, no al de la que recibió
        // el webhook: con dos empresas en el mismo phone_number_id son
        // distintas, y el check azul de una aparecía en el chat de la otra.
        if ($instanceId) {
            try {
                broadcast(new WhatsAppMessageEvent($message, (int) $instanceId, 'status'));
            } catch (\Throwable $e) {
                // El estado ya está guardado; el poll del chat lo recogerá.
                Log::channel('whatsapp')->warning('⚠️ No se pudo emitir el estado en tiempo real', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('whatsapp')->info('✅ Estado actualizado', [
            'wamid' => $message->wamid,
            'status' => $nuevo,
        ]);
    }

    /**
     * Un respaldo fuera de ventana que vuelve con «plantilla pausada»,
     * «desactivada» o «no existe» deja el estado guardado mintiendo durante
     * las 12 h de TTL de una aprobada. Se invalida para que el próximo aviso
     * pregunte a Meta.
     */
    private static function revisarRespaldo(WhatsAppMessage $message, $instanceId, $codigo): void
    {
        if (($message->metadata['window_guard'] ?? null) !== 'fallback_template' || ! $instanceId) {
            return;
        }

        $instance = Instance::find($instanceId);

        if ($instance) {
            app(WhatsAppFallbackTemplateService::class)->refrescarTrasError($instance, $codigo);
        }
    }

    private static function guardarPendiente(string $wamid, array $status): void
    {
        $clave = self::clavePendiente($wamid);
        $pendientes = Cache::get($clave, []);
        $pendientes = is_array($pendientes) ? $pendientes : [];

        if (count($pendientes) >= self::MAX_PENDIENTES) {
            return;
        }

        $pendientes[] = $status;

        Cache::put($clave, $pendientes, now()->addMinutes(self::MINUTOS_PENDIENTE));

        Log::channel('whatsapp')->info('⏳ Acuse de un wamid que aún no está guardado: queda pendiente', [
            'wamid' => $wamid,
            'status' => $status['status'] ?? null,
        ]);
    }

    /** Columnas que el acuse cambia en el mensaje. */
    private static function datos(array $status): array
    {
        $nuevo = strtolower((string) ($status['status'] ?? ''));
        $cuando = self::momento($status);

        $datos = ['status' => $nuevo];

        if ($nuevo === 'delivered') {
            $datos['delivered_at'] = $cuando;
        } elseif ($nuevo === 'read') {
            $datos['read_at'] = $cuando;
        } elseif ($nuevo === 'failed') {
            $error = $status['errors'][0] ?? [];

            $datos['failed_at'] = $cuando;
            $datos['error_message'] = $error['message'] ?? 'Error desconocido';
            $datos['error_code'] = $error['code'] ?? null;
            $datos['error_details'] = $error['error_data']['details'] ?? null;
        }

        return $datos;
    }

    /**
     * La hora del acuse según Meta. Con un acuse aplicado minutos después (el
     * pendiente) o un lote que Meta suelta tras horas de reintentos, `now()`
     * diría que se leyó cuando nos enteramos, no cuando lo leyó el cliente.
     */
    private static function momento(array $status): Carbon
    {
        $ts = $status['timestamp'] ?? null;

        return is_numeric($ts)
            ? Carbon::createFromTimestamp((int) $ts, config('app.timezone'))
            : now();
    }

    private static function pricing(array $status): array
    {
        $pricing = $status['pricing'] ?? [];
        $conversacion = $status['conversation'] ?? [];

        if (! is_array($pricing) || ! is_array($conversacion) || ($pricing === [] && $conversacion === [])) {
            return [];
        }

        return array_filter([
            'billable' => $pricing['billable'] ?? null,
            'pricing_model' => $pricing['pricing_model'] ?? null,
            'category' => $pricing['category'] ?? ($conversacion['origin']['type'] ?? null),
            'type' => $pricing['type'] ?? null,
            'conversation_id' => $conversacion['id'] ?? null,
            'conversation_expires_at' => $conversacion['expiration_timestamp'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Lleva el acuse de Meta a la fila del destinatario de la campaña.
     *
     * Sin esto una campaña reporta "enviada" y nada más: entregado, leído y
     * fallido son justo lo que hay que mirar después de un envío masivo, y esa
     * información llega siempre por webhook, nunca en la respuesta del envío.
     */
    private static function actualizarDestinatario(string $wamid, string $newStatus, array $messageUpdate): void
    {
        if (! in_array($newStatus, self::CONOCIDOS, true)) {
            return;
        }

        $recipient = WhatsAppCampaignRecipient::where('wamid', $wamid)->first();

        if (! $recipient) {
            return;
        }

        // Un acuse viejo no debe pisar a uno más avanzado: Meta no garantiza el
        // orden, y "delivered" llegando después de "read" borraría la lectura.
        $rank = self::RANGO;
        if (($rank[$newStatus] ?? 0) > 0
            && ($rank[$newStatus] ?? 0) <= ($rank[$recipient->status] ?? 0)
            && $newStatus !== 'failed') {
            return;
        }

        if ($recipient->status === $newStatus) {
            return;
        }

        $update = ['status' => $newStatus];

        if ($newStatus === 'delivered') {
            $update['delivered_at'] = $messageUpdate['delivered_at'] ?? now();
        } elseif ($newStatus === 'read') {
            $update['read_at'] = $messageUpdate['read_at'] ?? now();
            $update['delivered_at'] = $recipient->delivered_at ?: ($messageUpdate['read_at'] ?? now());
        } elseif ($newStatus === 'failed') {
            $update['error_message'] = $messageUpdate['error_message'] ?? null;
            $update['error_code'] = $messageUpdate['error_code'] ?? null;
            $update['error_details'] = $messageUpdate['error_details'] ?? null;
        }

        $recipient->update($update);
        $recipient->campaign?->refreshCounters();
    }
}
