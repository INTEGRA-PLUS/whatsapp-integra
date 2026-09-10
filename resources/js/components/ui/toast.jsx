import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { CheckCircle2, AlertTriangle, Info, X, Loader2 } from 'lucide-react';
import { clsx } from 'clsx';

/**
 * Avisos flotantes con los tokens de la marca.
 *
 * Nace de dos problemas distintos del tablero:
 *
 * 1. `window.alert`/`confirm` se pintan pegados al borde del navegador, con el
 *    dominio como título, y no se parecen en nada al resto de la aplicación.
 * 2. Peor: mover una tarjeta, renombrar una etapa o cambiar su grupo fallaban
 *    con un `console.error` y nada más. El usuario veía la tarjeta volver a su
 *    sitio sin explicación y no tenía forma de saber que no se había guardado.
 *
 * Se escribe en casa en vez de traer una librería porque el proyecto ya tiene
 * su propio `ConfirmDialog` con este lenguaje visual y basta con ~100 líneas.
 */

const AvisosContext = createContext(null);

const DURACION = { exito: 4000, info: 4500, error: 7000, cargando: null };

const ASPECTO = {
    exito: {
        icono: CheckCircle2,
        caja: 'border-success/30 bg-success/10 dark:bg-success/15',
        tinta: 'text-success',
    },
    error: {
        icono: AlertTriangle,
        caja: 'border-destructive/30 bg-destructive/10 dark:bg-destructive/15',
        tinta: 'text-destructive',
    },
    info: {
        icono: Info,
        caja: 'border-info/30 bg-info/10 dark:bg-info/15',
        tinta: 'text-info',
    },
    cargando: {
        icono: Loader2,
        caja: 'border-border bg-card',
        tinta: 'text-muted-foreground',
    },
};

/** Cuántos se ven a la vez. Más que esto tapa el contenido en vez de informar. */
const VISIBLES = 3;

export function AvisosProvider({ children }) {
    const [avisos, setAvisos] = useState([]);
    const relojes = useRef(new Map());

    const cerrar = useCallback(id => {
        setAvisos(prev => prev.filter(a => a.id !== id));
        const reloj = relojes.current.get(id);
        if (reloj) {
            clearTimeout(reloj);
            relojes.current.delete(id);
        }
    }, []);

    const programar = useCallback((id, duracion) => {
        if (!duracion) return;
        relojes.current.set(id, setTimeout(() => cerrar(id), duracion));
    }, [cerrar]);

    const mostrar = useCallback((tipo, texto, opciones = {}) => {
        const id = opciones.id ?? `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
        const duracion = opciones.duracion ?? DURACION[tipo];

        setAvisos(prev => {
            const sinEste = prev.filter(a => a.id !== id);
            // Los nuevos entran por abajo; el más viejo cae si sobran.
            return [...sinEste, { id, tipo, texto, detalle: opciones.detalle }].slice(-VISIBLES);
        });

        const anterior = relojes.current.get(id);
        if (anterior) clearTimeout(anterior);
        programar(id, duracion);

        return id;
    }, [programar]);

    // Al desmontar no debe quedar ningún temporizador apuntando a un setState.
    useEffect(() => () => {
        relojes.current.forEach(clearTimeout);
        relojes.current.clear();
    }, []);

    // Con el ratón encima no corre el reloj: un aviso que se va mientras lo
    // estás leyendo obliga a repetir la acción para volver a verlo.
    const detener = useCallback(() => {
        relojes.current.forEach(clearTimeout);
        relojes.current.clear();
    }, []);

    const reanudar = useCallback(() => {
        setAvisos(prev => {
            prev.forEach(a => {
                if (!relojes.current.has(a.id)) programar(a.id, DURACION[a.tipo]);
            });
            return prev;
        });
    }, [programar]);

    const api = useMemo(() => ({
        exito: (texto, opciones) => mostrar('exito', texto, opciones),
        error: (texto, opciones) => mostrar('error', texto, opciones),
        info: (texto, opciones) => mostrar('info', texto, opciones),
        cargando: (texto, opciones) => mostrar('cargando', texto, opciones),
        cerrar,
    }), [mostrar, cerrar]);

    return (
        <AvisosContext.Provider value={api}>
            {children}
            <Pila avisos={avisos} onCerrar={cerrar} onDetener={detener} onReanudar={reanudar} />
        </AvisosContext.Provider>
    );
}

function Pila({ avisos, onCerrar, onDetener, onReanudar }) {
    if (avisos.length === 0) return null;

    return (
        <div
            onMouseEnter={onDetener}
            onMouseLeave={onReanudar}
            // `aria-live` en el contenedor y no en cada aviso: así el lector de
            // pantalla anuncia los que van llegando sin volver a leer los que ya
            // estaban.
            aria-live="polite"
            aria-atomic="false"
            className="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2"
        >
            {avisos.map(aviso => (
                <Aviso key={aviso.id} {...aviso} onCerrar={() => onCerrar(aviso.id)} />
            ))}
        </div>
    );
}

function Aviso({ tipo, texto, detalle, onCerrar }) {
    const { icono: Icono, caja, tinta } = ASPECTO[tipo] ?? ASPECTO.info;

    return (
        <div
            role={tipo === 'error' ? 'alert' : 'status'}
            className={clsx(
                'pointer-events-auto flex items-start gap-2.5 rounded-2xl border px-3.5 py-3 shadow-lg backdrop-blur',
                'animate-in fade-in slide-in-from-bottom-2 duration-200',
                caja,
            )}
        >
            <Icono className={clsx('mt-px size-4 shrink-0', tinta, tipo === 'cargando' && 'animate-spin')} />
            <div className="min-w-0 flex-1">
                <p className="text-[12.5px] font-bold leading-snug text-foreground">{texto}</p>
                {detalle && (
                    <p className="mt-0.5 text-[11.5px] leading-snug text-muted-foreground">{detalle}</p>
                )}
            </div>
            <button
                type="button"
                onClick={onCerrar}
                aria-label="Cerrar aviso"
                className="-mr-1 -mt-1 shrink-0 rounded-lg p-1 text-muted-foreground transition-colors hover:bg-black/5 hover:text-foreground dark:hover:bg-white/10"
            >
                <X className="size-3.5" />
            </button>
        </div>
    );
}

/**
 * Devuelve `{ exito, error, info, cargando, cerrar }`.
 *
 * Fuera del proveedor no revienta: devuelve funciones vacías. Así una pantalla
 * que se monte en un test o en una historia aislada no se cae por no tener el
 * proveedor encima.
 */
export function useAviso() {
    const api = useContext(AvisosContext);
    return api ?? VACIO;
}

const VACIO = {
    exito: () => {},
    error: () => {},
    info: () => {},
    cargando: () => {},
    cerrar: () => {},
};
