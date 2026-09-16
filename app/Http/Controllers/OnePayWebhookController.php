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

        [$autentico, $como] = $this->verificar($request);

        Log::channel('whatsapp')->info('💳 OnePay avisó', [
            'tipo' => $tipo ?: 'sin tipo',
            'autenticado' => $como,
            // Los NOMBRES de las cabeceras, nunca sus valores: es lo que hace
            // falta para descubrir cómo firma OnePay sin dejar el secreto en un
            // log que se rota a un fichero y se lee con `tail`.
            'cabeceras' => implode(', ', array_keys($request->headers->all())),
        ]);

        if (! $autentico && config('services.onepay.webhook_modo') === 'exigir') {
            // 401 y no 200: aquí sí se quiere que la pasarela reintente, porque
            // un rechazo por firma es un problema de configuración nuestro y el
            // reintento nos da otra oportunidad de aceptarlo bien.
            Log::channel('whatsapp')->warning('⚠️ Aviso de OnePay rechazado: no valida la firma');

            return response()->json(['ok' => false, 'error' => 'firma'], 401);
        }

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
     * ¿Viene de OnePay de verdad?
     *
     * Se aceptan las dos formas porque no se sabe cuál usa —Integra 2.0 no
     * verifica nada y no hay de dónde copiarlo—:
     *
     * - **Token en cabecera.** El valor configurado aparece en alguna cabecera,
     *   se llame como se llame. Se buscan todas en vez de exigir un nombre
     *   concreto: adivinarlo mal es rechazar un pago real.
     * - **Firma HMAC.** El cuerpo crudo firmado con el secreto, en hex o base64.
     *   Sobre `getContent()` y nunca sobre `all()` re-serializado, por lo mismo
     *   que el webhook de Meta: un re-serializado cambia el orden y los
     *   escapes, y la firma deja de cuadrar sin motivo aparente.
     *
     * @return array{0: bool, 1: string} Si valida, y por qué camino.
     */
    private function verificar(Request $request): array
    {
        $token = (string) config('services.onepay.webhook_header');
        $secreto = (string) config('services.onepay.webhook_secret');

        if ($token === '' && $secreto === '') {
            return [false, 'sin configurar'];
        }

        foreach ($request->headers->all() as $nombre => $valores) {
            foreach ($valores as $valor) {
                // `hash_equals` y no `===`: comparar secretos carácter a
                // carácter filtra su longitud y su prefijo por el tiempo que
                // tarda en fallar.
                if ($token !== '' && hash_equals($token, trim((string) $valor))) {
                    return [true, 'token en '.$nombre];
                }

                // Algunas pasarelas lo mandan como «Bearer xxx» o «sha256=xxx».
                $limpio = preg_replace('/^(Bearer|sha256|sha1)[ =]/i', '', trim((string) $valor));

                if ($token !== '' && $limpio && hash_equals($token, $limpio)) {
                    return [true, 'token en '.$nombre];
                }

                if ($secreto !== '' && $limpio) {
                    $cuerpo = $request->getContent();
                    $hex = hash_hmac('sha256', $cuerpo, $secreto);

                    if (hash_equals($hex, $limpio) || hash_equals(base64_encode(hex2bin($hex)), $limpio)) {
                        return [true, 'firma en '.$nombre];
                    }
                }
            }
        }

        return [false, 'no valida'];
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
