import { useState } from 'react';
import { RefreshCw, X } from 'lucide-react';
import { useNuevaVersion } from '@/hooks/use-nueva-version';

/**
 * «Hay una versión nueva», dicho sin interrumpir.
 *
 * Antes, al desplegar, la página se recargaba sola en cuanto el usuario tocaba
 * cualquier cosa: perdía la conversación abierta y lo que estuviera escribiendo.
 * La sesión nunca se cerraba —eso vive en Redis y sobrevive al despliegue— pero
 * la sensación era la de que el sistema lo había echado.
 *
 * Va abajo a la derecha y no bloquea nada: quien está atendiendo a un cliente
 * termina primero y recarga después. Se puede cerrar; si se cierra, la
 * protección de Inertia sigue estando por debajo, así que no se queda nadie con
 * una versión vieja para siempre.
 */
export default function AvisoNuevaVersion() {
    const hayNueva = useNuevaVersion();
    const [descartado, setDescartado] = useState(false);

    if (!hayNueva || descartado) return null;

    return (
        <div
            role="status"
            aria-live="polite"
            className="fixed bottom-4 right-4 z-50 flex max-w-[min(22rem,calc(100vw-2rem))] items-start gap-3
                       rounded-xl border border-border bg-card p-3.5 shadow-lg
                       motion-safe:animate-in motion-safe:slide-in-from-bottom-2 motion-safe:fade-in"
        >
            <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <RefreshCw className="size-4" />
            </div>

            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-foreground">Hay una versión nueva</p>
                <p className="mt-0.5 text-[13px] leading-snug text-muted-foreground">
                    Termina lo que estás haciendo y recarga cuando quieras. No perderás tu sesión.
                </p>

                <button
                    type="button"
                    onClick={() => window.location.reload()}
                    className="mt-2.5 inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3
                               text-[13px] font-medium text-primary-foreground transition-colors
                               hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2
                               focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                    <RefreshCw className="size-3.5" />
                    Recargar ahora
                </button>
            </div>

            <button
                type="button"
                onClick={() => setDescartado(true)}
                aria-label="Ocultar el aviso"
                className="-mr-1 -mt-1 rounded-md p-1 text-muted-foreground transition-colors
                           hover:bg-muted hover:text-foreground focus-visible:outline-none
                           focus-visible:ring-2 focus-visible:ring-ring"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
