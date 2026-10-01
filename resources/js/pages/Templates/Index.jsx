import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import CabeceraModulo from '@/components/cabecera-modulo';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { TabButton, WhatsAppPreview, templateToModel } from './preview';
import {
    FileText,
    FileType,
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
    Copy,
    ArrowRight,
    AlertTriangle,
    Pencil,
    Trash2,
} from 'lucide-react';

// Orden de familias "por número y por prioridad": las plantillas con prefijo
// numérico (p. ej. "1_facturacion", "2_corte") van primero en orden ascendente;
// el resto, alfabético con colación numérica natural ("2_x" antes que "10_x").
function templatePriority(name) {
    const m = (name ?? '').match(/^(\d+)/);
    return m ? parseInt(m[1], 10) : Number.POSITIVE_INFINITY;
}
function byNumberThenName(a, b) {
    const pa = templatePriority(a.name);
    const pb = templatePriority(b.name);
    if (pa !== pb) return pa - pb;
    return (a.name ?? '').localeCompare(b.name ?? '', undefined, { numeric: true, sensitivity: 'base' });
}

const STATUS_STYLES = {
    APPROVED: 'bg-success/15 text-success ring-1 ring-inset ring-success/30',
    PENDING: 'bg-warning/15 text-warning ring-1 ring-inset ring-warning/30',
    REJECTED: 'bg-destructive/15 text-destructive ring-1 ring-inset ring-destructive/30',
    DISABLED: 'bg-muted/15 text-muted-foreground ring-1 ring-inset ring-border/30',
    PAUSED: 'bg-warning/15 text-warning ring-1 ring-inset ring-warning/30',
    IN_APPEAL: 'bg-info/15 text-info ring-1 ring-inset ring-info/30',
    DELETED: 'bg-destructive/15 text-destructive ring-1 ring-inset ring-destructive/30',
    PENDING_DELETION: 'bg-destructive/15 text-destructive ring-1 ring-inset ring-destructive/30',
    FLAGGED: 'bg-warning/15 text-warning ring-1 ring-inset ring-warning/30',
    LIMIT_EXCEEDED: 'bg-destructive/15 text-destructive ring-1 ring-inset ring-destructive/30',
    ARCHIVED: 'bg-muted/15 text-muted-foreground ring-1 ring-inset ring-border/30',
    LOCKED: 'bg-muted/15 text-muted-foreground ring-1 ring-inset ring-border/30',
};

const STATUS_DOT = {
    APPROVED: 'bg-success',
    PENDING: 'bg-warning',
    REJECTED: 'bg-destructive',
    DISABLED: 'bg-muted',
    PAUSED: 'bg-warning',
    IN_APPEAL: 'bg-info',
    DELETED: 'bg-destructive',
    PENDING_DELETION: 'bg-destructive',
    FLAGGED: 'bg-warning',
    LIMIT_EXCEEDED: 'bg-destructive',
    ARCHIVED: 'bg-muted-foreground',
    LOCKED: 'bg-muted-foreground',
};

/**
 * Qué significa cada estado, en español y sin siglas.
 *
 * Meta los devuelve en inglés y en mayúsculas —PENDING, REJECTED—, y así se
 * enseñaban tal cual. El estado más frecuente al crear una plantilla es
 * "pendiente", y se comunicaba con un punto naranja y nada más: quien la acaba
 * de crear no sabe si tiene que hacer algo, si falló, o si sólo hay que
 * esperar. La respuesta —esperar a que Meta la revise— cabe en una frase.
 */
const ESTADO = {
    APPROVED: { texto: 'Aprobada', ayuda: 'Meta la aprobó. Ya se puede enviar.' },
    PENDING: {
        texto: 'En revisión',
        ayuda: 'Meta la está revisando. Suele tardar unos minutos, a veces algunas horas. No hay que hacer nada: cuando la apruebe podrás enviarla.',
    },
    REJECTED: {
        texto: 'Rechazada',
        ayuda: 'Meta no la aprobó. Revisa el texto —las promociones encubiertas y los enlaces sospechosos son los motivos más comunes— y crea una versión corregida.',
    },
    DISABLED: { texto: 'Deshabilitada', ayuda: 'Meta la deshabilitó por su calidad. No se puede enviar.' },
    PAUSED: { texto: 'En pausa', ayuda: 'Pausada temporalmente por Meta, normalmente por muchos reportes de los destinatarios.' },
    IN_APPEAL: { texto: 'En apelación', ayuda: 'Se pidió a Meta que revisara su decisión. Toca esperar.' },
    DELETED: { texto: 'Eliminada', ayuda: 'Ya no existe en Meta.' },
    PENDING_DELETION: {
        texto: 'Borrándose',
        ayuda: 'Se pidió borrarla y Meta la está eliminando. Ya no se puede enviar, y el nombre no se puede reutilizar por un tiempo.',
    },
    FLAGGED: {
        texto: 'Marcada',
        ayuda: 'Su calidad bajó mucho: si no mejora en 7 días, Meta la deshabilita. Revisa a quién se envía y con qué frecuencia.',
    },
    LIMIT_EXCEEDED: {
        texto: 'Límite alcanzado',
        ayuda: 'La cuenta llegó al máximo de plantillas que permite Meta. Borra las que no uses para crear otras.',
    },
    ARCHIVED: { texto: 'Archivada', ayuda: 'Está archivada en Meta y no se puede enviar hasta desarchivarla.' },
    LOCKED: { texto: 'Bloqueada', ayuda: 'Meta la bloqueó y por ahora no se puede editar.' },
};

const estadoDe = s => ESTADO[s] ?? { texto: s ?? 'Sin estado', ayuda: '' };

/**
 * La calidad que Meta le pone a cada plantilla según cómo reaccionan los
 * destinatarios (bloqueos, reportes). Llega como `{ score: 'GREEN', date }`
 * en el listado; se acepta también un texto suelto por si el backend la
 * aplana. En amarillo y rojo hay que actuar antes de que Meta la pause.
 */
const CALIDAD = {
    GREEN: { texto: 'Calidad alta', punto: 'bg-success', clase: 'text-success', ayuda: 'Los destinatarios la reciben bien.' },
    YELLOW: {
        texto: 'Calidad media',
        punto: 'bg-warning',
        clase: 'text-warning',
        ayuda: 'Hay destinatarios que la bloquean o la reportan. Si sigue bajando, Meta la pausa: revisa a quién se envía y con qué frecuencia.',
    },
    RED: {
        texto: 'Calidad baja',
        punto: 'bg-destructive',
        clase: 'text-destructive',
        ayuda: 'Muchos destinatarios la bloquean o la reportan. Meta la va a pausar o deshabilitar: deja de enviarla a quien no la espera y revisa el texto.',
    },
    UNKNOWN: { texto: 'Calidad sin datos', punto: 'bg-muted-foreground/50', clase: 'text-muted-foreground', ayuda: 'Aún no se ha enviado lo suficiente para que Meta la califique.' },
};

function calidadDe(t) {
    const q = t?.quality_score;
    const score = (typeof q === 'string' ? q : q?.score) ?? 'UNKNOWN';
    return { score, ...(CALIDAD[score] ?? CALIDAD.UNKNOWN) };
}

const calidadPreocupa = t => ['YELLOW', 'RED'].includes(calidadDe(t).score);

/** Por qué la rechazó Meta, en español y con qué hacer. */
const MOTIVO_RECHAZO = {
    INVALID_FORMAT: 'Formato inválido: revisa las variables (sin saltos, sin dos seguidas, ni al principio ni al final) y que los ejemplos estén completos.',
    TAG_CONTENT_MISMATCH: 'La categoría no coincide con el contenido: por ejemplo, una promoción enviada como utilidad.',
    ABUSIVE_CONTENT: 'Meta consideró el contenido abusivo o contrario a sus políticas.',
    INCORRECT_CATEGORY: 'Categoría incorrecta para lo que dice el mensaje.',
    PROMOTIONAL: 'Tiene contenido promocional y no es una plantilla de marketing.',
    SCAM: 'Meta la consideró una posible estafa: revisa enlaces, premios o peticiones de datos.',
    NONE: null,
};
const motivoRechazo = r => (r && r !== 'NONE') ? (MOTIVO_RECHAZO[r] ?? r) : null;
const CATEGORIA_TEXTO = { MARKETING: 'Marketing', UTILITY: 'Utilidad', AUTHENTICATION: 'Autenticación' };

const CATEGORY_STYLES = {
    MARKETING: 'bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-400 ring-1 ring-inset ring-fuchsia-500/30',
    UTILITY: 'bg-info/15 text-info ring-1 ring-inset ring-info/30',
    AUTHENTICATION: 'bg-primary/15 text-accent-foreground ring-1 ring-inset ring-primary/30',
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

export default function TemplatesIndex({ instances = [], negocio = '' }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [instanceId, setInstanceId] = useState(instances[0]?.id ?? null);
    const [copiando, setCopiando] = useState(false);
    const [templates, setTemplates] = useState([]);
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [expanded, setExpanded] = useState(() => new Set());
    const [detail, setDetail] = useState(null);
    const [borrando, setBorrando] = useState(null); // la plantilla que se va a borrar
    const [borrandoEnCurso, setBorrandoEnCurso] = useState(false);
    const [errorBorrar, setErrorBorrar] = useState(null);

    async function confirmarBorrado() {
        if (!borrando) return;
        setBorrandoEnCurso(true);
        setErrorBorrar(null);
        try {
            await axios.delete(`/api/templates/${borrando.id}`, { params: { instance_id: instanceId } });
            setBorrando(null);
            setDetail(null);
            load();
        } catch (err) {
            const resp = err?.response?.data;
            setErrorBorrar(resp?.message || resp?.error?.error?.error_user_msg || resp?.error?.message || 'No se pudo borrar la plantilla.');
            setBorrando(null);
        } finally {
            setBorrandoEnCurso(false);
        }
    }

    function goToCreate() {
        router.visit(route('templates.create', { instance_id: instanceId }));
    }

    function goToTranslation(family) {
        const source = family.variants.find(v => v.status === 'APPROVED') ?? family.variants[0];
        router.visit(route('templates.create', {
            mode: 'translation',
            family: family.name,
            source_id: source?.id,
            instance_id: instanceId,
        }));
    }

    useEffect(() => {
        if (instanceId) load();
    }, [instanceId]);

    async function load() {
        if (!instanceId) return;
        setLoading(true);
        setError(null);
        try {
            // Meta pagina el listado. Sin seguir el cursor, una cuenta con más
            // plantillas que el tamaño de página veía sólo las primeras, y las
            // demás «no existían». Se sigue `paging.after` (o el cursor de
            // Meta, `paging.cursors.after`, mientras haya `next`), con un
            // tope por si el cursor no avanza.
            const todas = [];
            let after = null;
            let resumen = null;
            for (let pagina = 0; pagina < 30; pagina++) {
                const { data } = await axios.get('/api/templates', {
                    params: { instance_id: instanceId, limit: 200, ...(after ? { after } : {}) },
                });
                todas.push(...(data.data || []));
                resumen = resumen ?? data.summary ?? null;
                const paging = data.paging ?? {};
                const siguiente = paging.after ?? (paging.next ? paging.cursors?.after : null) ?? null;
                if (!siguiente || siguiente === after) break;
                after = siguiente;
            }
            setTemplates(todas);
            setSummary(resumen);
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
            .sort(byNumberThenName);
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
        const s = { total: templates.length, approved: 0, pending: 0, rejected: 0, families: 0, calidadBaja: [] };
        const names = new Set();
        for (const t of templates) {
            names.add(t.name);
            if (calidadPreocupa(t)) s.calidadBaja.push(t);
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
                <CabeceraModulo
                    icono={FileType}
                    titulo="Plantillas de WhatsApp"
                    descripcion="Administra las plantillas aprobadas por Meta y sus traducciones a distintos idiomas."
                >
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
                    <Link href={route('templates.defaults')}>
                        <Button variant="outline" className="gap-2 h-9 bg-card/80">
                            <Sparkles className="size-4" /> Plantillas por defecto
                        </Button>
                    </Link>
                    {can('templates.create') && instanceId && instances.length > 1 && (
                        <Button
                            onClick={() => setCopiando(true)}
                            variant="outline"
                            className="gap-2 h-9 bg-card/80"
                            title="Llevar plantillas de esta línea a otra"
                        >
                            <Copy className="size-4" /> Copiar a otra línea
                        </Button>
                    )}
                    {can('templates.create') && instanceId && (
                        <Button onClick={goToCreate} className="gap-2 h-9 shadow-md">
                            <Sparkles className="size-4" /> Nueva plantilla
                        </Button>
                    )}
                </CabeceraModulo>

                {copiando && (
                    <CopiarPlantillasModal
                        instances={instances}
                        origenId={instanceId}
                        onClose={() => setCopiando(false)}
                        onCopiado={load}
                    />
                )}

                {instances.length === 0 && (
                    <div className="rounded-2xl border border-dashed py-12 text-center text-sm text-muted-foreground">
                        No hay instancias con WABA configurado. Configura una instancia para consultar plantillas.
                    </div>
                )}

                {instanceId && (() => {
                    const active = instances.find(i => i.id === instanceId);
                    if (!active?.waba_id) return null;
                    return (
                        <div className="rounded-xl border bg-muted/30 px-4 py-2.5 text-xs text-muted-foreground flex flex-wrap items-center gap-2">
                            <span className="font-medium text-foreground">Trabajando sobre WABA:</span>
                            <code className="font-mono text-foreground bg-background border px-2 py-0.5 rounded">{active.waba_id}</code>
                            <span className="opacity-60">·</span>
                            <span>Verifica que coincide con el WABA que ves en tu Meta Business Manager.</span>
                            <a
                                href={`https://business.facebook.com/wa/manage/message-templates/?waba_id=${active.waba_id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="ml-auto underline hover:text-foreground"
                            >
                                Abrir en Meta
                            </a>
                        </div>
                    );
                })()}

                {/* STATS */}
                {instances.length > 0 && templates.length > 0 && (
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <StatCard icon={FileText} label="Total" value={stats.total} tone="primary" />
                        <StatCard icon={Languages} label="Familias" value={stats.families} tone="indigo" />
                        <StatCard icon={CheckCircle2} label="Aprobadas" value={stats.approved} tone="emerald" />
                        <StatCard icon={Clock} label="En revisión" value={stats.pending} tone="amber" />
                    </div>
                )}

                {/* Qué significa que haya plantillas en revisión.

                    La tarjeta de arriba dice cuántas hay, pero no si eso es un
                    problema ni si hay que hacer algo. Es el estado con el que
                    nace toda plantilla, así que quien acaba de crear la suya lo
                    ve siempre, y sin esta frase no sabe si le falló algo o sólo
                    tiene que esperar. Sólo aparece cuando hay alguna: si están
                    todas aprobadas, no hay nada que explicar. */}
                {stats.pending > 0 && (
                    <div className="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3">
                        <Clock className="size-4 shrink-0 text-warning mt-0.5" />
                        <p className="text-xs text-muted-foreground">
                            <span className="font-semibold text-foreground">
                                {stats.pending === 1
                                    ? 'Una plantilla está en revisión.'
                                    : `${stats.pending} plantillas están en revisión.`}
                            </span>{' '}
                            Meta revisa cada plantilla antes de dejar enviarla: suele tardar
                            unos minutos, a veces algunas horas. No tienes que hacer nada —
                            cuando la apruebe podrás usarla en campañas y respuestas.
                        </p>
                    </div>
                )}

                {/* Las rechazadas sí piden acción, y por eso se avisan aparte y
                    en rojo: quedarse esperando una plantilla que Meta ya
                    descartó es perder días. */}
                {stats.rejected > 0 && (
                    <div className="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3">
                        <XCircle className="size-4 shrink-0 text-destructive mt-0.5" />
                        <p className="text-xs text-muted-foreground">
                            <span className="font-semibold text-foreground">
                                {stats.rejected === 1
                                    ? 'Una plantilla fue rechazada.'
                                    : `${stats.rejected} plantillas fueron rechazadas.`}
                            </span>{' '}
                            {stats.rejected === 1
                                ? 'Meta no la aprobó y no se puede enviar. Ábrela para ver el texto:'
                                : 'Meta no las aprobó y no se pueden enviar. Ábrelas para ver el texto:'}{' '}
                            las promociones encubiertas y los enlaces sospechosos son los
                            motivos más comunes. Corrige y crea una versión nueva.
                        </p>
                    </div>
                )}

                {/* La calidad baja avisa antes de que Meta pause la plantilla:
                    una vez pausada, los envíos fallan y ya es tarde. */}
                {stats.calidadBaja.length > 0 && (
                    <div className="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3">
                        <AlertTriangle className="size-4 shrink-0 text-warning mt-0.5" />
                        <p className="text-xs text-muted-foreground">
                            <span className="font-semibold text-foreground">
                                {stats.calidadBaja.length === 1
                                    ? 'Una plantilla tiene la calidad en amarillo o rojo:'
                                    : `${stats.calidadBaja.length} plantillas tienen la calidad en amarillo o rojo:`}
                            </span>{' '}
                            {stats.calidadBaja.slice(0, 5).map(t => `${t.name} (${t.language})`).join(', ')}
                            {stats.calidadBaja.length > 5 ? '…' : ''}.{' '}
                            Los destinatarios la están bloqueando o reportando; si sigue así, Meta la pausa y luego
                            la deshabilita. Envíala sólo a quien la espera y revisa el texto.
                        </p>
                    </div>
                )}

                {errorBorrar && (
                    <div className="flex items-start justify-between gap-3 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        <span>{errorBorrar}</span>
                        <button type="button" onClick={() => setErrorBorrar(null)} className="shrink-0"><X className="size-4" /></button>
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
                                <option value="FLAGGED">Marcada</option>
                                <option value="LIMIT_EXCEEDED">Límite alcanzado</option>
                                <option value="ARCHIVED">Archivada</option>
                                <option value="LOCKED">Bloqueada</option>
                                <option value="PENDING_DELETION">Borrándose</option>
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
                            <Button onClick={goToCreate} className="mt-6 gap-2">
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
                                canEdit={can('templates.update')}
                                canDelete={can('templates.delete')}
                                onDelete={setBorrando}
                                instanceId={instanceId}
                                onAddTranslation={() => goToTranslation(family)}
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
                    negocio={negocio}
                    canEdit={can('templates.update')}
                    canDelete={can('templates.delete')}
                    onDelete={setBorrando}
                    onClose={() => setDetail(null)}
                    onSelectSibling={(sibling) => setDetail({ id: sibling.id, name: sibling.name })}
                />
            )}

            {/* Borrar en Meta es real y no se deshace: no hay papelera. Y el
                nombre queda retenido un tiempo —Meta no deja crear otra con el
                mismo nombre e idioma mientras tanto—, así que «la borro y la
                vuelvo a crear» no funciona como se espera. Hay que decirlo
                antes, no después. */}
            <ConfirmDialog
                open={!!borrando}
                title={borrando ? `¿Borrar ${borrando.name} (${borrando.language})?` : ''}
                description={borrando
                    ? `Se borra en Meta la versión en ${LANG_LABELS[borrando.language] ?? borrando.language}; los demás idiomas de la plantilla se quedan. No se puede deshacer, y los envíos que la usen (campañas, facturas, respuestas automáticas) empezarán a fallar.\n\nEl nombre «${borrando.name}» en ese idioma no se puede volver a usar durante un tiempo.`
                    : ''}
                confirmLabel="Borrar"
                loading={borrandoEnCurso}
                onConfirm={confirmarBorrado}
                onCancel={() => !borrandoEnCurso && setBorrando(null)}
            />

        </>
    );
}

/**
 * Copiar plantillas de una línea a otra de la misma empresa.
 *
 * Las plantillas no viven en el CRM: viven en Meta y son **por WABA**. Dos
 * líneas con WhatsApp Business distinto tienen catálogos separados, y lo que
 * hay en una no existe en la otra.
 *
 * Eso rompió la facturación de Transinternet el 10-sep-2026: al cambiar su
 * línea de envíos, Meta devolvía «(#100) Invalid parameter» en cada factura
 * porque en el WABA nuevo no existía `facturacion`. Antes de esto, mudarse de
 * línea significaba rehacer el catálogo a mano y descubrirlo factura a factura.
 */
function CopiarPlantillasModal({ instances, origenId, onClose, onCopiado }) {
    const origen = instances.find(i => i.id === origenId);
    const destinos = instances.filter(i => i.id !== origenId);

    const [destinoId, setDestinoId] = useState(destinos[0]?.id ?? null);
    const [enviando, setEnviando] = useState(false);
    const [resultado, setResultado] = useState(null);
    const [error, setError] = useState(null);

    const copiar = async () => {
        setEnviando(true);
        setError(null);
        setResultado(null);

        try {
            const { data } = await axios.post('/api/templates/duplicar', {
                origen_instance_id: origenId,
                destino_instance_id: destinoId,
            });
            setResultado(data);
            onCopiado?.();
        } catch (e) {
            setError(e.response?.data?.message ?? 'No se pudieron copiar las plantillas.');
        } finally {
            setEnviando(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-2xl border border-border bg-card p-6 shadow-2xl" onClick={e => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-foreground">Copiar plantillas a otra línea</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Cada línea tiene su propio catálogo en Meta. Esto lleva a la otra las que le falten.
                        </p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1 text-muted-foreground hover:bg-muted hover:text-foreground">
                        <X className="size-4" />
                    </button>
                </div>

                {!resultado && (
                    <>
                        <div className="mb-4 flex items-center gap-3 rounded-xl border border-border bg-muted/40 p-3">
                            <div className="min-w-0 flex-1">
                                <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground">Desde</p>
                                <p className="truncate text-sm font-semibold text-foreground">{origen?.name}</p>
                                <p className="truncate text-xs text-muted-foreground">{origen?.display_phone_number}</p>
                            </div>

                            <ArrowRight className="size-4 shrink-0 text-muted-foreground" />

                            <div className="min-w-0 flex-1">
                                <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground">Hacia</label>
                                <select
                                    value={destinoId ?? ''}
                                    onChange={e => setDestinoId(Number(e.target.value))}
                                    className="mt-1 h-9 w-full rounded-lg border border-input bg-card px-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring/50"
                                >
                                    {destinos.map(i => (
                                        <option key={i.id} value={i.id}>{i.name} ({i.display_phone_number})</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* Que Meta apruebe por su cuenta no es un detalle: es la
                            diferencia entre «ya puedo enviar» y «puedo enviar
                            cuando Meta diga». */}
                        <div className="mb-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">
                            <AlertTriangle className="mt-px size-3.5 shrink-0" />
                            {/* El texto va dentro de un span: suelto, cada nodo se
                                convierte en un elemento flex y la frase se parte
                                en columnas. */}
                            <span>
                                Las copias llegan como <strong>pendientes</strong>: cada WhatsApp Business las aprueba
                                por separado, y hasta que Meta las apruebe no se pueden enviar por la línea nueva.
                            </span>
                        </div>

                        {error && (
                            <p className="mb-3 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">{error}</p>
                        )}

                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>Cancelar</Button>
                            <Button onClick={copiar} disabled={enviando || !destinoId} className="gap-2">
                                {enviando ? <Loader2 className="size-4 animate-spin" /> : <Copy className="size-4" />}
                                {enviando ? 'Copiando…' : 'Copiar las que falten'}
                            </Button>
                        </div>
                    </>
                )}

                {resultado && (
                    <>
                        <p className="mb-3 text-sm text-foreground">{resultado.mensaje}</p>

                        {resultado.ya_estaban > 0 && (
                            <p className="mb-3 text-xs text-muted-foreground">
                                {resultado.ya_estaban} ya estaban en la otra línea y no se tocaron.
                            </p>
                        )}

                        {resultado.resultados?.length > 0 && (
                            <ul className="mb-4 max-h-56 space-y-1.5 overflow-y-auto">
                                {resultado.resultados.map(r => (
                                    <li key={r.plantilla} className="flex items-start gap-2 text-xs">
                                        {r.ok
                                            ? <CheckCircle2 className="mt-px size-3.5 shrink-0 text-success" />
                                            : <XCircle className="mt-px size-3.5 shrink-0 text-destructive" />}
                                        <span className="min-w-0">
                                            <span className="font-semibold text-foreground">{r.plantilla}</span>
                                            {r.ok
                                                ? <span className="text-muted-foreground"> · {r.estado === 'APPROVED' ? 'aprobada' : 'pendiente de Meta'}</span>
                                                : <span className="block text-destructive">{r.error}</span>}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="flex justify-end">
                            <Button onClick={onClose}>Cerrar</Button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

function StatCard({ icon: Icon, label, value, tone }) {
    const tones = {
        primary: 'bg-primary/10 text-primary',
        emerald: 'bg-success/10 text-success',
        amber: 'bg-warning/10 text-warning',
        indigo: 'bg-primary/10 text-accent-foreground',
        red: 'bg-destructive/10 text-destructive',
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

function FamilyCard({ family, isOpen, onToggle, onOpenDetail, canCreate, canEdit = false, canDelete = false, onDelete, instanceId, onAddTranslation }) {
    const CatIcon = CATEGORY_ICONS[family.category] ?? FileText;
    const variantCount = family.variants.length;
    const approvedCount = family.variants.filter(v => v.status === 'APPROVED').length;
    // La versión que abren «Ver» y «Editar»: la aprobada si hay, que es la que
    // se envía. Con varios idiomas, el detalle deja saltar a los demás.
    const principal = family.variants.find(v => v.status === 'APPROVED') ?? family.variants[0];
    const editable = principal && ESTADOS_EDITABLES.includes(principal.status);
    const conCalidadBaja = family.variants.filter(calidadPreocupa);
    const recategorizadas = family.variants.filter(v => v.previous_category && v.previous_category !== v.category);

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
                                <span className="inline-flex items-center gap-1 text-[11px] text-success">
                                    <CheckCircle2 className="size-3" />
                                    {approvedCount} aprobada{approvedCount > 1 ? 's' : ''}
                                </span>
                            )}
                        </div>
                        {/* El nombre abre el detalle. Hasta ahora lo único que
                            se podía pulsar era la pastilla del idioma —pequeña,
                            y con el estado escrito dentro, que la hace parecer
                            una etiqueta y no un botón—. Así que quien quería ver
                            su plantilla hacía clic en el nombre, que es lo
                            obvio, y no pasaba nada. */}
                        <button
                            type="button"
                            onClick={() => onOpenDetail(family.variants[0])}
                            title={`Ver ${family.name}`}
                            className="group/nombre flex max-w-full items-center gap-1.5 text-left"
                        >
                            <h3 className="font-mono text-base font-semibold text-foreground truncate group-hover/nombre:underline" title={family.name}>
                                {family.name}
                            </h3>
                            <Eye className="size-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover/nombre:opacity-100" />
                        </button>
                    </div>
                </div>

                {/* Language pills */}
                <div className="flex flex-wrap gap-1.5">
                    {family.variants.map(v => (
                        <button
                            key={v.id}
                            onClick={() => onOpenDetail(v)}
                            title={`${LANG_LABELS[v.language] ?? v.language} · ${estadoDe(v.status).texto}. ${estadoDe(v.status).ayuda}`}
                            className={`group/pill inline-flex items-center gap-1.5 rounded-full border pl-2 pr-2.5 py-1 text-xs font-mono transition-colors hover:border-primary/50 hover:bg-primary/5 ${
                                v.status === 'APPROVED'
                                    ? 'bg-background'
                                    : STATUS_STYLES[v.status] ?? 'bg-background'
                            }`}
                        >
                            <span className={`size-1.5 rounded-full ${STATUS_DOT[v.status] ?? 'bg-muted'}`} />
                            <span className="font-medium">{v.language}</span>
                            {/* Lo aprobado no lleva texto: es el estado normal y en una
                                empresa con veinte plantillas sería ruido repetido veinte
                                veces. Lo que no está aprobado sí lo dice, porque es
                                justo lo que hay que entender. Un punto de color no
                                explica nada por sí solo. */}
                            {v.status !== 'APPROVED' && (
                                <span className="font-sans font-semibold">
                                    {estadoDe(v.status).texto}
                                </span>
                            )}
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

                {conCalidadBaja.length > 0 && (
                    <p className={`mt-3 flex items-start gap-1.5 text-[11px] ${conCalidadBaja.some(v => calidadDe(v).score === 'RED') ? 'text-destructive' : 'text-warning'}`}>
                        <AlertTriangle className="size-3 mt-0.5 shrink-0" />
                        <span>
                            {conCalidadBaja.map(v => `${v.language}: ${calidadDe(v).texto.toLowerCase()}`).join(' · ')}.{' '}
                            {calidadDe(conCalidadBaja[0]).ayuda}
                        </span>
                    </p>
                )}

                {recategorizadas.length > 0 && (
                    <p className="mt-2 text-[11px] text-muted-foreground">
                        Meta la cambió de categoría: antes era {CATEGORIA_TEXTO[recategorizadas[0].previous_category] ?? recategorizadas[0].previous_category}.
                    </p>
                )}

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
                                    <span title={calidadDe(v).ayuda} className={`inline-flex items-center gap-1 text-[10px] ${calidadDe(v).clase}`}>
                                        <span className={`size-1.5 rounded-full ${calidadDe(v).punto}`} />
                                        {calidadDe(v).texto}
                                    </span>
                                </div>
                                <span
                                    title={estadoDe(v.status).ayuda}
                                    className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[v.status] ?? 'bg-muted text-muted-foreground'}`}
                                >
                                    {estadoDe(v.status).texto}
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {/* Las dos acciones que la gente busca, a la vista. Antes «ver» era
                pulsar el nombre o la pastilla del idioma y «editar» estaba
                dentro del detalle: nadie las encontraba y preguntaban cómo se
                veía o se cambiaba una plantilla (28-sep-2026). */}
            {principal && (
                <div className="flex flex-wrap items-center gap-2 border-t bg-muted/20 px-4 py-2.5 sm:px-5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 min-w-[7rem] flex-1 gap-1.5"
                        onClick={() => onOpenDetail(principal)}
                    >
                        <Eye className="size-3.5" /> Ver plantilla
                    </Button>
                    {canEdit && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 min-w-[7rem] flex-1 gap-1.5"
                            disabled={!editable}
                            title={editable
                                ? `Editar la versión en ${principal.language}`
                                : 'Meta sólo deja editar plantillas aprobadas, rechazadas o pausadas'}
                            onClick={() => router.visit(route('templates.edit', {
                                templateId: principal.id,
                                instance_id: instanceId,
                            }))}
                        >
                            <Pencil className="size-3.5" /> Editar
                        </Button>
                    )}
                    {/* Con un solo idioma no hay duda de qué se borra; con
                        varios, se borra desde el detalle de cada idioma. */}
                    {canDelete && variantCount === 1 && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            title={`Borrar la versión en ${principal.language}`}
                            onClick={() => onDelete?.(principal)}
                        >
                            <Trash2 className="size-3.5" /> Borrar
                        </Button>
                    )}
                    {/* El detalle por idioma sólo tiene sentido con varios idiomas;
                        con uno repetía lo que ya dice la tarjeta. */}
                    {variantCount > 1 && (
                        <button
                            type="button"
                            onClick={onToggle}
                            className="flex w-full items-center justify-center gap-1.5 pt-1 text-[11px] text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {isOpen ? <ChevronDown className="size-3" /> : <ChevronRight className="size-3" />}
                            {isOpen ? 'Ocultar idiomas' : `Ver los ${variantCount} idiomas`}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

/** Los estados en los que Meta deja editar una plantilla. */
const ESTADOS_EDITABLES = ['APPROVED', 'REJECTED', 'PAUSED'];

function TemplateDetailModal({ templateId, templateName, instanceId, negocio, canEdit = false, canDelete = false, onDelete, onClose, onSelectSibling }) {
    const [template, setTemplate] = useState(null);
    const [siblings, setSiblings] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [tab, setTab] = useState('preview');

    // Se abre por la vista previa y no por la ficha de datos: quien entra aquí
    // quiere ver cómo le va a llegar el mensaje al cliente. El id, la categoría
    // y el estado están a una pestaña, y ya se ven en la tarjeta de fuera.
    useEffect(() => {
        setTab('preview');
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
                // Meta filtra `name` por «contiene»: sin esto, `pago` listaba
                // como traducciones las de `pago_recibido`.
                setSiblings((famRes.data.data || []).filter(s => s.name === templateName));
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
                    <div className="flex shrink-0 items-center gap-1">
                        {/* Se edita el idioma que está abierto: cada traducción es
                            una plantilla aparte en Meta, con su propio estado. */}
                        {canEdit && template && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5"
                                disabled={!ESTADOS_EDITABLES.includes(template.status)}
                                title={ESTADOS_EDITABLES.includes(template.status)
                                    ? `Editar la versión en ${template.language}`
                                    : 'Meta solo deja editar plantillas aprobadas, rechazadas o pausadas'}
                                onClick={() => router.visit(route('templates.edit', {
                                    templateId: template.id,
                                    instance_id: instanceId,
                                }))}
                            >
                                <Pencil className="size-3.5" /> Editar
                            </Button>
                        )}
                        {canDelete && template && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                title={`Borrar la versión en ${template.language}`}
                                onClick={() => onDelete?.({ id: template.id, name: template.name ?? templateName, language: template.language })}
                            >
                                <Trash2 className="size-3.5" /> Borrar
                            </Button>
                        )}
                        <Button variant="ghost" size="icon" onClick={onClose}>
                            <X className="size-4" />
                        </Button>
                    </div>
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
                            // El negocio, no el nombre técnico de la plantilla:
                            // el cliente ve quién le escribe, no `facturacion`.
                            verifiedName={negocio || 'Tu negocio'}
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
                            <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
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
                                    <span
                                        title={estadoDe(template.status).ayuda}
                                        className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold ${STATUS_STYLES[template.status] ?? 'bg-muted text-muted-foreground'}`}
                                    >
                                        {estadoDe(template.status).texto}
                                    </span>
                                </Field>
                                <Field label="Calidad">
                                    <span title={calidadDe(template).ayuda} className={`inline-flex items-center gap-1.5 text-xs font-medium ${calidadDe(template).clase}`}>
                                        <span className={`size-2 rounded-full ${calidadDe(template).punto}`} />
                                        {calidadDe(template).texto}
                                    </span>
                                </Field>
                            </div>

                            {estadoDe(template.status).ayuda && template.status !== 'APPROVED' && (
                                <p className="text-xs text-muted-foreground">{estadoDe(template.status).ayuda}</p>
                            )}

                            {calidadPreocupa(template) && (
                                <div className={`rounded-md border px-3 py-2 text-xs ${calidadDe(template).score === 'RED' ? 'border-destructive/30 bg-destructive/10 text-destructive' : 'border-warning/30 bg-warning/10 text-warning'}`}>
                                    <strong>{calidadDe(template).texto}.</strong> {calidadDe(template).ayuda}
                                </div>
                            )}

                            {template.previous_category && template.previous_category !== template.category && (
                                <div className="rounded-md border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                                    Meta la cambió de categoría: antes era{' '}
                                    <strong className="text-foreground">{CATEGORIA_TEXTO[template.previous_category] ?? template.previous_category}</strong>, ahora es{' '}
                                    <strong className="text-foreground">{CATEGORIA_TEXTO[template.category] ?? template.category}</strong>.
                                    {template.category === 'MARKETING' && ' Los mensajes de marketing cuestan más por envío.'}
                                </div>
                            )}

                            {motivoRechazo(template.rejected_reason) && (
                                <div className="rounded-md border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-warning">
                                    <strong>Motivo de rechazo:</strong> {motivoRechazo(template.rejected_reason)}
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
                                                    {estadoDe(s.status).texto}
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
