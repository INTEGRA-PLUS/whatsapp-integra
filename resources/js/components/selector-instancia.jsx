import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Search, Inbox } from 'lucide-react';
import { clsx } from 'clsx';
import { LogoCanal, canalDe } from '@/components/logo-canal';

/**
 * Elegir por qué línea se está trabajando.
 *
 * Era un `<select>` nativo con `max-w-[140px]`: el nombre salía cortado
 * («@INTEGRACOLOMB…»), en mayúsculas forzadas, y no decía **por qué canal**
 * habla esa línea. Con WhatsApp, Instagram y Messenger en la misma cuenta eso
 * deja de ser un detalle: son bandejas distintas y el agente tiene que saber
 * en cuál está antes de escribir.
 *
 * Ahora cada línea se ve con el logo de su canal, su nombre completo y el
 * número o el usuario debajo; y si hay varios canales, se agrupan por canal.
 */

/** Lo que identifica a la línea por debajo del nombre. */
function detalleDe(instancia) {
    const canal = instancia.channel ?? 'whatsapp';

    if (canal === 'whatsapp') {
        return instancia.display_phone_number
            || instancia.meta?.verified_name
            || instancia.phone_number_id
            || null;
    }

    // En Instagram y Messenger el nombre ya suele ser el usuario; si hay un
    // nombre verificado distinto, es el de la página y ayuda a distinguirlas.
    return instancia.meta?.verified_name ?? null;
}

const ORDEN_DE_CANALES = ['whatsapp', 'instagram', 'messenger'];

export default function SelectorInstancia({ instancias, valor, onCambio }) {
    const [abierto, setAbierto] = useState(false);
    const [texto, setTexto] = useState('');
    const [sitio, setSitio] = useState(null);
    const disparadorRef = useRef(null);
    const panelRef = useRef(null);

    const elegida = instancias.find(i => String(i.id) === String(valor)) ?? null;

    // Con una sola línea el buscador sobra; con quince es imprescindible.
    const conBuscador = instancias.length > 6;

    const visibles = useMemo(() => {
        const q = texto.trim().toLowerCase();
        if (!q) return instancias;
        return instancias.filter(i =>
            (i.name ?? '').toLowerCase().includes(q)
            || (detalleDe(i) ?? '').toLowerCase().includes(q)
            || canalDe(i).nombre.toLowerCase().includes(q)
        );
    }, [instancias, texto]);

    const porCanal = useMemo(() => {
        const mapa = new Map();
        for (const i of visibles) {
            const canal = i.channel ?? 'whatsapp';
            if (!mapa.has(canal)) mapa.set(canal, []);
            mapa.get(canal).push(i);
        }
        return [...mapa.entries()].sort(
            (a, b) => ORDEN_DE_CANALES.indexOf(a[0]) - ORDEN_DE_CANALES.indexOf(b[0])
        );
    }, [visibles]);

    // Sólo se encabeza por canal si hay más de uno: con una sola bandeja el
    // título «WhatsApp» encima de todo es ruido.
    const variosCanales = new Set(instancias.map(i => i.channel ?? 'whatsapp')).size > 1;

    // El panel vive en un portal: la barra superior recorta lo que sobresale.
    useEffect(() => {
        if (!abierto) { setSitio(null); return; }

        const medir = () => {
            const r = disparadorRef.current?.getBoundingClientRect();
            if (!r) return;
            const ancho = 320;
            setSitio({
                // Alineado por la derecha, que es donde vive el disparador.
                left: Math.max(8, Math.min(r.right - ancho, window.innerWidth - ancho - 8)),
                top: r.bottom + 8,
                ancho,
            });
        };

        medir();
        window.addEventListener('resize', medir);
        document.addEventListener('scroll', medir, true);
        return () => {
            window.removeEventListener('resize', medir);
            document.removeEventListener('scroll', medir, true);
        };
    }, [abierto]);

    useEffect(() => {
        if (!abierto) return;

        const fuera = e => {
            if (disparadorRef.current?.contains(e.target)) return;
            if (panelRef.current?.contains(e.target)) return;
            setAbierto(false);
        };
        const escape = e => { if (e.key === 'Escape') setAbierto(false); };

        document.addEventListener('mousedown', fuera);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('mousedown', fuera);
            document.removeEventListener('keydown', escape);
        };
    }, [abierto]);

    const elegir = id => {
        onCambio(String(id));
        setAbierto(false);
        setTexto('');
    };

    return (
        <>
            <button
                ref={disparadorRef}
                type="button"
                onClick={() => setAbierto(a => !a)}
                aria-expanded={abierto}
                aria-haspopup="listbox"
                className={clsx(
                    'flex h-8 min-w-0 items-center gap-2 rounded-lg border border-border/10 px-2 transition-colors',
                    'bg-background/50 hover:border-border/30 dark:bg-black/20',
                )}
            >
                {elegida ? (
                    <>
                        <LogoCanal instancia={elegida} className="size-5" apagado={!elegida.active} />
                        <span className="flex min-w-0 flex-col items-start leading-none">
                            <span className="max-w-[10rem] truncate text-[11.5px] font-black text-foreground">
                                {elegida.name || 'Sin nombre'}
                            </span>
                            {detalleDe(elegida) && (
                                <span className="max-w-[10rem] truncate text-[9.5px] font-bold text-muted-foreground">
                                    {detalleDe(elegida)}
                                </span>
                            )}
                        </span>
                    </>
                ) : (
                    <>
                        <Inbox className="size-4 text-muted-foreground" />
                        <span className="text-[11.5px] font-black text-muted-foreground">Elegir línea</span>
                    </>
                )}
                <ChevronDown className={clsx('size-3.5 shrink-0 text-muted-foreground transition-transform', abierto && 'rotate-180')} />
            </button>

            {abierto && createPortal(
                <div
                    ref={panelRef}
                    role="listbox"
                    style={{
                        left: sitio?.left ?? 0,
                        top: sitio?.top ?? 0,
                        width: sitio?.ancho ?? 320,
                        visibility: sitio ? 'visible' : 'hidden',
                    }}
                    className="animate-in fade-in slide-in-from-top-1 fixed z-[80] max-w-[calc(100vw-1rem)] overflow-hidden rounded-2xl border border-border bg-card shadow-2xl duration-150"
                >
                    {conBuscador && (
                        <div className="flex items-center gap-2 border-b border-border px-3 py-2 focus-within:border-primary/40 focus-within:bg-primary/5">
                            <Search className="size-3.5 shrink-0 text-muted-foreground" />
                            <input
                                autoFocus
                                value={texto}
                                onChange={e => setTexto(e.target.value)}
                                placeholder="Buscar una línea…"
                                className="w-full border-none bg-transparent p-0 text-[12.5px] text-foreground outline-none placeholder:text-muted-foreground focus:ring-0"
                            />
                        </div>
                    )}

                    <div className="max-h-80 overflow-y-auto py-1">
                        {visibles.length === 0 && (
                            <p className="px-3 py-5 text-center text-[11.5px] text-muted-foreground">
                                {instancias.length === 0
                                    ? 'Todavía no hay ninguna línea conectada'
                                    : 'Ninguna línea coincide'}
                            </p>
                        )}

                        {porCanal.map(([canal, lineas]) => (
                            <div key={canal}>
                                {variosCanales && (
                                    <p className="px-3 pb-1 pt-2 text-[9px] font-black uppercase tracking-widest text-muted-foreground">
                                        {canalDe({ channel: canal }).nombre}
                                    </p>
                                )}

                                {lineas.map(inst => {
                                    const puesta = String(inst.id) === String(valor);
                                    const detalle = detalleDe(inst);

                                    return (
                                        <button
                                            key={inst.id}
                                            type="button"
                                            role="option"
                                            aria-selected={puesta}
                                            onClick={() => elegir(inst.id)}
                                            className={clsx(
                                                'flex w-full items-center gap-2.5 px-2.5 py-2 text-left transition-colors hover:bg-muted',
                                                puesta && 'bg-primary/10',
                                            )}
                                        >
                                            <LogoCanal instancia={inst} className="size-8" apagado={!inst.active} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[12.5px] font-bold text-foreground">
                                                    {inst.name || 'Sin nombre'}
                                                </span>
                                                {detalle && (
                                                    <span className="block truncate text-[11px] text-muted-foreground">{detalle}</span>
                                                )}
                                            </span>
                                            {/* Una línea apagada se puede elegir, pero se avisa: si no,
                                                el agente escribe y el mensaje no sale. */}
                                            {!inst.active && (
                                                <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider text-muted-foreground">
                                                    Inactiva
                                                </span>
                                            )}
                                            {puesta && <Check className="size-4 shrink-0 text-accent-foreground" />}
                                        </button>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </div>,
                document.body,
            )}
        </>
    );
}
