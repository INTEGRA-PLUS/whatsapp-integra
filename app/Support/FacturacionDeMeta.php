<?php

namespace App\Support;

use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\MetaWhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 * Y se apaga sola cuando Meta ENTREGA una plantilla enviada después de que
 * empezara el problema (ver `PagoDeMetaObserver`). El botón de comprobar, en
 * cambio, sólo la deja «por confirmar»: `health_status` puede decir AVAILABLE
 * en una cuenta que falla en la primera plantilla.
 *
 * ## Es de la cuenta, no del número
 *
 * El método de pago es de la cuenta de WhatsApp Business (WABA). Todo lo que
 * se marca o se apaga se hace a la vez en todas las líneas de la misma empresa
 * con el mismo `waba_id`, nunca cruzando empresas.
 */
class FacturacionDeMeta
{
    public const SIN_MONEDA = 'sin_moneda';

    public const SIN_METODO = 'sin_metodo_de_pago';

    /**
     * El botón de comprobar dijo que Meta ya no marca el pago, pero todavía
     * no ha salido ningún mensaje que lo demuestre. Ámbar, no rojo.
     */
    public const POR_CONFIRMAR = 'por_confirmar';

    /** Cada cuánto, como mucho, se vuelve a avisar a los admins de la misma cuenta. */
    public const ANTIRREBOTE_HORAS = 6;

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
     * Qué falta según el texto de Meta, o null si el texto no lo dice (sólo
     * llegó el código, como en parte del histórico).
     *
     * Null y no «sin método» por defecto: adivinar pisaba un `sin_moneda` que
     * sí venía dicho por el error con un `sin_metodo` que nadie había dicho, y
     * la guía mandaba a poner la tarjeta a quien lo que no tenía era moneda.
     */
    public static function tipoConocido(?string $mensaje): ?string
    {
        if (preg_match('/currency|moneda/i', (string) $mensaje)) {
            return self::SIN_MONEDA;
        }

        return preg_match('/payment|funding|billing|credit line|pago/i', (string) $mensaje) ? self::SIN_METODO : null;
    }

    /**
     * Las líneas que comparten el pago con esta: las de la MISMA empresa con
     * la misma cuenta de WhatsApp Business. El método de pago es de la cuenta
     * (WABA), no del número: si una línea no puede enviar por pago, las otras
     * de esa cuenta tampoco, y arreglarlo las arregla todas.
     *
     * Acotado por empresa a propósito: el índice único es (empresa,
     * phone_number_id) y dos empresas pueden haber conectado la misma cuenta.
     * La alerta de una no puede encenderse ni apagarse por lo que pasa en la
     * otra.
     */
    public static function lineasDelMismoPago(Instance $instance): Collection
    {
        if (! $instance->waba_id) {
            return Instance::whereKey($instance->id)->get();
        }

        return Instance::where('company_id', $instance->company_id)
            ->where('waba_id', $instance->waba_id)
            ->get();
    }

    /**
     * Lo registra si el fallo es de cobro. Devuelve si lo era.
     *
     * `$enviadoEn` es cuándo salió el mensaje que falló. Meta reintenta durante
     * días y suelta los fallos de golpe: el 131042 de una factura del lunes
     * puede llegar el miércoles, con la tarjeta ya puesta y la alerta ya
     * apagada. Un fallo de un envío anterior a la última vez que se dio el
     * pago por bueno no es noticia y no vuelve a encender nada.
     */
    public static function registrarFallo(?Instance $instance, $codigo, ?string $mensaje, $enviadoEn = null): bool
    {
        if (! $instance || ! self::esErrorDePago($codigo, $mensaje)) {
            return false;
        }

        $alDia = $instance->pago_al_dia_desde ? Carbon::parse($instance->pago_al_dia_desde) : null;
        $enviado = $enviadoEn ? Carbon::parse($enviadoEn) : null;

        if ($alDia && $enviado && $enviado->lt($alDia)) {
            Log::channel('whatsapp')->info('💳 Fallo de pago de un envío anterior al arreglo: no se vuelve a encender la alerta', [
                'instance_id' => $instance->id,
                'company_id' => $instance->company_id,
                'enviado' => $enviado->toIso8601String(),
                'pago_al_dia_desde' => $alDia->toIso8601String(),
            ]);

            return true;
        }

        self::encender($instance, self::tipoConocido($mensaje), self::enlaceDe($mensaje));

        return true;
    }

    /**
     * Apaga la alerta de todas las líneas que comparten el pago.
     *
     * Sólo debe llamarse con una prueba de que Meta cobra: un mensaje
     * entregado después de que empezara el problema. Ver `PagoDeMetaObserver`.
     */
    public static function resolver(?Instance $instance): void
    {
        if (! $instance) {
            return;
        }

        $lineas = self::lineasDelMismoPago($instance)->filter(fn (Instance $l) => $l->problema_de_pago);

        if ($lineas->isEmpty()) {
            return;
        }

        Log::channel('whatsapp')->info('💳 La cuenta de Meta ya cobra: se apaga la alerta de pago', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id,
            'waba_id' => $instance->waba_id,
            'lineas' => $lineas->pluck('id')->all(),
            'desde' => optional($lineas->first()->problema_de_pago_desde)->toIso8601String(),
        ]);

        self::guardar($instance, $lineas, fn () => [
            'problema_de_pago' => null,
            'problema_de_pago_desde' => null,
            'enlace_de_pago' => null,
            'pago_al_dia_desde' => now(),
        ]);
    }

    /**
     * Meta ya no marca problema de pago, pero eso no basta para darlo por
     * resuelto: una cuenta sin moneda puede salir AVAILABLE en `health_status`
     * y fallar igual en la primera plantilla. La alerta pasa a «por confirmar»
     * —ámbar, sin culpar a la tarjeta— y la apaga del todo el próximo mensaje
     * que Meta entregue; si en cambio vuelve un 131042, se enciende otra vez.
     */
    public static function porConfirmar(Instance $instance): void
    {
        $lineas = self::lineasDelMismoPago($instance)
            ->filter(fn (Instance $l) => in_array($l->problema_de_pago, [self::SIN_MONEDA, self::SIN_METODO], true));

        if ($lineas->isEmpty()) {
            return;
        }

        self::guardar($instance, $lineas, fn () => [
            'problema_de_pago' => self::POR_CONFIRMAR,
            'pago_al_dia_desde' => now(),
        ]);
    }

    /**
     * Desde el chequeo diario o el botón de comprobar.
     *
     * `$monedaComprobada`: sólo quien acaba de leerle la moneda a Meta puede
     * cambiar un `sin_moneda` por `sin_metodo`. El chequeo diario sólo sabe
     * que «es de pago», y si pisaba el `sin_moneda` que dijo el propio error
     * la guía mandaba a poner tarjeta a quien no tenía moneda.
     */
    public static function marcar(Instance $instance, string $tipo, bool $monedaComprobada = false): void
    {
        self::encender($instance, $tipo, null, $monedaComprobada);
    }

    private static function encender(Instance $instance, ?string $tipo, ?string $enlace, bool $monedaComprobada = false): void
    {
        $lineas = self::lineasDelMismoPago($instance);
        $activos = [self::SIN_MONEDA, self::SIN_METODO];

        $yaEstaba = $lineas->contains(fn (Instance $l) => in_array($l->problema_de_pago, $activos, true));

        // El mismo incidente para todas: la guía guarda el avance por esta
        // fecha, y si cada línea tuviera la suya el progreso se partiría.
        // Un «por confirmar» que vuelve a fallar sigue siendo el mismo
        // incidente: conserva su fecha.
        $desde = $lineas->filter(fn (Instance $l) => $l->problema_de_pago)
            ->map(fn (Instance $l) => $l->problema_de_pago_desde)
            ->filter()
            ->min() ?? now();

        $tipoFinal = null;

        self::guardar($instance, $lineas, function (Instance $l) use ($tipo, $enlace, $monedaComprobada, $desde, $activos, &$tipoFinal) {
            $actual = in_array($l->problema_de_pago, $activos, true) ? $l->problema_de_pago : null;
            $nuevo = $tipo ?? $actual ?? self::SIN_METODO;

            if ($nuevo === self::SIN_METODO && $actual === self::SIN_MONEDA && ! $monedaComprobada) {
                $nuevo = self::SIN_MONEDA;
            }

            $tipoFinal = $nuevo;

            return [
                'problema_de_pago' => $nuevo,
                'problema_de_pago_desde' => $desde,
                'enlace_de_pago' => $enlace ?? $l->enlace_de_pago,
            ];
        });

        if ($yaEstaba) {
            return;
        }

        // Antirrebote por cuenta: con una alerta que se apaga y se vuelve a
        // encender (un «por confirmar» que falla, un fallo rezagado) cada
        // vuelta era otro correo a los admins por el mismo problema. Una vez
        // cada pocas horas basta: la alerta roja sigue en pantalla.
        $clave = 'pago-meta:aviso:'.$instance->company_id.':'.($instance->waba_id ?: 'linea-'.$instance->id);

        if (Cache::add($clave, now()->toIso8601String(), now()->addHours(self::ANTIRREBOTE_HORAS))) {
            self::avisar($instance, $lineas, $tipoFinal ?? self::SIN_METODO);
        }
    }

    /**
     * Guarda cada línea sin disparar observers y deja la instancia que llegó
     * con los mismos valores, para que quien llamó no siga con una copia vieja.
     *
     * @param  callable(Instance): array  $cambios
     */
    private static function guardar(Instance $instance, Collection $lineas, callable $cambios): void
    {
        foreach ($lineas as $linea) {
            $valores = $cambios($linea);
            $linea->forceFill($valores)->saveQuietly();

            if ($linea->id === $instance->id) {
                $instance->forceFill($valores)->syncOriginalAttributes(array_keys($valores));
            }
        }
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
        $monedaLeida = is_string($moneda) && $moneda !== '';

        $estado = $salud['data']['health_status']['can_send_message'] ?? null;
        $motivo = self::motivoDePago($salud['data']['health_status'] ?? []);

        if ($motivo !== null) {
            self::marcar($instance, self::tipoConocido($motivo) ?? self::SIN_METODO, $monedaLeida);

            return ['ok' => false, 'mensaje' => 'Meta sigue sin un método de pago válido: '.$motivo];
        }

        // Moneda vacía NO enciende nada: Graph devuelve el campo vacío tanto si
        // la cuenta no tiene moneda como si el token no tiene permiso para
        // leerla, y no hay forma de distinguirlo. Sólo se usa para no dar por
        // arreglado un `sin_moneda` que ya estaba encendido.
        if (! $monedaLeida && $instance->problema_de_pago === self::SIN_MONEDA) {
            return [
                'ok' => false,
                'mensaje' => 'Meta todavía no nos muestra ninguna moneda en esta cuenta. Revisa el paso 3. '
                    .'Si ya la elegiste, la alerta se apagará sola en cuanto Meta entregue el próximo mensaje.',
            ];
        }

        if ($estado === null) {
            return ['ok' => false, 'mensaje' => 'Meta no dijo si la cuenta ya puede enviar. Prueba de nuevo en unos minutos.'];
        }

        // Meta no marca nada de pago. No se apaga del todo: queda «por
        // confirmar» hasta que Meta entregue una plantilla, porque una cuenta
        // sin moneda puede salir AVAILABLE y fallar igual en la primera.
        $teniaProblema = in_array($instance->problema_de_pago, [self::SIN_MONEDA, self::SIN_METODO, self::POR_CONFIRMAR], true);
        self::porConfirmar($instance);

        $confirmacion = $teniaProblema
            ? ' La alerta se apagará del todo en cuanto Meta entregue el próximo mensaje; si vuelve a rechazar uno por pago, reaparecerá.'
            : '';

        if ($estado === 'AVAILABLE') {
            return [
                'ok' => true,
                'por_confirmar' => $teniaProblema,
                'mensaje' => 'Meta ya no marca ningún problema de pago en esta línea. Puedes volver a enviar los mensajes que fallaron.'.$confirmacion,
            ];
        }

        // Ya no es el pago, pero Meta limita por otra cosa. Se dice cuál: un
        // «por otro motivo» a secas se leyó como que la tarjeta seguía mal
        // (JHeda, 30-sep-2026, con el pago ya hecho y el negocio sin
        // verificar). LIMITED no es BLOCKED: envía, con tope.
        $otros = self::otrosMotivos($salud['data']['health_status'] ?? []);

        return [
            'ok' => $estado === 'LIMITED',
            'limitada' => true,
            'por_confirmar' => $teniaProblema,
            'mensaje' => ($estado === 'LIMITED'
                ? 'Meta ya no marca ningún problema de pago y puedes enviar, pero mantiene un límite en tu cuenta: '.$otros['texto']
                : 'Meta ya no marca ningún problema de pago, pero todavía no deja enviar: '.$otros['texto']).$confirmacion,
            'guia' => $otros['guia'],
        ];
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

    /**
     * Lo que limita la cuenta que no es el pago, dicho en español y con la
     * guía que lo resuelve cuando la hay.
     *
     * @return array{texto: string, guia: ?string}
     */
    public static function otrosMotivos(array $salud): array
    {
        $conocidos = [
            '141010' => 'tu negocio no ha pasado la verificación de Meta. Mientras tanto solo puedes escribir primero a 250 clientes distintos al día. Verificar el negocio sube ese límite.',
        ];

        $textos = [];
        $guia = null;

        foreach ($salud['entities'] ?? [] as $entidad) {
            foreach ($entidad['errors'] ?? [] as $error) {
                $codigo = (string) ($error['error_code'] ?? '');

                if (isset($conocidos[$codigo])) {
                    $textos[] = $conocidos[$codigo];
                    $guia = '/instances/guia-limites-whatsapp';
                } elseif ($texto = $error['error_description'] ?? $error['description'] ?? null) {
                    $textos[] = $texto;
                }
            }
        }

        return [
            'texto' => $textos === [] ? 'Meta no dio el motivo. Revisa el estado de la línea en Instancias.' : implode(' ', array_unique($textos)),
            'guia' => $guia,
        ];
    }

    /**
     * El correo a los admins. Mismas palabras que la alerta roja del layout
     * (`alerta-pago-meta.jsx`) y la guía: tres textos distintos para el mismo
     * problema eran tres versiones de qué hacer.
     */
    private static function avisar(Instance $instance, Collection $lineas, string $tipo): void
    {
        Log::channel('whatsapp')->error('💳 La cuenta de Meta no tiene con qué cobrar: no salen mensajes', [
            'instance_id' => $instance->id,
            'company_id' => $instance->company_id,
            'waba_id' => $instance->waba_id,
            'lineas' => $lineas->pluck('id')->all(),
            'problema' => $tipo,
        ]);

        $admins = User::where('company_id', $instance->company_id)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $nombres = $lineas->where('active', true)->pluck('name')->filter()->map(fn ($n) => "«{$n}»")->implode(', ')
            ?: "«{$instance->name}»";

        Notification::send($admins, new SystemNotification(
            'Tus mensajes de WhatsApp no están saliendo',
            "Meta está rechazando los envíos de {$nombres} porque la cuenta de WhatsApp Business "
                .self::queFalta($tipo).'. Entra en Instancias → «Activar el pago en Meta» y sigue los pasos: '
                .'si tienes el acceso y la tarjeta a mano, son unos minutos.',
            'Sistema'
        ));
    }

    /** «…porque la cuenta de WhatsApp Business» + esto. */
    public static function queFalta(?string $tipo): string
    {
        return $tipo === self::SIN_MONEDA
            ? 'no tiene configurada la moneda de facturación'
            : 'no tiene un método de pago válido';
    }
}
