import { useMemo, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Blocks, Search, Download, Power, Settings2, Check, Loader2, Info } from 'lucide-react';
import { iconFor, CATEGORIES, categoryLabel } from './icons';

export default function ExtensionsIndex({ extensions: initial }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [extensions, setExtensions] = useState(initial ?? []);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();

        return extensions
            .filter((e) => category === 'all' || e.category === category)
            .filter((e) => !q || e.name.toLowerCase().includes(q) || e.description.toLowerCase().includes(q))
            // Las instaladas arriba: son las que el admin vuelve a abrir. El
            // catálogo completo se explora una vez; lo instalado se revisa.
            .sort((a, b) => (b.installed ? 1 : 0) - (a.installed ? 1 : 0));
    }, [extensions, search, category]);

    const instaladas = extensions.filter((e) => e.installed).length;

    function replace(updated) {
        setExtensions((prev) => prev.map((e) => (e.slug === updated.slug ? { ...e, ...updated } : e)));
    }

    async function run(slug, action) {
        setBusy(slug);
        setError(null);
        try {
            const { data } = await action();
            replace(data);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudo completar la acción.');
        } finally {
            setBusy(null);
        }
    }

    const install = (e) => run(e.slug, () => axios.post(`/api/extensions/${e.slug}/install`));
    const toggle = (e) => run(e.slug, () => axios.post(`/api/extensions/${e.slug}/toggle`, { enabled: !e.enabled }));

    return (
        <>
            <Head title="Extensiones" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <Blocks className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-foreground">Extensiones</h1>
                            <p className="text-sm text-muted-foreground mt-0.5">
                                Añade comportamientos a tu CRM. Instala lo que necesites, configúralo y enciéndelo cuando esté listo.
                            </p>
                        </div>
                    </div>
                    <span className="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                        {instaladas} de {extensions.length} instaladas
                    </span>
                </div>

                {error && (
                    <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        {error}
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative flex-1 min-w-[16rem] max-w-sm">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Buscar extensión…"
                            className="h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {CATEGORIES.map((c) => (
                            <button
                                key={c.value}
                                onClick={() => setCategory(c.value)}
                                className={clsx(
                                    'rounded-full px-3 py-1.5 text-xs font-medium transition-colors',
                                    category === c.value
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground hover:bg-muted/70'
                                )}
                            >
                                {c.label}
                            </button>
                        ))}
                    </div>
                </div>

                {filtered.length === 0 ? (
                    <div className="rounded-xl border border-dashed py-16 text-center text-sm text-muted-foreground">
                        No hay extensiones que coincidan con lo que buscas.
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {filtered.map((extension) => (
                            <ExtensionCard
                                key={extension.slug}
                                extension={extension}
                                busy={busy === extension.slug}
                                canInstall={can('extensions.create')}
                                canUpdate={can('extensions.update')}
                                onInstall={() => install(extension)}
                                onToggle={() => toggle(extension)}
                            />
                        ))}
                    </div>
                )}

                <div className="flex items-start gap-3 p-4 bg-muted/40 rounded-xl border text-xs text-muted-foreground max-w-3xl">
                    <Info className="size-4 text-primary shrink-0 mt-0.5" />
                    <p>
                        Instalar y encender son dos cosas distintas: una extensión recién instalada no hace nada
                        hasta que la enciendes, para que puedas revisar sus ajustes con calma. Apagarla conserva
                        la configuración; desinstalarla la borra.
                    </p>
                </div>
            </div>
        </>
    );
}

function ExtensionCard({ extension, busy, canInstall, canUpdate, onInstall, onToggle }) {
    const Icon = iconFor(extension.icon);

    return (
        <div
            className={clsx(
                'flex flex-col rounded-xl border bg-card p-5 transition-colors',
                extension.installed && extension.enabled ? 'border-primary/40' : 'hover:border-primary/30'
            )}
        >
            <div className="flex items-start gap-3">
                <div
                    className={clsx(
                        'size-10 shrink-0 rounded-xl flex items-center justify-center',
                        extension.enabled ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'
                    )}
                >
                    <Icon className="size-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <Link
                            href={route('extensions.show', extension.slug)}
                            className="text-sm font-semibold text-foreground hover:underline"
                        >
                            {extension.name}
                        </Link>
                        {extension.installed && (
                            <span
                                className={clsx(
                                    'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    extension.enabled
                                        ? 'bg-success/15 text-success'
                                        : 'bg-muted text-muted-foreground'
                                )}
                            >
                                {extension.enabled ? 'Encendida' : 'Apagada'}
                            </span>
                        )}
                    </div>
                    <span className="mt-1 inline-block rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                        {categoryLabel(extension.category)}
                    </span>
                </div>
            </div>

            <p className="mt-3 flex-1 text-sm text-muted-foreground leading-relaxed">{extension.description}</p>

            <div className="mt-4 flex items-center gap-2">
                {!extension.installed ? (
                    <Button size="sm" className="gap-2" disabled={busy || !canInstall} onClick={onInstall}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
                        Instalar
                    </Button>
                ) : (
                    <>
                        <Button
                            size="sm"
                            variant={extension.enabled ? 'outline' : 'default'}
                            className="gap-2"
                            disabled={busy || !canUpdate}
                            onClick={onToggle}
                        >
                            {busy ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
                            {extension.enabled ? 'Apagar' : 'Encender'}
                        </Button>
                        <Button size="sm" variant="ghost" className="gap-2" asChild>
                            <Link href={route('extensions.show', extension.slug)}>
                                <Settings2 className="size-4" /> Ajustes
                            </Link>
                        </Button>
                    </>
                )}
                {extension.installed && !extension.enabled && (
                    <span className="ml-auto text-[11px] text-muted-foreground">Instalada</span>
                )}
                {extension.installed && extension.enabled && (
                    <span className="ml-auto inline-flex items-center gap-1 text-[11px] text-success">
                        <Check className="size-3" /> Activa
                    </span>
                )}
            </div>
        </div>
    );
}

ExtensionsIndex.layout = (page) => <AppLayout breadcrumb={['Extensiones']}>{page}</AppLayout>;
