import { useState, useCallback, useEffect, useRef } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    MessageSquareX,
    RefreshCw,
    Search,
    Send,
    Eye,
    X,
    AlertTriangle,
    Clock,
    HelpCircle,
    Building2,
    Paperclip,
    Download,
    Loader2,
    FileText,
    Image as ImageIcon,
    Mic,
    Video,
    LayoutTemplate,
    MessageSquare,
    Info,
    ChevronRight,
    Filter,
    Repeat,
    Phone,
    CheckCircle2,
} from 'lucide-react';

// ── Diccionarios de presentación ─────────────────────────────────────────────

const TYPE_ICONS = {
    text: MessageSquare,
    image: ImageIcon,
    document: FileText,
    audio: Mic,
    video: Video,
    template: LayoutTemplate,
};

const TYPE_LABELS = {
    text: 'Texto',
    image: 'Imagen',
    document: 'Documento',
    audio: 'Audio',
    video: 'Video',
    template: 'Plantilla',
    sticker: 'Sticker',
    location: 'Ubicación',
    contacts: 'Contacto',
};

// Cada bucket es un motivo distinto de "no entregado", con su propio color.
const STATUS_META = {
    failed: { label: 'No llegó', color: 'rose', icon: AlertTriangle, help: 'WhatsApp no pudo entregarlo.' },
    pending: { label: 'Sin enviar', color: 'amber', icon: Clock, help: 'Se quedó sin salir del sistema.' },
    sent: { label: 'Sin confirmar', color: 'sky', icon: HelpCircle, help: 'Salió, pero WhatsApp no confirma que llegara.' },
};

// Qué se puede hacer con el fallo, que es lo primero que quiere saber quien lee.
const SEVERITY_LABELS = {
    temporary: { label: 'Puede reintentarse', tone: 'emerald' },
    permanent: { label: 'Reintentar no ayudará', tone: 'rose' },
    window: { label: 'Requiere plantilla', tone: 'amber' },
    config: { label: 'Requiere un administrador', tone: 'indigo' },
};

const TONES = {
    rose: 'bg-rose-500/10 text-rose-600 border-rose-500/20',
    amber: 'bg-amber-500/10 text-amber-600 border-amber-500/20',
    sky: 'bg-sky-500/10 text-sky-600 border-sky-500/20',
    indigo: 'bg-indigo-500/10 text-indigo-600 border-indigo-500/20',
    emerald: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
    slate: 'bg-muted text-muted-foreground border-border/40',
};

const BUCKETS = [
    { key: 'all', label: 'Todos', statKey: 'total' },
    { key: 'failed', label: 'No llegaron', statKey: 'failed' },
    { key: 'pending', label: 'Sin enviar', statKey: 'pending' },
    { key: 'sent', label: 'Sin confirmar', statKey: 'sent_unconfirmed' },
];

const RANGES = [
    { value: 'today', label: 'Hoy' },
    { value: 'week', label: 'Últimos 7 días' },
    { value: 'month', label: 'Últimos 30 días' },
    { value: 'year', label: 'Último año' },
    { value: 'all', label: 'Todo el histórico' },
    { value: 'custom', label: 'Personalizado' },
];

function mediaUrlFor(messageId, { inline = false } = {}) {
    return route('master.messages.media', messageId) + (inline ? '?inline=1' : '');
}

function truncate(text, max = 90) {
    if (!text) return '';
    return text.length > max ? text.slice(0, max) + '…' : text;
}

/** "hace 3 h" a partir de la fecha ya formateada por el backend. */
function relativeFrom(dateString) {
    if (!dateString) return '';
    const then = new Date(dateString.replace(' ', 'T'));
    if (Number.isNaN(then.getTime())) return '';
    const minutes = Math.round((Date.now() - then.getTime()) / 60000);
    if (minutes < 1) return 'ahora mismo';
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `hace ${hours} h`;
    const days = Math.round(hours / 24);
    return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
}

// ── Página ───────────────────────────────────────────────────────────────────

export default function MasterMessages({ messages, stats, error_breakdown, companies, instances, filters, company_locked }) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [detailId, setDetailId] = useState(null);
    const [retrying, setRetrying] = useState(null);
    // Un reintento cambia el historial del mensaje: obliga al modal a recargarlo.
    const [detailVersion, setDetailVersion] = useState(0);
    const searchTimer = useRef(null);

    const { data: rows, links } = messages;

    const applyFilters = useCallback((overrides = {}) => {
        const params = {
            bucket: filters.bucket,
            company_id: filters.company_id ?? '',
            instance_id: filters.instance_id ?? '',
            type: filters.type ?? '',
            reason: filters.reason ?? '',
            search: filters.search ?? '',
            range: filters.range,
            start_date: filters.start_date,
            end_date: filters.end_date,
            ...overrides,
        };

        // Los vacíos no viajan: mantiene la URL legible para compartirla.
        Object.keys(params).forEach(key => {
            if (params[key] === '' || params[key] === null) delete params[key];
        });

        router.get(route('master.messages.index'), params, { preserveState: true, replace: true });
    }, [filters]);

    function handleSearch(value) {
        setSearch(value);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => applyFilters({ search: value }), 500);
    }

    useEffect(() => () => clearTimeout(searchTimer.current), []);

    function handleRetry(messageId) {
        setRetrying(messageId);
        router.post(route('master.messages.retry', messageId), {}, {
            preserveScroll: true,
            onSuccess: () => setDetailVersion(v => v + 1),
            onFinish: () => setRetrying(null),
        });
    }

    // Cambiar de empresa invalida la instancia seleccionada (es de otra empresa).
    function handleCompanyChange(value) {
        applyFilters({ company_id: value, instance_id: '' });
    }

    return (
        <>
            <Head title="Mensajes no entregados" />

            <div className="flex flex-col min-h-screen bg-muted/10">
                <div className="bg-card/40 backdrop-blur-3xl px-8 py-8 sticky top-0 z-40 border-b border-border/20 shadow-sm">
                    <div className="max-w-[1700px] mx-auto flex items-center justify-between gap-6 flex-wrap">
                        <div className="flex items-center gap-6">
                            <div className="size-14 rounded-2xl bg-rose-600 flex items-center justify-center text-white shadow-2xl shadow-rose-600/20">
                                <MessageSquareX className="size-7" />
                            </div>
                            <div>
                                <h1 className="text-2xl font-black tracking-tight text-foreground uppercase flex items-center gap-3">
                                    Mensajes no entregados
                                    {/* La insignia solo tiene sentido en la vista global, que es la del Master. */}
                                    {!company_locked && (
                                        <span className="text-[10px] font-black bg-indigo-500/10 text-indigo-600 px-2 py-0.5 rounded-full border border-indigo-500/20 tracking-widest hidden sm:inline-block">TODAS LAS EMPRESAS</span>
                                    )}
                                </h1>
                                <p className="text-sm font-medium text-muted-foreground mt-1">
                                    {messages.total?.toLocaleString?.() ?? rows.length} mensajes que el cliente nunca recibió
                                    {company_locked
                                        ? ` · ${companies[0]?.name ?? 'esta empresa'}`
                                        : stats.companies_affected > 0 && ` · ${stats.companies_affected} ${stats.companies_affected === 1 ? 'empresa' : 'empresas'} afectada${stats.companies_affected === 1 ? '' : 's'}`}
                                </p>
                            </div>
                        </div>
                        <Button
                            onClick={() => router.reload()}
                            variant="ghost"
                            className="rounded-xl h-11 px-5 font-black uppercase tracking-widest text-[10px] gap-2"
                        >
                            <RefreshCw className="size-4" /> Refrescar
                        </Button>
                    </div>
                </div>

                <div className="p-8 max-w-[1700px] mx-auto w-full space-y-8">
                    {/* KPIs */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
                        <KPICard label="No llegaron" value={stats.failed} tone="rose" icon={<AlertTriangle className="size-5" />} sub={`${stats.failed_last_24h} en las últimas 24 h`} />
                        <KPICard label="Sin enviar" value={stats.pending} tone="amber" icon={<Clock className="size-5" />} sub="Se quedaron en el sistema" />
                        <KPICard label="Sin confirmar" value={stats.sent_unconfirmed} tone="sky" icon={<HelpCircle className="size-5" />} sub="WhatsApp no confirma entrega" />
                        <KPICard label="Con archivo adjunto" value={stats.with_attachment} tone="indigo" icon={<Paperclip className="size-5" />} sub="Imagen, documento o audio" />
                        <KPICard label="Ya reenviados" value={stats.retried} tone="emerald" icon={<Repeat className="size-5" />} sub="Se intentó al menos una vez" />
                    </div>

                    {/* Motivos más frecuentes */}
                    {error_breakdown.length > 0 && (
                        <div className="bg-card border border-border/40 rounded-[2.5rem] p-8 shadow-sm">
                            <div className="flex items-center gap-3 mb-6">
                                <Info className="size-4 text-indigo-600" />
                                <h2 className="text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em]">Por qué no llegaron · toca para filtrar</h2>
                            </div>
                            <div className="flex flex-wrap gap-3">
                                {error_breakdown.map(item => {
                                    const active = filters.reason === item.title;
                                    return (
                                        <button
                                            key={item.title}
                                            type="button"
                                            onClick={() => applyFilters({ reason: active ? '' : item.title })}
                                            className={`text-left rounded-2xl border px-5 py-4 transition-all max-w-md ${active ? 'bg-indigo-600 text-white border-indigo-600 shadow-lg shadow-indigo-600/20' : 'bg-muted/20 border-border/40 hover:border-indigo-500/40'}`}
                                        >
                                            <div className="flex items-center gap-3">
                                                <span className={`text-xl font-black ${active ? 'text-white' : 'text-rose-600'}`}>{item.total}</span>
                                                <div className="min-w-0">
                                                    <p className="text-[12.5px] font-semibold" title={item.raw ?? ''}>{item.title}</p>
                                                    <p className={`text-[10px] font-bold uppercase tracking-widest mt-0.5 ${active ? 'text-white/60' : 'text-muted-foreground/70'}`}>
                                                        {item.total === 1 ? '1 mensaje' : `${item.total} mensajes`}
                                                    </p>
                                                </div>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* Filtros */}
                    <div className="bg-card border border-border/40 rounded-[2.5rem] p-8 shadow-sm space-y-6">
                        <div className="flex flex-wrap gap-2">
                            {BUCKETS.map(bucket => (
                                <button
                                    key={bucket.key}
                                    type="button"
                                    onClick={() => applyFilters({ bucket: bucket.key })}
                                    className={`h-11 px-5 rounded-xl font-black uppercase tracking-widest text-[10px] transition-all flex items-center gap-2 ${
                                        filters.bucket === bucket.key
                                            ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20'
                                            : 'bg-muted/30 text-muted-foreground hover:bg-muted/60'
                                    }`}
                                >
                                    {bucket.label}
                                    <span className={`px-2 py-0.5 rounded-full text-[10px] ${filters.bucket === bucket.key ? 'bg-white/20' : 'bg-background'}`}>
                                        {stats[bucket.statKey] ?? 0}
                                    </span>
                                </button>
                            ))}
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                            <div className="relative lg:col-span-2">
                                <Search className="absolute left-4 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                <input
                                    type="text"
                                    value={search}
                                    onChange={e => handleSearch(e.target.value)}
                                    placeholder="Buscar por texto del mensaje, teléfono o nombre del cliente…"
                                    className="w-full h-12 pl-11 pr-4 rounded-2xl bg-muted/30 border border-border/40 text-sm font-medium focus:outline-none focus:border-indigo-500/60 transition-colors"
                                />
                            </div>

                            {company_locked ? (
                                <div>
                                    <span className="flex items-center gap-1.5 text-[10px] font-black text-muted-foreground uppercase tracking-[0.2em] mb-2">
                                        <Building2 className="size-3.5" /> Empresa
                                    </span>
                                    <div className="h-12 px-4 rounded-2xl bg-indigo-500/[0.06] border border-indigo-500/20 flex items-center text-sm font-bold text-indigo-600 truncate">
                                        {companies[0]?.name ?? 'Esta empresa'}
                                    </div>
                                </div>
                            ) : (
                                <FilterSelect
                                    icon={<Building2 className="size-3.5" />}
                                    label="Empresa"
                                    value={filters.company_id ?? ''}
                                    onChange={handleCompanyChange}
                                    options={[
                                        { value: '', label: 'Todas las empresas' },
                                        ...companies.map(c => ({ value: c.id, label: c.name })),
                                    ]}
                                />
                            )}

                            <FilterSelect
                                icon={<Phone className="size-3.5" />}
                                label="Instancia"
                                value={filters.instance_id ?? ''}
                                onChange={value => applyFilters({ instance_id: value })}
                                options={[
                                    { value: '', label: 'Todas las instancias' },
                                    ...instances.map(i => ({ value: i.id, label: `${i.name}${i.display_phone_number ? ` · ${i.display_phone_number}` : ''}` })),
                                ]}
                            />

                            <FilterSelect
                                icon={<Filter className="size-3.5" />}
                                label="Tipo"
                                value={filters.type ?? ''}
                                onChange={value => applyFilters({ type: value })}
                                options={[
                                    { value: '', label: 'Todos los tipos' },
                                    ...['text', 'image', 'document', 'audio', 'video', 'template'].map(t => ({ value: t, label: TYPE_LABELS[t] })),
                                ]}
                            />
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <FilterSelect
                                icon={<Clock className="size-3.5" />}
                                label="Rango"
                                value={filters.range}
                                onChange={value => applyFilters({ range: value })}
                                options={RANGES}
                            />
                            {filters.range === 'custom' && (
                                <>
                                    <DateInput label="Desde" value={filters.start_date} onChange={value => applyFilters({ start_date: value })} />
                                    <DateInput label="Hasta" value={filters.end_date} onChange={value => applyFilters({ end_date: value })} />
                                </>
                            )}
                        </div>
                    </div>

                    {/* Listado */}
                    <div className="bg-card border border-border/40 rounded-[2.5rem] shadow-xl overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="bg-muted/30 border-b border-border/40">
                                        <Th>Mensaje</Th>
                                        <Th className="hidden lg:table-cell">Empresa / Instancia</Th>
                                        <Th className="hidden md:table-cell">Destinatario</Th>
                                        <Th>Motivo</Th>
                                        <Th className="hidden xl:table-cell">Fecha del fallo</Th>
                                        <Th className="text-right">Acciones</Th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/30">
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="px-10 py-20 text-center">
                                                <CheckCircle2 className="size-10 mx-auto text-emerald-500/60 mb-4" />
                                                <p className="text-sm font-bold text-foreground">Ningún mensaje sin entregar en este recorte</p>
                                                <p className="text-xs text-muted-foreground mt-1 italic">Prueba a ampliar el rango de fechas o quitar filtros.</p>
                                            </td>
                                        </tr>
                                    )}
                                    {rows.map(row => {
                                        const meta = STATUS_META[row.status] ?? STATUS_META.failed;
                                        const TypeIcon = TYPE_ICONS[row.type] ?? MessageSquare;
                                        const hasAttachment = ['image', 'document', 'audio', 'video'].includes(row.type);

                                        return (
                                            <tr key={row.id} className="hover:bg-rose-500/[0.02] transition-colors group align-top">
                                                <td className="px-8 py-6">
                                                    <div className="flex items-start gap-4">
                                                        <div className={`size-12 rounded-2xl border flex items-center justify-center flex-shrink-0 ${TONES[meta.color]}`}>
                                                            <TypeIcon className="size-5" />
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="flex items-center gap-2 flex-wrap">
                                                                <span className="font-mono text-[10px] font-black text-muted-foreground">#{row.id}</span>
                                                                <Badge tone={meta.color}>{meta.label}</Badge>
                                                                <Badge tone="slate">{TYPE_LABELS[row.type] ?? row.type}</Badge>
                                                                {hasAttachment && (
                                                                    <Badge tone={row.media_available ? 'indigo' : 'slate'}>
                                                                        <Paperclip className="size-3 inline mr-1" />
                                                                        {row.media_available ? 'Adjunto' : 'Adjunto ya no disponible'}
                                                                    </Badge>
                                                                )}
                                                                {row.retry_count > 0 && (
                                                                    <Badge tone="emerald">
                                                                        <Repeat className="size-3 inline mr-1" />
                                                                        {row.retry_count === 1 ? 'reenviado 1 vez' : `reenviado ${row.retry_count} veces`}
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <p className="text-[13px] font-semibold text-foreground mt-2 max-w-md break-words">
                                                                {truncate(row.content) || <span className="italic text-muted-foreground">Sin texto</span>}
                                                            </p>
                                                            {row.filename && (
                                                                <p className="text-[11px] font-bold text-muted-foreground mt-1 font-mono truncate max-w-md">{row.filename}</p>
                                                            )}
                                                            {row.sender && (
                                                                <p className="text-[10px] font-bold text-muted-foreground/70 uppercase tracking-widest mt-1.5">Enviado por {row.sender}</p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="px-8 py-6 hidden lg:table-cell">
                                                    <p className="text-[13px] font-black text-foreground">{row.company.name ?? '—'}</p>
                                                    <p className="text-[11px] font-semibold text-muted-foreground mt-0.5">{row.instance.name ?? 'Sin instancia'}</p>
                                                    {row.instance.phone && (
                                                        <p className="text-[10px] font-mono text-muted-foreground/70 mt-0.5">{row.instance.phone}</p>
                                                    )}
                                                </td>

                                                <td className="px-8 py-6 hidden md:table-cell">
                                                    <p className="text-[13px] font-bold text-foreground">{row.recipient.name || 'Sin nombre'}</p>
                                                    <p className="text-[11px] font-mono text-muted-foreground mt-0.5">{row.recipient.phone_number ?? '—'}</p>
                                                </td>

                                                <td className="px-8 py-6">
                                                    <div className="max-w-sm">
                                                        <p className="text-[13px] font-bold text-foreground break-words">{row.reason}</p>
                                                        <p className="text-[11.5px] text-muted-foreground mt-1 break-words">{truncate(row.explanation, 130)}</p>
                                                        {SEVERITY_LABELS[row.severity] && (
                                                            <span className="inline-block mt-2">
                                                                <Badge tone={SEVERITY_LABELS[row.severity].tone}>{SEVERITY_LABELS[row.severity].label}</Badge>
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>

                                                <td className="px-8 py-6 hidden xl:table-cell">
                                                    <p className="text-[12px] font-bold text-foreground font-mono">{row.failure_moment ?? '—'}</p>
                                                    <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-widest mt-0.5">{relativeFrom(row.failure_moment)}</p>
                                                    {row.last_retried_at && (
                                                        <p className="text-[10px] text-emerald-600 font-semibold mt-1.5">
                                                            Reenviado {relativeFrom(row.last_retried_at)}
                                                            {row.last_retried_by && ` por ${row.last_retried_by}`}
                                                        </p>
                                                    )}
                                                </td>

                                                <td className="px-8 py-6">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => setDetailId(row.id)}
                                                            className="rounded-xl h-10 px-4 gap-2 font-black uppercase tracking-widest text-[10px] hover:bg-indigo-600 hover:text-white transition-all"
                                                        >
                                                            <Eye className="size-4" /> Detalle
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={!row.retryable || retrying === row.id}
                                                            onClick={() => handleRetry(row.id)}
                                                            title={row.retryable ? 'Volver a enviar este mensaje al cliente' : (row.retry_blocked ?? 'Este mensaje no se puede volver a enviar')}
                                                            className="rounded-xl h-10 px-4 gap-2 font-black uppercase tracking-widest text-[10px] text-emerald-600 hover:bg-emerald-600 hover:text-white transition-all disabled:opacity-40"
                                                        >
                                                            {retrying === row.id
                                                                ? <Loader2 className="size-4 animate-spin" />
                                                                : <Send className="size-4" />}
                                                            Enviar de nuevo
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {links && links.length > 3 && (
                            <div className="flex items-center justify-between gap-4 px-8 py-6 border-t border-border/40 bg-muted/20 flex-wrap">
                                <p className="text-[11px] font-black text-muted-foreground uppercase tracking-widest">
                                    {messages.from ?? 0}–{messages.to ?? 0} de {messages.total ?? 0}
                                </p>
                                <div className="flex items-center gap-1.5 flex-wrap">
                                    {links.map((link, index) => (
                                        <Link
                                            key={index}
                                            href={link.url ?? '#'}
                                            preserveState
                                            preserveScroll
                                            className={`min-w-10 h-10 px-3 rounded-xl flex items-center justify-center text-[11px] font-black transition-all ${
                                                link.active
                                                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20'
                                                    : link.url
                                                        ? 'bg-card border border-border/40 hover:border-indigo-500/40 text-foreground'
                                                        : 'text-muted-foreground/40 pointer-events-none'
                                            }`}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {detailId && (
                <DetailModal
                    messageId={detailId}
                    version={detailVersion}
                    onClose={() => setDetailId(null)}
                    onRetry={handleRetry}
                    retrying={retrying}
                />
            )}
        </>
    );
}

// ── Modal de detalle ─────────────────────────────────────────────────────────

function DetailModal({ messageId, version, onClose, onRetry, retrying }) {
    const [detail, setDetail] = useState(null);
    const [error, setError] = useState('');
    const [attachmentOpen, setAttachmentOpen] = useState(false);

    useEffect(() => {
        let cancelled = false;
        setError('');

        axios.get(route('master.messages.show', messageId))
            .then(res => { if (!cancelled) setDetail(res.data.message); })
            .catch(() => { if (!cancelled) setError('No se pudo cargar el detalle del mensaje.'); });

        return () => { cancelled = true; };
    }, [messageId, version]);

    useEffect(() => {
        function onKey(e) { if (e.key === 'Escape' && !attachmentOpen) onClose(); }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, attachmentOpen]);

    const meta = detail ? (STATUS_META[detail.status] ?? STATUS_META.failed) : STATUS_META.failed;
    const hasAttachment = detail && ['image', 'document', 'audio', 'video'].includes(detail.type);

    return (
        <>
            <div className="fixed inset-0 z-50 flex items-start justify-center bg-background/60 backdrop-blur-2xl p-4 sm:p-8 overflow-y-auto animate-in fade-in duration-200" onClick={onClose}>
                <div className="w-full max-w-4xl rounded-[2.5rem] bg-card border border-border/40 shadow-2xl my-4" onClick={e => e.stopPropagation()}>
                    <div className="flex items-start justify-between gap-6 p-8 sm:p-10 border-b border-border/30">
                        <div className="flex items-center gap-5 min-w-0">
                            <div className={`size-14 rounded-2xl border flex items-center justify-center flex-shrink-0 ${TONES[meta.color]}`}>
                                <meta.icon className="size-7" />
                            </div>
                            <div className="min-w-0">
                                <h2 className="text-xl font-black tracking-tight uppercase truncate">Mensaje #{messageId}</h2>
                                <p className="text-sm text-muted-foreground font-medium mt-0.5">{meta.help}</p>
                            </div>
                        </div>
                        <button onClick={onClose} className="p-2.5 rounded-xl hover:bg-muted transition-colors flex-shrink-0">
                            <X className="size-5" />
                        </button>
                    </div>

                    {!detail && !error && (
                        <div className="p-20 flex items-center justify-center">
                            <Loader2 className="size-8 animate-spin text-indigo-600" />
                        </div>
                    )}

                    {error && (
                        <div className="p-10 text-center text-sm font-semibold text-rose-600">{error}</div>
                    )}

                    {detail && (
                        <div className="p-8 sm:p-10 space-y-8">
                            {/* Motivo */}
                            <Section title="Por qué no le llegó" icon={<AlertTriangle className="size-4" />}>
                                <div className={`rounded-2xl border p-6 ${TONES[meta.color]}`}>
                                    <p className="text-[15px] font-black leading-snug">{detail.reason}</p>
                                    <p className="text-[13px] mt-2.5 opacity-90 leading-relaxed">{detail.explanation}</p>
                                    {SEVERITY_LABELS[detail.severity] && (
                                        <span className="inline-block mt-4">
                                            <Badge tone={SEVERITY_LABELS[detail.severity].tone}>{SEVERITY_LABELS[detail.severity].label}</Badge>
                                        </span>
                                    )}
                                </div>
                            </Section>

                            {/* Qué hacer */}
                            <Section title="Qué puedes hacer" icon={<Info className="size-4" />}>
                                <ul className="space-y-2.5">
                                    <li className="flex items-start gap-3 text-[13.5px] font-semibold text-foreground">
                                        <ChevronRight className="size-4 mt-0.5 text-indigo-600 flex-shrink-0" />
                                        <span>{detail.advice}</span>
                                    </li>
                                    {detail.diagnosis?.map((hint, index) => (
                                        <li key={index} className="flex items-start gap-3 text-[13px] font-medium text-muted-foreground">
                                            <ChevronRight className="size-4 mt-0.5 text-indigo-600/60 flex-shrink-0" />
                                            <span>{hint}</span>
                                        </li>
                                    ))}
                                </ul>
                            </Section>

                            {/* Cronología */}
                            <Section title="Cuándo pasó" icon={<Clock className="size-4" />}>
                                <div className="grid grid-cols-2 sm:grid-cols-3 gap-6">
                                    <Field label="Se escribió" value={detail.created_at ?? '—'} mono />
                                    <Field label="Salió hacia WhatsApp" value={detail.sent_at ?? 'No salió'} mono />
                                    <Field label="Se dio por no entregado" value={detail.failed_at ?? '—'} mono />
                                    <Field label="Llegó al teléfono" value={detail.delivered_at ?? 'Nunca'} mono />
                                    <Field label="El cliente lo leyó" value={detail.read_at ?? 'Nunca'} mono />
                                    <Field label="Intentos de reenvío" value={detail.retry_count} mono />
                                </div>
                            </Section>

                            {/* Contenido */}
                            <Section title="Qué decía el mensaje" icon={<MessageSquare className="size-4" />}>
                                <div className="rounded-2xl bg-muted/30 border border-border/40 p-6">
                                    <p className="text-[13.5px] font-medium text-foreground whitespace-pre-wrap break-words">
                                        {detail.content || <span className="italic text-muted-foreground">Este mensaje no tiene texto.</span>}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-x-8 gap-y-3 mt-4">
                                    <Field label="Tipo" value={TYPE_LABELS[detail.type] ?? detail.type} />
                                    <Field label="Lo envió" value={detail.sender ?? 'El sistema'} />
                                </div>
                            </Section>

                            {/* Adjunto */}
                            {hasAttachment && (
                                <Section title="Adjunto" icon={<Paperclip className="size-4" />}>
                                    {detail.media_available ? (
                                        <div className="rounded-2xl bg-muted/30 border border-border/40 p-6 flex items-center justify-between gap-6 flex-wrap">
                                            <div className="min-w-0">
                                                <p className="text-[13.5px] font-bold text-foreground truncate">{detail.filename || 'Archivo adjunto'}</p>
                                                <p className="text-[11px] font-mono text-muted-foreground mt-1">
                                                    {detail.media_mime_type || 'tipo desconocido'}
                                                    {detail.has_own_copy ? ' · guardado en el sistema' : ' · se descarga de WhatsApp al abrirlo'}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <Button
                                                    onClick={() => setAttachmentOpen(true)}
                                                    className="rounded-2xl h-11 px-5 bg-indigo-600 hover:bg-indigo-700 text-white font-black uppercase tracking-widest text-[10px] gap-2"
                                                >
                                                    <Eye className="size-4" /> Ver adjunto
                                                </Button>
                                                <AttachmentDownload messageId={detail.id} filename={detail.filename} />
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="rounded-2xl bg-muted/30 border border-border/40 p-6">
                                            <p className="text-[13px] font-semibold text-muted-foreground">
                                                El archivo ya no está disponible: WhatsApp borra los adjuntos 30 días después del envío.
                                            </p>
                                        </div>
                                    )}
                                </Section>
                            )}

                            {/* Destino */}
                            <Section title="A quién iba" icon={<Building2 className="size-4" />}>
                                <div className="grid grid-cols-2 sm:grid-cols-3 gap-6">
                                    <Field label="Cliente" value={detail.conversation?.contact_name || detail.conversation?.name || 'Sin nombre'} />
                                    <Field label="Su teléfono" value={detail.conversation?.phone_number ?? '—'} mono />
                                    <Field label="Agente a cargo" value={detail.conversation?.assigned_agent ?? 'Sin asignar'} />
                                    <Field label="Empresa" value={detail.instance?.company_name ?? '—'} />
                                    <Field label="Línea de envío" value={detail.instance?.phone_number || detail.instance?.name || '—'} mono />
                                    <Field
                                        label="Se le puede escribir libremente"
                                        value={detail.conversation?.window_open
                                            ? 'Sí, respondió hace menos de 24 h'
                                            : 'No, hay que enviarle una plantilla'}
                                    />
                                </div>
                            </Section>

                            {/* Historial de reenvíos */}
                            {(detail.retries?.length > 0 || detail.retry_of) && (
                                <Section title="Intentos de reenvío" icon={<Repeat className="size-4" />}>
                                    <div className="space-y-2.5">
                                        {detail.retry_of && (
                                            <div className="rounded-2xl bg-muted/30 border border-border/40 px-5 py-4 text-[12.5px] font-semibold">
                                                Este es un reenvío de un mensaje anterior, del {detail.retry_of.created_at}.
                                            </div>
                                        )}
                                        {detail.retries?.map(retry => {
                                            const delivered = retry.status === 'delivered' || retry.status === 'read';
                                            return (
                                                <div key={retry.id} className="rounded-2xl bg-muted/30 border border-border/40 px-5 py-4 flex items-center justify-between gap-4 flex-wrap">
                                                    <div className="min-w-0">
                                                        <p className="text-[12.5px] font-bold">Reenviado el {retry.created_at}</p>
                                                        {retry.reason && (
                                                            <p className="text-[11.5px] text-rose-600 mt-1">{retry.reason}</p>
                                                        )}
                                                    </div>
                                                    <Badge tone={delivered ? 'emerald' : (STATUS_META[retry.status]?.color ?? 'slate')}>
                                                        {delivered ? 'Sí llegó' : (STATUS_META[retry.status]?.label ?? retry.status)}
                                                    </Badge>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </Section>
                            )}

                            {/* Detalle técnico: todo lo que soporte necesita, fuera del camino */}
                            <details className="rounded-2xl bg-muted/20 border border-border/40 overflow-hidden group">
                                <summary className="px-6 py-4 cursor-pointer text-[11px] font-black uppercase tracking-[0.2em] text-muted-foreground hover:text-foreground flex items-center gap-2">
                                    <FileText className="size-3.5" /> Detalle técnico (para soporte)
                                </summary>
                                <div className="px-6 pb-6 space-y-5">
                                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-5">
                                        <Field label="ID del mensaje" value={detail.id} mono />
                                        <Field label="Estado interno" value={detail.technical?.status ?? '—'} mono />
                                        <Field label="Código WhatsApp" value={detail.technical?.error_code ?? '—'} mono />
                                        <Field label="ID de envío (wamid)" value={detail.wamid ?? 'No asignado'} mono />
                                        <Field label="Línea (phone_number_id)" value={detail.instance?.phone_number_id ?? '—'} mono />
                                        <Field label="Conversación" value={detail.conversation?.id ?? '—'} mono />
                                    </div>
                                    {detail.technical?.error_message && (
                                        <div>
                                            <p className="text-[9.5px] font-black opacity-60 uppercase tracking-[0.2em] mb-1">Respuesta original de WhatsApp</p>
                                            <p className="text-[12px] font-mono break-words text-muted-foreground">{detail.technical.error_message}</p>
                                            {detail.technical.error_details && (
                                                <p className="text-[11.5px] font-mono break-words text-muted-foreground/80 mt-1">{detail.technical.error_details}</p>
                                            )}
                                        </div>
                                    )}
                                    {detail.metadata && (
                                        <div>
                                            <p className="text-[9.5px] font-black opacity-60 uppercase tracking-[0.2em] mb-1">Datos del envío</p>
                                            <pre className="text-[11px] font-mono overflow-x-auto whitespace-pre-wrap break-all text-muted-foreground">
                                                {JSON.stringify(detail.metadata, null, 2)}
                                            </pre>
                                        </div>
                                    )}
                                </div>
                            </details>

                            {/* Acciones */}
                            <div className="flex items-center gap-3 pt-4 border-t border-border/30 flex-wrap">
                                <Button
                                    disabled={!detail.retryable || retrying === detail.id}
                                    onClick={() => onRetry(detail.id)}
                                    className="h-14 px-7 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-black uppercase tracking-widest text-[10px] gap-2 disabled:opacity-40"
                                >
                                    {retrying === detail.id
                                        ? <Loader2 className="size-4 animate-spin" />
                                        : <Send className="size-4" />}
                                    Enviar de nuevo
                                </Button>
                                <Button variant="ghost" onClick={onClose} className="h-14 px-7 rounded-2xl font-black uppercase tracking-widest text-[10px]">
                                    Cerrar
                                </Button>
                                {!detail.retryable && (
                                    <p className="text-[11.5px] font-semibold text-muted-foreground max-w-md">
                                        {detail.retry_blocked ?? 'Este mensaje no se puede volver a enviar.'}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {attachmentOpen && detail && (
                <AttachmentModal message={detail} onClose={() => setAttachmentOpen(false)} />
            )}
        </>
    );
}

// ── Modal de adjunto ─────────────────────────────────────────────────────────

function AttachmentModal({ message, onClose }) {
    const inlineUrl = mediaUrlFor(message.id, { inline: true });
    const mime = message.media_mime_type ?? '';

    useEffect(() => {
        function onKey(e) { if (e.key === 'Escape') onClose(); }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    // El visor se elige por mime real y, si falta, por el tipo del mensaje.
    const kind = mime.startsWith('image/') || message.type === 'image' ? 'image'
        : mime.startsWith('video/') || message.type === 'video' ? 'video'
        : mime.startsWith('audio/') || message.type === 'audio' ? 'audio'
        : mime === 'application/pdf' ? 'pdf'
        : 'other';

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-background/80 backdrop-blur-2xl p-4 sm:p-8 animate-in fade-in duration-200" onClick={onClose}>
            <div className="w-full max-w-5xl rounded-[2.5rem] bg-card border border-border/40 shadow-2xl overflow-hidden flex flex-col max-h-[92vh]" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between gap-6 px-8 py-6 border-b border-border/30">
                    <div className="flex items-center gap-4 min-w-0">
                        <div className="size-11 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-600 flex items-center justify-center flex-shrink-0">
                            <Paperclip className="size-5" />
                        </div>
                        <div className="min-w-0">
                            <p className="text-[13.5px] font-black text-foreground truncate">{message.filename || 'Archivo adjunto'}</p>
                            <p className="text-[10px] font-mono text-muted-foreground uppercase tracking-widest mt-0.5">{mime || 'tipo desconocido'}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 flex-shrink-0">
                        <AttachmentDownload messageId={message.id} filename={message.filename} />
                        <button onClick={onClose} className="p-2.5 rounded-xl hover:bg-muted transition-colors">
                            <X className="size-5" />
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-auto bg-muted/20 flex items-center justify-center p-6">
                    {kind === 'image' && (
                        <img src={inlineUrl} alt={message.filename || 'Adjunto'} className="max-w-full max-h-[70vh] rounded-2xl shadow-xl object-contain" />
                    )}
                    {kind === 'video' && (
                        <video src={inlineUrl} controls className="max-w-full max-h-[70vh] rounded-2xl shadow-xl" />
                    )}
                    {kind === 'audio' && (
                        <audio src={inlineUrl} controls className="w-full max-w-xl" />
                    )}
                    {kind === 'pdf' && (
                        <iframe src={inlineUrl} title={message.filename || 'Documento'} className="w-full h-[70vh] rounded-2xl bg-white shadow-xl" />
                    )}
                    {kind === 'other' && (
                        <div className="text-center py-16">
                            <FileText className="size-12 mx-auto text-muted-foreground/50 mb-4" />
                            <p className="text-sm font-bold text-foreground">Este formato no se puede previsualizar</p>
                            <p className="text-xs text-muted-foreground mt-1 mb-6 italic">Descárgalo para abrirlo con la aplicación correspondiente.</p>
                            <a href={inlineUrl} target="_blank" rel="noopener noreferrer" className="text-[11px] font-black uppercase tracking-widest text-indigo-600 hover:underline">
                                Abrir en una pestaña nueva
                            </a>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * Descarga por XHR para poder mostrar el motivo cuando el archivo ya no está:
 * un enlace directo dejaría al usuario ante un JSON de error.
 */
function AttachmentDownload({ messageId, filename }) {
    const [downloading, setDownloading] = useState(false);
    const [error, setError] = useState('');

    async function download() {
        if (downloading) return;
        setDownloading(true);
        setError('');
        try {
            const res = await axios.get(mediaUrlFor(messageId), { responseType: 'blob' });
            const objectUrl = URL.createObjectURL(res.data);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = filename || 'archivo';
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
        } catch (err) {
            let detail = 'No se pudo descargar el archivo.';
            const body = err?.response?.data;
            if (body instanceof Blob) {
                try { detail = JSON.parse(await body.text())?.error || detail; } catch { /* respuesta no JSON */ }
            } else if (body?.error) {
                detail = body.error;
            }
            setError(detail);
        } finally {
            setDownloading(false);
        }
    }

    return (
        <div className="flex flex-col items-end">
            <Button
                variant="ghost"
                onClick={download}
                disabled={downloading}
                className="rounded-2xl h-11 px-5 font-black uppercase tracking-widest text-[10px] gap-2"
            >
                {downloading ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
                {downloading ? 'Descargando' : 'Descargar'}
            </Button>
            {error && <p className="text-[10.5px] font-semibold text-rose-600 mt-1 max-w-xs text-right">{error}</p>}
        </div>
    );
}

// ── Piezas de UI ─────────────────────────────────────────────────────────────

function Th({ children, className = '' }) {
    return (
        <th className={`px-8 py-6 text-left text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em] ${className}`}>
            {children}
        </th>
    );
}

function Badge({ children, tone = 'slate' }) {
    return (
        <span className={`text-[9.5px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full border ${TONES[tone]}`}>
            {children}
        </span>
    );
}

function KPICard({ label, value, sub, icon, tone }) {
    return (
        <div className="bg-card border border-border/40 rounded-[2rem] p-7 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1 group">
            <div className="flex items-center justify-between mb-4">
                <span className="text-[10px] font-black text-muted-foreground uppercase tracking-[0.2em]">{label}</span>
                <div className={`size-10 rounded-xl border flex items-center justify-center ${TONES[tone]}`}>{icon}</div>
            </div>
            <p className="text-3xl font-black tracking-tight text-foreground">{(value ?? 0).toLocaleString()}</p>
            {sub && <p className="text-[11px] font-semibold text-muted-foreground mt-1.5">{sub}</p>}
        </div>
    );
}

function FilterSelect({ label, value, onChange, options, icon }) {
    return (
        <label className="block">
            <span className="flex items-center gap-1.5 text-[10px] font-black text-muted-foreground uppercase tracking-[0.2em] mb-2">
                {icon} {label}
            </span>
            <select
                value={value}
                onChange={e => onChange(e.target.value)}
                className="w-full h-12 px-4 rounded-2xl bg-muted/30 border border-border/40 text-sm font-medium focus:outline-none focus:border-indigo-500/60 transition-colors"
            >
                {options.map(option => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                ))}
            </select>
        </label>
    );
}

function DateInput({ label, value, onChange }) {
    return (
        <label className="block">
            <span className="text-[10px] font-black text-muted-foreground uppercase tracking-[0.2em] mb-2 block">{label}</span>
            <input
                type="date"
                value={value ?? ''}
                onChange={e => onChange(e.target.value)}
                className="w-full h-12 px-4 rounded-2xl bg-muted/30 border border-border/40 text-sm font-medium focus:outline-none focus:border-indigo-500/60 transition-colors"
            />
        </label>
    );
}

function Section({ title, icon, children }) {
    return (
        <section>
            <div className="flex items-center gap-2.5 mb-4 text-indigo-600">
                {icon}
                <h3 className="text-[11px] font-black text-muted-foreground uppercase tracking-[0.2em]">{title}</h3>
            </div>
            {children}
        </section>
    );
}

function Field({ label, value, mono = false }) {
    return (
        <div className="min-w-0">
            <p className="text-[9.5px] font-black opacity-60 uppercase tracking-[0.2em]">{label}</p>
            <p className={`text-[12.5px] font-bold break-words ${mono ? 'font-mono' : ''}`}>{value}</p>
        </div>
    );
}

MasterMessages.layout = page => <AppLayout>{page}</AppLayout>;
