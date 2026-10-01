import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    Check,
    CircleCheck,
    Clock,
    Copy,
    Lightbulb,
    Link2,
    MapPin,
    MessageCircle,
    UserRound,
    XCircle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import CabeceraModulo from '@/components/cabecera-modulo';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { guiaPorSlug } from './contenido';

/**
 * Una guía, paso a paso.
 *
 * Lo primero que se ve son los tiempos: es lo que la gente pregunta antes que
 * el procedimiento («¿y eso cuánto se demora?»). Después, qué tener listo, y
 * luego los pasos, cada uno con dónde se hace y cuánto tarda.
 *
 * El bloque «Responder a quien pregunta» existe porque estas guías nacieron de
 * preguntas que llegan por WhatsApp: el texto se copia tal cual, con el enlace.
 */

// El cuerpo de los pasos viene como JSX con listas, citas y código: sin plugin
// de tipografía, el estilo va aquí y una sola vez.
const PROSA = cn(
    'space-y-2 text-sm leading-relaxed text-muted-foreground',
    '[&_strong]:font-semibold [&_strong]:text-foreground',
    '[&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:pl-5',
    '[&_code]:rounded [&_code]:bg-muted [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[12px] [&_code]:text-foreground',
    '[&_blockquote]:rounded-lg [&_blockquote]:border-l-4 [&_blockquote]:border-primary/50 [&_blockquote]:bg-primary/5 [&_blockquote]:px-3 [&_blockquote]:py-2 [&_blockquote]:text-foreground',
);

export default function GuiaShow({ slug }) {
    const guia = guiaPorSlug(slug);

    if (!guia) {
        return (
            <>
                <Head title="Guía no encontrada" />
                <div className="mx-auto flex max-w-3xl flex-col gap-4 p-6">
                    <CabeceraModulo icono={BookOpen} volver={route('guias.index')} titulo="Esta guía no existe" />
                    <p className="text-sm text-muted-foreground">
                        Puede que el enlace esté mal copiado. <Link href={route('guias.index')} className="underline underline-offset-2">Ver todas las guías</Link>.
                    </p>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={guia.titulo} />

            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <CabeceraModulo icono={guia.icono ?? BookOpen} volver={route('guias.index')} titulo={guia.titulo} descripcion={guia.resumen} />

                <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                    <div className="flex min-w-0 flex-col gap-6">
                        <Tiempos guia={guia} />
                        <Antes guia={guia} />

                        <section aria-labelledby="pasos" className="rounded-2xl border bg-card p-4 sm:p-6">
                            <h2 id="pasos" className="text-base font-semibold text-foreground">Paso a paso</h2>
                            <ol className="mt-5">
                                {guia.pasos.map((paso, i) => (
                                    <Paso key={paso.titulo} paso={paso} n={i + 1} ultimo={i === guia.pasos.length - 1} />
                                ))}
                            </ol>
                        </section>

                        {guia.rechazo && (
                            <section id="rechazo" className="scroll-mt-6 rounded-2xl border border-destructive/30 bg-destructive/5 p-4 sm:p-6">
                                <h2 className="flex items-center gap-2 text-base font-semibold text-foreground">
                                    <XCircle className="size-4 text-destructive" /> {guia.rechazo.titulo}
                                </h2>
                                <div className={cn(PROSA, 'mt-3')}>{guia.rechazo.cuerpo}</div>
                            </section>
                        )}

                        {guia.editar && (
                            <Nota tono="aviso" titulo="Editar una plantilla ya aprobada">{guia.editar}</Nota>
                        )}
                    </div>

                    <aside className="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
                        <Compartir guia={guia} />
                        <Indice guia={guia} />
                    </aside>
                </div>
            </div>
        </>
    );
}

function Tiempos({ guia }) {
    return (
        <section aria-labelledby="tiempos" className="rounded-2xl border bg-card p-4 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 id="tiempos" className="flex items-center gap-2 text-base font-semibold text-foreground">
                    <Clock className="size-4 text-primary" /> Cuánto se demora
                </h2>
                {guia.quien && (
                    <p className="flex max-w-sm items-start gap-1.5 text-xs text-muted-foreground">
                        <UserRound className="mt-px size-3.5 shrink-0" /> {guia.quien}
                    </p>
                )}
            </div>

            <dl className="mt-4 divide-y divide-border rounded-xl border">
                {guia.tiempos.filas.map(([que, cuanto]) => (
                    <div key={que} className="flex flex-col gap-0.5 px-3 py-2.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                        <dt className="text-sm text-muted-foreground">{que}</dt>
                        <dd className="text-sm font-semibold text-foreground sm:text-right">{cuanto}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

function Antes({ guia }) {
    if (!guia.antes?.length) return null;

    return (
        <section aria-labelledby="antes" className="rounded-2xl border bg-card p-4 sm:p-6">
            <h2 id="antes" className="text-base font-semibold text-foreground">Antes de empezar, ten listo</h2>
            <ul className="mt-3 space-y-2">
                {guia.antes.map(item => (
                    <li key={item} className="flex items-start gap-2 text-sm text-muted-foreground">
                        <CircleCheck className="mt-0.5 size-4 shrink-0 text-primary" /> <span>{item}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Paso({ paso, n, ultimo }) {
    return (
        <li id={`paso-${n}`} className="relative scroll-mt-6 pb-8 pl-12 last:pb-0">
            {/* La línea que une los números: termina en el último paso. */}
            {!ultimo && <span aria-hidden="true" className="absolute left-[15px] top-9 bottom-1 w-px bg-border" />}
            <span className="absolute left-0 top-0 flex size-8 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                {n}
            </span>

            <h3 className="pt-1 text-sm font-semibold leading-snug text-foreground sm:text-base">{paso.titulo}</h3>

            <div className="mt-1.5 flex flex-wrap gap-2 text-[11px]">
                {paso.donde && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                        <MapPin className="size-3" /> {paso.donde}
                    </span>
                )}
                {paso.duracion && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 font-medium text-foreground">
                        <Clock className="size-3" /> {paso.duracion}
                    </span>
                )}
            </div>

            <div className={cn(PROSA, 'mt-3')}>{paso.cuerpo}</div>

            {paso.imagenes?.length > 0 && (
                <div className="mt-3 flex flex-col items-start gap-3">
                    {paso.imagenes.map(img => <Captura key={img.src} {...img} />)}
                </div>
            )}

            {(paso.consejo || paso.aviso) && (
                <div className="mt-3 flex flex-col gap-2">
                    {paso.consejo && <Nota tono="consejo">{paso.consejo}</Nota>}
                    {paso.aviso && <Nota tono="aviso">{paso.aviso}</Nota>}
                </div>
            )}
        </li>
    );
}

/**
 * Una captura de la pantalla real. Se abre en grande al tocarla: en el móvil
 * las letras del formulario no se leen a tamaño de columna.
 */
function Captura({ src, ancho, alto, alt }) {
    return (
        <a
            href={src}
            target="_blank"
            rel="noopener noreferrer"
            title="Ver en grande"
            className="inline-block max-w-full overflow-hidden rounded-xl border bg-muted/30 align-top transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
        >
            {/* Capturadas al doble de resolución y pintadas a la mitad, que es
                su tamaño real: nítidas en pantallas retina, y un botón suelto
                no se estira a todo el ancho. `srcSet` con `2x` no bastaba: el
                navegador toma `src` como candidata 1x y gana ésa. Ancho y alto
                reservan el hueco antes de que cargue. */}
            <img src={src} width={ancho} height={alto} alt={alt} loading="lazy" className="block h-auto max-w-full" />
        </a>
    );
}

function Nota({ tono, titulo, children }) {
    const Icono = tono === 'aviso' ? AlertTriangle : Lightbulb;

    return (
        <div className={cn(
            'flex items-start gap-2 rounded-lg px-3 py-2 text-[13px] leading-relaxed',
            tono === 'aviso' ? 'bg-warning/10 text-foreground' : 'bg-info/10 text-foreground',
        )}>
            <Icono className={cn('mt-0.5 size-4 shrink-0', tono === 'aviso' ? 'text-warning' : 'text-info')} />
            <div>
                {titulo && <p className="font-semibold">{titulo}</p>}
                <p className={titulo ? 'mt-0.5 text-muted-foreground' : undefined}>{children}</p>
            </div>
        </div>
    );
}

/**
 * El texto para contestar por WhatsApp, con el enlace a la guía al final.
 *
 * `navigator.clipboard` no existe fuera de https ni en algunos navegadores
 * embebidos; ahí se selecciona el texto para que se pueda copiar a mano en vez
 * de fallar en silencio.
 */
function Compartir({ guia }) {
    const [copiado, setCopiado] = useState(null);
    const enlace = typeof window !== 'undefined' ? `${window.location.origin}${route('guias.show', guia.slug, false)}` : '';
    const texto = `${guia.compartir}\n\nGuía completa: ${enlace}`;

    const copiar = async (que, valor) => {
        try {
            await navigator.clipboard.writeText(valor);
            setCopiado(que);
            setTimeout(() => setCopiado(null), 2000);
        } catch {
            document.getElementById('texto-compartir')?.select();
        }
    };

    return (
        <section className="rounded-2xl border bg-card p-4">
            <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                <MessageCircle className="size-4 text-primary" /> Responder a quien pregunta
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">El resumen de esta guía, listo para pegar en WhatsApp.</p>

            <textarea
                id="texto-compartir"
                readOnly
                value={texto}
                rows={9}
                className="mt-3 w-full resize-y rounded-lg border border-input bg-muted/30 p-2.5 text-xs leading-relaxed text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
            />

            <div className="mt-2 flex flex-wrap gap-2">
                <Button type="button" size="sm" className="flex-1 gap-1.5" onClick={() => copiar('texto', texto)}>
                    {copiado === 'texto' ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
                    {copiado === 'texto' ? 'Copiado' : 'Copiar respuesta'}
                </Button>
                <Button type="button" size="sm" variant="outline" className="gap-1.5" onClick={() => copiar('enlace', enlace)}>
                    {copiado === 'enlace' ? <Check className="size-3.5" /> : <Link2 className="size-3.5" />}
                    {copiado === 'enlace' ? 'Copiado' : 'Enlace'}
                </Button>
            </div>
        </section>
    );
}

function Indice({ guia }) {
    return (
        <nav aria-label="Pasos de la guía" className="hidden rounded-2xl border bg-card p-4 lg:block">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">En esta guía</p>
            <ol className="mt-2 space-y-1">
                {guia.pasos.map((paso, i) => (
                    <li key={paso.titulo}>
                        <a
                            href={`#paso-${i + 1}`}
                            className="flex gap-2 rounded-md px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <span className="w-4 shrink-0 text-right font-mono">{i + 1}</span>
                            <span>{paso.titulo}</span>
                        </a>
                    </li>
                ))}
                {guia.rechazo && (
                    <li>
                        <a href="#rechazo" className="flex gap-2 rounded-md px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">
                            <span className="w-4 shrink-0" />
                            <span>{guia.rechazo.titulo}</span>
                        </a>
                    </li>
                )}
            </ol>
        </nav>
    );
}

// Inertia 3 llama al layout DOS veces: primero con las props de la página
// (`layout(props)`) para ver si devuelve un elemento, y después con el elemento
// ya armado. Leer `page.props.slug` a secas reventaba en la primera llamada
// («Cannot read properties of undefined (reading 'slug')») y la guía no abría.
// Mismo patrón que Extensions/Show.
GuiaShow.layout = page => (
    <AppLayout breadcrumb={['Guías', guiaPorSlug(page?.props?.slug ?? page?.slug)?.titulo ?? 'Guía']}>{page}</AppLayout>
);
