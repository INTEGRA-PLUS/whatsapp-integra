import { useEffect, useState } from 'react';
import axios from 'axios';
import { Receipt, Loader2, X, AlertCircle, AlertTriangle, MessageSquare, ExternalLink } from 'lucide-react';
import ComprobanteDePagoFila from '@/components/ComprobanteDePagoFila';

/**
 * Los comprobantes que esperan a alguien, desde el chat con `/pendientes`.
 *
 * 2026-10-06: un cliente escribió «me toca nuevamente validar su pago». Las
 * capturas quedan pendientes en la bandeja, pero quien está contestando chats
 * no la tiene abierta, y el cliente acaba reenviando la foto. Esto pone la
 * lista a un comando de distancia, sin salir de la conversación.
 *
 * Sólo lo ve quien tiene `pagos.aprobar` —el chat no ofrece el comando a nadie
 * más— y el endpoint lo vuelve a exigir: esconder el botón no es un permiso.
 * Aprobar sigue siendo en el chat, junto a la foto: aquí sólo se elige cuál.
 */
export default function PagosPendientes({ onCerrar, onAbrir }) {
    const [datos, setDatos] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        let vivo = true;

        axios.get('/api/comprobantes-de-pago/pendientes')
            .then(({ data }) => { if (vivo) setDatos(data); })
            .catch(err => {
                if (vivo) setError(err?.response?.data?.message ?? 'No se pudieron cargar los pagos por aprobar.');
            });

        return () => { vivo = false; };
    }, []);

    useEffect(() => {
        const alPulsar = e => { if (e.key === 'Escape') onCerrar(); };
        document.addEventListener('keydown', alPulsar);
        return () => document.removeEventListener('keydown', alPulsar);
    }, [onCerrar]);

    const comprobantes = datos?.comprobantes ?? [];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onCerrar}>
            <div
                role="dialog"
                aria-label="Pagos por aprobar"
                className="flex max-h-[85svh] w-full max-w-xl flex-col rounded-xl border bg-card shadow-2xl"
                onClick={e => e.stopPropagation()}
            >
                <div className="flex items-start justify-between gap-3 border-b px-5 py-4">
                    <div>
                        <h2 className="flex items-center gap-2 font-semibold text-foreground">
                            <Receipt className="size-4 text-primary" /> Pagos por aprobar
                            {datos && <span className="tabular-nums text-sm font-normal text-muted-foreground">{comprobantes.length}</span>}
                        </h2>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Ninguno se registra en Integra hasta que alguien lo aprueba en su chat.
                        </p>
                    </div>
                    <button onClick={onCerrar} aria-label="Cerrar" className="rounded-md p-1 text-muted-foreground hover:bg-muted">
                        <X className="size-4" />
                    </button>
                </div>

                <div className="flex-1 space-y-2.5 overflow-y-auto px-5 py-4">
                    {datos && (!datos.leyendo || !datos.vision) && (
                        <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-foreground">
                            <AlertTriangle className="mt-px size-3.5 shrink-0 text-warning" />
                            {!datos.vision
                                ? 'El modelo de visión no está configurado en este servidor: no se está leyendo ninguna foto.'
                                : 'La lectura de comprobantes está apagada (Integraciones → Pagos a facturas): las fotos que lleguen no se revisan.'}
                        </p>
                    )}

                    {error ? (
                        <p className="flex items-center gap-2 text-sm text-destructive">
                            <AlertCircle className="size-4" /> {error}
                        </p>
                    ) : datos === null ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" /> Buscando los pendientes…
                        </p>
                    ) : comprobantes.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            No hay comprobantes esperando aprobación.
                        </p>
                    ) : (
                        comprobantes.map(c => (
                            <ComprobanteDePagoFila
                                key={c.id}
                                c={c}
                                compacta
                                accion={c.conversacion && (
                                    <button
                                        type="button"
                                        onClick={() => { onAbrir(c.conversacion); onCerrar(); }}
                                        className="inline-flex shrink-0 items-center gap-1.5 self-center rounded-lg border border-border/70 px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted/40"
                                    >
                                        <MessageSquare className="size-4" />
                                        <span className="hidden sm:inline">Abrir chat</span>
                                    </button>
                                )}
                            />
                        ))
                    )}
                </div>

                <div className="border-t px-5 py-3">
                    <a
                        href={route('pagos-por-aprobar.index')}
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                    >
                        <ExternalLink className="size-3.5" /> Ver la bandeja completa, con aprobados y rechazados
                    </a>
                </div>
            </div>
        </div>
    );
}
