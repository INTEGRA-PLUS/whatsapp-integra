import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { Sun, Moon, Monitor } from 'lucide-react';

/**
 * El sistema de diseño de Integra, dentro del propio producto.
 *
 * Vive aquí y no en un sitio aparte a propósito: pinta con los mismos tokens
 * que la aplicación, así que no puede quedarse desactualizado. Si alguien
 * cambia `--primary` en `app.css`, esta página cambia con él; si viviera en
 * otro repositorio, en tres meses documentaría unos colores que ya no son.
 *
 * La regla que sostiene todo esto: **usar tokens, nunca colores crudos**. Decir
 * `bg-primary` y no `bg-green-500`, `text-muted-foreground` y no
 * `text-slate-500`. Es lo que permite cambiar la marca en un archivo en vez de
 * en mil clases, y lo que hace que el tema claro y el oscuro salgan solos.
 */
export default function SistemaDiseno() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <>
            <Head title="Sistema de diseño" />

            <div className="p-6 space-y-10 max-w-5xl">
                <header className="space-y-3">
                    <p className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                        Fundamentos
                    </p>
                    <h1 className="text-4xl font-black tracking-tight text-foreground">
                        Sistema de diseño
                    </h1>
                    <p className="text-sm leading-relaxed text-muted-foreground max-w-2xl">
                        Las decisiones de color de Integra, en tokens semánticos. Cada uno tiene su valor en
                        claro y en oscuro; las pantallas usan el nombre, nunca el color. Cambia el tema aquí
                        arriba para ver los dos.
                    </p>

                    <div className="flex gap-2 pt-2">
                        {[
                            ['light', 'Claro', Sun],
                            ['dark', 'Oscuro', Moon],
                            ['system', 'Sistema', Monitor],
                        ].map(([valor, etiqueta, Icono]) => (
                            <Button
                                key={valor}
                                size="sm"
                                variant={appearance === valor ? 'default' : 'outline'}
                                onClick={() => updateAppearance(valor)}
                                className="gap-1.5"
                            >
                                <Icono className="size-3.5" />
                                {etiqueta}
                            </Button>
                        ))}
                    </div>
                </header>

                <Seccion
                    titulo="Superficies"
                    nota="El fondo de la aplicación y las capas que se apoyan encima. En oscuro, el fondo es el navy de la marca y las tarjetas se despegan con un gris azulado más claro."
                >
                    <Muestra token="background" clase="bg-background" texto="text-foreground" />
                    <Muestra token="card" clase="bg-card" texto="text-card-foreground" />
                    <Muestra token="popover" clase="bg-popover" texto="text-popover-foreground" />
                    <Muestra token="muted" clase="bg-muted" texto="text-muted-foreground" />
                    <Muestra token="sidebar" clase="bg-sidebar" texto="text-sidebar-foreground" />
                    <Muestra token="secondary" clase="bg-secondary" texto="text-secondary-foreground" />
                </Seccion>

                <Seccion
                    titulo="Marca y acción"
                    nota="El verde de Integra lleva texto navy encima, siempre: en blanco daría 1.73:1 y sería ilegible. Con navy da 10.7:1."
                >
                    <Muestra token="primary" clase="bg-primary" texto="text-primary-foreground" />
                    <Muestra token="accent" clase="bg-accent" texto="text-accent-foreground" />
                    <Muestra token="ring (foco)" clase="bg-ring" texto="text-primary-foreground" />
                </Seccion>

                <Seccion
                    titulo="Estados"
                    nota="Para que «va bien», «ojo» y «esto falló» se digan igual en todo el producto. Antes cada pantalla elegía su propio emerald o su propio amber."
                >
                    <Muestra token="success" clase="bg-success" texto="text-success-foreground" />
                    <Muestra token="warning" clase="bg-warning" texto="text-warning-foreground" />
                    <Muestra token="destructive" clase="bg-destructive" texto="text-destructive-foreground" />
                    <Muestra token="info" clase="bg-info" texto="text-info-foreground" />
                </Seccion>

                <Seccion titulo="Gráficas" nota="Las series, en orden. El panel maestro las elegía a mano.">
                    {/* Las clases van escritas enteras: Tailwind lee el código fuente
                        como texto y no ve un `bg-chart-${n}` construido al vuelo,
                        así que esas clases nunca llegarían al CSS compilado. */}
                    {[
                        ['chart-1', 'bg-chart-1'],
                        ['chart-2', 'bg-chart-2'],
                        ['chart-3', 'bg-chart-3'],
                        ['chart-4', 'bg-chart-4'],
                        ['chart-5', 'bg-chart-5'],
                    ].map(([nombre, clase]) => (
                        <div key={nombre} className="space-y-2">
                            <div className={`h-16 rounded-lg border border-border ${clase}`} />
                            <p className="text-xs font-mono text-muted-foreground">{nombre}</p>
                        </div>
                    ))}
                </Seccion>

                <section className="space-y-4">
                    <h2 className="text-lg font-black tracking-tight text-foreground">Componentes</h2>
                    <div className="rounded-xl border border-border bg-card p-6 space-y-6">
                        <div className="flex flex-wrap items-center gap-3">
                            <Button>Acción principal</Button>
                            <Button variant="outline">Secundaria</Button>
                            <Button variant="destructive">Destructiva</Button>
                            <Button variant="ghost">Terciaria</Button>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Etiqueta clase="bg-success/15 text-success border-success/30">Activa</Etiqueta>
                            <Etiqueta clase="bg-warning/15 text-warning border-warning/30">Pendiente</Etiqueta>
                            <Etiqueta clase="bg-destructive/15 text-destructive border-destructive/30">Fallida</Etiqueta>
                            <Etiqueta clase="bg-info/15 text-info border-info/30">Importado</Etiqueta>
                        </div>

                        <div className="max-w-sm space-y-2">
                            <label className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                Campo de texto
                            </label>
                            <input
                                placeholder="Escribe algo"
                                className="h-11 w-full rounded-lg border border-input bg-background px-4 text-sm text-foreground placeholder:text-muted-foreground outline-none focus:border-ring focus:ring-4 focus:ring-ring/20 transition"
                            />
                        </div>
                    </div>
                </section>

                <section className="space-y-4">
                    <h2 className="text-lg font-black tracking-tight text-foreground">Tipografía</h2>
                    <div className="rounded-xl border border-border bg-card p-6 space-y-4">
                        <p className="text-4xl font-black tracking-tight text-foreground">Titular</p>
                        <p className="text-lg font-bold text-foreground">Subtítulo</p>
                        <p className="text-sm text-foreground">
                            Texto general. La marca pide Montserrat; el producto todavía usa la fuente del
                            sistema y ese cambio va aparte, porque afecta a cada pantalla.
                        </p>
                        <p className="text-sm text-muted-foreground">Texto atenuado, para lo secundario.</p>
                        <p className="text-xs font-mono text-muted-foreground">Monoespaciada: Phone ID, WABA ID</p>
                    </div>
                </section>
            </div>
        </>
    );
}

function Seccion({ titulo, nota, children }) {
    return (
        <section className="space-y-4">
            <div className="space-y-1">
                <h2 className="text-lg font-black tracking-tight text-foreground">{titulo}</h2>
                {nota && <p className="text-sm text-muted-foreground max-w-2xl">{nota}</p>}
            </div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
        </section>
    );
}

function Muestra({ token, clase, texto }) {
    return (
        <div className="space-y-2">
            <div className={`h-20 rounded-lg border border-border flex items-end p-3 ${clase}`}>
                <span className={`text-sm font-bold ${texto}`}>Aa</span>
            </div>
            <p className="text-xs font-mono text-muted-foreground">{token}</p>
        </div>
    );
}

function Etiqueta({ clase, children }) {
    return (
        <span className={`inline-flex items-center rounded-lg border px-3 py-1 text-xs font-bold uppercase tracking-wide ${clase}`}>
            {children}
        </span>
    );
}

SistemaDiseno.layout = page => <AppLayout>{page}</AppLayout>;
