import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Loader2, Search, X } from 'lucide-react';
import { clsx } from 'clsx';

/**
 * Los desplegables de filtro del tablero.
 *
 * No usan el `DropdownMenu` de Radix a propósito: ese componente se queda con
 * el teclado para su propia navegación por letras, y un campo de búsqueda
 * dentro pierde las pulsaciones. Aquí el panel es un div posicionado con su
 * propio cierre al hacer clic fuera y con Escape, que es todo lo que hace falta.
 */

/** Cierra el panel al hacer clic fuera o al pulsar Escape. */
function useCierreExterno(abierto, cerrar) {
    const cajaRef = useRef(null);

    useEffect(() => {
        if (!abierto) return;

        const fuera = e => {
            if (cajaRef.current && !cajaRef.current.contains(e.target)) cerrar();
        };
        const escape = e => {
            if (e.key === 'Escape') { e.stopPropagation(); cerrar(); }
        };

        // En `mousedown` y no en `click`: si se espera al click, abrir un
        // desplegable pulsando sobre otro cierra los dos.
        document.addEventListener('mousedown', fuera);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('mousedown', fuera);
            document.removeEventListener('keydown', escape);
        };
    }, [abierto, cerrar]);

    return cajaRef;
}

function Disparador({ icono: Icono, etiqueta, resumen, activo, abierto, onClick, onLimpiar }) {
    return (
        <div
            className={clsx(
                'group flex items-stretch rounded-2xl border transition-all',
                activo
                    ? 'border-primary/40 bg-primary/10 dark:bg-primary/15'
                    : 'border-border bg-card hover:border-primary/30',
            )}
        >
            <button
                type="button"
                onClick={onClick}
                aria-expanded={abierto}
                className="flex min-w-0 items-center gap-2 px-3 py-2"
            >
                {Icono && (
                    <Icono className={clsx('size-3.5 shrink-0', activo ? 'text-accent-foreground' : 'text-muted-foreground')} />
                )}
                <span className="flex min-w-0 flex-col items-start">
                    <span className="text-[9px] font-black uppercase leading-none tracking-widest text-muted-foreground">
                        {etiqueta}
                    </span>
                    <span className={clsx(
                        'mt-0.5 max-w-[13rem] truncate text-[11.5px] font-bold leading-none',
                        activo ? 'text-accent-foreground' : 'text-foreground',
                    )}>
                        {resumen}
                    </span>
                </span>
                <ChevronDown className={clsx(
                    'size-3.5 shrink-0 text-muted-foreground transition-transform',
                    abierto && 'rotate-180',
                )} />
            </button>

            {activo && onLimpiar && (
                <button
                    type="button"
                    onClick={onLimpiar}
                    aria-label={`Quitar el filtro de ${etiqueta.toLowerCase()}`}
                    className="mr-1.5 self-center rounded-lg p-1 text-muted-foreground transition-colors hover:bg-black/5 hover:text-foreground dark:hover:bg-white/10"
                >
                    <X className="size-3.5" />
                </button>
            )}
        </div>
    );
}

function Panel({ children, ancho = 'w-72' }) {
    return (
        <div className={clsx(
            'absolute left-0 top-full z-50 mt-2 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-border bg-card shadow-xl',
            'animate-in fade-in slide-in-from-top-1 duration-150',
            ancho,
        )}>
            {children}
        </div>
    );
}

/**
 * Selección múltiple, opcionalmente por secciones.
 *
 * `opciones`: `[{ valor, texto, ayuda?, seccion? }]`. Dentro de una misma
 * sección las opciones se suman en el servidor (una **o** otra); entre
 * secciones distintas se acumulan. El texto del panel lo dice, porque marcar
 * dos municipios y no ver menos resultados desconcierta.
 */
export function SelectorMultiple({
    etiqueta,
    icono,
    opciones,
    seleccion,
    onCambio,
    buscador = false,
    textoVacio = 'Todos',
    nota,
}) {
    const [abierto, setAbierto] = useState(false);
    const [texto, setTexto] = useState('');
    const cajaRef = useCierreExterno(abierto, () => setAbierto(false));

    const visibles = useMemo(() => {
        const q = texto.trim().toLowerCase();
        if (!q) return opciones;
        return opciones.filter(o => o.texto.toLowerCase().includes(q));
    }, [opciones, texto]);

    const porSeccion = useMemo(() => {
        const mapa = new Map();
        for (const o of visibles) {
            const s = o.seccion ?? '';
            if (!mapa.has(s)) mapa.set(s, []);
            mapa.get(s).push(o);
        }
        return [...mapa.entries()];
    }, [visibles]);

    const elegidas = opciones.filter(o => seleccion.includes(o.valor));
    const resumen = elegidas.length === 0
        ? textoVacio
        : elegidas.length === 1
            ? elegidas[0].texto
            : `${elegidas.length} seleccionados`;

    const alternar = valor => {
        onCambio(seleccion.includes(valor) ? seleccion.filter(v => v !== valor) : [...seleccion, valor]);
    };

    return (
        <div ref={cajaRef} className="relative">
            <Disparador
                icono={icono}
                etiqueta={etiqueta}
                resumen={resumen}
                activo={elegidas.length > 0}
                abierto={abierto}
                onClick={() => setAbierto(a => !a)}
                onLimpiar={() => onCambio([])}
            />

            {abierto && (
                <Panel>
                    {buscador && (
                        <div className="flex items-center gap-2 border-b border-border px-3 py-2 focus-within:border-primary/40 focus-within:bg-primary/5">
                            <Search className="size-3.5 shrink-0 text-muted-foreground" />
                            <input
                                autoFocus
                                value={texto}
                                onChange={e => setTexto(e.target.value)}
                                placeholder="Filtrar…"
                                className="w-full border-none bg-transparent p-0 text-[12.5px] text-foreground outline-none placeholder:text-muted-foreground focus:ring-0 focus-visible:outline-none"
                            />
                        </div>
                    )}

                    <div className="max-h-72 overflow-y-auto py-1">
                        {porSeccion.length === 0 && (
                            <p className="px-3 py-4 text-center text-[11.5px] text-muted-foreground">Nada que coincida</p>
                        )}

                        {porSeccion.map(([seccion, items]) => (
                            <div key={seccion || '__sin__'}>
                                {seccion && (
                                    <p className="px-3 pb-1 pt-2 text-[9px] font-black uppercase tracking-widest text-muted-foreground">
                                        {seccion}
                                    </p>
                                )}
                                {items.map(o => {
                                    const marcada = seleccion.includes(o.valor);
                                    return (
                                        <button
                                            key={o.valor}
                                            type="button"
                                            onClick={() => alternar(o.valor)}
                                            className="flex w-full items-center gap-2.5 px-3 py-1.5 text-left transition-colors hover:bg-muted"
                                        >
                                            <span className={clsx(
                                                'flex size-4 shrink-0 items-center justify-center rounded-md border transition-colors',
                                                marcada ? 'border-primary bg-primary text-primary-foreground' : 'border-border',
                                            )}>
                                                {marcada && <Check className="size-3" />}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[12.5px] font-bold text-foreground">{o.texto}</span>
                                                {o.ayuda && (
                                                    <span className="block truncate text-[11px] text-muted-foreground">{o.ayuda}</span>
                                                )}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        ))}
                    </div>

                    {nota && (
                        <p className="border-t border-border px-3 py-2 text-[10.5px] leading-snug text-muted-foreground">
                            {nota}
                        </p>
                    )}
                </Panel>
            )}
        </div>
    );
}

/**
 * Selección de uno, buscando en el servidor mientras se escribe.
 *
 * `buscar(texto)` devuelve `[{ valor, texto, ayuda? }]`.
 */
export function SelectorBuscador({
    etiqueta,
    icono,
    valor,
    etiquetaDelValor,
    onCambio,
    buscar,
    marcador = 'Escribe un nombre o un número…',
    textoVacio = 'Cualquiera',
}) {
    const [abierto, setAbierto] = useState(false);
    const [texto, setTexto] = useState('');
    const [cargando, setCargando] = useState(false);
    const [resultados, setResultados] = useState([]);
    const cajaRef = useCierreExterno(abierto, () => setAbierto(false));

    // Se espera a que la escritura pare: sin esto, «ISNARDO» son siete
    // peticiones y la primera puede contestar la última.
    useEffect(() => {
        if (!abierto) return;

        let vivo = true;
        setCargando(true);
        const reloj = setTimeout(async () => {
            try {
                const datos = await buscar(texto);
                if (vivo) setResultados(datos);
            } catch {
                if (vivo) setResultados([]);
            } finally {
                if (vivo) setCargando(false);
            }
        }, 300);

        return () => { vivo = false; clearTimeout(reloj); };
    }, [texto, abierto, buscar]);

    return (
        <div ref={cajaRef} className="relative">
            <Disparador
                icono={icono}
                etiqueta={etiqueta}
                resumen={valor ? (etiquetaDelValor ?? 'Uno elegido') : textoVacio}
                activo={!!valor}
                abierto={abierto}
                onClick={() => setAbierto(a => !a)}
                onLimpiar={() => onCambio(null, null)}
            />

            {abierto && (
                <Panel ancho="w-80">
                    <div className="flex items-center gap-2 border-b border-border px-3 py-2 focus-within:border-primary/40 focus-within:bg-primary/5">
                        <Search className="size-3.5 shrink-0 text-muted-foreground" />
                        <input
                            autoFocus
                            value={texto}
                            onChange={e => setTexto(e.target.value)}
                            placeholder={marcador}
                            className="w-full border-none bg-transparent p-0 text-[12.5px] text-foreground outline-none placeholder:text-muted-foreground focus:ring-0 focus-visible:outline-none"
                        />
                        {cargando && <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" />}
                    </div>

                    <div className="max-h-72 overflow-y-auto py-1">
                        {!cargando && resultados.length === 0 && (
                            <p className="px-3 py-4 text-center text-[11.5px] text-muted-foreground">
                                {texto ? 'Nadie con ese nombre' : 'Escribe para buscar'}
                            </p>
                        )}

                        {resultados.map(r => (
                            <button
                                key={r.valor}
                                type="button"
                                onClick={() => { onCambio(r.valor, r.texto); setAbierto(false); }}
                                className={clsx(
                                    'flex w-full items-center gap-2.5 px-3 py-2 text-left transition-colors hover:bg-muted',
                                    String(r.valor) === String(valor) && 'bg-primary/10',
                                )}
                            >
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted text-[9px] font-black uppercase text-muted-foreground">
                                    {(r.texto || '?').slice(0, 2)}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[12.5px] font-bold text-foreground">{r.texto}</span>
                                    {r.ayuda && <span className="block truncate text-[11px] text-muted-foreground">{r.ayuda}</span>}
                                </span>
                                {String(r.valor) === String(valor) && <Check className="size-3.5 shrink-0 text-accent-foreground" />}
                            </button>
                        ))}
                    </div>
                </Panel>
            )}
        </div>
    );
}
