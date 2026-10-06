import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Receipt, AlertTriangle, MessageSquare } from 'lucide-react';
import ComprobanteDePagoFila from '@/components/ComprobanteDePagoFila';

/**
 * Las capturas de pago que mandaron los clientes, leídas por el modelo.
 *
 * Aquí se ven todas juntas; se aprueban desde el chat, al lado de la foto y de
 * la conversación, que es donde se ve si el cliente es quien dice ser.
 */

const PESTANAS = [
    { key: 'abiertos', label: 'Por aprobar', cuenta: c => (c.pendiente ?? 0) + (c.revisar ?? 0) },
    { key: 'aprobado', label: 'Aprobados', cuenta: c => c.aprobado ?? 0 },
    { key: 'rechazado', label: 'Rechazados', cuenta: c => c.rechazado ?? 0 },
    { key: 'todos', label: 'Todos', cuenta: null },
];

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
    const conv = c.conversacion;
    const chat = conv ? `/chat?conversation=${conv.id}&instance=${conv.instance_id}` : null;

    return (
        <ComprobanteDePagoFila
            c={c}
            accion={chat && (
                <Link
                    href={chat}
                    className="self-center shrink-0 rounded-lg border border-border/70 px-3 py-2 text-sm font-medium text-foreground hover:bg-muted/40 transition-colors inline-flex items-center gap-1.5"
                >
                    <MessageSquare className="size-4" />
                    <span className="hidden sm:inline">{['pendiente', 'revisar'].includes(c.estado) ? 'Aprobar en el chat' : 'Ver chat'}</span>
                </Link>
            )}
        />
    );
}

PagosPorAprobar.layout = page => <AppLayout breadcrumb={['Pagos por aprobar']}>{page}</AppLayout>;
