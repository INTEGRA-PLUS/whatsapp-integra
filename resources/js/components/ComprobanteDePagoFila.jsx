import { Receipt, AlertTriangle, CheckCircle2, XCircle, Clock } from 'lucide-react';

/**
 * Un comprobante de pago leído por el modelo, en una fila.
 *
 * Lo pintan la bandeja «Pagos por aprobar» y el modal de `/pendientes` del
 * chat, y tienen que verse igual: el asesor que aprueba desde uno reconoce el
 * mismo comprobante en el otro. Lo de la derecha cambia —un enlace al chat en
 * la bandeja, abrir la conversación en el modal—, así que va en `accion`.
 */

export const ESTADOS_COMPROBANTE = {
    pendiente: { label: 'Por aprobar', cls: 'bg-warning/15 text-warning', Icon: Clock },
    aprobando: { label: 'Aprobando…', cls: 'bg-sky-500/15 text-sky-700 dark:text-sky-400', Icon: Clock },
    revisar:   { label: 'Revisar en Integra', cls: 'bg-destructive/15 text-destructive', Icon: AlertTriangle },
    aprobado:  { label: 'Aprobado', cls: 'bg-success/15 text-success', Icon: CheckCircle2 },
    rechazado: { label: 'Rechazado', cls: 'bg-black/5 dark:bg-white/10 text-muted-foreground', Icon: XCircle },
};

export function pesos(v) {
    return v == null ? null : '$' + Number(v).toLocaleString('es-CO');
}

export default function ComprobanteDePagoFila({ c, accion = null, compacta = false }) {
    const e = ESTADOS_COMPROBANTE[c.estado] ?? ESTADOS_COMPROBANTE.pendiente;
    const conv = c.conversacion;
    const quien = conv?.name || conv?.phone_number || 'Cliente';
    const miniatura = compacta ? 'size-14' : 'size-16 sm:size-20';

    return (
        <div className={`rounded-xl border bg-card flex gap-3 ${compacta ? 'p-3' : 'p-3 sm:p-4 sm:gap-4'}`}>
            {c.mensaje?.media_url ? (
                <a href={c.mensaje.media_url} target="_blank" rel="noreferrer" className="shrink-0">
                    <img src={c.mensaje.media_url} alt="Comprobante" className={`${miniatura} rounded-lg object-cover border bg-muted`} />
                </a>
            ) : (
                <div className={`${miniatura} rounded-lg border bg-muted flex items-center justify-center shrink-0`}>
                    <Receipt className="size-6 text-muted-foreground" />
                </div>
            )}

            <div className="min-w-0 flex-1 space-y-1.5">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-base font-bold tabular-nums text-foreground">{pesos(c.monto) ?? 'Valor sin leer'}</span>
                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ${e.cls}`}>
                        <e.Icon className="size-3" /> {e.label}
                    </span>
                    {c.duplicado_de_id && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-destructive/10 text-destructive px-2 py-0.5 text-[11px] font-semibold">
                            <AlertTriangle className="size-3" /> Repetido de #{c.duplicado_de_id}
                        </span>
                    )}
                </div>
                <p className="text-sm text-foreground truncate">{quien}</p>
                <p className="text-xs text-muted-foreground truncate">
                    {[c.banco, c.fecha, c.referencia && `ref. ${c.referencia}`].filter(Boolean).join(' · ') || 'Sin datos leídos'}
                </p>
                {c.estado === 'aprobado' && (
                    <p className="text-xs text-success">
                        {pesos(c.monto_aprobado)} a {c.factura_codigo ?? `la factura #${c.factura_id}`}
                        {c.resultado?.recibo_caja != null && ` · recibo #${c.resultado.recibo_caja}`}
                        {c.revisor?.name && ` · ${c.revisor.name}`}
                    </p>
                )}
                {c.estado === 'rechazado' && (
                    <p className="text-xs text-muted-foreground truncate">
                        {c.revisor?.name && `${c.revisor.name}: `}{c.motivo_rechazo}
                    </p>
                )}
                {c.error && ['pendiente', 'revisar'].includes(c.estado) && (
                    <p className="text-xs text-destructive">{c.error}</p>
                )}
            </div>

            {accion}
        </div>
    );
}
