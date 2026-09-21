import { clsx } from 'clsx';
import { Check } from 'lucide-react';

/**
 * Las tres piezas que comparten «Mi plan» y «Planes».
 *
 * Son las dos caras de la misma conversación —lo que tienes y lo que hay— y el
 * cliente pasa de una a la otra por el menú. Cuando cada una escribía sus
 * propias cabeceras y sus propias pastillas, la diferencia se notaba: el mismo
 * «Esto va en todos los planes» salía en una como lista gris dentro de una caja
 * y en la otra como rejilla de fichas.
 */

/**
 * La cabecera de una sección.
 *
 * El contador va fuera del `<h2>` a propósito: metido dentro, el lector de
 * pantalla leía «Extensiones de tu plan4».
 */
export function Titulo({ eyebrow, nota, contador = null, children }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
                {eyebrow && (
                    <p className="text-[10px] font-black uppercase tracking-[0.18em] text-muted-foreground/70">
                        {eyebrow}
                    </p>
                )}
                <h2 className="mt-1 text-xl font-black tracking-tight text-foreground">{children}</h2>
                {nota && <p className="mt-1 max-w-3xl text-sm leading-relaxed text-muted-foreground">{nota}</p>}
            </div>

            {contador !== null && (
                <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-bold tabular-nums text-muted-foreground">
                    {contador}
                </span>
            )}
        </div>
    );
}

/** Los estados, con la misma forma en todos los sitios donde salen. */
export function Pastilla({ tono = 'muted', icono: Icono, punto = false, children }) {
    return (
        <span className={clsx(
            'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-bold',
            tono === 'success' && 'border-success/30 bg-success/12 text-success',
            tono === 'warning' && 'border-warning/40 bg-warning/15 text-warning',
            tono === 'muted' && 'border-border bg-muted text-muted-foreground'
        )}>
            {punto && <span className="size-1.5 rounded-full bg-current" />}
            {Icono && <Icono className="size-3 shrink-0" />}
            {children}
        </span>
    );
}

/**
 * Lo que lleva cualquier plan, en fichas y no en lista.
 *
 * Son nueve cosas que se ojean, no un párrafo que se lee. Como lista gris
 * dentro de una caja, lo que el cliente más paga —el CRM entero— parecía la
 * letra pequeña de la pantalla.
 *
 * El verde de la marca no vale como tinta sobre claro (2.05:1), así que el
 * check va en `accent-foreground`, que es el mismo tono bajado hasta 4.52:1.
 */
export function ListaConChecks({ items = [], columnas = 'sm:grid-cols-2 lg:grid-cols-3' }) {
    return (
        <ul className={clsx('grid gap-2.5', columnas)}>
            {items.map(item => (
                <li
                    key={item}
                    className="flex items-start gap-2.5 rounded-xl border border-border/70 bg-card px-3.5 py-3 text-sm leading-snug text-foreground"
                >
                    <span className="mt-px flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/25 text-accent-foreground">
                        <Check className="size-3" strokeWidth={3.5} />
                    </span>
                    {item}
                </li>
            ))}
        </ul>
    );
}
