import { useState, useMemo, useEffect, useRef, useCallback, memo } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import {
    Search,
    MessageSquare,
    Plus,
    User,
    Clock,
    CheckCircle2,
    Layers,
    Inbox,
    ChevronLeft,
    ChevronRight,
    AlertCircle,
    GripVertical,
    Calendar,
    ArrowRight,
    Users,
    Zap,
    LayoutDashboard,
    X,
    Loader2,
    ChevronDown,
} from 'lucide-react';
import {
    DragDropContext,
    Droppable,
    Draggable,
} from '@hello-pangea/dnd';
import { clsx } from 'clsx';
import { colorPorIndice } from '@/lib/paleta';

const PER_PAGE = 30;

// Icon map: backend string → React component
const ICON_MAP = { Plus, MessageSquare, Calendar, CheckCircle2, Zap, LayoutDashboard, Users, User };
const getIcon = (name) => ICON_MAP[name] ?? Zap;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function apiRequest(method, url, body = null) {
    const options = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'Accept': 'application/json',
        },
    };
    if (body) options.body = JSON.stringify(body);
    const res = await fetch(url, options);
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message ?? `HTTP ${res.status}`);
    }
    return res.json();
}

// ─── KanbanCard ──────────────────────────────────────────────────────────────

/**
 * Cuánto lleva la tarjeta en su columna.
 *
 * Cada columna es una etiqueta, así que la fecha en que se enganchó es la fecha
 * en que la tarjeta entró. Las filas anteriores al 9-sep-2026 se guardaron sin
 * hora —la relación no declaraba withTimestamps— y para esas no se inventa
 * nada: no se pinta el distintivo.
 */
const diasEnEtapa = (entroEnEtapa) => {
    if (!entroEnEtapa) return null;

    const dias = Math.floor((Date.now() - new Date(entroEnEtapa).getTime()) / 86400000);

    return Number.isFinite(dias) && dias >= 0 ? dias : null;
};

const DIAS_PARA_AVISAR = 7;

const KanbanCard = memo(({ conv, isOverlay, isDragging, ...props }) => {
    const dias = diasEnEtapa(conv.entro_en_etapa);
    const estancada = dias !== null && dias >= DIAS_PARA_AVISAR;

    return (
        <div
            {...props}
            className={clsx(
                'group relative bg-card px-3.5 py-3 rounded-2xl border select-none',
                isOverlay
                    ? 'border-primary/40 shadow-2xl z-50 cursor-grabbing ring-2 ring-primary/20 scale-[1.02] rotate-1 transition-transform duration-200'
                    : isDragging
                        ? 'opacity-0'
                        : 'border-border/60 shadow-sm cursor-grab active:cursor-grabbing hover:shadow-lg hover:-translate-y-0.5 hover:border-primary/30 transition-all duration-200'
            )}
        >
            <div className="flex items-center gap-3 mb-2">
                <div className="relative shrink-0">
                    <div className="size-9 rounded-xl bg-muted dark:bg-card text-foreground dark:text-muted-foreground flex items-center justify-center font-black text-[11px] uppercase border border-border/50">
                        {conv.initials}
                    </div>
                    {conv.unread_count > 0 && (
                        <div className="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 bg-primary text-primary-foreground text-[10px] font-black rounded-full border-2 border-white dark:border-muted flex items-center justify-center">
                            {conv.unread_count}
                        </div>
                    )}
                </div>

                <div className="min-w-0 flex-1">
                    <h3 className="text-[13px] font-black text-foreground dark:text-muted-foreground truncate tracking-tight leading-tight">
                        {conv.name || conv.phone_number}
                    </h3>
                    <div className="flex items-center gap-1 text-[10px] text-muted-foreground font-bold leading-tight">
                        <Clock className="size-2.5 shrink-0" />
                        {conv.last_message_at
                            ? new Date(conv.last_message_at).toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })
                            : 'sin mensajes'}
                    </div>
                </div>

                <GripVertical className="size-4 shrink-0 text-muted-foreground/0 group-hover:text-muted-foreground/60 transition-colors" />
            </div>

            <p className="text-[12px] text-muted-foreground line-clamp-2 leading-snug mb-2.5">
                {conv.last_message || 'Sin mensajes todavía'}
            </p>

            <div className="flex items-center justify-between gap-2">
                {conv.assigned_agent ? (
                    <div className="flex items-center gap-1.5 min-w-0">
                        <div
                            className="size-5 shrink-0 rounded-full bg-primary/20 text-accent-foreground flex items-center justify-center text-[8px] font-black"
                            title={conv.assigned_agent.name}
                        >
                            {conv.assigned_agent.name.substring(0, 2).toUpperCase()}
                        </div>
                        <span className="text-[10px] font-bold text-muted-foreground truncate">
                            {conv.assigned_agent.name}
                        </span>
                    </div>
                ) : (
                    <span className="text-[10px] font-bold text-muted-foreground/60">Sin agente</span>
                )}

                {dias !== null && (
                    <span
                        className={clsx(
                            'shrink-0 px-2 py-0.5 rounded-full text-[10px] font-black tabular-nums',
                            estancada
                                ? 'bg-warning/15 text-warning'
                                : 'bg-muted text-muted-foreground'
                        )}
                        title={estancada
                            ? `Lleva ${dias} días en esta etapa sin moverse`
                            : `Entró en esta etapa hace ${dias} ${dias === 1 ? 'día' : 'días'}`}
                    >
                        {dias === 0 ? 'hoy' : `${dias} d`}
                    </span>
                )}
            </div>
        </div>
    );
});

const SortableKanbanCard = memo(({ conv, index }) => {
    return (
        <Draggable draggableId={String(conv.id)} index={index}>
            {(provided, snapshot) => (
                <div
                    ref={provided.innerRef}
                    {...provided.draggableProps}
                    {...provided.dragHandleProps}
                    className="outline-none"
                    style={provided.draggableProps.style}
                >
                    <KanbanCard 
                        conv={conv} 
                        isOverlay={snapshot.isDragging}
                        isDragging={snapshot.isDragging} 
                    />
                </div>
            )}
        </Draggable>
    );
});

// ─── ColumnaBorrador ─────────────────────────────────────────────────────────
//
// La etapa que todavía no existe: ocupa el sitio de la columna, con el cursor
// dentro. Enter la crea, Escape o dejarla vacía la descarta.

const ColumnaBorrador = ({ valor, onCambio, onCrear, onCancelar, creando, error }) => (
    <div className="flex-1 min-w-[300px] max-w-[400px] flex flex-col">
        <form
            onSubmit={e => { e.preventDefault(); onCrear(valor); }}
            className="mb-6 px-3"
        >
            <label className="block text-[9px] font-black text-muted-foreground uppercase tracking-widest mb-2">
                Nombre de la etapa
            </label>
            <input
                autoFocus
                value={valor}
                disabled={creando}
                onChange={e => onCambio(e.target.value)}
                onKeyDown={e => { if (e.key === 'Escape') onCancelar(); }}
                placeholder="Ej. Cotización enviada"
                className="w-full px-4 py-2.5 bg-white dark:bg-muted border border-border rounded-2xl text-xs focus:outline-none focus:ring-4 focus:ring-primary/5 focus:border-primary/40 transition-all shadow-sm placeholder:text-muted-foreground disabled:opacity-60"
            />
            {error && <p className="text-[10px] text-destructive mt-2 font-bold">{error}</p>}
            <div className="flex items-center gap-2 mt-3">
                <button
                    type="submit"
                    disabled={creando || !valor.trim()}
                    className="px-4 py-2 bg-foreground text-background rounded-2xl text-[11px] font-black disabled:opacity-40 transition-all"
                >
                    {creando ? 'Creando…' : 'Crear etapa'}
                </button>
                <button
                    type="button"
                    onClick={onCancelar}
                    className="px-3 py-2 text-[11px] font-bold text-muted-foreground hover:text-foreground transition-colors"
                >
                    Cancelar
                </button>
            </div>
        </form>
        <div className="flex-1 rounded-[1.75rem] border-2 border-dashed border-border/60" />
    </div>
);

// ─── BoardColumn ─────────────────────────────────────────────────────────────

const BoardColumn = memo(({ col, indice, items, totalCount, loading, hasMore, error, onLoadMore, onRename, onDelete, onAddCard, onCambiarGrupo, onCambiarBandeja }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [title, setTitle]         = useState(col.name);
    const [editandoGrupo, setEditandoGrupo] = useState(false);
    const [grupo, setGrupo]         = useState(col.grupo ?? '');
    const Icon = getIcon(col.icon);
    const tono = colorPorIndice(indice);

    const handleRenameSubmit = (e) => {
        e?.preventDefault();
        if (title.trim() && title.trim() !== col.name) onRename(col.id, title.trim());
        setIsEditing(false);
    };

    const guardarGrupo = (e) => {
        e?.preventDefault();
        const limpio = grupo.trim();
        if (limpio !== (col.grupo ?? '')) onCambiarGrupo(col.id, limpio || null);
        setEditandoGrupo(false);
    };

    return (
        <div className={clsx(
            'flex-1 min-w-[290px] max-w-[360px] flex flex-col h-full group/column rounded-3xl border overflow-hidden transition-colors',
            tono.borde,
            tono.tenue
        )}>
            {/* La barra de color es lo que separa una etapa de la siguiente de
                un vistazo, sin tener que leer los títulos. */}
            <div className={clsx('h-1 shrink-0', tono.barra)} />

            {/* Header */}
            <div className="relative flex items-start justify-between gap-2 px-4 pt-3.5 pb-3">
                <div className="flex items-center gap-3 min-w-0 flex-1">
                    {/* En oscuro los tonos de etapa se aclaran para despegarse del
                        navy, y ahí el icono blanco se pierde: sobre el verde de
                        marca claro son 1,73:1. El navy del fondo sobre ese
                        mismo verde son 10,7:1. */}
                    <div className={clsx('shrink-0 p-2 rounded-xl text-white dark:text-background shadow-sm', tono.punto)}>
                        <Icon className="size-3.5" />
                    </div>
                    <div className="flex flex-col min-w-0">
                        {isEditing ? (
                            <form onSubmit={handleRenameSubmit}>
                                <input
                                    autoFocus
                                    value={title}
                                    onChange={e => setTitle(e.target.value)}
                                    onBlur={handleRenameSubmit}
                                    className="bg-transparent border-none p-0 font-black text-[12px] text-foreground dark:text-muted-foreground uppercase tracking-[0.1em] focus:ring-0 w-32"
                                />
                            </form>
                        ) : (
                            <h2
                                onClick={() => setIsEditing(true)}
                                title={col.name}
                                className="font-black text-[12px] text-foreground dark:text-muted-foreground uppercase tracking-[0.08em] cursor-text truncate"
                            >
                                {col.name}
                            </h2>
                        )}
                        {editandoGrupo ? (
                            <form onSubmit={guardarGrupo}>
                                <input
                                    autoFocus
                                    value={grupo}
                                    onChange={e => setGrupo(e.target.value)}
                                    onBlur={guardarGrupo}
                                    onKeyDown={e => { if (e.key === 'Escape') { setGrupo(col.grupo ?? ''); setEditandoGrupo(false); } }}
                                    placeholder="Grupo (Estado, Zona…)"
                                    className="bg-transparent border-b border-border p-0 text-[10px] text-muted-foreground focus:ring-0 focus:border-primary/40 w-36"
                                />
                            </form>
                        ) : null}
                        <div className="flex items-center gap-1.5 mt-0.5">
                            <span className={clsx('px-1.5 py-px rounded-md text-[10px] font-black tabular-nums bg-white/70 dark:bg-black/20', tono.texto)}>
                                {(totalCount ?? items.length).toLocaleString('es-CO')}
                            </span>
                            {col.es_bandeja && (
                                <span
                                    className="inline-flex items-center gap-1 text-[9px] font-black text-muted-foreground uppercase tracking-wider"
                                    title="Recoge lo que no está clasificado en este grupo"
                                >
                                    <Inbox className="size-2.5" /> Bandeja
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="absolute right-2.5 top-2.5 flex items-center gap-0.5 p-0.5 rounded-xl bg-card/90 backdrop-blur shadow-sm border border-border/60 opacity-0 group-hover/column:opacity-100 transition-opacity">
                    <button
                        onClick={() => onCambiarBandeja(col.id, !col.es_bandeja)}
                        className={clsx(
                            'p-1.5 rounded-lg transition-colors',
                            col.es_bandeja
                                ? 'bg-primary/15 text-accent-foreground'
                                : 'hover:bg-muted text-muted-foreground hover:text-foreground'
                        )}
                        title={col.es_bandeja
                            ? 'Recoge lo que no está clasificado en este grupo'
                            : 'Hacer que recoja lo que no está clasificado en este grupo'}
                    >
                        <Inbox className="size-3.5" />
                    </button>
                    <button onClick={() => setEditandoGrupo(true)} className="p-1.5 hover:bg-muted text-muted-foreground hover:text-foreground rounded-lg transition-colors" title="Grupo de la etapa">
                        <Layers className="size-3.5" />
                    </button>
                    <button onClick={() => onDelete(col.id)} className="p-1.5 hover:bg-destructive/15 dark:hover:bg-destructive/20 text-muted-foreground hover:text-destructive rounded-lg transition-colors" title="Eliminar etapa">
                        <AlertCircle className="size-3.5" />
                    </button>
                    <button onClick={() => onAddCard(col.id)} className="p-1.5 hover:bg-muted dark:hover:bg-muted text-muted-foreground hover:text-muted-foreground rounded-lg transition-colors" title="Agregar tarjeta">
                        <Plus className="size-3.5" />
                    </button>
                </div>
            </div>

            {/* Body */}
            <Droppable droppableId={String(col.id)}>
                {(provided, snapshot) => (
                    <div 
                        ref={provided.innerRef}
                        {...provided.droppableProps}
                        className={clsx(
                            'flex-1 overflow-y-auto space-y-3 custom-scrollbar px-3 pb-6 pt-1 min-h-[200px] transition-colors duration-200',
                            snapshot.isDraggingOver ? clsx('ring-2 ring-inset', tono.encima) : ''
                        )}
                    >
                        {items.map((conv, index) => (
                            <SortableKanbanCard key={conv.id} conv={conv} index={index} />
                        ))}
                        {provided.placeholder}

                        {/* Loading skeleton (first load) */}
                        {loading && items.length === 0 && (
                            <div className="space-y-3">
                                {[1, 2, 3].map(n => (
                                    <div key={n} className="bg-card/70 rounded-2xl border border-border/50 p-4 animate-pulse">
                                        <div className="flex items-center gap-3 mb-4">
                                            <div className="size-11 rounded-2xl bg-muted" />
                                            <div className="flex-1 space-y-2">
                                                <div className="h-3 bg-muted rounded-full w-3/4" />
                                                <div className="h-2 bg-muted rounded-full w-1/2" />
                                            </div>
                                        </div>
                                        <div className="space-y-1.5">
                                            <div className="h-2 bg-muted rounded-full" />
                                            <div className="h-2 bg-muted rounded-full w-5/6" />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Error state */}
                        {error && (
                            <div className="border-2 border-dashed border-destructive/30 rounded-[2rem] py-10 px-4 flex flex-col items-center justify-center gap-2 text-center">
                                <AlertCircle className="size-6 text-destructive" />
                                <p className="text-[11px] font-bold text-destructive">Error al cargar tarjetas</p>
                                <p className="text-[10px] text-muted-foreground">{error}</p>
                                <button
                                    onClick={() => onLoadMore(col.id)}
                                    className="mt-1 text-[10px] font-black text-accent-foreground uppercase tracking-widest hover:underline"
                                >
                                    Reintentar
                                </button>
                            </div>
                        )}

                        {/* Empty state */}
                        {!loading && !error && items.length === 0 && (
                            <div className="border-2 border-dashed border-border rounded-[2.5rem] py-16 flex flex-col items-center justify-center transition-all hover:bg-muted dark:hover:bg-muted/30 hover:border-primary/20">
                                <div className="p-4 bg-white dark:bg-muted shadow-lg rounded-full mb-3">
                                    <LayoutDashboard className="size-6 text-muted-foreground dark:text-foreground" />
                                </div>
                                <p className="text-[10px] font-black uppercase tracking-[0.2em] text-muted-foreground dark:text-foreground">Arrastra aquí</p>
                            </div>
                        )}

                        {/* Load more */}
                        {hasMore && !loading && !error && items.length > 0 && (
                            <button
                                onClick={() => onLoadMore(col.id)}
                                className="w-full py-3 flex items-center justify-center gap-2 text-[11px] font-black text-muted-foreground hover:text-accent-foreground uppercase tracking-widest border-2 border-dashed border-border rounded-2xl hover:border-primary/30 transition-all"
                            >
                                <ChevronDown className="size-3.5" /> Cargar más
                            </button>
                        )}

                        {/* Loading more spinner */}
                        {loading && items.length > 0 && (
                            <div className="flex justify-center py-4">
                                <Loader2 className="size-5 text-accent-foreground animate-spin" />
                            </div>
                        )}
                    </div>
                )}
            </Droppable>
        </div>
    );
});

// ─── NewCardModal ─────────────────────────────────────────────────────────────

const NewCardModal = ({ instances, defaultColumnId, onClose, onCreated }) => {
    const [form, setForm]     = useState({ name: '', phone_number: '', instance_id: String(instances[0]?.id ?? '') });
    const [saving, setSaving] = useState(false);
    const [error, setError]   = useState(null);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        try {
            const card = await apiRequest('POST', '/api/kanban/cards', { ...form, column_id: defaultColumnId });
            onCreated(card);
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white dark:bg-muted rounded-3xl shadow-2xl w-full max-w-md mx-4 p-6">
                <div className="flex items-center justify-between mb-6">
                    <h2 className="font-black text-foreground dark:text-white text-lg">Nueva Tarjeta</h2>
                    <button onClick={onClose} className="p-2 hover:bg-muted dark:hover:bg-muted rounded-xl transition-colors">
                        <X className="size-4 text-muted-foreground" />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="block text-[11px] font-black text-muted-foreground uppercase tracking-widest mb-1">Nombre (opcional)</label>
                        <input
                            type="text"
                            value={form.name}
                            onChange={e => setForm(p => ({ ...p, name: e.target.value }))}
                            placeholder="Ej: Juan García"
                            className="w-full px-4 py-2.5 bg-muted border border-border rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary/40 transition-all"
                        />
                    </div>
                    <div>
                        <label className="block text-[11px] font-black text-muted-foreground uppercase tracking-widest mb-1">Número de teléfono *</label>
                        <input
                            type="text"
                            required
                            value={form.phone_number}
                            onChange={e => setForm(p => ({ ...p, phone_number: e.target.value }))}
                            placeholder="Ej: 573001234567"
                            className="w-full px-4 py-2.5 bg-muted border border-border rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary/40 transition-all"
                        />
                    </div>
                    {instances.length > 1 && (
                        <div>
                            <label className="block text-[11px] font-black text-muted-foreground uppercase tracking-widest mb-1">Instancia WhatsApp *</label>
                            <select
                                value={form.instance_id}
                                onChange={e => setForm(p => ({ ...p, instance_id: e.target.value }))}
                                className="w-full px-4 py-2.5 bg-muted border border-border rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary/40 transition-all"
                            >
                                {instances.map(inst => <option key={inst.id} value={inst.id}>{inst.name}</option>)}
                            </select>
                        </div>
                    )}
                    {error && <p className="text-[12px] text-destructive font-medium">{error}</p>}
                    <div className="flex gap-3 pt-2">
                        <button type="button" onClick={onClose} className="flex-1 py-2.5 border border-border rounded-2xl text-sm font-bold text-muted-foreground hover:bg-muted dark:hover:bg-muted transition-colors">
                            Cancelar
                        </button>
                        <button type="submit" disabled={saving} className="flex-1 py-2.5 bg-foreground dark:bg-foreground text-background dark:text-background rounded-2xl text-sm font-black shadow-lg hover:scale-[1.02] active:scale-95 transition-all disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center gap-2">
                            {saving && <Loader2 className="size-4 animate-spin" />}
                            {saving ? 'Creando...' : 'Crear Tarjeta'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

// ─── Main Component ───────────────────────────────────────────────────────────

export default function Kanban({ columns: initialColumns, total_conversations, en_tablero = 0, estancadas = 0, instances: initialInstances }) {
    const [searchQuery, setSearchQuery]     = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [columns, setColumns]             = useState(initialColumns ?? []);
    // ── Grupos ─────────────────────────────────────────────────────────────
    //
    // Cada columna pertenece a un grupo: Estado, Zona, Área… El tablero pinta
    // las columnas de UN grupo y las de los demás se vuelven filtros arriba.
    //
    // Es lo que permite que una conversación esté a la vez en «Pendiente
    // cliente», en «MONTERIA» y en «FACTURACION». Antes había que elegir una
    // sola, y arrastrar la tarjeta borraba las otras dos.
    //
    // `grupo` nulo es el grupo «sin agrupar», donde están todas las columnas
    // que ya existían.
    const [grupoActivo, setGrupoActivo]     = useState(() => (initialColumns ?? [])[0]?.grupo ?? null);
    const [filtros, setFiltros]             = useState([]);   // ids de columnas de otros grupos
    const [borradorEtapa, setBorradorEtapa] = useState(null);   // null = no hay borrador abierto
    const [creandoEtapa, setCreandoEtapa]   = useState(false);
    const [errorEtapa, setErrorEtapa]       = useState(null);
    const [newCardColumn, setNewCardColumn] = useState(null);

    // boardData[colId] = Card[]
    const [boardData, setBoardData] = useState(() =>
        Object.fromEntries((initialColumns ?? []).map(c => [c.id, []]))
    );
    // colMeta[colId] = { page, hasMore, loading, error }
    const [colMeta, setColMeta] = useState(() =>
        Object.fromEntries((initialColumns ?? []).map(c => [c.id, { page: 0, hasMore: true, loading: false, error: null }]))
    );

    const [activeId, setActiveId]           = useState(null);
    // Real card counts per column from the server (not just loaded cards).
    const [colCounts, setColCounts]         = useState({});

    const grupos = useMemo(() => {
        const vistos = [];
        for (const c of columns) {
            const g = c.grupo ?? null;
            if (!vistos.includes(g)) vistos.push(g);
        }
        return vistos;
    }, [columns]);

    const columnasVisibles = useMemo(
        () => columns.filter(c => (c.grupo ?? null) === grupoActivo),
        [columns, grupoActivo]
    );

    const otrosGrupos = useMemo(
        () => grupos
            .filter(g => g !== grupoActivo)
            .map(g => ({ grupo: g, columnas: columns.filter(c => (c.grupo ?? null) === g) })),
        [grupos, grupoActivo, columns]
    );

    // ── Desplazamiento horizontal ──────────────────────────────────────────
    //
    // Con los grupos, un tablero baja a seis o siete columnas y cabe entero.
    // Pero «Sin agrupar» puede seguir teniendo cuarenta, y entonces la única
    // forma de llegar a la última era arrastrar una barra de 6 píxeles.
    const tableroRef = useRef(null);
    // Cada flecha se pinta sólo si hay algo hacia ese lado: si no, la de la
    // izquierda tapa tarjetas de la primera columna sin hacer nada.
    const [puedeIzquierda, setPuedeIzquierda] = useState(false);
    const [puedeDerecha, setPuedeDerecha]     = useState(false);

    const desplazar = (direccion) => {
        tableroRef.current?.scrollBy({ left: direccion * 360, behavior: 'smooth' });
    };

    // Para las dependencias de los efectos: un array nuevo en cada render los
    // dispararía en bucle.
    const filtrosKey = filtros.join(',');

    // loadCounts se llama también desde el canal de tiempo real, sin
    // argumentos, así que lee la vista actual de aquí en vez de recrearse.
    const vistaRef = useRef({ grupo: grupoActivo, filtros });
    useEffect(() => { vistaRef.current = { grupo: grupoActivo, filtros }; }, [grupoActivo, filtrosKey]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const tablero = tableroRef.current;
        if (!tablero) return;

        const medir = () => {
            const restante = tablero.scrollWidth - tablero.clientWidth - tablero.scrollLeft;
            setPuedeIzquierda(tablero.scrollLeft > 8);
            setPuedeDerecha(restante > 8);
        };
        medir();

        // Al cambiar de grupo cambian las columnas, y al cambiar el tamaño de
        // la ventana cambia lo que cabe.
        const observador = new ResizeObserver(medir);
        observador.observe(tablero);
        tablero.addEventListener('scroll', medir, { passive: true });

        return () => {
            observador.disconnect();
            tablero.removeEventListener('scroll', medir);
        };
    }, [columnasVisibles.length, borradorEtapa]);

    // We keep a ref to the latest boardData so handleDragEnd can read the
    // current state synchronously without relying on stale closures.
    const boardDataRef = useRef(boardData);
    useEffect(() => { boardDataRef.current = boardData; }, [boardData]);

    // AbortController per column so we can cancel stale requests.
    const abortControllersRef = useRef({});

    // ── Data fetching ──────────────────────────────────────────────────────

    const loadColumnCards = useCallback(async (colId, page, search, reset = false, filtrosActivos = []) => {
        // Cancel any in-flight request for this column
        if (abortControllersRef.current[colId]) {
            abortControllersRef.current[colId].abort();
        }
        const controller = new AbortController();
        abortControllersRef.current[colId] = controller;

        setColMeta(prev => ({ ...prev, [colId]: { ...prev[colId], loading: true } }));
        try {
            const params = new URLSearchParams({ page, per_page: PER_PAGE });
            if (search) params.set('search', search);
            filtrosActivos.forEach(id => params.append('filtros[]', id));

            const res = await fetch(`/api/kanban/columns/${colId}/cards?${params}`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                signal: controller.signal,
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();

            // If this controller was replaced (another request started), ignore.
            if (controller.signal.aborted) return;

            setBoardData(prev => {
                const newData = reset ? data.data : [...(prev[colId] ?? []), ...data.data];
                // Simple de-duplicate by ID
                const seen = new Set();
                const unique = newData.filter(c => {
                    if (seen.has(c.id)) return false;
                    seen.add(c.id);
                    return true;
                });
                return { ...prev, [colId]: unique };
            });
            setColMeta(prev => ({
                ...prev,
                [colId]: { page: data.current_page, hasMore: data.has_more, loading: false, error: null },
            }));
        } catch (err) {
            if (err.name === 'AbortError') return; // Request was cancelled, ignore
            console.error(`Error cargando columna ${colId}:`, err);
            setColMeta(prev => ({ ...prev, [colId]: { ...prev[colId], loading: false, error: err.message } }));
        }
    }, []);

    // Fetch real card counts per column
    const loadCounts = useCallback(async () => {
        const { grupo, filtros: activos } = vistaRef.current;
        try {
            const params = new URLSearchParams();
            if (grupo) params.set('grupo', grupo);
            activos.forEach(id => params.append('filtros[]', id));

            const data = await apiRequest('GET', `/api/kanban/counts?${params}`);
            setColCounts(data);
        } catch (err) {
            console.error('Error cargando conteos:', err);
        }
    }, []);

    // Carga inicial, y recarga al cambiar de grupo o de filtros.
    useEffect(() => {
        loadCounts();
        columnasVisibles.forEach(col => loadColumnCards(col.id, 1, debouncedSearch, true, filtros));
    }, [grupoActivo, filtrosKey]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Tiempo real ────────────────────────────────────────────────────────
    //
    // El tablero se mueve solo: si otro agente arrastra una tarjeta, etiqueta un
    // chat o entra un mensaje nuevo, la tarjeta salta de columna sin recargar.
    // El canal es por instancia, así que hay que suscribirse a todas las de la
    // empresa (el tablero no filtra por instancia, a diferencia del chat).
    useEffect(() => {
        const instances = initialInstances ?? [];
        if (!window.Echo || instances.length === 0 || columns.length === 0) return;

        // Las conversaciones sin columna asignada viven en la primera columna
        // (mismo criterio que usa el backend en columnCards).
        const firstColumnId = columnasVisibles[0]?.id;

        // Recontar es una petición aparte: se agrupan las ráfagas (una tanda de
        // mensajes entrantes) en una sola llamada.
        let countsTimer = null;
        const scheduleCounts = () => {
            clearTimeout(countsTimer);
            countsTimer = setTimeout(loadCounts, 1500);
        };

        const removeCard = (id) => {
            setBoardData(prev => {
                const next = {};
                for (const [colId, cards] of Object.entries(prev)) {
                    next[colId] = cards.filter(c => c.id !== id);
                }
                return next;
            });
        };

        const upsertCard = (conv) => {
            const targetKey = String(conv.kanban_column_id ?? firstColumnId);

            setBoardData(prev => {
                // La columna destino puede no estar en el tablero (una columna
                // creada por otro agente en esta misma sesión); ahí no hay nada
                // que pintar hasta que se recargue.
                if (!(targetKey in prev)) return prev;

                const next = {};
                for (const [colId, cards] of Object.entries(prev)) {
                    next[colId] = cards.filter(c => c.id !== conv.id);
                }

                // Conserva lo que ya tenía la tarjeta y le encima lo que llega:
                // el payload del evento es un superconjunto de lo que pide el
                // tablero, pero así no se pierde nada si eso cambia.
                const previous = Object.values(prev).flat().find(c => c.id === conv.id);
                const card = { ...(previous ?? {}), ...conv };

                // Se inserta respetando el orden del tablero (last_message_at desc).
                const list = next[targetKey];
                const at = list.findIndex(
                    c => new Date(c.last_message_at ?? 0) < new Date(card.last_message_at ?? 0),
                );
                next[targetKey] = at === -1 ? [...list, card] : [...list.slice(0, at), card, ...list.slice(at)];

                return next;
            });
        };

        const channels = instances.map(inst => {
            const name = `instance.${inst.id}`;

            window.Echo.private(name).listen('.conversation.event', (e) => {
                if (e?.action === 'deleted') {
                    removeCard(e.conversation_id);
                    scheduleCounts();
                    return;
                }
                if (e?.action === 'updated' && e.conversation) {
                    upsertCard(e.conversation);
                    scheduleCounts();
                }
                // 'bulk_closed' no toca el tablero: el kanban muestra las
                // conversaciones estén abiertas o cerradas.
            });

            return name;
        });

        return () => {
            clearTimeout(countsTimer);
            channels.forEach(name => window.Echo.leave(name));
        };
    }, [initialInstances, columns, loadCounts]);

    // Debounce search
    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(searchQuery), 350);
        return () => clearTimeout(t);
    }, [searchQuery]);

    // Re-fetch all columns when search changes
    useEffect(() => {
        if (debouncedSearch !== undefined) {
            columnasVisibles.forEach(col => loadColumnCards(col.id, 1, debouncedSearch, true, filtros));
        }
    }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

    const handleLoadMore = (colId) => {
        const meta = colMeta[colId];
        if (!meta || meta.loading) return;
        if (!meta.error && !meta.hasMore) return;
        loadColumnCards(colId, meta.page + 1, debouncedSearch, false, filtros);
    };

    // ── Column CRUD ────────────────────────────────────────────────────────

    /**
     * Una etapa no se crea hasta que tiene nombre.
     *
     * Antes este botón creaba de golpe una columna llamada literalmente «Nueva
     * Etapa» —y con ella una etiqueta real, porque cada columna es una
     * etiqueta— y aparecía al final del tablero, fuera de la vista, esperando a
     * que alguien cayera en que hay que hacer clic en el título para
     * renombrarla. El 9-sep-2026 había **14 columnas «Nueva Etapa»** en la
     * flota, cuatro de las siete de INCO INTEGRATEC y dos de las diez de CMNET.
     *
     * Ahora aparece un borrador con el cursor dentro: se escribe el nombre y se
     * crea, o se deja vacío y no se crea nada.
     */
    const addColumn = () => {
        setErrorEtapa(null);
        setBorradorEtapa('');
    };

    const crearEtapa = async (nombre) => {
        const limpio = nombre.trim();
        if (!limpio) { setBorradorEtapa(null); return; }

        setCreandoEtapa(true);
        try {
            const col = await apiRequest('POST', '/api/kanban/columns', {
                name: limpio, color: 'bg-muted', icon: 'Zap', subtitle: 'Personalizado',
                grupo: grupoActivo,   // nace donde se está mirando, no suelta al final
            });
            setColumns(prev => [...prev, col]);
            setBoardData(prev => ({ ...prev, [col.id]: [] }));
            setColMeta(prev => ({ ...prev, [col.id]: { page: 1, hasMore: false, loading: false } }));
            setBorradorEtapa(null);
            setErrorEtapa(null);
        } catch (err) {
            // El nombre repetido es el caso normal aquí, y hay que poder
            // corregirlo sin perder lo escrito: el borrador se queda abierto.
            setErrorEtapa(err.message);
        } finally {
            setCreandoEtapa(false);
        }
    };

    /**
     * Mover una etapa a otro grupo.
     *
     * Es lo que separa las dimensiones que hoy están revueltas: en Star NET,
     * mandar los quince municipios a un grupo «Zona» y los estados del ticket a
     * otro «Estado» convierte 43 columnas ilegibles en seis columnas con un
     * filtro de zona encima.
     */
    const cambiarGrupo = async (id, nuevoGrupo) => {
        try {
            const updated = await apiRequest('PUT', `/api/kanban/columns/${id}`, { grupo: nuevoGrupo });
            setColumns(prev => prev.map(c => c.id === id ? { ...c, grupo: updated.grupo } : c));
            setFiltros([]);
        } catch (err) {
            console.error('Error al cambiar el grupo:', err);
        }
    };

    /**
     * Marcar qué columna recoge lo que no está clasificado.
     *
     * En «Estado» quieres que sea «Nuevo». En «Zona» no quieres ninguna: una
     * conversación sin municipio no es de Cereté por ser Cereté la primera
     * columna, y ahí la suma de las columnas debe ser menor que el total.
     */
    const cambiarBandeja = async (id, valor) => {
        const col = columns.find(c => c.id === id);
        try {
            await apiRequest('PUT', `/api/kanban/columns/${id}`, { es_bandeja: valor });
            // El servidor desmarca la anterior del mismo grupo; aquí lo mismo.
            setColumns(prev => prev.map(c => {
                if (c.id === id) return { ...c, es_bandeja: valor };
                if (valor && (c.grupo ?? null) === (col?.grupo ?? null)) return { ...c, es_bandeja: false };
                return c;
            }));
            loadCounts();
            columnasVisibles.forEach(c => loadColumnCards(c.id, 1, debouncedSearch, true, filtros));
        } catch (err) {
            console.error('Error al cambiar la bandeja:', err);
        }
    };

    const renameColumn = async (id, newName) => {
        try {
            const updated = await apiRequest('PUT', `/api/kanban/columns/${id}`, { name: newName });
            setColumns(prev => prev.map(c => c.id === id ? { ...c, name: updated.name } : c));
        } catch (err) {
            console.error('Error al renombrar columna:', err);
        }
    };

    const deleteColumn = async (id) => {
        if (!confirm('¿Eliminar esta etapa? Las tarjetas pasarán a la primera etapa disponible.')) return;
        try {
            await apiRequest('DELETE', `/api/kanban/columns/${id}`);
            window.location.reload();
        } catch (err) {
            console.error('Error al eliminar columna:', err);
        }
    };

    const handleCardCreated = (card) => {
        setNewCardColumn(null);
        const colId = card.kanban_column_id ?? columns[0]?.id;
        if (!colId) return;
        setBoardData(prev => ({ ...prev, [colId]: [card, ...(prev[colId] ?? [])] }));
        setColCounts(prev => ({ ...prev, [colId]: (prev[colId] ?? 0) + 1 }));
    };

    // ── Drag and drop ──────────────────────────────────────────────────────

    const handleDragEnd = (result) => {
        const { source, destination, draggableId } = result;

        if (!destination) return;

        if (
            source.droppableId === destination.droppableId &&
            source.index === destination.index
        ) {
            return;
        }

        const activeIdStr = draggableId;
        const originColId = source.droppableId;
        const targetColId = destination.droppableId;
        
        const snapshot = boardDataRef.current;
        const card = snapshot[originColId].find(c => String(c.id) === activeIdStr);

        if (!card) return;

        if (originColId !== targetColId) {
            // ── Cross-column move (Optimistic) ──────────────────────────────
            setBoardData(prev => {
                const newOrigin = [...(prev[originColId] ?? [])];
                newOrigin.splice(source.index, 1);
                
                const newTarget = [...(prev[targetColId] ?? [])];
                newTarget.splice(destination.index, 0, { ...card, kanban_column_id: Number(targetColId) });
                
                return { ...prev, [originColId]: newOrigin, [targetColId]: newTarget };
            });

            setColCounts(prev => ({
                ...prev,
                [originColId]: Math.max(0, (prev[originColId] ?? 0) - 1),
                [targetColId]: (prev[targetColId] ?? 0) + 1,
            }));

            apiRequest('POST', `/api/kanban/conversations/${activeIdStr}/move`, { column_id: targetColId })
                .catch(err => {
                    console.error('Move failed:', err);
                    loadColumnCards(originColId, 1, debouncedSearch, true);
                    loadColumnCards(targetColId, 1, debouncedSearch, true);
                    loadCounts();
                });
        } else {
            // ── Same-column reorder ────────────────────────────────────────
            setBoardData(prev => {
                const items = [...(prev[originColId] ?? [])];
                const [reorderedItem] = items.splice(source.index, 1);
                items.splice(destination.index, 0, reorderedItem);
                return { ...prev, [originColId]: items };
            });
        }
    };

    /**
     * Sólo se muestra lo que se puede calcular.
     *
     * Aquí había dos cifras inventadas: un «Pipeline Total» que era el número
     * de conversaciones multiplicado por 150.000 pesos, y una «Conversión» fija
     * del 94% escrita a mano, idéntica para todas las empresas. Parecían datos
     * de negocio y no lo eran, en una pantalla que se enseña a clientes.
     *
     * Cuando las tarjetas tengan importe y fecha de entrada en cada etapa, el
     * valor del embudo y la conversión real podrán calcularse de verdad. Hasta
     * entonces, esto: cuántas conversaciones hay, cuántas están puestas en el
     * tablero y cuántas llevan una semana sin moverse.
     */
    const stats = useMemo(() => [
        { label: 'Conversaciones', value: total_conversations.toLocaleString('es-CO'), icon: User,            color: 'text-info',              bg: 'bg-info/5',
          hint: 'Todas las de la empresa' },
        { label: 'En el tablero',  value: en_tablero.toLocaleString('es-CO'),          icon: LayoutDashboard, color: 'text-accent-foreground', bg: 'bg-primary/5',
          hint: 'Colocadas en alguna etapa' },
        { label: 'Sin mover +7d',  value: estancadas.toLocaleString('es-CO'),          icon: Clock,           color: estancadas > 0 ? 'text-warning' : 'text-muted-foreground', bg: 'bg-warning/5',
          hint: 'Una semana sin actividad' },
        { label: 'Etapas',         value: columns.length,                       icon: Layers,          color: 'text-success',           bg: 'bg-success/5',
          hint: 'Columnas del tablero' },
    ], [total_conversations, en_tablero, estancadas, columns.length]);

    // ── Render ─────────────────────────────────────────────────────────────

    return (
        <>
            <Head title="Tablero" />

            {newCardColumn !== null && (
                <NewCardModal
                    instances={initialInstances ?? []}
                    defaultColumnId={newCardColumn}
                    onClose={() => setNewCardColumn(null)}
                    onCreated={handleCardCreated}
                />
            )}

            {/*
                La altura se fija aquí, como en el chat (`h-[calc(100svh-49px)]`, la
                barra superior; y desde `md` los 8px de margen del inset).

                Sin eso, las columnas crecían con sus tarjetas, el `main` del
                layout crecía con ellas —nada de la cadena acota la altura— y la
                página entera medía 4.835px: el `overflow-y-auto` de cada columna
                no llegaba a activarse nunca. Se desplazaba el documento, así
                que las cabeceras de las etapas y las métricas se iban de la
                vista, y no se podía comparar dos columnas porque cada una tenía
                sus tarjetas a distinta altura.
            */}
            <div className="relative h-[calc(100svh-49px)] md:h-[calc(100svh-65px)] flex flex-col min-h-0 bg-tablero overflow-hidden">
                {/* Header */}
                <div className="px-6 lg:px-10 pt-8 pb-4 relative z-10">
                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-8">
                        <div>
                            <h1 className="text-3xl font-black text-foreground dark:text-white tracking-tighter mb-1">Tablero</h1>
                            <p className="text-muted-foreground text-[11px] font-bold flex items-center gap-1.5">
                                {columnasVisibles.length === 0
                                    ? 'Sin etapas todavía'
                                    : <>
                                        {columnasVisibles.length} {columnasVisibles.length === 1 ? 'etapa' : 'etapas'}
                                        {grupoActivo && <><ArrowRight className="size-2.5" /> {grupoActivo}</>}
                                        {filtros.length > 0 && <span className="text-accent-foreground">· {filtros.length} {filtros.length === 1 ? 'filtro' : 'filtros'}</span>}
                                      </>}
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <div className="relative group">
                                <div className="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                                    <Search className="size-3.5 text-muted-foreground" />
                                </div>
                                <input
                                    type="text"
                                    placeholder="Buscar..."
                                    value={searchQuery}
                                    onChange={e => setSearchQuery(e.target.value)}
                                    className="pl-10 pr-4 py-2.5 bg-white dark:bg-muted border border-border rounded-2xl text-xs focus:outline-none focus:ring-4 focus:ring-primary/5 focus:border-primary/40 transition-all w-[240px] lg:w-[300px] shadow-sm placeholder:text-muted-foreground"
                                />
                            </div>
                            <button onClick={addColumn} className="flex items-center gap-2 px-5 py-2.5 bg-foreground dark:bg-foreground text-background dark:text-background rounded-2xl text-xs font-black shadow-xl hover:scale-[1.02] active:scale-95 transition-all">
                                <LayoutDashboard className="size-4" /> Nueva Etapa
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
                        {stats.map((stat, i) => (
                            <div key={i} className="bg-white dark:bg-muted p-4 lg:p-5 rounded-3xl border border-border shadow-sm">
                                <div className="flex items-center gap-4">
                                    <div className={clsx('p-3 rounded-2xl shadow-inner', stat.bg, stat.color)}>
                                        <stat.icon className="size-5" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-[9px] font-black text-muted-foreground uppercase tracking-widest mb-0.5">{stat.label}</p>
                                        <p className="text-lg font-black text-foreground dark:text-white tracking-tighter leading-none">{stat.value}</p>
                                        <p className="text-[10px] text-muted-foreground mt-1 truncate">{stat.hint}</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {(grupos.length > 1 || otrosGrupos.length > 0) && (
                    <div className="px-6 lg:px-10 pt-6 flex flex-wrap items-center gap-x-6 gap-y-3 relative z-10">
                        <div className="flex items-center gap-2">
                            <span className="text-[9px] font-black text-muted-foreground uppercase tracking-widest">Ver por</span>
                            <div className="flex items-center gap-1 p-1 bg-muted/60 rounded-2xl">
                                {grupos.map(g => (
                                    <button
                                        key={g ?? '__sin__'}
                                        onClick={() => { setGrupoActivo(g); setFiltros([]); }}
                                        className={clsx(
                                            'px-3 py-1.5 rounded-xl text-[11px] font-black transition-all',
                                            g === grupoActivo
                                                ? 'bg-white dark:bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        )}
                                    >
                                        {g ?? 'Sin agrupar'}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {otrosGrupos.map(({ grupo, columnas }) => (
                            <div key={grupo ?? '__sin__'} className="flex items-center gap-2">
                                <span className="text-[9px] font-black text-muted-foreground uppercase tracking-widest">
                                    {grupo ?? 'Sin agrupar'}
                                </span>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {columnas.map(c => {
                                        const activo = filtros.includes(c.id);
                                        return (
                                            <button
                                                key={c.id}
                                                onClick={() => setFiltros(prev => activo ? prev.filter(id => id !== c.id) : [...prev, c.id])}
                                                className={clsx(
                                                    'px-2.5 py-1 rounded-full text-[10px] font-bold border transition-all',
                                                    activo
                                                        ? 'bg-primary/15 border-primary/40 text-accent-foreground'
                                                        : 'bg-transparent border-border text-muted-foreground hover:border-primary/30 hover:text-foreground'
                                                )}
                                            >
                                                {c.name}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}

                        {filtros.length > 0 && (
                            <button
                                onClick={() => setFiltros([])}
                                className="text-[10px] font-black text-muted-foreground hover:text-foreground underline underline-offset-4"
                            >
                                Quitar filtros
                            </button>
                        )}
                    </div>
                )}

                {/* Board */}
                {puedeIzquierda && (
                    <button
                        onClick={() => desplazar(-1)}
                        aria-label="Ver las etapas anteriores"
                        className="absolute left-2 top-1/2 z-30 size-10 rounded-full bg-white/90 dark:bg-muted/90 border border-border shadow-lg backdrop-blur flex items-center justify-center text-muted-foreground hover:text-foreground hover:scale-105 active:scale-95 transition-all"
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                )}
                {puedeDerecha && (
                    <button
                        onClick={() => desplazar(1)}
                        aria-label="Ver las etapas siguientes"
                        className="absolute right-2 top-1/2 z-30 size-10 rounded-full bg-white/90 dark:bg-muted/90 border border-border shadow-lg backdrop-blur flex items-center justify-center text-muted-foreground hover:text-foreground hover:scale-105 active:scale-95 transition-all"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                )}

                <div ref={tableroRef} className="flex-1 overflow-x-auto px-6 lg:px-10 pt-4 pb-8 flex gap-6 lg:gap-8 custom-scrollbar relative z-10">
                    <DragDropContext onDragEnd={handleDragEnd}>
                        {columnasVisibles.map((col, indice) => (
                            <BoardColumn
                                key={col.id}
                                col={col}
                                indice={indice}
                                items={boardData[col.id] ?? []}
                                totalCount={colCounts[col.id]}
                                loading={colMeta[col.id]?.loading ?? false}
                                hasMore={colMeta[col.id]?.hasMore ?? false}
                                error={colMeta[col.id]?.error ?? null}
                                onLoadMore={handleLoadMore}
                                onRename={renameColumn}
                                onCambiarGrupo={cambiarGrupo}
                                onCambiarBandeja={cambiarBandeja}
                                onDelete={deleteColumn}
                                onAddCard={setNewCardColumn}
                            />
                        ))}

                        {borradorEtapa !== null ? (
                            <ColumnaBorrador
                                valor={borradorEtapa}
                                onCambio={setBorradorEtapa}
                                onCrear={crearEtapa}
                                onCancelar={() => { setBorradorEtapa(null); setErrorEtapa(null); }}
                                creando={creandoEtapa}
                                error={errorEtapa}
                            />
                        ) : (
                            <div className="flex-shrink-0 w-[100px] flex flex-col items-center justify-start pt-12">
                                <button onClick={addColumn} className="size-12 rounded-full border-2 border-dashed border-border flex items-center justify-center text-muted-foreground hover:text-accent-foreground hover:border-primary/30 hover:bg-primary/5 transition-all group">
                                    <Plus className="size-6 group-hover:rotate-90 transition-transform duration-300" />
                                </button>
                                <p className="text-[10px] font-black text-muted-foreground mt-4 uppercase tracking-widest">Añadir</p>
                            </div>
                        )}

                        <div className="flex-shrink-0 w-2 lg:w-4" />
                    </DragDropContext>
                </div>
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
                .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.1); border-radius: 20px; }
                .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.2); }
                
                body.cursor-grabbing-active, 
                body.cursor-grabbing-active * { 
                    cursor: grabbing !important; 
                }
            `}} />
        </>
    );
}

Kanban.layout = page => <AppLayout breadcrumb={['Tablero']}>{page}</AppLayout>;
