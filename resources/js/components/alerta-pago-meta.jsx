import { Link, usePage } from '@inertiajs/react';
import { CreditCard, ArrowRight } from 'lucide-react';

/**
 * La alerta roja de «Meta no está cobrando: no salen tus mensajes».
 *
 * En el layout, arriba de cualquier pantalla, porque mientras dura no sale ni
 * una factura y el cliente sólo ve «Fallido» mensaje a mensaje sin saber por
 * qué (JHeda, 30-sep-2026). Un aviso en Instancias no basta: nadie entra ahí
 * cuando lo que falla es la facturación.
 *
 * No se puede cerrar a propósito. Se apaga sola cuando sale una plantilla o
 * cuando el botón de la guía confirma con Meta que ya hay tarjeta.
 *
 * `?.` en todo: Inertia 3 llama al layout dos veces y en la primera las props
 * compartidas pueden no estar.
 */
export default function AlertaPagoMeta() {
    const { alertaPagoMeta } = usePage().props ?? {};
    const lineas = Array.isArray(alertaPagoMeta) ? alertaPagoMeta : [];

    if (lineas.length === 0) return null;

    // En la propia guía ya se explica todo: repetir la alerta encima es ruido.
    if (typeof window !== 'undefined' && window.location.pathname.startsWith('/instances/pago-en-meta')) {
        return null;
    }

    const nombres = lineas.map(l => `${l.nombre}${l.numero ? ` (${l.numero})` : ''}`).join(', ');
    const sinMoneda = lineas.some(l => l.problema === 'sin_moneda');

    return (
        <div role="alert" className="mx-3 mt-3 sm:mx-4">
            <div className="flex flex-col gap-3 rounded-xl border border-destructive/40 bg-destructive px-4 py-3 text-white shadow-sm sm:flex-row sm:items-center">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15">
                    <CreditCard className="size-5" />
                </span>
                <div className="min-w-0 flex-1 text-sm leading-snug">
                    <p className="font-bold">Tus mensajes de WhatsApp no están saliendo</p>
                    <p className="text-white/90">
                        Meta rechaza los envíos de {nombres} porque tu cuenta no tiene{' '}
                        {sinMoneda ? 'moneda ni tarjeta configuradas' : 'un método de pago válido'}.
                        Hay que asociar una tarjeta en Meta: son cinco minutos.
                    </p>
                </div>
                <Link
                    href={`/instances/pago-en-meta?linea=${lineas[0].id}`}
                    className="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-lg bg-white px-4 text-sm font-bold text-destructive transition-opacity hover:opacity-90"
                >
                    Arreglarlo paso a paso <ArrowRight className="size-4" />
                </Link>
            </div>
        </div>
    );
}
