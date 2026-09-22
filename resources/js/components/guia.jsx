import { useEffect, useState } from 'react';
import { Check, CheckCircle2, ChevronDown, Info, TriangleAlert } from 'lucide-react';

/**
 * Las piezas con las que se arma una guía dentro del producto.
 *
 * Nacieron dentro de «Conectar tu WhatsApp Business» y vivían ahí. Al escribir
 * la segunda guía —la del límite de mensajes— había que copiarlas, y dos copias
 * de un componente son dos sitios donde el estilo se va separando hasta que las
 * dos guías del mismo producto no se parecen.
 *
 * Lo que NO está aquí es lo que sólo le sirve a una guía. La etiqueta de
 * «celular / escritorio», por ejemplo, tiene sentido en un proceso que salta
 * entre el navegador y el teléfono y ninguno en uno que ocurre entero en el
 * panel de Meta: por eso `Paso` recibe la etiqueta ya montada en vez de saber
 * de dónde sale.
 */

/** Un recuadro de aviso. `alto` es para lo que, si se ignora, cuesta un rechazo. */
export function Aviso({ tono = 'info', titulo, children }) {
    const estilos = {
        info: 'border-info/30 bg-info/10 text-info',
        ojo: 'border-warning/30 bg-warning/10 text-warning',
        alto: 'border-destructive/30 bg-destructive/10 text-destructive',
        bien: 'border-success/30 bg-success/10 text-success',
    }[tono];
    const Icono = { info: Info, ojo: TriangleAlert, alto: TriangleAlert, bien: CheckCircle2 }[tono];

    return (
        <div className={`rounded-xl border px-4 py-3 ${estilos}`}>
            <p className="flex items-center gap-2 text-[13px] font-bold">
                <Icono className="size-4 shrink-0" />
                {titulo}
            </p>
            <div className="mt-1.5 space-y-1.5 text-[13px] leading-relaxed opacity-90 [&_strong]:font-semibold">
                {children}
            </div>
        </div>
    );
}

/**
 * Una captura con su explicación.
 *
 * `estrecha` para las que son un diálogo de Meta y no una pantalla entera: a
 * todo el ancho se ven pixeladas y ocupan media página para enseñar un modal de
 * trescientos puntos.
 */
export function Captura({ src, alt, pie, estrecha = false }) {
    return (
        <figure className="flex flex-col gap-2.5 rounded-xl border bg-card p-3 shadow-xs">
            <img
                src={src}
                alt={alt}
                loading="lazy"
                className={`w-full rounded-lg border bg-muted/30 ${estrecha ? 'mx-auto max-w-[300px]' : ''}`}
            />
            <figcaption className="px-0.5 text-[12.5px] leading-snug text-muted-foreground [&_strong]:font-semibold [&_strong]:text-foreground">
                {pie}
            </figcaption>
        </figure>
    );
}

/**
 * Un paso plegable.
 *
 * Sólo uno abierto a la vez: una guía de trece pasos desplegada entera son seis
 * pantallas de scroll, y ahí el lector deja de saber por dónde iba.
 */
export function Paso({ n, total, titulo, etiqueta, ruta, abierto, hecho, onAbrir, onHecho, children }) {
    return (
        <section className={`overflow-hidden rounded-xl border transition-colors ${
            abierto ? 'border-primary/40 bg-card shadow-xs' : 'bg-card/40'
        }`}>
            <button
                type="button"
                onClick={onAbrir}
                aria-expanded={abierto}
                className="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors hover:bg-black/[.03] dark:hover:bg-white/[.04]"
            >
                <span className={`flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] font-bold tabular-nums ${
                    hecho
                        ? 'bg-success text-primary-foreground'
                        : abierto ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                }`}>
                    {hecho ? <Check className="size-4" strokeWidth={3} /> : n}
                </span>

                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className={`text-[14.5px] font-semibold ${hecho && !abierto ? 'text-muted-foreground' : 'text-foreground'}`}>
                            {titulo}
                        </span>
                        {etiqueta}
                    </span>
                </span>

                <ChevronDown className={`size-4 shrink-0 text-muted-foreground transition-transform ${abierto ? 'rotate-180' : ''}`} />
            </button>

            {abierto && (
                <div className="border-t px-4 pb-5 pt-4">
                    {ruta && (
                        <p className="mb-3 rounded-lg bg-muted/50 px-3 py-2 font-mono text-[11.5px] leading-relaxed text-muted-foreground">
                            {ruta}
                        </p>
                    )}
                    <div className="space-y-4 text-[14px] leading-relaxed text-foreground/90 [&_strong]:font-semibold">
                        {children}
                    </div>
                    <button
                        type="button"
                        onClick={onHecho}
                        className="mt-5 inline-flex h-9 items-center gap-2 rounded-lg bg-primary px-4 text-[13px] font-bold text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        <Check className="size-4" strokeWidth={3} />
                        {n < total ? 'Listo, siguiente paso' : 'Terminé'}
                    </button>
                </div>
            )}
        </section>
    );
}

/**
 * El avance de la guía, recordado en el navegador.
 *
 * Quien sigue una guía cambia de pestaña, va al panel de Meta, vuelve, cierra
 * sin querer. Volver y encontrarla en blanco es lo que hace que llame a
 * soporte en vez de terminarla.
 *
 * Si el almacenamiento está bloqueado —modo incógnito, navegador con
 * restricciones— la guía funciona igual: sólo deja de recordar.
 */
export function useAvance(memoria, total) {
    const [hechos, setHechos] = useState([]);
    const [abierto, setAbierto] = useState(1);

    useEffect(() => {
        try {
            const guardado = JSON.parse(localStorage.getItem(memoria) ?? '[]');
            if (Array.isArray(guardado) && guardado.length) {
                setHechos(guardado);
                setAbierto(Math.min(Math.max(...guardado) + 1, total));
            }
        } catch {
            /* sin memoria, pero la guía se sigue igual */
        }
    }, [memoria, total]);

    function marcar(n) {
        const nuevos = [...new Set([...hechos, n])];
        setHechos(nuevos);
        setAbierto(n < total ? n + 1 : 0);
        try { localStorage.setItem(memoria, JSON.stringify(nuevos)); } catch { /* ignorado */ }

        // El siguiente paso queda arriba de la vista: sin esto el lector se
        // queda mirando el final del paso que acaba de cerrar.
        requestAnimationFrame(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
    }

    function reiniciar() {
        setHechos([]);
        setAbierto(1);
        try { localStorage.removeItem(memoria); } catch { /* ignorado */ }
    }

    /** Las props que necesita cada `<Paso n={...}>`. */
    const paso = n => ({
        n,
        total,
        abierto: abierto === n,
        hecho: hechos.includes(n),
        onAbrir: () => setAbierto(abierto === n ? 0 : n),
        onHecho: () => marcar(n),
    });

    return {
        paso,
        reiniciar,
        completados: hechos.length,
        porcentaje: Math.round((hechos.length / total) * 100),
        terminado: hechos.length === total,
    };
}
