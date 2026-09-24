import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * La cabecera de un módulo: icono, título, una línea de descripción y, a la
 * derecha, sus botones.
 *
 * Nació en Plantillas, con el verde de la marca desvaneciéndose hacia la
 * derecha, y se sacó aquí para que todos los módulos la compartan. Hasta
 * entonces cada pantalla escribía la suya —unas con `text-2xl font-semibold`,
 * otras con `font-black`, unas con icono y otras sin él— y al pasar de un
 * módulo a otro por el menú se notaba que las había hecho gente distinta.
 *
 * Va con poco relleno a propósito: es la puerta del módulo, no el módulo, y
 * en un portátil cada línea que ocupa se la quita a la tabla de debajo.
 *
 * `volver` pinta una flecha antes del icono, para las pantallas de detalle o
 * de creación que cuelgan de un listado.
 *
 * `accionesClassName` va al contenedor de los botones. Existe por los
 * asistentes de creación, cuyo indicador de pasos se esconde en el móvil: sin
 * esconder también el contenedor, quedaba un hueco vacío bajo el título.
 */
export default function CabeceraModulo({ icono: Icono, titulo, descripcion, volver, children, className, accionesClassName }) {
    return (
        <header className={cn(
            'relative overflow-hidden rounded-2xl border bg-gradient-to-br from-primary/10 via-card to-card px-4 py-3 sm:px-5 sm:py-3.5',
            className,
        )}>
            <div aria-hidden="true" className="pointer-events-none absolute -right-12 -top-12 size-40 rounded-full bg-primary/10 blur-3xl" />

            <div className="relative flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    {volver && (
                        <Link
                            href={volver}
                            aria-label="Volver"
                            className="flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-card hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" />
                        </Link>
                    )}
                    {Icono && (
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary ring-1 ring-primary/20">
                            <Icono className="size-5" />
                        </div>
                    )}
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold leading-tight tracking-tight text-foreground sm:text-2xl">
                            {titulo}
                        </h1>
                        {descripcion && (
                            <div className="mt-0.5 max-w-2xl text-sm leading-snug text-muted-foreground">
                                {descripcion}
                            </div>
                        )}
                    </div>
                </div>

                {children && (
                    <div className={cn('flex flex-wrap items-center gap-2 lg:shrink-0 lg:justify-end', accionesClassName)}>
                        {children}
                    </div>
                )}
            </div>
        </header>
    );
}
