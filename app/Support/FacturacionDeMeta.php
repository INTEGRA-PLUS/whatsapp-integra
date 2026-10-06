<?php

namespace App\Support;

use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\MetaWhatsAppService;
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
 * Y se apaga sola en cuanto sale una plantilla: si Meta dejó pasar una, la
 * cuenta ya paga.
 */
class FacturacionDeMeta
{
    public const SIN_MONEDA = 'sin_moneda';

    public const SIN_METODO = 'sin_metodo_de_pago';

    /**
     * Hay tarjeta, pero un cobro de Meta quedó sin pagar —casi siempre por
     * falta de saldo o cupo—. «has unsettled payments».
     *
     * Antes caía en SIN_METODO y la alerta decía «no tienes un método de
     * pago»: el 3-oct-2026 una empresa respondió que su tarjeta seguía
     * asociada, y tenía razón. Lo que faltaba era pagar lo debido.
     */
    public const PAGO_PENDIENTE = 'pago_pendiente';

    /** Meta restringió los pagos de la cuenta. «payment has been restricted». */
    public const PAGO_RESTRINGIDO = 'pago_restringido';

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

    /**
     * Qué le pasa a la cuenta, leído del texto de Meta. El título del error
     * («Business eligibility payment issue») es el mismo en todos los casos:
     * lo que distingue es el detalle, así que hay que pasar el detalle.
     */
    public static function tipo(?string $mensaje): string
    {
        $mensaje = (string) $mensaje;

        return match (true) {
            (bool) preg_match('/currency/i', $mensaje) => self::SIN_MONEDA,
            (bool) preg_match('/unsettled|outstanding|overdue|past due/i', $mensaje) => self::PAGO_PENDIENTE,
            (bool) preg_match('/restricted|disabled|suspended/i', $mensaje) => self::PAGO_RESTRINGIDO,
            default => self::SIN_METODO,
        };
    }

    /**
     * El problema dicho para quien paga: qué pasa y qué hacer.
     *
     * @return array{titulo: string, explicacion: string, accion: string}
     */
    public static function explicacion(?string $tipo): array
    {
        return match ($tipo) {
            self::PAGO_PENDIENTE => [
                'titulo' => 'Meta tiene un cobro pendiente sin pagar',
                'explicacion' => 'Tu tarjeta sigue asociada, pero Meta intentó cobrar lo consumido y el cobro no pasó: '
                    .'lo más común es que la tarjeta no tuviera saldo o cupo, o que el banco bloqueara la compra internacional. '
                    .'Mientras ese saldo siga pendiente, Meta rechaza los envíos.',
                'accion' => 'Paga el saldo pendiente en Meta («Pagar ahora») o cambia a una tarjeta con saldo.',
            ],
            self::PAGO_RESTRINGIDO => [
                'titulo' => 'Meta restringió los pagos de tu cuenta',
                'explicacion' => 'Meta bloqueó los cobros de esta cuenta de WhatsApp Business. Suele pasar tras varios '
                    .'cobros rechazados seguidos o cuando Meta revisa la tarjeta. Mientras siga así, rechaza los envíos.',
                'accion' => 'Abre la facturación en Meta: allí dice qué pide para levantar la restricción (pagar lo pendiente, verificar la tarjeta o cambiarla).',
            ],
            self::SIN_MONEDA => [
                'titulo' => 'Tu cuenta de Meta no tiene moneda ni tarjeta',
                'explicacion' => 'La cuenta de WhatsApp Business nunca se configuró para pagar: le falta la moneda y el método de pago.',
                'accion' => 'Elige país y moneda en Meta y añade una tarjeta.',
            ],
            default => [
                'titulo' => 'Tu cuenta de Meta no tiene un método de pago válido',
                'explicacion' => 'Meta no encuentra una tarjeta con la que cobrar: no hay ninguna, está vencida o fue rechazada.',
                'accion' => 'Añade o actualiza la tarjeta en la facturación de Meta.',
            ],
        };
    }

    /**
     * El texto de Meta sin el enlace, que en la pantalla ya es un botón.
     */
    public static function detalleLegible(?string $detalle): ?string
    {
        if (! $detalle) {
            return null;
        }

        $limpio = preg_replace('#\s*Visit https://business\.facebook\.com/\S+ to resolve this issue\.?#i', '', $detalle);

        return trim($limpio) ?: null;
    }

    /**
     * Lo que Meta registra como consumido este mes y el anterior.
     *
     * Es lo más cerca que se puede llegar de «cuánto debes»: el saldo
     * pendiente exacto, con impuestos, sólo lo enseña el panel de Meta. Se
     * guarda media hora porque `pricing_analytics` se actualiza con retraso y
     * la guía la abre cada agente que ve la alerta.
     *
     * Va con el portafolio de Meta dueño de la cuenta, que es a quien Meta le
     * cobra. Muchas líneas viven en el portafolio de Integra, pero hay
     * clientes que crearon el suyo, y quien tiene varios en su Facebook no
     * sabía en cuál buscar la factura (6-oct-2026). Se pide aparte y se
     * devuelve aunque el consumo falle: son preguntas independientes.
     *
     * @return array{moneda: ?string, periodos: ?array<int, array{periodo: string, desde: string, hasta: string, total: float, cobrados: int, gratis: int, categorias: array}>, cuenta: ?string, portafolio: ?array{id: string, nombre: ?string}}|null
     */
    public static function consumo(Instance $instance, MetaWhatsAppService $meta): ?array
    {
        if (! $instance->waba_id || ! $instance->access_token) {
            return null;
        }

        $duenio = Cache::remember(
            "portafolio-meta:{$instance->id}:{$instance->waba_id}",
            now()->addDay(),
            fn () => $meta->portafolioDeLaCuenta($instance->waba_id, $instance->access_token)
        );

        $consumo = self::consumoPorPeriodo($instance, $meta);

        if (! $consumo && ! $duenio) {
            return null;
        }

        return [
            ...($consumo ?? ['moneda' => null, 'periodos' => null]),
            'cuenta' => $duenio['cuenta'] ?? null,
            'portafolio' => $duenio['portafolio'] ?? null,
        ];
    }

    private static function consumoPorPeriodo(Instance $instance, MetaWhatsAppService $meta): ?array
    {
        return Cache::remember("consumo-meta:{$instance->id}", now()->addMinutes(30), function () use ($instance, $meta) {
            $inicioMes = now()->startOfMonth();
            $periodos = [
                ['desde' => $inicioMes->copy()->subMonthNoOverflow(), 'hasta' => $inicioMes->copy()],
                ['desde' => $inicioMes->copy(), 'hasta' => now()],
            ];

            $moneda = null;
            $salida = [];

            foreach ($periodos as $p) {
                $res = $meta->consumoDeLaCuenta($instance->waba_id, $instance->access_token, $p['desde']->timestamp, $p['hasta']->timestamp);

                if (! ($res['success'] ?? false)) {
                    Log::channel('whatsapp')->warning('No se pudo leer el consumo de Meta', [
                        'instance_id' => $instance->id,
                        'error' => $res['error'] ?? null,
                    ]);

                    return null;
                }

                $moneda ??= $res['data']['currency'] ?? null;
                $categorias = [];
                $cobrados = 0;
                $gratis = 0;

                foreach ($res['data']['pricing_analytics']['data'][0]['data_points'] ?? [] as $punto) {
                    $categoria = $punto['pricing_category'] ?? 'OTRA';
                    $volumen = (int) ($punto['volume'] ?? 0);
                    $costo = (float) ($punto['cost'] ?? 0);

                    if ($costo > 0) {
                        $categorias[$categoria] ??= ['categoria' => $categoria, 'mensajes' => 0, 'costo' => 0.0];
                        $categorias[$categoria]['mensajes'] += $volumen;
                        $categorias[$categoria]['costo'] += $costo;
                        $cobrados += $volumen;
                    } else {
                        $gratis += $volumen;
                    }
                }

                $categorias = collect($categorias)
                    ->map(fn ($c) => [...$c, 'costo' => round($c['costo'], 4)])
                    ->sortByDesc('costo')
                    ->values()
                    ->all();

                $salida[] = [
                    'periodo' => ucfirst($p['desde']->locale('es')->isoFormat('MMMM YYYY')),
                    'desde' => $p['desde']->toDateString(),
                    'hasta' => $p['hasta']->toDateString(),
                    'en_curso' => $p['hasta']->isToday(),
                    'total' => round(array_sum(array_column($categorias, 'costo')), 2),
                    'cobrados' => $cobrados,
                    'gratis' => $gratis,
                    'categorias' => $categorias,
                ];
            }

            return ['moneda' => $moneda, 'periodos' => array_reverse($salida)];
        });
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
            'detalle_de_pago' => $mensaje ?: $instance->detalle_de_pago,
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
            'detalle_de_pago' => null,
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

        // Ya no es el pago, pero Meta limita por otra cosa. Se dice cuál: un
        // «por otro motivo» a secas se leyó como que la tarjeta seguía mal
        // (JHeda, 30-sep-2026, con el pago ya hecho y el negocio sin
        // verificar). LIMITED no es BLOCKED: envía, con tope.
        if ($estado !== null && $motivo === null) {
            self::resolver($instance);

            $otros = self::otrosMotivos($salud['data']['health_status'] ?? []);

            return [
                'ok' => $estado === 'LIMITED',
                'limitada' => true,
                'mensaje' => $estado === 'LIMITED'
                    ? 'El pago quedó listo y ya puedes enviar. Meta mantiene un límite en tu cuenta: '.$otros['texto']
                    : 'El pago quedó listo, pero Meta todavía no deja enviar: '.$otros['texto'],
                'guia' => $otros['guia'],
            ];
        }

        if ($motivo !== null) {
            self::marcar($instance, self::tipo($motivo));

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

        $que = self::explicacion($instance->problema_de_pago);

        Notification::send($admins, new SystemNotification(
            'Tus mensajes de WhatsApp no están saliendo',
            "Meta está rechazando los envíos de «{$instance->name}»: {$que['titulo']}. {$que['accion']} "
                .'Tienes el detalle y lo consumido en Instancias → «Pago en Meta».',
            'Sistema'
        ));
    }
}
