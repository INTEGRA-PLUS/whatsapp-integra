import { useEffect, useRef, useState } from 'react';
import { clsx } from 'clsx';
import { Sparkles, X as XIcon, Loader2, RefreshCw, Copy, Check, AlertTriangle } from 'lucide-react';

/**
 * El resumen de la conversación, en un modal.
 *
 * Antes era una franja encima del hilo. Funcionaba, pero tenía dos problemas de
 * los que sólo se ven usándolo: empujaba los mensajes hacia abajo —justo los que
 * estabas leyendo— y competía con ellos por la atención, porque quedaba al lado
 * de las burbujas con el mismo peso visual.
 *
 * Aquí se lee entero, se cierra, y el hilo no se ha movido.
 *
 * ## Por qué los pendientes pesan más que los puntos
 *
 * No son dos listas del mismo tipo. «Puntos clave» es contexto: qué pasó. Los
 * pendientes son **deuda con el cliente**, y son la razón por la que alguien
 * pide un resumen antes de contestar. Pintarlos igual que los puntos —que es lo
 * que hacía la franja— los escondía en la lectura rápida, que es la única que
 * se hace cuando tienes a alguien esperando.
 */
export function ResumenDialog({
    open,
    contacto,
    resumen,
    cargando,
    error,
    onRehacer,
    onCerrar,
}) {
    const cerrarRef = useRef(null);
    const [copiado, setCopiado] = useState(false);

    // Escape en captura: el chat también lo escucha para deseleccionar la
    // conversación abierta, y aquí tiene que ganar el modal. Mismo motivo que
    // en ConfirmDialog.
    useEffect(() => {
        if (!open) return;

        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                onCerrar?.();
            }
        }

        document.addEventListener('keydown', onKey, true);
        return () => document.removeEventListener('keydown', onKey, true);
    }, [open, onCerrar]);

    useEffect(() => {
        if (open) cerrarRef.current?.focus();
    }, [open]);

    // El «copiado» vuelve solo a su sitio: un botón que se queda en verde para
    // siempre hace dudar de si la segunda copia funcionó.
    useEffect(() => {
        if (!copiado) return;
        const t = setTimeout(() => setCopiado(false), 2000);
        return () => clearTimeout(t);
    }, [copiado]);

    if (!open) return null;

    const puntos = resumen?.puntos ?? [];
    const pendientes = resumen?.pendientes ?? [];

    async function copiar() {
        try {
            await navigator.clipboard.writeText(comoTexto(contacto, resumen));
            setCopiado(true);
        } catch {
            // Sin portapapeles (http, permiso denegado) no se avisa: el resumen
            // está en pantalla y se puede seleccionar a mano.
        }
    }

    return (
        <div
            className="fixed inset-0 z-[200] flex items-center justify-center bg-black/70 p-4 animate-in fade-in duration-150"
            onClick={onCerrar}
            role="dialog"
            aria-modal="true"
            aria-label="Resumen de la conversación"
        >
            <div
                className="flex w-full max-w-2xl max-h-[85vh] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl animate-in zoom-in-105 duration-150 dark:bg-[#202c33]"
                onClick={e => e.stopPropagation()}
            >
                {/* Cabecera. Lleva el nombre del contacto porque el modal tapa
                    el chat: sin él no sabes de quién estás leyendo el resumen. */}
                <div className="flex items-start gap-3 border-b border-black/5 px-5 py-4 dark:border-white/5">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-info/15 text-info">
                        <Sparkles className="size-5" />
                    </div>

                    <div className="min-w-0 flex-1">
                        <h2 className="text-[15px] font-bold leading-snug text-foreground">
                            Resumen de la conversación
                        </h2>
                        <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                            {contacto || 'Conversación'}
                            {resumen?.generado_en && (
                                <span className="text-muted-foreground/70">
                                    {' · '}{cuandoSeHizo(resumen.generado_en)}
                                </span>
                            )}
                        </p>
                    </div>

                    <button
                        ref={cerrarRef}
                        onClick={onCerrar}
                        aria-label="Cerrar el resumen"
                        className="-m-1 shrink-0 rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-black/5 dark:hover:bg-white/10"
                    >
                        <XIcon className="size-4" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4 custom-scrollbar">
                    {cargando && <Esqueleto />}

                    {error && !cargando && (
                        <div className="flex items-start gap-2.5 rounded-xl border border-destructive/25 bg-destructive/5 px-4 py-3">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
                            <p className="text-[13px] leading-relaxed text-foreground">{error}</p>
                        </div>
                    )}

                    {resumen && !cargando && !error && (
                        <div className="space-y-5">
                            {/* El resumen en grande: es lo que se lee si sólo se
                                leen tres segundos. */}
                            <p className="text-[15px] leading-relaxed text-foreground">
                                {resumen.resumen}
                            </p>

                            {puntos.length > 0 && (
                                <section>
                                    <Rotulo texto="Puntos clave" cuantos={puntos.length} />
                                    <ul className="mt-2 space-y-1.5 border-l-2 border-info/25 pl-3.5">
                                        {puntos.map((p, i) => (
                                            <li key={i} className="text-[13px] leading-relaxed text-muted-foreground">
                                                {p}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}

                            {pendientes.length > 0 && (
                                <section>
                                    <Rotulo texto="Queda pendiente" cuantos={pendientes.length} alerta />
                                    <ul className="mt-2 space-y-1.5">
                                        {pendientes.map((p, i) => (
                                            <li
                                                key={i}
                                                className="flex gap-2.5 rounded-lg bg-warning/10 px-3 py-2 text-[13px] leading-relaxed text-foreground"
                                            >
                                                <span className="mt-[7px] size-1.5 shrink-0 rounded-full bg-warning" />
                                                {p}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                        </div>
                    )}
                </div>

                {/* Pie. El aviso de que lo escribió un modelo va aquí y no al
                    final del texto: ahí se lee como parte del resumen. */}
                <div className="flex flex-wrap items-center gap-2 border-t border-black/5 bg-black/[0.02] px-5 py-3 dark:border-white/5 dark:bg-white/[0.02]">
                    <p className="min-w-0 flex-1 text-[11px] leading-snug text-muted-foreground/70">
                        Generado por IA a partir de los mensajes. Puede tener errores.
                    </p>

                    {resumen && !cargando && (
                        <>
                            <button
                                onClick={copiar}
                                className="flex h-8 items-center gap-1.5 rounded-lg px-3 text-[12px] font-bold text-muted-foreground transition-colors hover:bg-black/5 dark:hover:bg-white/10"
                            >
                                {copiado
                                    ? <><Check className="size-3.5 text-success" /> Copiado</>
                                    : <><Copy className="size-3.5" /> Copiar</>}
                            </button>

                            <button
                                onClick={onRehacer}
                                title="Volver a generarlo con el modelo"
                                className="flex h-8 items-center gap-1.5 rounded-lg px-3 text-[12px] font-bold text-info transition-colors hover:bg-info/10"
                            >
                                <RefreshCw className="size-3.5" /> Rehacer
                            </button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

function Rotulo({ texto, cuantos, alerta }) {
    return (
        <div className="flex items-center gap-2">
            <p className={clsx(
                'text-[10px] font-black uppercase tracking-widest',
                alerta ? 'text-warning' : 'text-muted-foreground/60'
            )}>
                {texto}
            </p>
            <span className="text-[10px] font-bold tabular-nums text-muted-foreground/50">{cuantos}</span>
        </div>
    );
}

/**
 * Mientras se piensa, la forma de lo que va a llegar.
 *
 * Un «Leyendo la conversación…» de una línea hacía que el modal diera un salto
 * al llegar la respuesta. Con la silueta puesta, el contenido aparece donde ya
 * estabas mirando.
 */
function Esqueleto() {
    return (
        <div className="space-y-5" aria-busy="true">
            <div className="flex items-center gap-2 text-[12px] text-muted-foreground">
                <Loader2 className="size-3.5 animate-spin" /> Leyendo la conversación…
            </div>

            <div className="space-y-2">
                {['100%', '96%', '72%'].map((w, i) => (
                    <div key={i} className="h-3.5 animate-pulse rounded bg-muted" style={{ width: w }} />
                ))}
            </div>

            <div className="space-y-2 border-l-2 border-info/15 pl-3.5">
                {['88%', '80%'].map((w, i) => (
                    <div key={i} className="h-3 animate-pulse rounded bg-muted" style={{ width: w }} />
                ))}
            </div>
        </div>
    );
}

/** «hace 5 min», para saber si el resumen es de antes del último mensaje. */
function cuandoSeHizo(iso) {
    const cuando = new Date(iso);
    if (Number.isNaN(cuando.getTime())) return null;

    const min = Math.round((Date.now() - cuando.getTime()) / 60000);

    if (min < 1) return 'recién hecho';
    if (min < 60) return `hace ${min} min`;
    if (min < 1440) return `hace ${Math.round(min / 60)} h`;

    return cuando.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' });
}

/** El resumen como texto plano, para pegarlo en un ticket o un correo. */
function comoTexto(contacto, resumen) {
    const partes = [`Resumen de la conversación con ${contacto || 'el cliente'}`, '', resumen?.resumen ?? ''];

    if (resumen?.puntos?.length) {
        partes.push('', 'Puntos clave:', ...resumen.puntos.map(p => `- ${p}`));
    }

    if (resumen?.pendientes?.length) {
        partes.push('', 'Queda pendiente:', ...resumen.pendientes.map(p => `- ${p}`));
    }

    return partes.join('\n');
}
