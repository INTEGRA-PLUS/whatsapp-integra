import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    ArrowLeft, Download, Power, Trash2, Loader2, Save, ShieldCheck, Zap, Check,
} from 'lucide-react';
import { iconFor, categoryLabel } from './icons';
import SettingsForm from './SettingsForm';

export default function ExtensionShow({ extension: initial }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [extension, setExtension] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [saved, setSaved] = useState(false);

    const Icon = iconFor(extension.icon);

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
                        {!extension.installed ? (
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

                {extension.installed && !extension.enabled && (
                    <div className="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">
                        Está instalada pero apagada: no hará nada hasta que la enciendas.
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
