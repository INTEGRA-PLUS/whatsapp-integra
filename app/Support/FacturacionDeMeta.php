<?php

namespace App\Support;

use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\MetaWhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * La línea no puede enviar porque a su cuenta de Meta le falta la moneda o la
 * tarjeta.
 *
 * ## Por qué existe
 *
 * El 30-sep-2026 las facturas de JHeda Comunicaciones volvían todas con «your
 * WhatsApp Business account currency is not configured». En el CRM sólo se veía
 * «Fallido», mensaje a mensaje, y nadie en la empresa sabía que tenía que ir a
 * Meta: el cobro de Meta va directo al cliente, no pasa por nosotros, y somos
 * Tech Provider, no BSP — **no hay API con la que asociarle la tarjeta**. Lo
 * único que se puede hacer es detectarlo en cuanto pasa y llevarle paso a paso
 * hasta la pantalla de Meta donde se arregla.
 *
 * ## De dónde se entera
 *
 * - Del fallo de un envío (código 131042 o el texto de Meta), venga del chat,
 *   de una campaña, del webhook o de la API del ERP. Es lo que ocurre primero.
 * - Del chequeo diario de salud, que lee `health_status` de la cuenta.
 * - Del botón «Ya lo hice, comprobar» de la guía.
 *
 * Y se apaga sola en cuanto sale una plantilla: si Meta dejó pasar una, la
 * cuenta ya paga.
 */
class FacturacionDeMeta
{
    public const SIN_MONEDA = 'sin_moneda';

    public const SIN_METODO = 'sin_metodo_de_pago';

    /** El código de Meta para todo lo que es cobro: tarjeta, moneda, crédito. */
    public const CODIGO = '131042';

    /**
     * ¿Es un error de cobro? Por código cuando lo hay; por texto cuando no,
     * porque en parte del histórico `error_code` llegó vacío.
     */
    public static function esErrorDePago($codigo, ?string $mensaje): bool
    {
        // 141006 es el mismo problema visto desde `health_status`: así salió
        // la cuenta de JHeda al consultarla el 30-sep-2026.
        if (in_array((string) $codigo, [self::CODIGO, '141006'], true)) {
            return true;
        }

        return (bool) preg_match(
            '/currency is not configured|payment (method|account|issue)|business eligibility payment/i',
            (string) $mensaje
        );
    }

    public static function tipo(?string $mensaje): string
    {
        return preg_match('/currency/i', (string) $mensaje) ? self::SIN_MONEDA : self::SIN_METODO;
    }

    /** El enlace al asistente de Meta que trae el propio error, si lo trae. */
    public static function enlaceDe(?string $mensaje): ?string
    {
        return preg_match('#https://business\.facebook\.com/billing_hub/[^\s"\'<>]+#', (string) $mensaje, $m)
            ? rtrim($m[0], '.,);')
            : null;
    }

    /**
     * Adónde mandar al cliente: el enlace del error si lo hay —abre la cuenta
     * exacta con el portafolio ya elegido— y si no, el centro de facturación
     * con la cuenta de WhatsApp preseleccionada.
     */
    public static function enlace(Instance $instance): string
    {
        if ($instance->enlace_de_pago) {
            return $instance->enlace_de_pago;
        }

        return $instance->waba_id
            ? 'https://business.facebook.com/billing_hub/accounts/details/?asset_id='.$instance->waba_id.'&account_type=whatsapp-business-account'
            : 'https://business.facebook.com/billing_hub/accounts';
    }

    /**
     * Lo registra si el fallo es de cobro. Devuelve si lo era.
     *
     * Avisa a los admins sólo la primera vez: cada factura fallida del mismo
     * lote no es una noticia nueva.
     */
    public static function registrarFallo(?Instance $instance, $codigo, ?string $mensaje): bool
    {
        if (! $instance || ! self::esErrorDePago($codigo, $mensaje)) {
            return false;
        }

        $nuevo = ! $instance->problema_de_pago;

        $instance->forceFill([
            'problema_de_pago' => self::tipo($mensaje),
            'problema_de_pago_desde' => $instance->problema_de_pago_desde ?? now(),
            'enlace_de_pago' => self::enlaceDe($mensaje) ?? $instance->enlace_de_pago,
        ])->saveQuietly();

        if ($nuevo) {
            self::avisar($instance);
        }

        return true;
    }

    public static function resolver(?Instance $instance): void
    {
        if (! $instance?->problema_de_pago) {
            return;
        }

        Log::channel('whatsapp')->info('💳 La cuenta de Meta ya cobra: se apaga la alerta de pago', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id,
            'desde' => optional($instance->problema_de_pago_desde)->toIso8601String(),
        ]);

        $instance->forceFill([
            'problema_de_pago' => null,
            'problema_de_pago_desde' => null,
            'enlace_de_pago' => null,
        ])->saveQuietly();
    }

    /**
     * Pregunta a Meta si la cuenta ya puede enviar. Es lo que hay detrás del
     * botón «Ya lo hice, comprobar».
     *
     * Se fía de `health_status`, que dice si la cuenta puede enviar y por qué
     * no. La moneda se pide aparte y en su propia llamada: si Meta la niega,
     * que no se lleve por delante la respuesta que sí importa.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public static function comprobar(Instance $instance, MetaWhatsAppService $meta): array
    {
        if (! $instance->waba_id || ! $instance->access_token) {
            return ['ok' => false, 'mensaje' => 'Esta línea no tiene cuenta de WhatsApp conectada que comprobar.'];
        }

        $salud = $meta->healthStatus($instance->waba_id, $instance->access_token);

        if (! ($salud['success'] ?? false)) {
            return ['ok' => false, 'mensaje' => 'Meta no respondió. Espera un minuto y vuelve a comprobar.'];
        }

        $moneda = $meta->monedaDeLaCuenta($instance->waba_id, $instance->access_token);

        if ($moneda === '') {
            self::marcar($instance, self::SIN_MONEDA);

            return ['ok' => false, 'mensaje' => 'Meta todavía no tiene moneda configurada en esta cuenta. Revisa el paso 2.'];
        }

        $estado = $salud['data']['health_status']['can_send_message'] ?? null;
        $motivo = self::motivoDePago($salud['data']['health_status'] ?? []);

        if ($estado === 'AVAILABLE') {
            self::resolver($instance);

            return ['ok' => true, 'mensaje' => 'Listo: Meta ya deja enviar por esta línea. Vuelve a enviar los mensajes que fallaron.'];
        }

        // Bloqueada, pero no por el pago: la alerta de pago se apaga y se dice
        // que el problema es otro, en vez de mandarle a poner otra tarjeta.
        if ($estado !== null && $motivo === null) {
            self::resolver($instance);

            return ['ok' => false, 'mensaje' => 'El pago ya está en orden, pero Meta sigue limitando esta cuenta por otro motivo. Revisa el estado de la línea en Instancias.'];
        }

        if ($motivo !== null) {
            self::marcar($instance, self::SIN_METODO);

            return ['ok' => false, 'mensaje' => 'Meta sigue sin un método de pago válido: '.$motivo];
        }

        return ['ok' => false, 'mensaje' => 'Meta no dijo si la cuenta ya puede enviar. Prueba de nuevo en unos minutos.'];
    }

    /**
     * El motivo de pago dentro de `health_status`, o null si lo que bloquea es
     * otra cosa. Una cuenta bloqueada por política no se arregla con una
     * tarjeta, y decírselo sería mandarle a hacer algo que no sirve.
     */
    public static function motivoDePago(array $salud): ?string
    {
        foreach ($salud['entities'] ?? [] as $entidad) {
            foreach ($entidad['errors'] ?? [] as $error) {
                $texto = $error['error_description'] ?? $error['description'] ?? $error['message'] ?? '';

                if (self::esErrorDePago($error['error_code'] ?? null, $texto)
                    || preg_match('/payment|funding|billing|currency/i', $texto)) {
                    return $texto !== '' ? $texto : 'problema con el método de pago.';
                }
            }
        }

        return null;
    }

    public static function marcar(Instance $instance, string $tipo): void
    {
        $nuevo = ! $instance->problema_de_pago;

        $instance->forceFill([
            'problema_de_pago' => $tipo,
            'problema_de_pago_desde' => $instance->problema_de_pago_desde ?? now(),
        ])->saveQuietly();

        if ($nuevo) {
            self::avisar($instance);
        }
    }

    private static function avisar(Instance $instance): void
    {
        Log::channel('whatsapp')->error('💳 La cuenta de Meta no tiene con qué cobrar: no salen mensajes', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id,
            'problema' => $instance->problema_de_pago,
        ]);

        $admins = User::where('company_id', $instance->company_id)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new SystemNotification(
            'Tus mensajes de WhatsApp no están saliendo',
            "Meta está rechazando los envíos de «{$instance->name}» porque tu cuenta de WhatsApp Business "
                .'no tiene tarjeta ni moneda configuradas. Entra en Instancias → «Activar el pago en Meta» '
                .'y sigue los pasos: son cinco minutos.',
            'Sistema'
        ));
    }
}
