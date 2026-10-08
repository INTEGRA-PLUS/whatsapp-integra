import { Link, usePage } from '@inertiajs/react';
import { CreditCard, ArrowRight, Hourglass } from 'lucide-react';

/**
 * La alerta roja de «Meta no está cobrando: no salen tus mensajes».
 *
 * En el layout, arriba de cualquier pantalla, porque mientras dura no sale ni
 * una factura y el cliente sólo ve «Fallido» mensaje a mensaje sin saber por
 * qué (JHeda, 30-sep-2026). Un aviso en Instancias no basta: nadie entra ahí
 * cuando lo que falla es la facturación.
 *
 * No se puede cerrar a propósito. Se apaga sola cuando Meta ENTREGA una
 * plantilla enviada después de que empezara el problema. El botón de la guía
 * no la apaga: la deja en ámbar, «por confirmar», porque que Meta diga que la
 * cuenta está disponible no garantiza que la próxima plantilla pase.
 *
 * `?.` en todo: Inertia 3 llama al layout dos veces y en la primera las props
 * compartidas pueden no estar.
 */

/** Las líneas a las que Meta rechaza envíos ahora mismo (rojo). Lo mismo que `FacturacionDeMeta::ACTIVOS`. */
const ACTIVOS = ['sin_moneda', 'sin_metodo_de_pago', 'pago_pendiente', 'pago_restringido'];
export const conProblemaDePago = l => ACTIVOS.includes(l?.problema);

/**
 * Qué le falta a la cuenta. La misma frase en la alerta, en la guía y en el
 * correo a los admins (`FacturacionDeMeta::queFalta`): tres redacciones del
 * mismo problema eran tres versiones de qué había que hacer.
 */
export function queFalta(lineas) {
    const tipos = new Set(lineas.map(l => l.problema));
    if (tipos.size === 1 && tipos.has('sin_moneda')) return 'no tiene configurada la moneda de facturación';
    if (tipos.size === 1 && tipos.has('pago_pendiente')) return 'tiene un cobro de Meta sin pagar';
    if (tipos.size === 1 && tipos.has('pago_restringido')) return 'tiene los pagos restringidos por Meta';
    if (tipos.has('sin_moneda')) return 'no tiene configurada la moneda de facturación o un método de pago válido';
    return 'no tiene un método de pago válido';
}

export const nombresDe = lineas => lineas.map(l => `${l.nombre}${l.numero ? ` (${l.numero})` : ''}`).join(', ');

export default function AlertaPagoMeta() {
    const { alertaPagoMeta } = usePage().props ?? {};
    const lineas = Array.isArray(alertaPagoMeta) ? alertaPagoMeta : [];

    if (lineas.length === 0) return null;

    // En la propia guía ya se explica todo: repetir la alerta encima es ruido.
    if (typeof window !== 'undefined' && window.location.pathname.startsWith('/instances/pago-en-meta')) {
        return null;
    }

    const rojas = lineas.filter(conProblemaDePago);
    const porConfirmar = lineas.filter(l => l?.problema === 'por_confirmar');

    if (rojas.length === 0) {
        if (porConfirmar.length === 0) return null;

        return (
            <div role="status" className="mx-3 mt-3 sm:mx-4">
                <div className="flex flex-col gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-foreground sm:flex-row sm:items-center">
                    <Hourglass className="size-5 shrink-0 text-warning" />
                    <p className="min-w-0 flex-1 text-sm leading-snug">
                        <strong>El pago de Meta parece arreglado en {nombresDe(porConfirmar)}.</strong>{' '}
                        Lo confirmaremos en cuanto Meta entregue la próxima plantilla; si vuelve a rechazar un envío
                        por pago, la alerta reaparecerá.
                    </p>
                    <Link
                        href={`/instances/pago-en-meta?linea=${porConfirmar[0].id}`}
                        className="shrink-0 text-sm font-semibold text-primary underline-offset-2 hover:underline"
                    >
                        Ver la guía
                    </Link>
                </div>
            </div>
        );
    }

    // El motivo real de Meta, no siempre «no tienes tarjeta»: con la tarjeta
    // asociada y un cobro rechazado por saldo, decir eso hizo que una empresa
    // desconfiara del aviso entero (3-oct-2026).
    const titulo = rojas[0]?.titulo ?? 'Tu cuenta de Meta no tiene un método de pago válido';
    const pendiente = rojas.some(l => l.problema === 'pago_pendiente');

    return (
        <div role="alert" className="mx-3 mt-3 sm:mx-4">
            <div className="flex flex-col gap-3 rounded-xl border border-destructive/40 bg-destructive px-4 py-3 text-white shadow-sm sm:flex-row sm:items-center">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15">
                    <CreditCard className="size-5" />
                </span>
                <div className="min-w-0 flex-1 text-sm leading-snug">
                    <p className="font-bold">Tus mensajes de WhatsApp no están saliendo</p>
                    <p className="text-white/90">
                        Meta rechaza los envíos de {nombresDe(rojas)}: {titulo.charAt(0).toLowerCase() + titulo.slice(1)}.{' '}
                        {pendiente
                            ? 'Tu tarjeta sigue asociada; lo que falta es pagar lo consumido.'
                            : 'Hay que arreglarlo en la facturación de Meta.'}
                    </p>
                </div>
                <Link
                    href={`/instances/pago-en-meta?linea=${rojas[0].id}`}
                    className="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-lg bg-white px-4 text-sm font-bold text-destructive transition-opacity hover:opacity-90"
                >
                    {pendiente ? 'Ver el detalle y lo consumido' : 'Arreglarlo paso a paso'} <ArrowRight className="size-4" />
                </Link>
            </div>
        </div>
    );
}
