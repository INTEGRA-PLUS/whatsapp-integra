import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Titulo } from '@/components/seccion';
import {
    ArrowLeft, Download, Power, Trash2, Loader2, Save, ShieldCheck, Zap, Check, Lock,
    Plug, AlertTriangle, ArrowRight, CircleAlert,
} from 'lucide-react';
import { iconFor, categoryLabel } from './icons';
import { Maqueta, tieneMaqueta } from './maquetas';
import SettingsForm from './SettingsForm';

/**
 * La ficha de una extensión. Una sola pantalla para las ocho: lo que cambia
 * entre ellas viaja en el manifiesto, no en el código de aquí.
 *
 * ## Cómo está compuesta (21-sep-2026)
 *
 * Era una columna de seis cajas idénticas —mismo borde, mismo blanco, títulos
 * todos en `text-sm`—, y con todo al mismo peso la pantalla no decía por dónde
 * empezar: la maqueta, que es lo que de verdad contesta «¿qué va a cambiar en
 * mi bandeja?», pesaba lo mismo que la línea de «instalada el 3 de marzo».
 *
 * Ahora baja de temperatura como «Mi plan» y «Planes»: el carné en navy arriba
 * —con la identidad de la extensión, su estado y sus botones— y de ahí para
 * abajo contenido sobre el fondo claro. Una sola superficie oscura por
 * pantalla; dos compiten.
 *
 * Y el texto de «Qué hace» deja de ser gris. Es lo que hay que leer antes de
 * encender algo en la bandeja donde trabaja un equipo, así que va en el color
 * del texto normal; el gris se queda para lo accesorio.
 */
export default function ExtensionShow({ extension: initial }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [extension, setExtension] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [saved, setSaved] = useState(false);

    // Lo que le falta a ESTA empresa para poder usarla: o no ha conectado el
    // proveedor, o lo conectó pero su token no llega a lo que la extensión
    // pide. Son dos arreglos distintos y por eso se cuentan por separado.
    const dependencia = extension.dependencia ?? null;
    const faltaConexion = Boolean(dependencia && !dependencia.conectada);
    const faltaScope = Boolean(dependencia?.conectada && dependencia.puede === false);

    async function run(action) {
        setBusy(true);
        setError(null);
        try {
            const { data } = await action();
            // El esquema no vuelve en las respuestas de la API (sus opciones se
            // resuelven al renderizar la página), así que se conserva el que ya
            // se tenía en vez de dejar el formulario sin campos.
            setExtension((prev) => ({ ...prev, ...data, schema: prev.schema }));
            return true;
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudo completar la acción.');
            return false;
        } finally {
            setBusy(false);
        }
    }

    const install = () => run(() => axios.post(`/api/extensions/${extension.slug}/install`));
    const toggle = () =>
        run(() => axios.post(`/api/extensions/${extension.slug}/toggle`, { enabled: !extension.enabled }));

    async function uninstall() {
        if (!confirm(`¿Desinstalar «${extension.name}»? Se perderán sus ajustes.`)) return;
        await run(() => axios.delete(`/api/extensions/${extension.slug}`));
    }

    async function saveSettings(settings) {
        const ok = await run(() => axios.put(`/api/extensions/${extension.slug}/settings`, { settings }));
        if (ok) {
            setSaved(true);
            setTimeout(() => setSaved(false), 2500);
        }
    }

    return (
        <>
            <Head title={extension.name} />

            <div className="mx-auto flex max-w-5xl flex-col gap-8 p-6 lg:p-8">
                <Link
                    href={route('extensions.index')}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Volver al catálogo
                </Link>

                <Carne
                    extension={extension}
                    dependencia={dependencia}
                    faltaConexion={faltaConexion}
                    busy={busy}
                    can={can}
                    onInstall={install}
                    onToggle={toggle}
                    onUninstall={uninstall}
                />

                {/* Los avisos comparten forma a propósito: con una extensión que
                    depende de un ERP pueden salir dos a la vez, y dos recuadros
                    con borde distinto compitiendo se leen como dos problemas
                    cuando son uno solo contado dos veces. */}
                {error && <Aviso tono="destructive" icono={CircleAlert}>{error}</Aviso>}

                {dependencia && (
                    <Aviso
                        tono={faltaConexion || faltaScope ? 'warning' : 'success'}
                        icono={faltaConexion || faltaScope ? AlertTriangle : Plug}
                    >
                        {faltaConexion ? (
                            <>
                                <p className="font-bold text-foreground">
                                    Necesita tu cuenta de {dependencia.nombre}
                                </p>
                                <p className="mt-1 leading-relaxed text-muted-foreground">
                                    Esta extensión no hace nada por su cuenta: la consulta la resuelve{' '}
                                    {dependencia.nombre}. Conecta tu cuenta en Integraciones y podrás
                                    instalarla.
                                </p>
                                <Link
                                    href={route('integrations.index')}
                                    className="mt-2 inline-flex items-center gap-1 font-bold text-accent-foreground hover:underline"
                                >
                                    Ir a Integraciones <ArrowRight className="size-3.5" />
                                </Link>
                            </>
                        ) : faltaScope ? (
                            <>
                                <p className="font-bold text-foreground">
                                    Tu {dependencia.nombre} está conectado, pero le falta un permiso
                                </p>
                                <p className="mt-1 leading-relaxed text-muted-foreground">
                                    El token no puede «{dependencia.etiqueta}». Pídele a quien administra
                                    tu {dependencia.nombre} que lo reemita con el permiso{' '}
                                    <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground">
                                        {dependencia.scope}
                                    </code>
                                    , que no viene con los demás y hay que pedirlo aparte.
                                </p>
                            </>
                        ) : (
                            <p className="leading-relaxed text-muted-foreground">
                                <span className="font-bold text-foreground">{dependencia.nombre} conectado.</span>{' '}
                                Esta extensión funciona contra tu cuenta de {dependencia.nombre}
                                {dependencia.puede === true && dependencia.etiqueta
                                    ? `, y su token puede «${dependencia.etiqueta.toLowerCase()}».`
                                    : '.'}
                            </p>
                        )}
                    </Aviso>
                )}

                {extension.installed && !extension.enabled && (
                    <Aviso tono="warning" icono={Power}>
                        <p className="leading-relaxed text-muted-foreground">
                            <span className="font-bold text-foreground">Instalada pero apagada.</span>{' '}
                            No hará nada hasta que la enciendas.
                        </p>
                    </Aviso>
                )}

                {/* La maqueta va ANTES del texto, no después: la pregunta que
                    trae a quien abre esta pantalla es «¿qué va a cambiar en mi
                    bandeja?», y se responde antes con una imagen que con tres
                    párrafos. El texto queda para el detalle y los límites. */}
                {tieneMaqueta(extension.slug) && (
                    <section className="space-y-4">
                        <Titulo
                            eyebrow="Vista previa"
                            nota="Un ejemplo con datos inventados, para que se entienda antes de encenderla."
                        >
                            Así se ve
                        </Titulo>
                        <div className="rounded-2xl border bg-card p-5 shadow-sm">
                            <Maqueta slug={extension.slug} />
                        </div>
                    </section>
                )}

                <section className="space-y-4">
                    <Titulo eyebrow="La extensión">Qué hace</Titulo>
                    <div className="rounded-2xl border bg-card p-6 shadow-sm">
                        {/* El primer párrafo entra más grande: es el que resume,
                            y los de abajo son el detalle y los límites. */}
                        <div className="space-y-3.5 text-[15px] leading-relaxed text-foreground">
                            {extension.detail.split('\n\n').map((parrafo, i) => (
                                <p key={i} className={clsx(i === 0 && 'text-base font-medium')}>
                                    {parrafo}
                                </p>
                            ))}
                        </div>
                    </div>
                </section>

                {(extension.permissions?.length > 0 || extension.hooks?.length > 0) && (
                    <section className="space-y-4">
                        <Titulo eyebrow="La letra pequeña">A qué accede y cuándo</Titulo>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Listado titulo="Permisos que pide" icono={ShieldCheck} items={extension.permissions} />
                            <Listado titulo="Cuándo se ejecuta" icono={Zap} items={extension.hooks} />
                        </div>
                    </section>
                )}

                {extension.installed && extension.schema?.length > 0 && (
                    <section className="space-y-4">
                        <Titulo eyebrow="Configuración">Ajustes</Titulo>
                        <div className="rounded-2xl border bg-card p-6 shadow-sm">
                            {saved && (
                                <span className="mb-3 inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success/12 px-2.5 py-1 text-xs font-bold text-success">
                                    <Check className="size-3.5" /> Guardado
                                </span>
                            )}
                            <SettingsForm
                                schema={extension.schema}
                                values={extension.settings ?? {}}
                                disabled={busy || !can('extensions.update')}
                                onSubmit={saveSettings}
                                submitLabel={
                                    <>
                                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                                        Guardar ajustes
                                    </>
                                }
                            />
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

/**
 * El carné de la extensión: qué es, cómo está y qué se puede hacer con ella.
 *
 * En navy —`bg-sidebar`, el mismo token que la barra lateral, que ya es navy en
 * los dos temas— por lo mismo que en «Mi plan»: lo primero que se ve al entrar
 * tiene que ser de qué extensión hablamos y si está encendida, no el enlace de
 * vuelta al catálogo.
 *
 * Los botones van dentro y con clases propias en vez de las variantes de
 * `Button`: `variant="outline"` pinta `bg-background`, que sobre el navy es un
 * rectángulo blanco.
 */
function Carne({ extension, dependencia, faltaConexion, busy, can, onInstall, onToggle, onUninstall }) {
    const Icono = iconFor(extension.icon);

    return (
        <section className="relative overflow-hidden rounded-3xl border border-sidebar-border/70 bg-sidebar text-sidebar-foreground shadow-lg">
            <div aria-hidden className="pointer-events-none absolute -right-24 -top-32 size-80 rounded-full bg-sidebar-primary/20 blur-3xl" />

            <div className="relative flex flex-wrap items-start justify-between gap-6 p-7 lg:p-8">
                <div className="flex min-w-0 flex-1 items-start gap-4">
                    <div className={clsx(
                        'flex size-14 shrink-0 items-center justify-center rounded-2xl',
                        extension.enabled
                            ? 'bg-sidebar-primary/20 text-sidebar-accent-foreground'
                            : 'bg-sidebar-accent/30 text-sidebar-foreground/70'
                    )}>
                        <Icono className="size-7" />
                    </div>

                    <div className="min-w-0">
                        <h1 className="text-3xl font-black tracking-tight text-sidebar-foreground">
                            {extension.name}
                        </h1>
                        <p className="mt-1.5 max-w-xl text-sm leading-relaxed text-sidebar-foreground/70">
                            {extension.description}
                        </p>

                        <div className="mt-3.5 flex flex-wrap items-center gap-2">
                            <Chip>{categoryLabel(extension.category)}</Chip>

                            {/* El estado, en palabras y no sólo en color: un
                                punto gris no distingue «apagada» de «sin
                                instalar», que son dos situaciones con arreglos
                                distintos. */}
                            {extension.installed ? (
                                <Chip tono={extension.enabled ? 'verde' : 'neutro'} punto>
                                    {extension.enabled ? 'Encendida' : 'Apagada'}
                                </Chip>
                            ) : (
                                <Chip>Sin instalar</Chip>
                            )}

                            {dependencia && (
                                <Chip tono={dependencia.conectada ? 'verde' : 'ambar'}>
                                    <Plug className="size-3" />
                                    {dependencia.conectada
                                        ? `${dependencia.nombre} conectado`
                                        : `Necesita ${dependencia.nombre}`}
                                </Chip>
                            )}
                        </div>

                        {extension.installed && extension.installed_at && (
                            <p className="mt-3 text-xs text-sidebar-foreground/50">
                                Instalada el {new Date(extension.installed_at).toLocaleDateString('es-CO')}
                                {extension.installed_by ? ` por ${extension.installed_by}` : ''}.
                            </p>
                        )}
                    </div>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {/* Fuera de plan, el candado en vez del botón. La ficha se
                        puede leer entera sin tenerla contratada —para eso se
                        enseña—, pero instalarla no. Y con salida: decirle a
                        alguien que no puede y no adónde ir es la forma de que
                        no pregunte. */}
                    {extension.en_plan === false ? (
                        <BotonSecundario as={Link} href={route('mi-plan')}>
                            <Lock className="size-4" /> No incluido — ver mi plan
                        </BotonSecundario>
                    ) : faltaConexion ? (
                        /* Sin la integración que necesita no se ofrece
                           «Instalar»: el servidor lo rechaza con un 409, y
                           ofrecer un botón que no puede funcionar sólo sirve
                           para que alguien lo pulse. En su lugar, el camino
                           — que está en otra pantalla. */
                        <BotonSecundario as={Link} href={route('integrations.index')}>
                            <Plug className="size-4" /> Conectar {dependencia.nombre}
                        </BotonSecundario>
                    ) : !extension.installed ? (
                        <BotonPrincipal disabled={busy || !can('extensions.create')} onClick={onInstall}>
                            {busy ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
                            Instalar
                        </BotonPrincipal>
                    ) : (
                        <>
                            {extension.enabled ? (
                                <BotonSecundario disabled={busy || !can('extensions.update')} onClick={onToggle}>
                                    {busy ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
                                    Apagar
                                </BotonSecundario>
                            ) : (
                                <BotonPrincipal disabled={busy || !can('extensions.update')} onClick={onToggle}>
                                    {busy ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
                                    Encender
                                </BotonPrincipal>
                            )}

                            <button
                                type="button"
                                title="Desinstalar"
                                disabled={busy || !can('extensions.delete')}
                                onClick={onUninstall}
                                className="flex size-10 items-center justify-center rounded-xl text-sidebar-foreground/60 transition-colors hover:bg-destructive/30 hover:text-sidebar-foreground disabled:opacity-40"
                            >
                                <Trash2 className="size-4" />
                            </button>
                        </>
                    )}
                </div>
            </div>
        </section>
    );
}

/** El verde de marca lleva SIEMPRE texto navy encima: en blanco daría 2.11:1. */
function BotonPrincipal({ children, ...props }) {
    return (
        <button
            type="button"
            className="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
            {...props}
        >
            {children}
        </button>
    );
}

/** El secundario del carné, que a veces es un enlace y a veces un botón. */
function BotonSecundario({ as: Componente = 'button', children, ...props }) {
    return (
        <Componente
            {...(Componente === 'button' ? { type: 'button' } : {})}
            className="inline-flex items-center gap-1.5 rounded-xl border border-sidebar-border bg-sidebar-accent/30 px-4 py-2.5 text-sm font-bold text-sidebar-foreground transition-colors hover:bg-sidebar-accent/50 disabled:opacity-50"
            {...props}
        >
            {children}
        </Componente>
    );
}

/** Pastilla del carné: sobre navy, así que sus colores son los de la barra. */
function Chip({ tono = 'neutro', punto = false, children }) {
    return (
        <span className={clsx(
            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold',
            tono === 'verde' && 'border-sidebar-primary/40 bg-sidebar-primary/15 text-sidebar-accent-foreground',
            tono === 'ambar' && 'border-warning/40 bg-warning/15 text-warning',
            tono === 'neutro' && 'border-sidebar-border bg-sidebar-accent/25 text-sidebar-foreground/70'
        )}>
            {punto && <span className="size-1.5 rounded-full bg-current" />}
            {children}
        </span>
    );
}

/** Los avisos de la pantalla, con la misma forma los tres. */
function Aviso({ tono, icono: Icono, children }) {
    return (
        <div className={clsx(
            'flex items-start gap-3 rounded-2xl border px-4 py-3.5 text-sm',
            tono === 'destructive' && 'border-destructive/30 bg-destructive/10',
            tono === 'warning' && 'border-warning/40 bg-warning/10',
            tono === 'success' && 'border-success/30 bg-success/10'
        )}>
            <Icono className={clsx(
                'mt-0.5 size-4 shrink-0',
                tono === 'destructive' && 'text-destructive',
                tono === 'warning' && 'text-warning',
                tono === 'success' && 'text-success'
            )} />
            <div className={clsx('min-w-0 flex-1', tono === 'destructive' && 'text-destructive')}>
                {children}
            </div>
        </div>
    );
}

/** Una lista de la letra pequeña: permisos o disparadores. */
function Listado({ titulo, icono: Icono, items = [] }) {
    if (items.length === 0) return null;

    return (
        <div className="rounded-2xl border bg-card p-5 shadow-sm">
            <h3 className="flex items-center gap-2.5 text-sm font-bold text-foreground">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/20 text-accent-foreground">
                    <Icono className="size-3.5" />
                </span>
                {titulo}
            </h3>
            <ul className="mt-3.5 space-y-2.5">
                {items.map((item) => (
                    <li key={item} className="flex items-start gap-2.5 text-[13.5px] leading-relaxed text-foreground">
                        <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/25 text-accent-foreground">
                            <Check className="size-2.5" strokeWidth={3.5} />
                        </span>
                        {item}
                    </li>
                ))}
            </ul>
        </div>
    );
}

// Inertia 3 invoca este callback DOS VECES y con formas distintas: primero con
// las props peladas, para averiguar si es una render function, y sólo después
// con el elemento ya creado. Leer `page.props` a secas reventaba en esa primera
// llamada —ahí `props.props` no existe— y se llevaba por delante toda la pagina.
ExtensionShow.layout = (page) => {
    const nombre = page?.props?.extension?.name ?? page?.extension?.name;

    return (
        <AppLayout breadcrumb={nombre ? ['Extensiones', nombre] : ['Extensiones']}>
            {page}
        </AppLayout>
    );
};
