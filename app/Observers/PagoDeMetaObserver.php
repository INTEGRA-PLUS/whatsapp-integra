<?php

namespace App\Observers;

use App\Models\Instance;
use App\Models\WhatsAppMessage;
use App\Support\FacturacionDeMeta;

/**
 * Enciende y apaga la alerta de pago de Meta mirando los mensajes.
 *
 * En el modelo y no en cada sitio que envía: chat, campañas, bot, IA y el
 * webhook de estados guardan todos por aquí, y un enganche por remitente es un
 * enganche que el séptimo remitente olvida.
 */
class PagoDeMetaObserver
{
    public function saved(WhatsAppMessage $message): void
    {
        if (! $message->wasRecentlyCreated && ! $message->wasChanged('status')) {
            return;
        }

        if ($message->status === 'failed') {
            if (FacturacionDeMeta::esErrorDePago($message->error_code, $message->error_message)) {
                FacturacionDeMeta::registrarFallo($this->instancia($message), $message->error_code, $message->error_message);
            }

            return;
        }

        // Una plantilla que sale es la prueba de que la cuenta ya cobra: las
        // respuestas dentro de las 24 h salen gratis incluso sin tarjeta, así
        // que sólo cuenta la plantilla.
        if ($message->direction === 'outbound'
            && in_array($message->status, ['sent', 'delivered', 'read'], true)
            && ($message->type === 'template' || isset($message->metadata['template']))) {
            $instancia = $this->instancia($message);

            if ($instancia?->problema_de_pago) {
                FacturacionDeMeta::resolver($instancia);
            }
        }
    }

    private function instancia(WhatsAppMessage $message): ?Instance
    {
        return $message->conversation?->instance;
    }
}
