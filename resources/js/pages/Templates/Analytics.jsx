import { useEffect, useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    BarChart3,
    Send,
    CheckCheck,
    Eye,
    MousePointerClick,
    Loader2,
    RefreshCw,
    ArrowLeft,
    Inbox,
    AlertTriangle,
    Calendar,
    Sparkles,
    Zap,
    ShieldCheck,
    TrendingUp,
    Filter,
    X,
    MessageSquare,
    DollarSign,
    Layers,
} from 'lucide-react';

const SECONDS_PER_DAY = 86400;

const RANGE_PRESETS = [
    { key: '7d', label: '7 días', days: 7 },
    { key: '30d', label: '30 días', days: 30 },
    { key: '90d', label: '90 días', days: 89 },
];

function unixNow() {
    return Math.floor(Date.now() / 1000);
}

function formatDate(ts) {
    return new Date(ts * 1000).toLocaleDateString('es', { day: '2-digit', month: 'short' });
}

function rate(num, den) {
    if (!den) return 0;
    return Math.round((num / den) * 1000) / 10;
}

export default function TemplatesAnalytics({ instances = [] }) {
    const [tab, setTab] = useState('templates');
    const [instanceId, setInstanceId] = useState(instances[0]?.id ?? null);
    const [rangeKey, setRangeKey] = useState('30d');
    const [start, setStart] = useState(() => unixNow() - 30 * SECONDS_PER_DAY);
    const [end, setEnd] = useState(() => unixNow());
    const [data, setData] = useState({ totals: { sent: 0, delivered: 0, read: 0, clicked: 0 }, templates: [], series: [] });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [hint, setHint] = useState(null);
    const [needsActivation, setNeedsActivation] = useState(false);
    const [activating, setActivating] = useState(false);
    const [activationError, setActivationError] = useState(null);
    const [templateList, setTemplateList] = useState([]);
    const [templateId, setTemplateId] = useState('');

    useEffect(() => {
        if (!instanceId) return;
        axios.get('/api/templates', { params: { instance_id: instanceId, limit: 500 } })
            .then(r => setTemplateList(r.data?.data ?? []))
            .catch(() => setTemplateList([]));
    }, [instanceId]);

    useEffect(() => {
        if (!instanceId) return;
        load();
    }, [instanceId, start, end, templateId]);

    const templateGroups = useMemo(() => {
        const map = new Map();
        for (const t of templateList) {
            if (!map.has(t.name)) map.set(t.name, []);
            map.get(t.name).push(t);
        }
        return Array.from(map.entries())
            .map(([name, variants]) => ({
                name,
                variants: variants.sort((a, b) => (a.language ?? '').localeCompare(b.language ?? '')),
            }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [templateList]);

    const selectedTemplate = useMemo(
        () => templateList.find(t => String(t.id) === String(templateId)),
        [templateList, templateId]
    );

    async function load() {
        setLoading(true);
        setError(null);
        setHint(null);
        setNeedsActivation(false);
        try {
            const params = { instance_id: instanceId, start, end, granularity: 'DAILY' };
            if (templateId) params['template_ids[]'] = templateId;
            const { data: res } = await axios.get('/api/templates/analytics', { params });
            if (res?.needs_activation) {
                setNeedsActivation(true);
                setData({ totals: { sent: 0, delivered: 0, read: 0, clicked: 0 }, templates: [], series: [] });
                return;
            }
            setData(res);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudo obtener la analítica.');
            setHint(err?.response?.data?.hint ?? null);
            setData({ totals: { sent: 0, delivered: 0, read: 0, clicked: 0 }, templates: [], series: [] });
        } finally {
            setLoading(false);
        }
    }

    async function activate() {
        if (!instanceId) return;
        setActivating(true);
        setActivationError(null);
        try {
            await axios.post('/api/templates/analytics/enable', { instance_id: instanceId });
            await load();
        } catch (err) {
            const meta = err?.response?.data?.error?.error;
            setActivationError(meta?.error_user_msg || meta?.message || err?.response?.data?.message || 'No se pudo activar la analítica.');
        } finally {
            setActivating(false);
        }
    }

    function applyPreset(preset) {
        const now = unixNow();
        setRangeKey(preset.key);
        setEnd(now);
        setStart(now - preset.days * SECONDS_PER_DAY);
    }

    const totals = data.totals;
    const deliveryRate = rate(totals.delivered, totals.sent);
    const readRate = rate(totals.read, totals.delivered);
    const clickRate = rate(totals.clicked, totals.delivered);

    return (
        <>
            <Head title="Analítica de plantillas" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                {/* HERO */}
                <div className="relative overflow-hidden rounded-2xl border bg-gradient-to-br from-primary/10 via-card to-card p-6 lg:p-8">
                    <div className="absolute -top-12 -right-12 size-48 rounded-full bg-primary/10 blur-3xl pointer-events-none" />
                    <div className="relative flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                        <div className="flex items-center gap-4">
                            <div className="size-14 rounded-2xl bg-primary/15 text-primary flex items-center justify-center ring-1 ring-primary/20">
                                <BarChart3 className="size-7" />
                            </div>
                            <div>
                                <h1 className="text-2xl lg:text-3xl font-semibold text-foreground tracking-tight">
                                    Analítica de plantillas
                                </h1>
                                <p className="text-sm text-muted-foreground mt-1 max-w-xl">
                                    Mide envíos, entregas, lecturas y clics por plantilla en el período seleccionado.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href={route('templates.index')}>
                                <Button variant="outline" className="gap-2 h-9 bg-card/80">
                                    <ArrowLeft className="size-4" /> Volver
                                </Button>
                            </Link>
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
                        </div>
                    </div>
                </div>

                {/* Top tabs */}
                <div className="flex gap-1 border-b">
                    <TopTab active={tab === 'templates'} onClick={() => setTab('templates')} icon={BarChart3}>
                        Plantillas
                    </TopTab>
                    <TopTab active={tab === 'conversations'} onClick={() => setTab('conversations')} icon={MessageSquare}>
                        Conversaciones (Costo)
                    </TopTab>
                </div>

                {tab === 'conversations' ? (
                    <ConversationsPanel instanceId={instanceId} />
                ) : needsActivation ? (
                    <ActivationScreen
                        onActivate={activate}
                        loading={activating}
                        error={activationError}
                    />
                ) : (
                <>
                {/* FILTERS */}
                <div className="flex flex-col lg:flex-row gap-2">
                    <div className="flex flex-wrap items-center gap-2 p-2 rounded-xl border bg-card flex-1">
                        <Calendar className="size-4 text-muted-foreground ml-2" />
                        <span className="text-xs text-muted-foreground">Rango:</span>
                        {RANGE_PRESETS.map(p => (
                            <button
                                key={p.key}
                                onClick={() => applyPreset(p)}
                                className={`h-8 px-3 rounded-md text-xs font-medium transition-colors ${
                                    rangeKey === p.key
                                        ? 'bg-primary text-primary-foreground'
                                        : 'hover:bg-muted text-foreground'
                                }`}
                            >
                                {p.label}
                            </button>
                        ))}
                        <span className="ml-auto text-xs text-muted-foreground pr-2">
                            {formatDate(start)} → {formatDate(end)}
                        </span>
                    </div>
                    <div className="flex items-center gap-2 p-2 rounded-xl border bg-card">
                        <Filter className="size-4 text-muted-foreground ml-1" />
                        <select
                            value={templateId}
                            onChange={e => setTemplateId(e.target.value)}
                            className="h-8 rounded-md border-0 bg-transparent px-2 text-xs font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-ring/50 min-w-[200px]"
                        >
                            <option value="">Todas las plantillas</option>
                            {templateGroups.map(g => (
                                <optgroup key={g.name} label={g.name}>
                                    {g.variants.map(v => (
                                        <option key={v.id} value={v.id}>
                                            {g.name} · {v.language}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>
                        {templateId && (
                            <button
                                onClick={() => setTemplateId('')}
                                className="size-7 rounded-md hover:bg-muted flex items-center justify-center text-muted-foreground"
                                title="Quitar filtro"
                            >
                                <X className="size-3.5" />
                            </button>
                        )}
                    </div>
                </div>

                {selectedTemplate && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground -mt-2">
                        <span>Mostrando:</span>
                        <span className="inline-flex items-center gap-1.5 rounded-md bg-primary/10 text-primary px-2 py-0.5 font-mono text-xs">
                            {selectedTemplate.name} · {selectedTemplate.language}
                        </span>
                    </div>
                )}

                {error && (
                    <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        <div className="flex items-start gap-2">
                            <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                            <div>
                                <p className="font-medium">{error}</p>
                                {hint && <p className="text-xs text-destructive/80 mt-1">{hint}</p>}
                            </div>
                        </div>
                    </div>
                )}

                {/* SUMMARY CARDS */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <MetricCard
                        icon={Send}
                        label="Enviadas"
                        value={totals.sent}
                        sub={data.templates.length > 0 ? `${data.templates.length} plantillas` : ''}
                        tone="primary"
                        loading={loading}
                    />
                    <MetricCard
                        icon={CheckCheck}
                        label="Entregadas"
                        value={totals.delivered}
                        sub={`${deliveryRate}% de envío`}
                        tone="emerald"
                        loading={loading}
                    />
                    <MetricCard
                        icon={Eye}
                        label="Leídas"
                        value={totals.read}
                        sub={`${readRate}% de entrega`}
                        tone="indigo"
                        loading={loading}
                    />
                    <MetricCard
                        icon={MousePointerClick}
                        label="Clics en botones"
                        value={totals.clicked}
                        sub={`${clickRate}% de entrega`}
                        tone="amber"
                        loading={loading}
                    />
                </div>

                {/* TIME SERIES */}
                {data.series.length > 0 && (
                    <div className="rounded-xl border bg-card p-5">
                        <div className="flex items-center justify-between mb-4">
                            <h2 className="text-sm font-semibold text-foreground">Volumen diario</h2>
                            <div className="flex items-center gap-3 text-[11px] text-muted-foreground">
                                <Legend color="bg-primary/70" label="Enviadas" />
                                <Legend color="bg-emerald-500/70" label="Entregadas" />
                                <Legend color="bg-indigo-500/70" label="Leídas" />
                            </div>
                        </div>
                        <TimeSeriesChart series={data.series} />
                    </div>
                )}

                {/* PER-TEMPLATE TABLE */}
                {loading && data.templates.length === 0 ? (
                    <div className="rounded-xl border bg-card p-10 flex items-center justify-center text-muted-foreground">
                        <Loader2 className="size-5 animate-spin mr-2" /> Cargando métricas...
                    </div>
                ) : data.templates.length === 0 ? (
                    <div className="rounded-2xl border border-dashed py-16 text-center bg-card">
                        <div className="size-16 mx-auto rounded-2xl bg-muted/50 flex items-center justify-center mb-4">
                            <Inbox className="size-8 text-muted-foreground/60" />
                        </div>
                        <p className="text-lg font-medium text-foreground">Sin datos en este período</p>
                        <p className="text-sm text-muted-foreground mt-1 max-w-md mx-auto">
                            No hay actividad de plantillas registrada entre las fechas seleccionadas, o la WABA aún no tiene analíticas habilitadas.
                        </p>
                    </div>
                ) : (
                    <div className="rounded-xl border bg-card overflow-hidden">
                        <div className="px-5 py-3 border-b flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-foreground">Rendimiento por plantilla</h2>
                            <span className="text-xs text-muted-foreground">{data.templates.length} plantillas con actividad</span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40 text-[10px] uppercase tracking-wider text-muted-foreground">
                                    <tr>
                                        <th className="text-left font-semibold px-4 py-2.5">Plantilla</th>
                                        <th className="text-right font-semibold px-4 py-2.5 w-24">Enviadas</th>
                                        <th className="text-left font-semibold px-4 py-2.5 w-44">Entrega</th>
                                        <th className="text-left font-semibold px-4 py-2.5 w-44">Lectura</th>
                                        <th className="text-right font-semibold px-4 py-2.5 w-24">Clics</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.templates.map(t => (
                                        <TemplateRow key={t.template_id} t={t} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
                </>
                )}
            </div>
        </>
    );
}

function ActivationScreen({ onActivate, loading, error }) {
    return (
        <div className="rounded-2xl border bg-gradient-to-br from-card to-primary/5 overflow-hidden">
            <div className="p-8 lg:p-12 text-center max-w-2xl mx-auto">
                <div className="relative inline-flex items-center justify-center mb-6">
                    <div className="absolute inset-0 bg-primary/20 blur-2xl rounded-full" />
                    <div className="relative size-20 rounded-2xl bg-primary/15 text-primary flex items-center justify-center ring-2 ring-primary/20">
                        <Sparkles className="size-10" />
                    </div>
                </div>

                <h2 className="text-2xl font-semibold text-foreground tracking-tight">
                    Activa la analítica de plantillas
                </h2>
                <p className="text-sm text-muted-foreground mt-2 max-w-md mx-auto">
                    Meta requiere una activación única por cuenta de WhatsApp Business para empezar a registrar
                    métricas de tus plantillas. Solo tomas un clic.
                </p>

                <div className="mt-8 grid grid-cols-1 sm:grid-cols-3 gap-3 text-left">
                    <BenefitCard
                        icon={TrendingUp}
                        title="Mide rendimiento"
                        desc="Sabe qué plantilla rinde y cuál es presupuesto desperdiciado."
                    />
                    <BenefitCard
                        icon={Zap}
                        title="Optimiza envíos"
                        desc="Identifica horarios y mensajes con mejor tasa de apertura."
                    />
                    <BenefitCard
                        icon={ShieldCheck}
                        title="Sin costos extra"
                        desc="La activación y el consumo de analíticas son gratuitos."
                    />
                </div>

                {error && (
                    <div className="mt-6 rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive text-left">
                        <div className="flex items-start gap-2">
                            <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                            <span>{error}</span>
                        </div>
                    </div>
                )}

                <div className="mt-8 flex flex-col sm:flex-row gap-3 justify-center items-center">
                    <Button onClick={onActivate} disabled={loading} className="gap-2 h-11 px-6 shadow-md">
                        {loading ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}
                        {loading ? 'Activando...' : 'Activar analítica ahora'}
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        Una sola vez por WABA · Empieza a registrar desde el momento de activar
                    </span>
                </div>
            </div>
        </div>
    );
}

function BenefitCard({ icon: Icon, title, desc }) {
    return (
        <div className="rounded-xl border bg-card/50 p-4">
            <div className="size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center mb-2">
                <Icon className="size-4" />
            </div>
            <div className="text-sm font-semibold text-foreground">{title}</div>
            <div className="text-xs text-muted-foreground mt-1">{desc}</div>
        </div>
    );
}

function MetricCard({ icon: Icon, label, value, sub, tone, loading }) {
    const tones = {
        primary: 'bg-primary/10 text-primary',
        emerald: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        indigo: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    };
    return (
        <div className="rounded-xl border bg-card p-5 hover:shadow-sm transition-shadow">
            <div className="flex items-start justify-between mb-3">
                <div className={`size-10 rounded-lg flex items-center justify-center ${tones[tone] ?? tones.primary}`}>
                    <Icon className="size-5" />
                </div>
                {loading && <Loader2 className="size-3 animate-spin text-muted-foreground" />}
            </div>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="text-2xl font-semibold text-foreground tabular-nums mt-0.5">
                {value.toLocaleString('es')}
            </div>
            {sub && <div className="text-[11px] text-muted-foreground mt-1">{sub}</div>}
        </div>
    );
}

function Legend({ color, label }) {
    return (
        <div className="flex items-center gap-1.5">
            <span className={`size-2.5 rounded-sm ${color}`} />
            <span>{label}</span>
        </div>
    );
}

function TimeSeriesChart({ series }) {
    const max = useMemo(() => {
        let m = 0;
        for (const d of series) {
            m = Math.max(m, d.sent, d.delivered, d.read);
        }
        return m || 1;
    }, [series]);

    const width = Math.max(series.length * 60, 600);
    const height = 180;
    const padX = 30;
    const padY = 20;
    const innerW = width - padX * 2;
    const innerH = height - padY * 2;
    const stepX = series.length > 1 ? innerW / (series.length - 1) : innerW;

    function pointPath(getter) {
        return series
            .map((d, i) => {
                const x = padX + i * stepX;
                const y = padY + innerH - (getter(d) / max) * innerH;
                return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
            })
            .join(' ');
    }

    return (
        <div className="overflow-x-auto">
            <svg width={width} height={height} className="block">
                {/* horizontal grid */}
                {[0, 0.25, 0.5, 0.75, 1].map((t, i) => (
                    <line
                        key={i}
                        x1={padX} x2={width - padX}
                        y1={padY + innerH * t} y2={padY + innerH * t}
                        stroke="currentColor"
                        className="text-muted-foreground/15"
                        strokeWidth={1}
                    />
                ))}
                {/* lines */}
                <path d={pointPath(d => d.sent)} fill="none" stroke="currentColor" strokeWidth={2} className="text-primary/80" />
                <path d={pointPath(d => d.delivered)} fill="none" stroke="currentColor" strokeWidth={2} className="text-emerald-500/80" />
                <path d={pointPath(d => d.read)} fill="none" stroke="currentColor" strokeWidth={2} className="text-indigo-500/80" />
                {/* points */}
                {series.map((d, i) => {
                    const x = padX + i * stepX;
                    return (
                        <g key={i}>
                            <circle cx={x} cy={padY + innerH - (d.sent / max) * innerH} r={3} className="fill-primary" />
                            <text x={x} y={height - 4} textAnchor="middle" className="fill-muted-foreground text-[9px]">
                                {formatDate(d.start)}
                            </text>
                        </g>
                    );
                })}
                {/* y-axis labels */}
                {[0, 0.5, 1].map((t, i) => (
                    <text key={i} x={padX - 6} y={padY + innerH - innerH * t + 3} textAnchor="end" className="fill-muted-foreground text-[9px]">
                        {Math.round(max * t).toLocaleString('es')}
                    </text>
                ))}
            </svg>
        </div>
    );
}

function TemplateRow({ t }) {
    const delivery = rate(t.delivered, t.sent);
    const read = rate(t.read, t.delivered);
    return (
        <tr className="border-t hover:bg-muted/30 transition-colors">
            <td className="px-4 py-3">
                <div className="font-mono text-sm text-foreground">{t.name}</div>
                {t.language && <div className="text-[10px] text-muted-foreground mt-0.5">{t.language} · id: {t.template_id}</div>}
            </td>
            <td className="px-4 py-3 text-right tabular-nums font-medium text-foreground">
                {t.sent.toLocaleString('es')}
            </td>
            <td className="px-4 py-3">
                <RatePill value={t.delivered} percent={delivery} barClass="bg-emerald-500" />
            </td>
            <td className="px-4 py-3">
                <RatePill value={t.read} percent={read} barClass="bg-indigo-500" />
            </td>
            <td className="px-4 py-3 text-right tabular-nums text-foreground">
                {t.clicked.toLocaleString('es')}
            </td>
        </tr>
    );
}

function RatePill({ value, percent, barClass }) {
    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-[11px]">
                <span className="font-medium text-foreground tabular-nums">{value.toLocaleString('es')}</span>
                <span className="text-muted-foreground tabular-nums">{percent}%</span>
            </div>
            <div className="h-1.5 rounded-full bg-muted overflow-hidden">
                <div className={`h-full rounded-full ${barClass}`} style={{ width: `${Math.min(percent, 100)}%` }} />
            </div>
        </div>
    );
}

function TopTab({ active, onClick, icon: Icon, children }) {
    return (
        <button
            onClick={onClick}
            className={`relative inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium transition-colors ${
                active ? 'text-primary' : 'text-muted-foreground hover:text-foreground'
            }`}
        >
            <Icon className="size-4" />
            {children}
            {active && <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-t" />}
        </button>
    );
}

const CATEGORY_LABELS = {
    AUTHENTICATION: 'Autenticación',
    MARKETING: 'Marketing',
    UTILITY: 'Utilidad',
    SERVICE: 'Servicio (cliente inició)',
    REFERRAL_CONVERSION: 'Referido',
    UNKNOWN: 'Desconocida',
};
const TYPE_LABELS = {
    FREE_TIER: 'Gratis (free tier)',
    FREE_ENTRY: 'Gratis (entry point)',
    REGULAR: 'Facturable',
    UNKNOWN: 'Desconocido',
};
const CATEGORY_COLORS = {
    AUTHENTICATION: 'bg-teal-500',
    MARKETING: 'bg-fuchsia-500',
    UTILITY: 'bg-blue-500',
    SERVICE: 'bg-emerald-500',
    REFERRAL_CONVERSION: 'bg-indigo-500',
    UNKNOWN: 'bg-zinc-500',
};

function ConversationsPanel({ instanceId }) {
    const [rangeKey, setRangeKey] = useState('30d');
    const [start, setStart] = useState(() => unixNow() - 30 * SECONDS_PER_DAY);
    const [end, setEnd] = useState(() => unixNow());
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [needsAct, setNeedsAct] = useState(false);
    const [activating, setActivating] = useState(false);

    useEffect(() => { if (instanceId) load(); }, [instanceId, start, end]);

    async function load() {
        if (!instanceId) return;
        setLoading(true);
        setError(null);
        setNeedsAct(false);
        try {
            const { data: res } = await axios.get('/api/templates/analytics/conversations', {
                params: { instance_id: instanceId, start, end, granularity: 'DAILY' },
            });
            if (res?.needs_activation) {
                setNeedsAct(true);
                setData(null);
                return;
            }
            setData(res);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudo cargar la analítica de conversaciones.');
            setData(null);
        } finally {
            setLoading(false);
        }
    }

    async function activate() {
        setActivating(true);
        try {
            await axios.post('/api/templates/analytics/enable', { instance_id: instanceId });
            await load();
        } finally {
            setActivating(false);
        }
    }

    function applyPreset(p) {
        const now = unixNow();
        setRangeKey(p.key);
        setEnd(now);
        setStart(now - p.days * SECONDS_PER_DAY);
    }

    if (needsAct) {
        return (
            <ActivationScreen
                onActivate={activate}
                loading={activating}
                error={null}
            />
        );
    }

    const totals = data?.totals ?? { conversation: 0, cost: 0 };
    const byCategory = data?.by_category ?? {};
    const byType = data?.by_type ?? {};
    const series = data?.series ?? [];

    const categoryTotal = Object.values(byCategory).reduce((s, v) => s + v, 0) || 1;

    return (
        <>
            <div className="flex flex-wrap items-center gap-2 p-2 rounded-xl border bg-card">
                <Calendar className="size-4 text-muted-foreground ml-2" />
                <span className="text-xs text-muted-foreground">Rango:</span>
                {RANGE_PRESETS.map(p => (
                    <button
                        key={p.key}
                        onClick={() => applyPreset(p)}
                        className={`h-8 px-3 rounded-md text-xs font-medium transition-colors ${
                            rangeKey === p.key
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted text-foreground'
                        }`}
                    >
                        {p.label}
                    </button>
                ))}
                <span className="ml-auto text-xs text-muted-foreground pr-2">
                    {formatDate(start)} → {formatDate(end)}
                </span>
            </div>

            {error && (
                <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    <div className="flex items-start gap-2">
                        <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                        <span>{error}</span>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <MetricCard
                    icon={MessageSquare}
                    label="Conversaciones"
                    value={totals.conversation}
                    sub="en el período"
                    tone="primary"
                    loading={loading}
                />
                <MetricCard
                    icon={DollarSign}
                    label="Costo total (USD)"
                    value={Math.round(totals.cost * 100) / 100}
                    sub="aproximado · sin impuestos"
                    tone="amber"
                    loading={loading}
                />
                <MetricCard
                    icon={Layers}
                    label="Categorías activas"
                    value={Object.keys(byCategory).length}
                    sub="utility / marketing / etc."
                    tone="indigo"
                    loading={loading}
                />
                <MetricCard
                    icon={CheckCheck}
                    label="Gratis (free tier)"
                    value={byType.FREE_TIER ?? 0}
                    sub={totals.conversation ? `${Math.round(((byType.FREE_TIER ?? 0) / totals.conversation) * 100)}% del total` : '—'}
                    tone="emerald"
                    loading={loading}
                />
            </div>

            {Object.keys(byCategory).length > 0 && (
                <div className="rounded-xl border bg-card p-5">
                    <h3 className="text-sm font-semibold text-foreground mb-3">Distribución por categoría</h3>
                    <div className="space-y-2">
                        {Object.entries(byCategory)
                            .sort(([, a], [, b]) => b - a)
                            .map(([cat, count]) => {
                                const pct = Math.round((count / categoryTotal) * 100);
                                return (
                                    <div key={cat}>
                                        <div className="flex justify-between text-xs mb-1">
                                            <span className="font-medium text-foreground">{CATEGORY_LABELS[cat] ?? cat}</span>
                                            <span className="text-muted-foreground tabular-nums">{count.toLocaleString('es')} · {pct}%</span>
                                        </div>
                                        <div className="h-2 rounded-full bg-muted overflow-hidden">
                                            <div className={`h-full rounded-full ${CATEGORY_COLORS[cat] ?? 'bg-zinc-500'}`} style={{ width: `${pct}%` }} />
                                        </div>
                                    </div>
                                );
                            })}
                    </div>
                </div>
            )}

            {Object.keys(byType).length > 0 && (
                <div className="rounded-xl border bg-card p-5">
                    <h3 className="text-sm font-semibold text-foreground mb-3">Por tipo de conversación</h3>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        {Object.entries(byType).map(([t, count]) => (
                            <div key={t} className="flex items-center justify-between rounded-lg border bg-muted/30 px-3 py-2">
                                <span className="text-xs font-medium text-foreground">{TYPE_LABELS[t] ?? t}</span>
                                <span className="text-sm tabular-nums font-semibold">{count.toLocaleString('es')}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {series.length > 0 && (
                <div className="rounded-xl border bg-card p-5">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-foreground">Volumen diario</h3>
                    </div>
                    <ConversationSeriesChart series={series} />
                </div>
            )}

            {loading && !data && (
                <div className="rounded-xl border bg-card p-10 flex items-center justify-center text-muted-foreground">
                    <Loader2 className="size-5 animate-spin mr-2" /> Cargando conversaciones...
                </div>
            )}
            {!loading && data && totals.conversation === 0 && (
                <div className="rounded-2xl border border-dashed py-16 text-center bg-card">
                    <div className="size-16 mx-auto rounded-2xl bg-muted/50 flex items-center justify-center mb-4">
                        <Inbox className="size-8 text-muted-foreground/60" />
                    </div>
                    <p className="text-lg font-medium text-foreground">Sin conversaciones en este período</p>
                </div>
            )}
        </>
    );
}

function ConversationSeriesChart({ series }) {
    const max = useMemo(() => {
        let m = 0;
        for (const d of series) m = Math.max(m, d.conversation);
        return m || 1;
    }, [series]);

    const width = Math.max(series.length * 50, 600);
    const height = 160;
    const padX = 30;
    const padY = 20;
    const innerW = width - padX * 2;
    const innerH = height - padY * 2;
    const stepX = series.length > 1 ? innerW / (series.length - 1) : innerW;
    const barW = Math.max(stepX * 0.5, 8);

    return (
        <div className="overflow-x-auto">
            <svg width={width} height={height} className="block">
                {[0, 0.25, 0.5, 0.75, 1].map((t, i) => (
                    <line
                        key={i}
                        x1={padX} x2={width - padX}
                        y1={padY + innerH * t} y2={padY + innerH * t}
                        stroke="currentColor"
                        className="text-muted-foreground/15"
                        strokeWidth={1}
                    />
                ))}
                {series.map((d, i) => {
                    const x = padX + i * stepX;
                    const h = (d.conversation / max) * innerH;
                    return (
                        <g key={i}>
                            <rect
                                x={x - barW / 2}
                                y={padY + innerH - h}
                                width={barW}
                                height={h}
                                rx={2}
                                className="fill-primary/70"
                            />
                            <text x={x} y={height - 4} textAnchor="middle" className="fill-muted-foreground text-[9px]">
                                {formatDate(d.start)}
                            </text>
                        </g>
                    );
                })}
                {[0, 0.5, 1].map((t, i) => (
                    <text key={i} x={padX - 6} y={padY + innerH - innerH * t + 3} textAnchor="end" className="fill-muted-foreground text-[9px]">
                        {Math.round(max * t).toLocaleString('es')}
                    </text>
                ))}
            </svg>
        </div>
    );
}

TemplatesAnalytics.layout = page => <AppLayout breadcrumb={['Plantillas', 'Analítica']}>{page}</AppLayout>;
