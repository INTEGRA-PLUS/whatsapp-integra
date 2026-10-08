<?php

namespace App\Observers;

use App\Models\Instance;
use App\Models\WhatsAppMessage;
use App\Support\FacturacionDeMeta;
use Illuminate\Support\Carbon;

/**
 * Enciende y apaga la alerta de pago de Meta mirando los mensajes.
 *
 * En el modelo y no en cada sitio que envía: chat, campañas, bot, IA y el
 * webhook de estados guardan todos por aquí, y un enganche por remitente es un
 * enganche que el séptimo remitente olvida.
 *
 * Meta reintenta durante días y los estados llegan desordenados: por eso nada
 * de aquí se fía de cuándo LLEGA un estado, sino de cuándo SALIÓ el mensaje.
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
                FacturacionDeMeta::registrarFallo(
                    $this->instancia($message),
                    $message->error_code,
                    $texto,
                    $this->enviadoEn($message)
                );
            }

            return;
        }

        if ($this->pruebaQueMetaCobra($message)) {
            $instancia = $this->instancia($message);

            if ($instancia?->problema_de_pago
                && $instancia->problema_de_pago_desde
                && $this->enviadoEn($message)->gt($instancia->problema_de_pago_desde)) {
                FacturacionDeMeta::resolver($instancia);
            }
        }
    }

    /**
     * ¿Este mensaje demuestra que la cuenta ya tiene con qué pagar?
     *
     * - ENTREGADO o LEÍDO, no «sent». `sent` sólo dice que Meta lo aceptó: el
     *   131042 llega después por webhook. Apagar con `sent` hacía parpadear la
     *   alerta —se apagaba, llegaba el fallo, se encendía— y cada vuelta era
     *   otro correo a los admins. Meta cobra lo entregado, no lo enviado.
     * - Sólo plantillas. Desde el 1-oct-2026 Meta también cobra los mensajes
     *   de servicio (texto libre dentro de las 24 h), pero cada número tiene
     *   1.000 gratis al mes y Meta los sigue entregando aunque la cuenta no
     *   tenga método de pago. Un texto libre entregado no prueba nada.
     *   (Queda un hueco: una plantilla dentro de la ventana gratuita de un
     *   anuncio de clic a WhatsApp tampoco se cobra. Es raro en una línea que
     *   factura y el webhook todavía no guarda `pricing.billable`, que sería
     *   la prueba exacta.)
     * - Que saliera DESPUÉS de que empezara el problema lo comprueba quien
     *   llama: un «leído» de la factura de ayer llega hoy y no dice nada de
     *   cómo está la cuenta ahora.
     */
    private function pruebaQueMetaCobra(WhatsAppMessage $message): bool
    {
        return $message->direction === 'outbound'
            && in_array($message->status, ['delivered', 'read'], true)
            && ($message->type === 'template' || isset($message->metadata['template']));
    }

    /**
     * Cuándo salió de verdad. `COALESCE(sent_at, created_at)`, como la ventana
     * de 24 h: cuando Meta suelta de golpe una cola de días, `created_at` es de
     * hoy y `sent_at` de hace tres.
     */
    private function enviadoEn(WhatsAppMessage $message): Carbon
    {
        return Carbon::parse($message->sent_at ?? $message->created_at ?? now());
    }

    private function instancia(WhatsAppMessage $message): ?Instance
    {
        return $message->conversation?->instance;
    }
}
