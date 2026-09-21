import { Lightbulb, RefreshCw, X } from 'lucide-react';

/**
 * Las sugerencias de respuesta, flotando sobre el cuadro de redacción.
 *
 * Posicionado `absolute bottom-full`, igual que el selector de respuestas
 * rápidas y por el mismo motivo: una franja que empuje el hilo mueve los
 * mensajes que estás leyendo justo cuando vas a contestarlos. Es la lección que
 * ya costó rehacer el resumen —acabó en modal por esto mismo—, y aquí no se
 * puede repetir porque estas tres aparecen solas, sin que nadie pulse nada.
 *
 * Pulsar una sugerencia la escribe en el campo. NO la envía: ni con
 * confirmación, ni con retardo. Ese clic de más es toda la diferencia entre una
 * ayuda de redacción y un bot contestando en nombre de la empresa.
 */
export default function SugerenciasPicker({
    sugerencias,
    cargando,
    error,
    onSelect,
    onRefrescar,
    onCerrar,
}) {
    // Con el panel cargando y sin nada que enseñar todavía, la cabecera sola es
    // la señal de que algo viene. Sin ella el panel aparecería de golpe y
    // movería el foco del asesor a mitad de frase.
    const vacio = !cargando && !error && sugerencias.length === 0;

    return (
        <div className="absolute bottom-full left-0 right-0 mb-2 mx-2 z-50 rounded-lg border bg-popover text-popover-foreground shadow-lg overflow-hidden max-h-64 flex flex-col">
            <div className="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground bg-muted/40 flex items-center gap-1.5">
                <Lightbulb className="size-3" />
                Sugerencias
                <span className="font-normal normal-case tracking-normal text-muted-foreground/70">
                    · las escribes tú, no se envían solas
                </span>
                <button
                    type="button"
                    onMouseDown={(e) => { e.preventDefault(); onRefrescar(); }}
                    disabled={cargando}
                    title="Pedir otras"
                    aria-label="Pedir otras sugerencias"
                    className="ml-auto size-6 flex items-center justify-center rounded-md hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40 transition-colors"
                >
                    <RefreshCw className={`size-3 ${cargando ? 'animate-spin' : ''}`} />
                </button>
                <button
                    type="button"
                    onMouseDown={(e) => { e.preventDefault(); onCerrar(); }}
                    title="Cerrar"
                    aria-label="Cerrar sugerencias"
                    className="size-6 flex items-center justify-center rounded-md hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
                >
                    <X className="size-3" />
                </button>
            </div>

            {cargando && sugerencias.length === 0 && (
                // Tres barras del tamaño de lo que va a llegar, para que el panel
                // no cambie de alto al llenarse y empuje el campo de texto.
                <div className="p-3 space-y-2">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-8 rounded-md bg-muted animate-pulse" />
                    ))}
                </div>
            )}

            {error && (
                <div className="px-3 py-2.5 text-xs text-muted-foreground">
                    {error}
                </div>
            )}

            {vacio && (
                <div className="px-3 py-2.5 text-xs text-muted-foreground">
                    No se me ocurre nada para este mensaje. Escríbelo tú.
                </div>
            )}

            {sugerencias.length > 0 && (
                <ul className="overflow-y-auto">
                    {sugerencias.map((s, idx) => (
                        <li
                            key={idx}
                            // `onMouseDown` con `preventDefault`, no `onClick`:
                            // el click llega después del blur del textarea, y
                            // para entonces el campo ya perdió el foco y el
                            // cursor acaba al principio del texto insertado.
                            onMouseDown={(e) => { e.preventDefault(); onSelect(s); }}
                            className="flex items-start gap-3 px-3 py-2 cursor-pointer text-sm hover:bg-accent/50 transition-colors"
                        >
                            <span className="inline-flex shrink-0 items-center rounded-md bg-primary/10 text-primary px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide">
                                {s.etiqueta}
                            </span>
                            <span className="flex-1 text-foreground/90 whitespace-pre-wrap break-words">
                                {s.texto}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
