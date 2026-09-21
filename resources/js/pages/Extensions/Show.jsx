import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    ArrowLeft, Download, Power, Trash2, Loader2, Save, ShieldCheck, Zap, Check, Eye, Lock,
    Plug, AlertTriangle, ArrowRight,
} from 'lucide-react';
import { iconFor, categoryLabel } from './icons';
import { Maqueta, tieneMaqueta } from './maquetas';
import SettingsForm from './SettingsForm';

export default function ExtensionShow({ extension: initial }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [extension, setExtension] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [saved, setSaved] = useState(false);

    const Icon = iconFor(extension.icon);

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
            <div className="flex flex-col gap-6 p-6 lg:p-8 max-w-4xl">
                <Link
                    href={route('extensions.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit"
                >
                    <ArrowLeft className="size-4" /> Volver al catálogo
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-4">
                        <div
                            className={clsx(
                                'size-14 shrink-0 rounded-2xl flex items-center justify-center',
                                extension.enabled ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'
                            )}
                        >
                            <Icon className="size-7" />
                        </div>
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold text-foreground">{extension.name}</h1>
                                {extension.installed && (
                                    <span
                                        className={clsx(
                                            'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                            extension.enabled
                                                ? 'bg-success/15 text-success'
                                                : 'bg-muted text-muted-foreground'
                                        )}
                                    >
                                        {extension.enabled ? 'Encendida' : 'Apagada'}
                                    </span>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground mt-1">{extension.description}</p>
                            <span className="mt-2 inline-block rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                                {categoryLabel(extension.category)}
                            </span>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Fuera de plan, el candado en vez del botón. Antes
                            aquí salía «Instalar» igual que en cualquier otra, y
                            quien lo pulsaba se comía el 402 del servidor: la
                            ficha se puede leer entera sin tenerla contratada
                            —para eso se enseña—, pero instalarla no. */}
                        {extension.en_plan === false ? (
                            <span className="inline-flex items-center gap-1.5 rounded-md bg-muted px-3 py-2 text-sm font-semibold text-muted-foreground">
                                <Lock className="size-4" /> No incluido en tu plan
                            </span>
                        ) : faltaConexion ? (
                            /* Sin la integración que necesita no se ofrece
                               «Instalar»: el servidor lo rechaza con un 409 y
                               ofrecer un botón que no puede funcionar sólo
                               sirve para que alguien lo pulse. En su lugar, el
                               camino — que está en otra pantalla. */
                            <Button asChild variant="outline" className="gap-2">
                                <Link href={route('integrations.index')}>
                                    <Plug className="size-4" /> Conectar {extension.dependencia.nombre}
                                </Link>
                            </Button>
                        ) : !extension.installed ? (
                            <Button className="gap-2" disabled={busy || !can('extensions.create')} onClick={install}>
                                {busy ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
                                Instalar
                            </Button>
                        ) : (
                            <>
                                <Button
                                    variant={extension.enabled ? 'outline' : 'default'}
                                    className="gap-2"
                                    disabled={busy || !can('extensions.update')}
                                    onClick={toggle}
                                >
                                    {busy ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
                                    {extension.enabled ? 'Apagar' : 'Encender'}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="text-destructive hover:bg-destructive/10"
                                    title="Desinstalar"
                                    disabled={busy || !can('extensions.delete')}
                                    onClick={uninstall}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {error && (
                    <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        {error}
                    </div>
                )}

                {dependencia && (
                    <div className={clsx(
                        'flex items-start gap-3 rounded-xl border px-4 py-3.5',
                        faltaConexion || faltaScope
                            ? 'border-warning/40 bg-warning/10'
                            : 'border-success/30 bg-success/10'
                    )}>
                        {faltaConexion || faltaScope
                            ? <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
                            : <Plug className="mt-0.5 size-4 shrink-0 text-success" />}

                        <div className="min-w-0 flex-1 text-sm">
                            {faltaConexion ? (
                                <>
                                    <p className="font-semibold text-foreground">
                                        Necesita tu cuenta de {dependencia.nombre}
                                    </p>
                                    <p className="mt-0.5 leading-relaxed text-muted-foreground">
                                        Esta extensión no hace nada por su cuenta: la consulta la
                                        resuelve {dependencia.nombre}. Conecta tu cuenta en
                                        Integraciones y podrás instalarla.
                                    </p>
                                    <Link
                                        href={route('integrations.index')}
                                        className="mt-1.5 inline-flex items-center gap-1 font-bold text-accent-foreground hover:underline"
                                    >
                                        Ir a Integraciones <ArrowRight className="size-3.5" />
                                    </Link>
                                </>
                            ) : faltaScope ? (
                                <>
                                    <p className="font-semibold text-foreground">
                                        Tu {dependencia.nombre} está conectado, pero le falta un permiso
                                    </p>
                                    <p className="mt-0.5 leading-relaxed text-muted-foreground">
                                        El token no puede «{dependencia.etiqueta}». Pídele a quien
                                        administra tu {dependencia.nombre} que lo reemita con el
                                        permiso <code className="rounded bg-muted px-1 py-px font-mono text-xs">{dependencia.scope}</code>,
                                        que no viene con los demás y hay que pedirlo aparte.
                                    </p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">
                                    <span className="font-semibold text-foreground">
                                        {dependencia.nombre} conectado.
                                    </span>{' '}
                                    Esta extensión funciona contra tu cuenta de {dependencia.nombre}
                                    {dependencia.puede === true && dependencia.etiqueta
                                        ? `, y su token puede «${dependencia.etiqueta.toLowerCase()}».`
                                        : '.'}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {extension.installed && !extension.enabled && (
                    <div className="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">
                        Está instalada pero apagada: no hará nada hasta que la enciendas.
                    </div>
                )}

                {/* La maqueta va ANTES del texto, no después: la pregunta que
                    trae a quien abre esta pantalla es «¿qué va a cambiar en mi
                    bandeja?», y se responde antes con una imagen que con tres
                    párrafos. El texto queda para el detalle y los límites. */}
                {tieneMaqueta(extension.slug) && (
                    <div className="rounded-xl border bg-card p-5">
                        <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <Eye className="size-4 text-primary" /> Así se ve
                        </h2>
                        <p className="mt-1 mb-4 text-xs text-muted-foreground">
                            Un ejemplo con datos inventados, para que se entienda antes de encenderla.
                        </p>
                        <Maqueta slug={extension.slug} />
                    </div>
                )}

                <div className="rounded-xl border bg-card p-5">
                    <h2 className="text-sm font-semibold text-foreground">Qué hace</h2>
                    <div className="mt-2 space-y-3 text-sm text-muted-foreground leading-relaxed">
                        {extension.detail.split('\n\n').map((parrafo, i) => (
                            <p key={i}>{parrafo}</p>
                        ))}
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    {extension.permissions?.length > 0 && (
                        <div className="rounded-xl border bg-card p-5">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                                <ShieldCheck className="size-4 text-primary" /> Permisos que pide
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {extension.permissions.map((permiso) => (
                                    <li key={permiso} className="flex items-start gap-2 text-sm text-muted-foreground">
                                        <Check className="size-3.5 mt-0.5 shrink-0 text-muted-foreground/60" />
                                        {permiso}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {extension.hooks?.length > 0 && (
                        <div className="rounded-xl border bg-card p-5">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                                <Zap className="size-4 text-primary" /> Cuándo se ejecuta
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {extension.hooks.map((hook) => (
                                    <li key={hook} className="flex items-start gap-2 text-sm text-muted-foreground">
                                        <Check className="size-3.5 mt-0.5 shrink-0 text-muted-foreground/60" />
                                        {hook}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                {extension.installed && extension.schema?.length > 0 && (
                    <div className="rounded-xl border bg-card p-5">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-foreground">Ajustes</h2>
                            {saved && (
                                <span className="inline-flex items-center gap-1 text-xs text-success">
                                    <Check className="size-3.5" /> Guardado
                                </span>
                            )}
                        </div>
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
                )}

                {extension.installed && extension.installed_at && (
                    <p className="text-xs text-muted-foreground">
                        Instalada el {new Date(extension.installed_at).toLocaleDateString('es-CO')}
                        {extension.installed_by ? ` por ${extension.installed_by}` : ''}.
                    </p>
                )}
            </div>
        </>
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
