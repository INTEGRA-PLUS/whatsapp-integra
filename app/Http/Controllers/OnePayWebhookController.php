<?php

namespace App\Http\Controllers;

use App\Models\SuscripcionCobro;
use App\Services\OnePayClient;
use App\Support\Suscripcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Lo que OnePay avisa cuando alguien paga una suscripción.
 *
 * Copia deliberada de `CronController::eventosOnePayWebhook` de Integra 2.0, que
 * lleva meses recibiendo estos mismos eventos en producción. Lo que hay aquí de
 * raro está copiado de allí porque allí falló primero.
 *
 * ## La trampa del importe
 *
 * **El monto viene en unidades distintas según el evento.** En `invoice.paid`
 * llega tal cual; en `payment.approved` llega en centavos y hay que dividir
 * entre cien. Equivocarse ahí es dar por pagado un cobro de 116.000 con 1.160.
 *
 * ## Por qué se busca el cobro por cuatro caminos
 *
 * Porque la pasarela **puede reemplazar la solicitud de pago**. Cuando lo hace
 * —le pasó a Megastore el 1-sep-2026 con la factura EST7496— pisa el
 * `provider_id` con una referencia interna suya, pierde el `metadata` y estrena
 * `id`. Los tres caminos obvios fallan a la vez y `external_id` es lo único que
 * sigue diciendo quién era.
 *
 * ## Lo que este webhook NO hace
 *
 * No calcula nada ni alarga la suscripción por su cuenta: llama a
 * `Suscripcion::pagar()`, que es el mismo sitio por el que pasa un pago marcado
 * a mano desde el panel. Un camino distinto sería un sitio más donde regalar un
 * periodo.
 */
class OnePayWebhookController extends Controller
{
    /** Los eventos que significan algo. El resto se contesta 200 y se ignora. */
    private const EVENTOS = ['invoice.paid', 'payment.approved'];

    public function __invoke(Request $request)
    {
        $tipo = (string) $request->input('event.type');

        Log::channel('whatsapp')->info('💳 OnePay avisó', ['tipo' => $tipo ?: 'sin tipo']);

        // 200 aunque no interese: un webhook que responde error hace que la
        // pasarela reintente en bucle un evento que nunca vamos a querer.
        if (! in_array($tipo, self::EVENTOS, true)) {
            return response()->json(['ok' => true, 'ignorado' => $tipo]);
        }

        $invoice = (array) $request->input('invoice', []);
        $payment = (array) ($invoice['payment'] ?? $request->input('payment', []));

        $cobro = $this->buscarCobro($invoice, $payment);

        if (! $cobro) {
            // 200 a propósito: si el pago no es nuestro —la cuenta podría
            // compartirse con Integra— reintentar no lo va a arreglar.
            Log::channel('whatsapp')->info('ℹ️ OnePay avisó de un pago que no es de una suscripción', [
                'provider' => $invoice['provider'] ?? null,
                'external_id' => $invoice['external_id'] ?? null,
            ]);

            return response()->json(['ok' => true, 'ajeno' => true]);
        }

        // La trampa: `payment.approved` llega en centavos.
        $pagado = (float) ($payment['amount'] ?? 0);

        if ($tipo === 'payment.approved') {
            $pagado /= 100;
        }

        $esperado = $cobro->importe_usd * (int) config('planes.tasa_cop');

        // Se acepta el pago aunque el importe no cuadre, y se avisa. Rechazarlo
        // dejaría al cliente habiendo pagado y sin servicio renovado, que es
        // peor problema que una diferencia de pesos por revisar.
        if (abs($pagado - $esperado) > 1) {
            Log::channel('whatsapp')->warning('⚠️ El importe pagado no cuadra con el cobro', [
                'cobro' => $cobro->id,
                'esperado_cop' => $esperado,
                'pagado_cop' => $pagado,
            ]);
        }

        $referencia = $payment['id'] ?? $invoice['id'] ?? null;

        // `pagar()` es idempotente: el reintento del webhook —y todas las
        // pasarelas reintentan— no alarga el periodo dos veces.
        if (! Suscripcion::pagar($cobro, $referencia ? (string) $referencia : null)) {
            return response()->json(['ok' => true, 'repetido' => true]);
        }

        Log::channel('whatsapp')->info('💳 Suscripción pagada por OnePay', [
            'cobro' => $cobro->id,
            'empresa' => $cobro->company_id,
            'hasta' => $cobro->periodo_hasta->toDateString(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * El cobro al que corresponde este pago, por los cuatro caminos.
     *
     * En este orden porque es el de fiabilidad decreciente: `external_id` es el
     * único que sobrevive a que la pasarela reemplace la solicitud, así que va
     * primero aunque en Integra fuera el segundo — allí se añadió después, tras
     * el incidente.
     */
    private function buscarCobro(array $invoice, array $payment): ?SuscripcionCobro
    {
        foreach ([$invoice['external_id'] ?? null, $payment['external_id'] ?? null,
            $invoice['provider_id'] ?? null, $payment['provider_id'] ?? null] as $referencia) {
            if ($id = OnePayClient::cobroDeLaReferencia($referencia ? (string) $referencia : null)) {
                if ($cobro = SuscripcionCobro::find($id)) {
                    return $cobro;
                }
            }
        }

        if ($id = ($invoice['metadata']['cobro_id'] ?? $payment['metadata']['cobro_id'] ?? null)) {
            if ($cobro = SuscripcionCobro::find($id)) {
                return $cobro;
            }
        }

        foreach ([$invoice['id'] ?? null, $payment['invoice_id'] ?? null] as $idOnePay) {
            if ($idOnePay && $cobro = SuscripcionCobro::where('referencia_onepay', $idOnePay)->first()) {
                return $cobro;
            }
        }

        return null;
    }
}
