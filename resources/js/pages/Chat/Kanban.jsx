import { useState, useMemo, useEffect, useRef, useCallback, memo } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import {
    Search,
    MessageSquare,
    Plus,
    User,
    Clock,
    DollarSign,
    CheckCircle2,
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

const KanbanCard = memo(({ conv, isOverlay, isDragging, ...props }) => (
    <div
        {...props}
        className={clsx(
            'group relative bg-white dark:bg-slate-900 p-4 rounded-[1.5rem] border select-none',
            isOverlay
                ? 'border-teal-500 shadow-2xl z-50 cursor-grabbing ring-2 ring-teal-500/10 scale-[1.02] rotate-1 transition-transform duration-200'
                : isDragging
                    ? 'opacity-0'
                    : 'border-slate-200/50 dark:border-slate-800/50 shadow-sm cursor-grab active:cursor-grabbing hover:shadow-md transition-all duration-200'
        )}
    >
        {/* Placeholder dashed border when dragging (visible only if we don't use opacity-0 above) */}
        <div className="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity">
            <GripVertical className="size-4 text-slate-300 dark:text-slate-600 group-hover:text-teal-500 transition-colors" />
        </div>

        <div className="flex items-start mb-4">
            <div className="flex items-center gap-3.5">
                <div className="relative">
                    <div className="size-11 rounded-2xl bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 text-slate-700 dark:text-slate-200 flex items-center justify-center font-black text-[13px] uppercase border border-slate-200/50 dark:border-slate-700/50 shadow-inner">
                        {conv.initials}
                    </div>
                    {conv.unread_count > 0 && (
                        <div className="absolute -top-1.5 -right-1.5 size-5 bg-teal-500 text-white text-[10px] font-black rounded-full border-2 border-white dark:border-slate-900 flex items-center justify-center shadow-lg shadow-teal-500/30">
                            {conv.unread_count}
                        </div>
                    )}
                </div>
                <div className="min-w-0 pr-6">
                    <h3 className="text-[14px] font-black text-slate-900 dark:text-slate-50 truncate tracking-tight leading-none mb-1">
                        {conv.name || conv.phone_number}
                    </h3>
                    <div className="flex items-center gap-1.5 text-[10px] text-slate-400 font-bold uppercase tracking-wider opacity-80">
                        <Clock className="size-3" />
                        {conv.last_message_at
                            ? new Date(conv.last_message_at).toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })
                            : '—'}
                    </div>
                </div>
            </div>
        </div>

        <div className="relative mb-5">
            <p className="text-[12px] text-slate-500 dark:text-slate-400 line-clamp-2 leading-relaxed font-medium">
                {conv.last_message || 'No hay mensajes previos...'}
            </p>
        </div>

        <div className="pt-4 border-t border-slate-100 dark:border-slate-800/60 flex items-center justify-between">
            <div className="flex items-center gap-3">
                <div className="flex -space-x-2.5">
                    <div className="size-7 rounded-full border-2 border-white dark:border-slate-900 bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[9px] font-black text-slate-400 shadow-sm">
                        <Users className="size-3.5" />
                    </div>
                    {conv.assigned_agent && (
                        <div
                            className="size-7 rounded-full border-2 border-white dark:border-slate-900 bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-[9px] font-black text-white shadow-md"
                            title={conv.assigned_agent.name}
                        >
                            {conv.assigned_agent.name.substring(0, 2).toUpperCase()}
                        </div>
                    )}
                </div>
                <div className="flex flex-col">
                    <span className="text-[9px] font-black text-slate-400 uppercase tracking-widest leading-none mb-0.5">
                        {conv.assigned_agent ? 'Asignado a' : 'Sin Agente'}
                    </span>
                    {conv.assigned_agent && (
                        <span className="text-[10px] font-bold text-slate-600 dark:text-slate-300 leading-none">
                            {conv.assigned_agent.name}
                        </span>
                    )}
                </div>
            </div>
            <div className="bg-emerald-500/10 dark:bg-emerald-500/20 px-2.5 py-1 rounded-full text-[9px] font-black text-emerald-600 dark:text-emerald-400 uppercase tracking-tighter flex items-center gap-1 border border-emerald-500/10">
                <Zap className="size-2.5 fill-current" />
                Lead
            </div>
        </div>
    </div>
));

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

// ─── BoardColumn ─────────────────────────────────────────────────────────────

const BoardColumn = memo(({ col, items, totalCount, loading, hasMore, error, onLoadMore, onRename, onDelete, onAddCard }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [title, setTitle]         = useState(col.name);
    const Icon = getIcon(col.icon);

    const handleRenameSubmit = (e) => {
        e?.preventDefault();
        if (title.trim() && title.trim() !== col.name) onRename(col.id, title.trim());
        setIsEditing(false);
    };

    return (
        <div className="flex-1 min-w-[300px] max-w-[400px] flex flex-col h-full group/column">
            {/* Header */}
            <div className="flex items-center justify-between mb-6 px-3">
                <div className="flex items-center gap-4">
                    <div className={clsx('p-2.5 rounded-2xl shadow-lg text-white', col.color)}>
                        <Icon className="size-4" />
                    </div>
                    <div className="flex flex-col">
                        {isEditing ? (
                            <form onSubmit={handleRenameSubmit}>
                                <input
                                    autoFocus
                                    value={title}
                                    onChange={e => setTitle(e.target.value)}
                                    onBlur={handleRenameSubmit}
                                    className="bg-transparent border-none p-0 font-black text-[12px] text-slate-800 dark:text-slate-100 uppercase tracking-[0.1em] focus:ring-0 w-32"
                                />
                            </form>
                        ) : (
                            <h2
                                onClick={() => setIsEditing(true)}
                                className="font-black text-[12px] text-slate-800 dark:text-slate-100 uppercase tracking-[0.1em] mb-0.5 cursor-text"
                            >
                                {col.name}
                            </h2>
                        )}
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-bold text-slate-400/70">{col.subtitle || 'Procesos'}</span>
                            <span className="size-1 rounded-full bg-slate-300 dark:bg-slate-700" />
                            <span className="text-[10px] font-black text-teal-600 dark:text-teal-400">{totalCount ?? items.length}</span>
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-1 opacity-0 group-hover/column:opacity-100 transition-all">
                    <button onClick={() => onDelete(col.id)} className="p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 text-slate-400 hover:text-red-500 rounded-lg transition-colors" title="Eliminar etapa">
                        <AlertCircle className="size-3.5" />
                    </button>
                    <button onClick={() => onAddCard(col.id)} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 rounded-lg transition-colors" title="Agregar tarjeta">
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
                            "flex-1 overflow-y-auto space-y-4 custom-scrollbar px-2 pb-24 min-h-[250px] transition-all duration-300 rounded-3xl",
                            snapshot.isDraggingOver ? 'bg-teal-500/[0.03] ring-2 ring-teal-500/10' : ''
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
                                    <div key={n} className="bg-white dark:bg-slate-900/60 rounded-[1.5rem] border border-slate-200/50 dark:border-slate-800/50 p-4 animate-pulse">
                                        <div className="flex items-center gap-3 mb-4">
                                            <div className="size-11 rounded-2xl bg-slate-100 dark:bg-slate-800" />
                                            <div className="flex-1 space-y-2">
                                                <div className="h-3 bg-slate-100 dark:bg-slate-800 rounded-full w-3/4" />
                                                <div className="h-2 bg-slate-100 dark:bg-slate-800 rounded-full w-1/2" />
                                            </div>
                                        </div>
                                        <div className="space-y-1.5">
                                            <div className="h-2 bg-slate-100 dark:bg-slate-800 rounded-full" />
                                            <div className="h-2 bg-slate-100 dark:bg-slate-800 rounded-full w-5/6" />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Error state */}
                        {error && (
                            <div className="border-2 border-dashed border-red-200 dark:border-red-900/40 rounded-[2rem] py-10 px-4 flex flex-col items-center justify-center gap-2 text-center">
                                <AlertCircle className="size-6 text-red-400" />
                                <p className="text-[11px] font-bold text-red-400">Error al cargar tarjetas</p>
                                <p className="text-[10px] text-slate-400">{error}</p>
                                <button
                                    onClick={() => onLoadMore(col.id)}
                                    className="mt-1 text-[10px] font-black text-teal-600 uppercase tracking-widest hover:underline"
                                >
                                    Reintentar
                                </button>
                            </div>
                        )}

                        {/* Empty state */}
                        {!loading && !error && items.length === 0 && (
                            <div className="border-2 border-dashed border-slate-200 dark:border-slate-800/60 rounded-[2.5rem] py-16 flex flex-col items-center justify-center transition-all hover:bg-slate-50 dark:hover:bg-slate-900/30 hover:border-teal-500/20">
                                <div className="p-4 bg-white dark:bg-slate-800 shadow-lg rounded-full mb-3">
                                    <LayoutDashboard className="size-6 text-slate-200 dark:text-slate-700" />
                                </div>
                                <p className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-300 dark:text-slate-700">Arrastra aquí</p>
                            </div>
                        )}

                        {/* Load more */}
                        {hasMore && !loading && !error && items.length > 0 && (
                            <button
                                onClick={() => onLoadMore(col.id)}
                                className="w-full py-3 flex items-center justify-center gap-2 text-[11px] font-black text-slate-400 hover:text-teal-600 uppercase tracking-widest border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl hover:border-teal-500/30 transition-all"
                            >
                                <ChevronDown className="size-3.5" /> Cargar más
                            </button>
                        )}

                        {/* Loading more spinner */}
                        {loading && items.length > 0 && (
                            <div className="flex justify-center py-4">
                                <Loader2 className="size-5 text-teal-500 animate-spin" />
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
            <div className="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-md mx-4 p-6">
                <div className="flex items-center justify-between mb-6">
                    <h2 className="font-black text-slate-900 dark:text-white text-lg">Nueva Tarjeta</h2>
                    <button onClick={onClose} className="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition-colors">
                        <X className="size-4 text-slate-500" />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-1">Nombre (opcional)</label>
                        <input
                            type="text"
                            value={form.name}
                            onChange={e => setForm(p => ({ ...p, name: e.target.value }))}
                            placeholder="Ej: Juan García"
                            className="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500/40 transition-all"
                        />
                    </div>
                    <div>
                        <label className="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-1">Número de teléfono *</label>
                        <input
                            type="text"
                            required
                            value={form.phone_number}
                            onChange={e => setForm(p => ({ ...p, phone_number: e.target.value }))}
                            placeholder="Ej: 573001234567"
                            className="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500/40 transition-all"
                        />
                    </div>
                    {instances.length > 1 && (
                        <div>
                            <label className="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-1">Instancia WhatsApp *</label>
                            <select
                                value={form.instance_id}
                                onChange={e => setForm(p => ({ ...p, instance_id: e.target.value }))}
                                className="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500/40 transition-all"
                            >
                                {instances.map(inst => <option key={inst.id} value={inst.id}>{inst.name}</option>)}
                            </select>
                        </div>
                    )}
                    {error && <p className="text-[12px] text-red-500 font-medium">{error}</p>}
                    <div className="flex gap-3 pt-2">
                        <button type="button" onClick={onClose} className="flex-1 py-2.5 border border-slate-200 dark:border-slate-700 rounded-2xl text-sm font-bold text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            Cancelar
                        </button>
                        <button type="submit" disabled={saving} className="flex-1 py-2.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl text-sm font-black shadow-lg hover:scale-[1.02] active:scale-95 transition-all disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center gap-2">
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

export default function Kanban({ columns: initialColumns, total_conversations, instances: initialInstances }) {
    const [searchQuery, setSearchQuery]     = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [columns, setColumns]             = useState(initialColumns ?? []);
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

    // We keep a ref to the latest boardData so handleDragEnd can read the
    // current state synchronously without relying on stale closures.
    const boardDataRef = useRef(boardData);
    useEffect(() => { boardDataRef.current = boardData; }, [boardData]);

    // AbortController per column so we can cancel stale requests.
    const abortControllersRef = useRef({});

    // ── Data fetching ──────────────────────────────────────────────────────

    const loadColumnCards = useCallback(async (colId, page, search, reset = false) => {
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
        try {
            const data = await apiRequest('GET', '/api/kanban/counts');
            setColCounts(data);
        } catch (err) {
            console.error('Error cargando conteos:', err);
        }
    }, []);

    // Initial load
    useEffect(() => {
        loadCounts();
        columns.forEach(col => loadColumnCards(col.id, 1, '', true));
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

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
        const firstColumnId = columns[0]?.id;

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
            columns.forEach(col => loadColumnCards(col.id, 1, debouncedSearch, true));
        }
    }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

    const handleLoadMore = (colId) => {
        const meta = colMeta[colId];
        if (!meta || meta.loading) return;
        if (!meta.error && !meta.hasMore) return;
        loadColumnCards(colId, meta.page + 1, debouncedSearch, false);
    };

    // ── Column CRUD ────────────────────────────────────────────────────────

    const addColumn = async () => {
        try {
            const col = await apiRequest('POST', '/api/kanban/columns', { name: 'Nueva Etapa', color: 'bg-slate-500', icon: 'Zap', subtitle: 'Personalizado' });
            setColumns(prev => [...prev, col]);
            setBoardData(prev => ({ ...prev, [col.id]: [] }));
            setColMeta(prev => ({ ...prev, [col.id]: { page: 1, hasMore: false, loading: false } }));
        } catch (err) {
            console.error('Error al crear columna:', err);
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

    // Stats memoized to avoid re-renders
    const stats = useMemo(() => [
        { label: 'Proyectos',      value: total_conversations,                              icon: User,          color: 'text-blue-500',   bg: 'bg-blue-500/5' },
        { label: 'Etapas',         value: columns.length,                                   icon: LayoutDashboard, color: 'text-emerald-500', bg: 'bg-emerald-500/5' },
        { label: 'Pipeline Total', value: `$ ${(total_conversations * 150000).toLocaleString()}`, icon: DollarSign, color: 'text-amber-500', bg: 'bg-amber-500/5' },
        { label: 'Conversión',     value: '94%',                                            icon: CheckCircle2,  color: 'text-purple-500', bg: 'bg-purple-500/5' },
    ], [total_conversations, columns.length]);

    // ── Render ─────────────────────────────────────────────────────────────

    return (
        <>
            <Head title="CRM Pipeline | Business WhatsApp" />

            {newCardColumn !== null && (
                <NewCardModal
                    instances={initialInstances ?? []}
                    defaultColumnId={newCardColumn}
                    onClose={() => setNewCardColumn(null)}
                    onCreated={handleCardCreated}
                />
            )}

            <div className="flex-1 flex flex-col min-h-0 bg-[#fdfdfe] dark:bg-[#080c14] overflow-hidden">
                {/* Header */}
                <div className="px-6 lg:px-10 pt-8 pb-4 relative z-10">
                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-8">
                        <div>
                            <div className="flex items-center gap-4 mb-1">
                                <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tighter">CRM Comercial</h1>
                                <div className="flex items-center gap-1.5 bg-emerald-500/10 text-emerald-600 px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-[0.15em] border border-emerald-500/10">
                                    <span className="size-1 rounded-full bg-emerald-500" />
                                    Board Dinámico
                                </div>
                            </div>
                            <p className="text-slate-400 dark:text-slate-500 text-[10px] font-bold uppercase tracking-widest flex items-center gap-2 opacity-80">
                                Personalización Total <ArrowRight className="size-2.5" /> WhatsApp API
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <div className="relative group">
                                <div className="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                                    <Search className="size-3.5 text-slate-300" />
                                </div>
                                <input
                                    type="text"
                                    placeholder="Buscar..."
                                    value={searchQuery}
                                    onChange={e => setSearchQuery(e.target.value)}
                                    className="pl-10 pr-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs focus:outline-none focus:ring-4 focus:ring-teal-500/5 focus:border-teal-500/40 transition-all w-[240px] lg:w-[300px] shadow-sm placeholder:text-slate-300"
                                />
                            </div>
                            <button onClick={addColumn} className="flex items-center gap-2 px-5 py-2.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl text-xs font-black shadow-xl hover:scale-[1.02] active:scale-95 transition-all">
                                <LayoutDashboard className="size-4" /> Nueva Etapa
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
                        {stats.map((stat, i) => (
                            <div key={i} className="bg-white dark:bg-slate-900 p-4 lg:p-5 rounded-3xl border border-slate-50 dark:border-slate-800 shadow-sm">
                                <div className="flex items-center gap-4">
                                    <div className={clsx('p-3 rounded-2xl shadow-inner', stat.bg, stat.color)}>
                                        <stat.icon className="size-5" />
                                    </div>
                                    <div>
                                        <p className="text-[9px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-0.5">{stat.label}</p>
                                        <p className="text-lg font-black text-slate-900 dark:text-white tracking-tighter leading-none">{stat.value}</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Board */}
                <div className="flex-1 overflow-x-auto px-6 lg:px-10 pt-4 pb-8 flex gap-6 lg:gap-8 custom-scrollbar relative z-10">
                    <DragDropContext onDragEnd={handleDragEnd}>
                        {columns.map(col => (
                            <BoardColumn
                                key={col.id}
                                col={col}
                                items={boardData[col.id] ?? []}
                                totalCount={colCounts[col.id]}
                                loading={colMeta[col.id]?.loading ?? false}
                                hasMore={colMeta[col.id]?.hasMore ?? false}
                                error={colMeta[col.id]?.error ?? null}
                                onLoadMore={handleLoadMore}
                                onRename={renameColumn}
                                onDelete={deleteColumn}
                                onAddCard={setNewCardColumn}
                            />
                        ))}

                        <div className="flex-shrink-0 w-[100px] flex flex-col items-center justify-start pt-12">
                            <button onClick={addColumn} className="size-12 rounded-full border-2 border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center text-slate-300 hover:text-teal-500 hover:border-teal-500 hover:bg-teal-500/5 transition-all group">
                                <Plus className="size-6 group-hover:rotate-90 transition-transform duration-300" />
                            </button>
                            <p className="text-[10px] font-black text-slate-300 mt-4 uppercase tracking-widest">Añadir</p>
                        </div>

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

Kanban.layout = page => <AppLayout breadcrumb={['CRM Commercial Board']}>{page}</AppLayout>;
