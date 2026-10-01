import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Receipt, AlertTriangle, MessageSquare, CheckCircle2, XCircle, Clock } from 'lucide-react';

/**
 * Las capturas de pago que mandaron los clientes, leídas por el modelo.
 *
 * Aquí se ven todas juntas; se aprueban desde el chat, al lado de la foto y de
 * la conversación, que es donde se ve si el cliente es quien dice ser.
 */

const ESTADOS = {
    pendiente: { label: 'Por aprobar', cls: 'bg-warning/15 text-warning', Icon: Clock },
    aprobando: { label: 'Aprobando…', cls: 'bg-sky-500/15 text-sky-700 dark:text-sky-400', Icon: Clock },
    revisar:   { label: 'Revisar en Integra', cls: 'bg-destructive/15 text-destructive', Icon: AlertTriangle },
    aprobado:  { label: 'Aprobado', cls: 'bg-success/15 text-success', Icon: CheckCircle2 },
    rechazado: { label: 'Rechazado', cls: 'bg-black/5 dark:bg-white/10 text-muted-foreground', Icon: XCircle },
};

const PESTANAS = [
    { key: 'abiertos', label: 'Por aprobar', cuenta: c => (c.pendiente ?? 0) + (c.revisar ?? 0) },
    { key: 'aprobado', label: 'Aprobados', cuenta: c => c.aprobado ?? 0 },
    { key: 'rechazado', label: 'Rechazados', cuenta: c => c.rechazado ?? 0 },
    { key: 'todos', label: 'Todos', cuenta: null },
];

function pesos(v) {
    return v == null ? null : '$' + Number(v).toLocaleString('es-CO');
}

export default function PagosPorAprobar({ comprobantes = [], estado = 'abiertos', conteos = {}, leyendo = false, vision = false }) {
    return (
        <>
            <Head title="Pagos por aprobar" />
            <div className="flex flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <div className="flex items-center gap-3">
                    <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <Receipt className="size-6" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Pagos por aprobar</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            Capturas de pago que mandaron los clientes por WhatsApp. Ninguna se registra en Integra hasta que alguien la aprueba.
                        </p>
                    </div>
                </div>

                {(!leyendo || !vision) && (
                    <div className="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-foreground flex items-start gap-2.5">
                        <AlertTriangle className="size-4 text-warning mt-0.5 shrink-0" />
                        <div>
                            {!vision
                                ? 'El modelo de visión no está configurado en este servidor, así que no se está leyendo ninguna foto.'
                                : 'La lectura de comprobantes está apagada: las fotos que lleguen no se revisan.'}
                            {vision && (
                                <> Actívala en <Link href={route('integrations.index')} className="font-medium underline">Integraciones → Pagos a facturas</Link>.</>
                            )}
                        </div>
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
                    {PESTANAS.map(p => {
                        const activa = p.key === estado;
                        const n = p.cuenta?.(conteos);
                        return (
                            <button
                                key={p.key}
                                type="button"
                                onClick={() => router.get(route('pagos-por-aprobar.index'), { estado: p.key }, { preserveScroll: true })}
                                className={`rounded-full border px-3.5 py-1.5 text-sm transition-colors ${
                                    activa ? 'border-primary/40 bg-primary/10 text-foreground font-medium' : 'border-border/70 text-muted-foreground hover:bg-muted/40'
                                }`}
                            >
                                {p.label}{n != null && <span className="ml-1.5 tabular-nums opacity-70">{n}</span>}
                            </button>
                        );
                    })}
                </div>

                {comprobantes.length === 0 ? (
                    <div className="rounded-xl border border-dashed px-4 py-12 text-center text-sm text-muted-foreground">
                        {estado === 'abiertos' ? 'No hay comprobantes esperando aprobación.' : 'No hay comprobantes en esta lista.'}
                    </div>
                ) : (
                    <div className="grid gap-3">
                        {comprobantes.map(c => <Fila key={c.id} c={c} />)}
                    </div>
                )}
            </div>
        </>
    );
}

function Fila({ c }) {
    const e = ESTADOS[c.estado] ?? ESTADOS.pendiente;
    const conv = c.conversacion;
    const quien = conv?.name || conv?.phone_number || 'Cliente';
    const chat = conv ? `/chat?conversation=${conv.id}&instance=${conv.instance_id}` : null;

    return (
        <div className="rounded-xl border bg-card p-3 sm:p-4 flex gap-3 sm:gap-4">
            {c.mensaje?.media_url ? (
                <a href={c.mensaje.media_url} target="_blank" rel="noreferrer" className="shrink-0">
                    <img src={c.mensaje.media_url} alt="Comprobante" className="size-16 sm:size-20 rounded-lg object-cover border bg-muted" />
                </a>
            ) : (
                <div className="size-16 sm:size-20 rounded-lg border bg-muted flex items-center justify-center shrink-0">
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

            {chat && (
                <Link
                    href={chat}
                    className="self-center shrink-0 rounded-lg border border-border/70 px-3 py-2 text-sm font-medium text-foreground hover:bg-muted/40 transition-colors inline-flex items-center gap-1.5"
                >
                    <MessageSquare className="size-4" />
                    <span className="hidden sm:inline">{['pendiente', 'revisar'].includes(c.estado) ? 'Aprobar en el chat' : 'Ver chat'}</span>
                </Link>
            )}
        </div>
    );
}

PagosPorAprobar.layout = page => <AppLayout breadcrumb={['Pagos por aprobar']}>{page}</AppLayout>;
