import { useEffect, useMemo, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import TemplateFormModal from './TemplateFormModal';
import { TabButton, WhatsAppPreview, templateToModel } from './preview';
import {
    FileText,
    Search,
    RefreshCw,
    ChevronDown,
    ChevronRight,
    Languages,
    X,
    Loader2,
    Inbox,
    Plus,
    BarChart3,
    CheckCircle2,
    Clock,
    XCircle,
    Megaphone,
    Wrench,
    KeyRound,
    Eye,
    Sparkles,
    FileSearch,
    Smartphone,
} from 'lucide-react';

const STATUS_STYLES = {
    APPROVED: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 ring-1 ring-inset ring-emerald-500/30',
    PENDING: 'bg-amber-500/15 text-amber-600 dark:text-amber-400 ring-1 ring-inset ring-amber-500/30',
    REJECTED: 'bg-red-500/15 text-red-600 dark:text-red-400 ring-1 ring-inset ring-red-500/30',
    DISABLED: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400 ring-1 ring-inset ring-zinc-500/30',
    PAUSED: 'bg-orange-500/15 text-orange-600 dark:text-orange-400 ring-1 ring-inset ring-orange-500/30',
    IN_APPEAL: 'bg-sky-500/15 text-sky-600 dark:text-sky-400 ring-1 ring-inset ring-sky-500/30',
    DELETED: 'bg-red-500/15 text-red-600 dark:text-red-400 ring-1 ring-inset ring-red-500/30',
};

const STATUS_DOT = {
    APPROVED: 'bg-emerald-500',
    PENDING: 'bg-amber-500',
    REJECTED: 'bg-red-500',
    DISABLED: 'bg-zinc-500',
    PAUSED: 'bg-orange-500',
    IN_APPEAL: 'bg-sky-500',
    DELETED: 'bg-red-500',
};

const CATEGORY_STYLES = {
    MARKETING: 'bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-400 ring-1 ring-inset ring-fuchsia-500/30',
    UTILITY: 'bg-blue-500/15 text-blue-600 dark:text-blue-400 ring-1 ring-inset ring-blue-500/30',
    AUTHENTICATION: 'bg-teal-500/15 text-teal-600 dark:text-teal-400 ring-1 ring-inset ring-teal-500/30',
};

const CATEGORY_ICONS = {
    MARKETING: Megaphone,
    UTILITY: Wrench,
    AUTHENTICATION: KeyRound,
};

const LANG_LABELS = {
    es: 'Español', es_AR: 'Español (AR)', es_ES: 'Español (ES)', es_MX: 'Español (MX)',
    en: 'Inglés', en_US: 'Inglés (US)', en_GB: 'Inglés (UK)',
    pt_BR: 'Portugués (BR)', pt_PT: 'Portugués (PT)',
    fr: 'Francés', it: 'Italiano', de: 'Alemán',
};

export default function TemplatesIndex({ instances = [] }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [instanceId, setInstanceId] = useState(instances[0]?.id ?? null);
    const [templates, setTemplates] = useState([]);
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [expanded, setExpanded] = useState(() => new Set());
    const [detail, setDetail] = useState(null);
    const [createMode, setCreateMode] = useState(null);

    useEffect(() => {
        if (instanceId) load();
    }, [instanceId]);

    async function load() {
        if (!instanceId) return;
        setLoading(true);
        setError(null);
        try {
            const { data } = await axios.get('/api/templates', {
                params: { instance_id: instanceId, limit: 200 },
            });
            setTemplates(data.data || []);
            setSummary(data.summary || null);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudieron cargar las plantillas.');
            setTemplates([]);
            setSummary(null);
        } finally {
            setLoading(false);
        }
    }

    const families = useMemo(() => {
        const q = search.trim().toLowerCase();
        const map = new Map();
        for (const t of templates) {
            if (statusFilter && t.status !== statusFilter) continue;
            if (categoryFilter && t.category !== categoryFilter) continue;
            if (q && !t.name.toLowerCase().includes(q)) continue;
            if (!map.has(t.name)) map.set(t.name, []);
            map.get(t.name).push(t);
        }
        return Array.from(map.entries())
            .map(([name, variants]) => ({
                name,
                variants: variants.sort((a, b) => (a.language ?? '').localeCompare(b.language ?? '')),
                category: variants[0]?.category,
            }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [templates, search, statusFilter, categoryFilter]);

    function toggle(name) {
        setExpanded(prev => {
            const next = new Set(prev);
            next.has(name) ? next.delete(name) : next.add(name);
            return next;
        });
    }

    function openDetail(template) {
        setDetail({ id: template.id, name: template.name });
    }

    const stats = useMemo(() => {
        const s = { total: templates.length, approved: 0, pending: 0, rejected: 0, families: 0 };
        const names = new Set();
        for (const t of templates) {
            names.add(t.name);
            if (t.status === 'APPROVED') s.approved++;
            else if (t.status === 'PENDING') s.pending++;
            else if (t.status === 'REJECTED') s.rejected++;
        }
        s.families = names.size;
        return s;
    }, [templates]);

    return (
        <>
            <Head title="Plantillas" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                {/* HERO HEADER */}
                <div className="relative overflow-hidden rounded-2xl border bg-gradient-to-br from-primary/10 via-card to-card p-6 lg:p-8">
                    <div className="absolute -top-12 -right-12 size-48 rounded-full bg-primary/10 blur-3xl pointer-events-none" />
                    <div className="relative flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                        <div className="flex items-center gap-4">
                            <div className="size-14 rounded-2xl bg-primary/15 text-primary flex items-center justify-center ring-1 ring-primary/20">
                                <FileText className="size-7" />
                            </div>
                            <div>
                                <h1 className="text-2xl lg:text-3xl font-semibold text-foreground tracking-tight">
                                    Plantillas de WhatsApp
                                </h1>
                                <p className="text-sm text-muted-foreground mt-1 max-w-xl">
                                    Administra las plantillas aprobadas por Meta y sus traducciones a distintos idiomas.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {instances.length > 1 && (
                                <select
                                    value={instanceId ?? ''}
                                    onChange={e => setInstanceId(Number(e.target.value) || null)}
                                    className="h-9 rounded-lg border border-input bg-card/80 px-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring/50"
                                >
                                    {instances.map(i => (
                                        <option key={i.id} value={i.id}>{i.name} ({i.display_phone_number})</option>
                                    ))}
                                </select>
                            )}
                            <Button onClick={load} disabled={loading || !instanceId} variant="outline" className="gap-2 h-9 bg-card/80">
                                {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                                Actualizar
                            </Button>
                            <Link href={route('templates.analytics')}>
                                <Button variant="outline" className="gap-2 h-9 bg-card/80">
                                    <BarChart3 className="size-4" /> Analítica
                                </Button>
                            </Link>
                            {can('templates.create') && instanceId && (
                                <Button onClick={() => setCreateMode({ mode: 'new' })} className="gap-2 h-9 shadow-md">
                                    <Sparkles className="size-4" /> Nueva plantilla
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {instances.length === 0 && (
                    <div className="rounded-2xl border border-dashed py-12 text-center text-sm text-muted-foreground">
                        No hay instancias con WABA configurado. Configura una instancia para consultar plantillas.
                    </div>
                )}

                {/* STATS */}
                {instances.length > 0 && templates.length > 0 && (
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <StatCard icon={FileText} label="Total" value={stats.total} tone="primary" />
                        <StatCard icon={Languages} label="Familias" value={stats.families} tone="indigo" />
                        <StatCard icon={CheckCircle2} label="Aprobadas" value={stats.approved} tone="emerald" />
                        <StatCard icon={Clock} label="Pendientes" value={stats.pending} tone="amber" />
                    </div>
                )}

                {/* FILTER BAR */}
                {instances.length > 0 && (
                    <div className="flex flex-col sm:flex-row gap-2 p-2 rounded-xl border bg-card">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Buscar por nombre de plantilla..."
                                className="flex h-10 w-full rounded-lg border-0 bg-transparent pl-10 pr-3 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                            />
                        </div>
                        <div className="flex gap-2">
                            <select
                                value={statusFilter}
                                onChange={e => setStatusFilter(e.target.value)}
                                className="h-10 rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring/50"
                            >
                                <option value="">Todos los estados</option>
                                <option value="APPROVED">Aprobada</option>
                                <option value="PENDING">Pendiente</option>
                                <option value="REJECTED">Rechazada</option>
                                <option value="DISABLED">Deshabilitada</option>
                                <option value="PAUSED">Pausada</option>
                                <option value="IN_APPEAL">En apelación</option>
                            </select>
                            <select
                                value={categoryFilter}
                                onChange={e => setCategoryFilter(e.target.value)}
                                className="h-10 rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring/50"
                            >
                                <option value="">Todas las categorías</option>
                                <option value="MARKETING">Marketing</option>
                                <option value="UTILITY">Utilidad</option>
                                <option value="AUTHENTICATION">Autenticación</option>
                            </select>
                            {(search || statusFilter || categoryFilter) && (
                                <Button
                                    variant="ghost"
                                    onClick={() => { setSearch(''); setStatusFilter(''); setCategoryFilter(''); }}
                                    className="h-10 gap-1 text-muted-foreground"
                                >
                                    <X className="size-4" /> Limpiar
                                </Button>
                            )}
                        </div>
                    </div>
                )}

                {error && (
                    <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        {error}
                    </div>
                )}

                {loading && templates.length === 0 ? (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        {Array.from({ length: 6 }).map((_, i) => (
                            <div key={i} className="rounded-xl border bg-card p-5 animate-pulse">
                                <div className="h-5 w-40 rounded bg-muted mb-3" />
                                <div className="flex gap-2 mb-4">
                                    <div className="h-5 w-16 rounded bg-muted" />
                                    <div className="h-5 w-12 rounded bg-muted" />
                                </div>
                                <div className="flex gap-2">
                                    <div className="h-7 w-20 rounded-full bg-muted" />
                                    <div className="h-7 w-20 rounded-full bg-muted" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : families.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed py-20 text-center bg-card">
                        <div className="size-16 rounded-2xl bg-muted/50 flex items-center justify-center mb-4">
                            <Inbox className="size-8 text-muted-foreground/60" />
                        </div>
                        <p className="text-lg font-medium text-foreground">Sin plantillas para mostrar</p>
                        <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                            {templates.length === 0
                                ? 'No se encontraron plantillas en esta WABA. Crea la primera.'
                                : 'Ningún resultado coincide con los filtros aplicados.'}
                        </p>
                        {templates.length === 0 && can('templates.create') && instanceId && (
                            <Button onClick={() => setCreateMode({ mode: 'new' })} className="mt-6 gap-2">
                                <Sparkles className="size-4" /> Crear primera plantilla
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        {families.map(family => (
                            <FamilyCard
                                key={family.name}
                                family={family}
                                isOpen={expanded.has(family.name)}
                                onToggle={() => toggle(family.name)}
                                onOpenDetail={openDetail}
                                canCreate={can('templates.create')}
                                onAddTranslation={() => setCreateMode({
                                    mode: 'translation',
                                    family,
                                    sourceTemplate: family.variants.find(v => v.status === 'APPROVED') ?? family.variants[0],
                                })}
                            />
                        ))}
                    </div>
                )}
            </div>

            {detail && (
                <TemplateDetailModal
                    templateId={detail.id}
                    templateName={detail.name}
                    instanceId={instanceId}
                    onClose={() => setDetail(null)}
                    onSelectSibling={(sibling) => setDetail({ id: sibling.id, name: sibling.name })}
                />
            )}

            {createMode && (
                <TemplateFormModal
                    mode={createMode.mode}
                    instanceId={instanceId}
                    family={createMode.family}
                    sourceTemplate={createMode.sourceTemplate}
                    onClose={() => setCreateMode(null)}
                    onCreated={() => {
                        setCreateMode(null);
                        load();
                    }}
                />
            )}
        </>
    );
}

function StatCard({ icon: Icon, label, value, tone }) {
    const tones = {
        primary: 'bg-primary/10 text-primary',
        emerald: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        indigo: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
        red: 'bg-red-500/10 text-red-600 dark:text-red-400',
    };
    return (
        <div className="rounded-xl border bg-card p-4 flex items-center gap-3 hover:shadow-sm transition-shadow">
            <div className={`size-10 rounded-lg flex items-center justify-center ${tones[tone] ?? tones.primary}`}>
                <Icon className="size-5" />
            </div>
            <div>
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="text-xl font-semibold text-foreground tabular-nums">{value}</div>
            </div>
        </div>
    );
}

function FamilyCard({ family, isOpen, onToggle, onOpenDetail, canCreate, onAddTranslation }) {
    const CatIcon = CATEGORY_ICONS[family.category] ?? FileText;
    const variantCount = family.variants.length;
    const approvedCount = family.variants.filter(v => v.status === 'APPROVED').length;

    return (
        <div className="group rounded-xl border bg-card overflow-hidden transition-all hover:border-primary/40 hover:shadow-md">
            <div className="p-4 sm:p-5">
                <div className="flex items-start justify-between gap-3 mb-3">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2 mb-2">
                            {family.category && (
                                <span className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${CATEGORY_STYLES[family.category] ?? 'bg-muted text-muted-foreground'}`}>
                                    <CatIcon className="size-3" />
                                    {family.category}
                                </span>
                            )}
                            <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                <Languages className="size-3" />
                                {variantCount} {variantCount === 1 ? 'idioma' : 'idiomas'}
                            </span>
                            {approvedCount > 0 && (
                                <span className="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400">
                                    <CheckCircle2 className="size-3" />
                                    {approvedCount} aprobada{approvedCount > 1 ? 's' : ''}
                                </span>
                            )}
                        </div>
                        <h3 className="font-mono text-base font-semibold text-foreground truncate" title={family.name}>
                            {family.name}
                        </h3>
                    </div>
                </div>

                {/* Language pills */}
                <div className="flex flex-wrap gap-1.5">
                    {family.variants.map(v => (
                        <button
                            key={v.id}
                            onClick={() => onOpenDetail(v)}
                            title={`Ver detalle · ${LANG_LABELS[v.language] ?? v.language} · ${v.status}`}
                            className="group/pill inline-flex items-center gap-1.5 rounded-full border bg-background pl-2 pr-2.5 py-1 text-xs font-mono hover:border-primary/50 hover:bg-primary/5 transition-colors"
                        >
                            <span className={`size-1.5 rounded-full ${STATUS_DOT[v.status] ?? 'bg-muted'}`} />
                            <span className="font-medium">{v.language}</span>
                            <Eye className="size-3 opacity-0 group-hover/pill:opacity-100 transition-opacity text-muted-foreground" />
                        </button>
                    ))}
                    {canCreate && (
                        <button
                            onClick={onAddTranslation}
                            className="inline-flex items-center gap-1 rounded-full border border-dashed border-primary/40 px-2.5 py-1 text-xs text-primary hover:bg-primary/10 transition-colors"
                            title="Crear traducción basada en esta plantilla"
                        >
                            <Plus className="size-3" /> Traducción
                        </button>
                    )}
                </div>

                {isOpen && (
                    <div className="mt-4 pt-4 border-t space-y-1.5">
                        {family.variants.map(v => (
                            <button
                                key={v.id}
                                onClick={() => onOpenDetail(v)}
                                className="w-full flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-muted/50 transition-colors text-left"
                            >
                                <div className="flex items-center gap-2 min-w-0">
                                    <span className={`size-2 rounded-full ${STATUS_DOT[v.status] ?? 'bg-muted'}`} />
                                    <span className="font-mono text-sm">{v.language}</span>
                                    <span className="text-[10px] text-muted-foreground truncate">id: {v.id}</span>
                                </div>
                                <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[v.status] ?? 'bg-muted text-muted-foreground'}`}>
                                    {v.status}
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {/* Footer: expand toggle */}
            {variantCount > 0 && (
                <button
                    onClick={onToggle}
                    className="w-full flex items-center justify-center gap-1.5 border-t bg-muted/20 hover:bg-muted/40 transition-colors text-[11px] text-muted-foreground py-1.5"
                >
                    {isOpen ? <ChevronDown className="size-3" /> : <ChevronRight className="size-3" />}
                    {isOpen ? 'Ocultar detalle' : 'Ver detalle por idioma'}
                </button>
            )}
        </div>
    );
}

function TemplateDetailModal({ templateId, templateName, instanceId, onClose, onSelectSibling }) {
    const [template, setTemplate] = useState(null);
    const [siblings, setSiblings] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [tab, setTab] = useState('detail');

    useEffect(() => {
        setTab('detail');
    }, [templateId]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError(null);
        Promise.all([
            axios.get(`/api/templates/${templateId}`, { params: { instance_id: instanceId } }),
            axios.get(`/api/templates/family/${encodeURIComponent(templateName)}`, { params: { instance_id: instanceId } }),
        ])
            .then(([detailRes, famRes]) => {
                if (cancelled) return;
                setTemplate(detailRes.data.data);
                setSiblings(famRes.data.data || []);
            })
            .catch(err => {
                if (cancelled) return;
                setError(err?.response?.data?.message ?? 'No se pudo cargar el detalle.');
            })
            .finally(() => !cancelled && setLoading(false));
        return () => { cancelled = true; };
    }, [templateId, templateName, instanceId]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-xl border bg-card shadow-2xl" onClick={e => e.stopPropagation()}>
                <div className="sticky top-0 bg-card border-b px-6 py-4 flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-foreground font-mono truncate">{templateName}</h2>
                        <p className="text-xs text-muted-foreground mt-0.5">Detalle de plantilla y traducciones</p>
                    </div>
                    <Button variant="ghost" size="icon" onClick={onClose}>
                        <X className="size-4" />
                    </Button>
                </div>

                {/* Tab strip */}
                <div className="sticky top-[73px] z-10 bg-card border-b px-6 flex gap-1">
                    <TabButton active={tab === 'detail'} onClick={() => setTab('detail')} icon={FileSearch}>
                        Detalle
                    </TabButton>
                    <TabButton active={tab === 'preview'} onClick={() => setTab('preview')} icon={Smartphone}>
                        Vista previa
                    </TabButton>
                </div>

                <div className="px-6 py-5 space-y-5">
                    {loading && (
                        <div className="flex items-center justify-center py-10 text-muted-foreground">
                            <Loader2 className="size-5 animate-spin mr-2" /> Cargando detalle...
                        </div>
                    )}

                    {!loading && template && tab === 'preview' && (
                        <WhatsAppPreview
                            model={templateToModel(template)}
                            verifiedName={templateName}
                            empty="Esta plantilla no tiene componentes para previsualizar."
                        />
                    )}

                    {error && (
                        <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                            {error}
                        </div>
                    )}

                    {!loading && template && tab === 'detail' && (
                        <>
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                                <Field label="ID" value={template.id} mono />
                                <Field label="Idioma" value={template.language} mono />
                                <Field label="Categoría">
                                    {template.category && (
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${CATEGORY_STYLES[template.category] ?? 'bg-muted text-muted-foreground'}`}>
                                            {template.category}
                                        </span>
                                    )}
                                </Field>
                                <Field label="Estado">
                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold ${STATUS_STYLES[template.status] ?? 'bg-muted text-muted-foreground'}`}>
                                        {template.status}
                                    </span>
                                </Field>
                            </div>

                            {template.rejected_reason && template.rejected_reason !== 'NONE' && (
                                <div className="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                                    <strong>Motivo de rechazo:</strong> {template.rejected_reason}
                                </div>
                            )}

                            <div>
                                <h3 className="text-sm font-semibold text-foreground mb-2">Componentes</h3>
                                <div className="space-y-2">
                                    {(template.components || []).map((c, i) => (
                                        <ComponentBlock key={i} component={c} />
                                    ))}
                                </div>
                            </div>

                            <details className="rounded-md border bg-muted/30">
                                <summary className="cursor-pointer px-3 py-2 text-xs font-medium text-muted-foreground select-none">
                                    Ver JSON crudo
                                </summary>
                                <pre className="px-3 pb-3 text-[11px] overflow-x-auto text-foreground">
{JSON.stringify(template, null, 2)}
                                </pre>
                            </details>

                            <div>
                                <h3 className="text-sm font-semibold text-foreground mb-2 flex items-center gap-2">
                                    <Languages className="size-4" /> Traducciones de la familia
                                    <span className="text-xs font-normal text-muted-foreground">({siblings.length})</span>
                                </h3>
                                <div className="flex flex-wrap gap-2">
                                    {siblings.map(s => {
                                        const active = String(s.id) === String(template.id);
                                        return (
                                            <button
                                                key={s.id}
                                                onClick={() => !active && onSelectSibling(s)}
                                                disabled={active}
                                                className={`inline-flex items-center gap-2 rounded-md border px-2 py-1 text-xs transition-colors ${
                                                    active
                                                        ? 'bg-primary/10 text-primary border-primary/30 cursor-default'
                                                        : 'hover:bg-muted'
                                                }`}
                                            >
                                                <span className="font-mono">{s.language}</span>
                                                <span className={`inline-flex items-center rounded px-1 py-0.5 text-[9px] font-semibold ${STATUS_STYLES[s.status] ?? 'bg-muted text-muted-foreground'}`}>
                                                    {s.status}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

function Field({ label, value, mono, children }) {
    return (
        <div>
            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">{label}</div>
            <div className={`mt-0.5 text-sm text-foreground ${mono ? 'font-mono' : ''}`}>
                {children ?? value ?? '—'}
            </div>
        </div>
    );
}

function ComponentBlock({ component }) {
    const type = component.type;
    return (
        <div className="rounded-md border bg-background px-3 py-2">
            <div className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">
                {type}{component.format ? ` · ${component.format}` : ''}
            </div>
            {component.text && (
                <p className="text-sm text-foreground whitespace-pre-wrap break-words">{component.text}</p>
            )}
            {component.example && (
                <pre className="mt-1 text-[11px] text-muted-foreground bg-muted/40 rounded px-2 py-1 overflow-x-auto">
{JSON.stringify(component.example, null, 2)}
                </pre>
            )}
            {Array.isArray(component.buttons) && component.buttons.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {component.buttons.map((b, i) => (
                        <span key={i} className="inline-flex items-center rounded-md border px-2 py-0.5 text-xs bg-muted/40">
                            {b.type}: {b.text}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

TemplatesIndex.layout = page => <AppLayout breadcrumb={['Plantillas']}>{page}</AppLayout>;
