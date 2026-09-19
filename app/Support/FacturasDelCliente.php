<?php

namespace App\Support;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;

/**
 * Las facturas de este cliente que se pueden volver a mandar.
 *
 * ## Por qué salen de nuestro historial y no del ERP
 *
 * Porque lo que hay que enviar es el **PDF**, y el listado de facturas de
 * Integra no lo trae: da códigos, montos y vencimientos, pero ningún archivo.
 * El PDF llega por el otro lado —Integra nos lo manda al facturar, por
 * `POST /api/v1/messages/document`— y ahí sí queda guardado, con el
 * `incoming_invoice_id` que dice de qué factura es.
 *
 * Así que la lista se arma con lo que ya pasó por el hilo. Tiene una
 * consecuencia que conviene tener presente: **sólo aparecen las facturas que
 * alguna vez se le enviaron**. Una factura emitida y nunca mandada no está
 * aquí, porque su PDF no existe de nuestro lado.
 *
 * La más reciente primero, que es la que el asesor quiere el 90% de las veces.
 */
class FacturasDelCliente
{
    /** Cuántas se ofrecen. Más abajo ya es archivo, y esto es para contestar. */
    private const CUANTAS = 8;

    /**
     * @return list<array{
     *     factura_id: int, mensaje_id: int, archivo: string,
     *     url: string, enviada_el: string
     * }>
     */
    public static function de(WhatsAppConversation $conversation): array
    {
        $mensajes = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereNotNull('incoming_invoice_id')
            ->whereNotNull('media_url')
            ->orderByDesc('id')
            // Se piden de más porque después se agrupa por factura: una misma
            // factura reenviada tres veces son tres mensajes y una sola opción.
            ->limit(self::CUANTAS * 5)
            ->get(['id', 'incoming_invoice_id', 'media_url', 'filename', 'created_at', 'sent_at']);

        return $mensajes
            // Una factura, una opción: la copia más nueva es la que se manda.
            ->unique('incoming_invoice_id')
            ->take(self::CUANTAS)
            ->map(fn (WhatsAppMessage $m) => [
                'factura_id' => (int) $m->incoming_invoice_id,
                'mensaje_id' => (int) $m->id,
                'archivo' => $m->filename ?: 'Factura.pdf',
                'url' => (string) $m->media_url,
                'enviada_el' => optional($m->sent_at ?? $m->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** Una en concreto, o `null` si esa factura no es de este cliente. */
    public static function una(WhatsAppConversation $conversation, int $facturaId): ?array
    {
        foreach (self::de($conversation) as $factura) {
            if ($factura['factura_id'] === $facturaId) {
                return $factura;
            }
        }

        return null;
    }
}
