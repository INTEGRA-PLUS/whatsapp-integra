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
            // El título y el detalle juntos: el título («Business eligibility
            // payment issue») es igual para todo; el motivo y el enlace de
            // «Pagar ahora» vienen en el detalle (3-oct-2026).
            $texto = trim($message->error_message.' '.$message->error_details);

            if (FacturacionDeMeta::esErrorDePago($message->error_code, $texto)) {
                FacturacionDeMeta::registrarFallo($this->instancia($message), $message->error_code, $texto);
            }

            return;
        }

        // Una plantilla ENTREGADA es la prueba de que la cuenta ya cobra: las
        // respuestas dentro de las 24 h salen gratis incluso sin tarjeta, así
        // que sólo cuenta la plantilla. Y entregada, no «sent»: Meta acepta el
        // envío con un 200 y lo rechaza por cobro segundos después por webhook.
        // Con «sent» la alerta se apagaba y se volvía a encender con cada
        // factura, y cada vuelta avisaba otra vez a los admins.
        if ($message->direction === 'outbound'
            && in_array($message->status, ['delivered', 'read'], true)
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
