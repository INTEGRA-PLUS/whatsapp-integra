<?php

namespace App\Services;

use App\Models\SuscripcionCobro;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Crea en OnePay la factura de un cobro de suscripción.
 *
 * Escrito mirando `OnePayService` de Integra 2.0, que lleva meses en producción
 * contra esta misma API. Las rarezas de abajo no son precauciones: son cosas que
 * allí fallaron y están documentadas en sus comentarios.
 *
 * ## Las tres trampas que ya costaron un incidente en Integra
 *
 * 1. **`provider_id` exige mínimo 5 caracteres.** Los códigos cortos fallaban la
 *    validación. Se rellena con ceros por la izquierda, y al volver el webhook
 *    los quita.
 * 2. **El importe va en pesos, entero, entre 5.000 y 100.000.000.** Nuestros
 *    precios están en USD, así que se convierten con una tasa fija de
 *    `config('planes.tasa_cop')` — fija y no en vivo, para que el mismo plan no
 *    cueste distinto cada mes sin que nadie lo decida.
 * 3. **La pasarela puede reemplazar la solicitud de pago.** Cuando lo hace pisa
 *    `provider_id` con una referencia suya y pierde el `metadata`. Por eso se
 *    manda el identificador también como `external_id`: allí fue lo único que
 *    sobrevivió (Megastore, factura EST7496, 1-sep-2026).
 *
 * ## Lo que este cliente NO hace
 *
 * No marca nada como pagado. Crear la factura sólo pone el cobro delante del
 * cliente; quien alarga la suscripción es `Suscripcion::pagar()`, y sólo cuando
 * OnePay confirma. Mezclar las dos cosas es cómo se regalan periodos.
 */
class OnePayClient
{
    /** Los límites de OnePay, en pesos. Rechaza fuera de rango. */
    private const MINIMO_COP = 5000;

    private const MAXIMO_COP = 100_000_000;

    public static function configurado(): bool
    {
        return filled(config('services.onepay.token'));
    }

    /**
     * Manda el cobro a OnePay y devuelve el id de la factura creada.
     *
     * `null` si no se pudo — y no lanza: un fallo de la pasarela no puede tumbar
     * la pantalla desde la que alguien estaba emitiendo. Queda en el log y el
     * cobro sigue pendiente, que es exactamente lo que es.
     */
    public static function crearFactura(SuscripcionCobro $cobro): ?string
    {
        if (! self::configurado()) {
            Log::warning('⚠️ OnePay sin token: el cobro queda sólo en el CRM', ['cobro' => $cobro->id]);

            return null;
        }

        $company = $cobro->company;
        $cop = $cobro->importe_usd * (int) config('planes.tasa_cop');

        if ($cop < self::MINIMO_COP || $cop > self::MAXIMO_COP) {
            Log::warning('⚠️ Importe fuera del rango que admite OnePay', [
                'cobro' => $cobro->id,
                'cop' => $cop,
            ]);

            return null;
        }

        $referencia = self::referencia($cobro);

        try {
            $respuesta = Http::withToken(config('services.onepay.token'))
                ->withHeaders([
                    // Determinista, como en Integra: dos envíos del mismo cobro
                    // no crean dos facturas. Sin esto, un doble clic cobra dos
                    // veces.
                    'x-idempotency' => hash('sha256', 'suscripcion_'.$cobro->id.'_'.$cobro->company_id),
                ])
                ->timeout((int) config('services.onepay.timeout', 30))
                ->post(rtrim((string) config('services.onepay.base_url'), '/').'/invoices', [
                    'reference' => str_pad((string) $company->id, 5, '0', STR_PAD_LEFT),
                    'provider_id' => $referencia,
                    // El `external_id` es el que sobrevive si la pasarela
                    // reemplaza la solicitud. Ver la cabecera de esta clase.
                    'external_id' => $referencia,
                    // Distinto del 'integra' que manda el ERP: es lo que
                    // permitirá separar los dos flujos si algún día comparten
                    // cuenta.
                    'provider' => 'integracrm',
                    'amount' => $cop,
                    'name' => 'Integra CRM · '.$cobro->concepto(),
                    'phone' => self::telefono($company->phone),
                    'email' => $company->email,
                    'due_date' => $cobro->periodo_desde->toDateString(),
                    'description' => 'Del '.$cobro->periodo_desde->format('d/m/Y')
                        .' al '.$cobro->periodo_hasta->format('d/m/Y'),
                    'metadata' => [
                        'cobro_id' => $cobro->id,
                        'company_id' => $company->id,
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('⚠️ OnePay no respondió', ['cobro' => $cobro->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $respuesta->successful()) {
            Log::warning('⚠️ OnePay rechazó la factura', [
                'cobro' => $cobro->id,
                'status' => $respuesta->status(),
                'body' => mb_substr($respuesta->body(), 0, 400),
            ]);

            return null;
        }

        $id = $respuesta->json('data.id') ?? $respuesta->json('id');

        if ($id) {
            $cobro->update(['referencia_onepay' => $id]);
        }

        return $id ? (string) $id : null;
    }

    /**
     * El identificador que viaja a OnePay y vuelve en el webhook.
     *
     * Lleva prefijo de letras a propósito, como los códigos de Integra: el
     * relleno a cinco caracteres es con ceros por la izquierda, y al volver se
     * quitan con `ltrim`. Sin letras delante, un id que empiece por cero
     * quedaría mutilado.
     */
    public static function referencia(SuscripcionCobro $cobro): string
    {
        return 'SUS'.str_pad((string) $cobro->id, 5, '0', STR_PAD_LEFT);
    }

    /** El id del cobro a partir de la referencia, o null si no es nuestra. */
    public static function cobroDeLaReferencia(?string $referencia): ?int
    {
        if (! $referencia || ! str_starts_with($referencia, 'SUS')) {
            return null;
        }

        $id = ltrim(substr($referencia, 3), '0');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * El teléfono en E.164, que es lo único que OnePay acepta.
     *
     * En Integra, un teléfono mal formado hacía fallar la factura entera y el
     * servicio acabó con un reintento que «autocorregía» el número del cliente
     * en la base. Aquí se manda `null` y ya: mejor una factura sin teléfono que
     * un número inventado en la ficha de una empresa.
     */
    private static function telefono(?string $phone): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $phone);

        if (strlen((string) $digitos) < 10) {
            return null;
        }

        return '+'.(str_starts_with($digitos, '57') ? $digitos : '57'.$digitos);
    }
}
