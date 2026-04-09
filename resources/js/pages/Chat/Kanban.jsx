import { useState, useMemo, useEffect, useRef, useCallback } from 'react';
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
    DndContext,
    DragOverlay,
    closestCorners,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    defaultDropAnimationSideEffects,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
    useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
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

const KanbanCard = ({ conv, isOverlay, ...props }) => (
    <div
        {...props}
        className={clsx(
            'group relative bg-white dark:bg-slate-900/60 backdrop-blur-xl p-4 rounded-[1.5rem] border transition-all duration-300 select-none',
            isOverlay
                ? 'border-teal-500/50 shadow-2xl scale-[1.02] z-50 cursor-grabbing ring-4 ring-teal-500/5'
                : 'border-slate-200/50 dark:border-slate-800/50 shadow-sm hover:shadow-xl hover:shadow-slate-200/50 dark:hover:shadow-black/20 hover:border-teal-500/30 cursor-grab active:cursor-grabbing'
        )}
    >
        <div className="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity">
            <GripVertical className="size-4 text-slate-300 dark:text-slate-600" />
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
);

const SortableKanbanCard = ({ conv }) => {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: conv.id });
    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.3 : 1 }}
            {...attributes}
            {...listeners}
        >
            <KanbanCard conv={conv} />
        </div>
    );
};

// ─── BoardColumn ─────────────────────────────────────────────────────────────

const BoardColumn = ({ col, items, loading, hasMore, error, onLoadMore, onRename, onDelete, onAddCard }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [title, setTitle]         = useState(col.name);
    const Icon = getIcon(col.icon);

    const { setNodeRef } = useSortable({ id: col.id, data: { type: 'Column', col } });

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
                    <div className={clsx('p-2.5 rounded-2xl shadow-lg text-white transition-transform group-hover/column:scale-105 duration-500', col.color)}>
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
                            <span className="text-[10px] font-black text-teal-600 dark:text-teal-400">{items.length}</span>
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
            <div ref={setNodeRef} className="flex-1 overflow-y-auto space-y-4 custom-scrollbar px-2 pb-20 min-h-[200px]">
                <SortableContext items={items.map(i => i.id)} strategy={verticalListSortingStrategy}>
                    {items.map(conv => <SortableKanbanCard key={conv.id} conv={conv} />)}
                </SortableContext>

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
                {hasMore && !loading && items.length > 0 && (
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
        </div>
    );
};

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

export default function Kanban({ columns: initialColumns, total_conversations, instances }) {
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
    const dragOriginContainer               = useRef(null);

    // ── Data fetching ──────────────────────────────────────────────────────

    const loadColumnCards = useCallback(async (colId, page, search, reset = false) => {
        setColMeta(prev => ({ ...prev, [colId]: { ...prev[colId], loading: true } }));
        try {
            const params = new URLSearchParams({ page, per_page: PER_PAGE });
            if (search) params.set('search', search);
            const data = await apiRequest('GET', `/api/kanban/columns/${colId}/cards?${params}`);
            setBoardData(prev => ({
                ...prev,
                [colId]: reset ? data.data : [...(prev[colId] ?? []), ...data.data],
            }));
            setColMeta(prev => ({
                ...prev,
                [colId]: { page: data.current_page, hasMore: data.current_page < data.last_page, loading: false, error: null },
            }));
        } catch (err) {
            console.error(`Error cargando columna ${colId}:`, err);
            setColMeta(prev => ({ ...prev, [colId]: { ...prev[colId], loading: false, error: err.message } }));
        }
    }, []);

    // Initial load — fire once per column on mount
    useEffect(() => {
        (initialColumns ?? []).forEach(col => loadColumnCards(col.id, 1, '', true));
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    // Debounce search
    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(searchQuery), 350);
        return () => clearTimeout(t);
    }, [searchQuery]);

    // Re-fetch all columns when search changes (skip on first render)
    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) { isFirstRender.current = false; return; }
        columns.forEach(col => loadColumnCards(col.id, 1, debouncedSearch, true));
    }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

    const handleLoadMore = (colId) => {
        const meta = colMeta[colId];
        if (!meta || meta.loading) return;
        if (meta.error) {
            // retry from page 1
            loadColumnCards(colId, 1, debouncedSearch, true);
            return;
        }
        if (!meta.hasMore) return;
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
            setColumns(prev => prev.filter(c => c.id !== id));
            setBoardData(prev => {
                const rest = { ...prev };
                const moved = rest[id] ?? [];
                delete rest[id];
                const firstId = Object.keys(rest)[0];
                if (firstId && moved.length) rest[firstId] = [...(rest[firstId] ?? []), ...moved];
                return rest;
            });
            setColMeta(prev => { const m = { ...prev }; delete m[id]; return m; });
        } catch (err) {
            console.error('Error al eliminar columna:', err);
        }
    };

    const handleCardCreated = (card) => {
        setNewCardColumn(null);
        const colId = card.kanban_column_id ?? columns[0]?.id;
        if (!colId) return;
        setBoardData(prev => {
            const cleaned = Object.fromEntries(Object.entries(prev).map(([k, v]) => [k, v.filter(c => c.id !== card.id)]));
            return { ...cleaned, [colId]: [card, ...(cleaned[colId] ?? [])] };
        });
    };

    // ── Drag and drop ──────────────────────────────────────────────────────

    const activeConv = useMemo(() => {
        if (!activeId) return null;
        for (const cards of Object.values(boardData)) {
            const found = cards.find(c => c.id === activeId);
            if (found) return found;
        }
        return null;
    }, [activeId, boardData]);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    function findContainer(id) {
        if (id in boardData) return id;
        return Object.keys(boardData).find(key => boardData[key].some(item => item.id === id));
    }

    const handleDragStart = (event) => {
        setActiveId(event.active.id);
        dragOriginContainer.current = findContainer(event.active.id);
    };

    const handleDragOver = ({ active, over }) => {
        if (!over) return;
        const activeContainer = findContainer(active.id);
        const overContainer   = findContainer(over.id);
        if (!activeContainer || !overContainer || activeContainer === overContainer) return;

        setBoardData(prev => {
            const activeItems = prev[activeContainer] ?? [];
            const overItems   = prev[overContainer]   ?? [];
            const activeIndex = activeItems.findIndex(i => i.id === active.id);
            const overIndex   = overItems.findIndex(i => i.id === over.id);
            const newIndex    = over.id in prev
                ? overItems.length + 1
                : overIndex >= 0 ? overIndex + (overIndex === overItems.length - 1 ? 1 : 0) : overItems.length + 1;
            return {
                ...prev,
                [activeContainer]: activeItems.filter(i => i.id !== active.id),
                [overContainer]: [...overItems.slice(0, newIndex), activeItems[activeIndex], ...overItems.slice(newIndex)],
            };
        });
    };

    const handleDragEnd = ({ active, over }) => {
        const originContainer  = dragOriginContainer.current;
        const currentContainer = over ? (findContainer(over.id) ?? findContainer(active.id)) : null;

        dragOriginContainer.current = null;
        setActiveId(null);

        if (!originContainer || !currentContainer) return;

        if (originContainer !== currentContainer) {
            apiRequest('POST', `/api/kanban/conversations/${active.id}/move`, { column_id: currentContainer })
                .catch(err => console.error('Error al mover tarjeta:', err));
        } else {
            const activeIndex = boardData[currentContainer]?.findIndex(i => i.id === active.id) ?? -1;
            const overIndex   = boardData[currentContainer]?.findIndex(i => i.id === over?.id)  ?? -1;
            if (activeIndex !== -1 && overIndex !== -1 && activeIndex !== overIndex) {
                setBoardData(prev => ({
                    ...prev,
                    [currentContainer]: arrayMove(prev[currentContainer], activeIndex, overIndex),
                }));
            }
        }
    };

    // ── Stats ──────────────────────────────────────────────────────────────

    const totalCards = Object.values(boardData).reduce((s, arr) => s + arr.length, 0);

    const stats = [
        { label: 'Proyectos',      value: total_conversations,                              icon: User,          color: 'text-blue-500',   bg: 'bg-blue-500/5' },
        { label: 'Etapas',         value: columns.length,                                   icon: LayoutDashboard, color: 'text-emerald-500', bg: 'bg-emerald-500/5' },
        { label: 'Pipeline Total', value: `$ ${(total_conversations * 150000).toLocaleString()}`, icon: DollarSign, color: 'text-amber-500', bg: 'bg-amber-500/5' },
        { label: 'Conversión',     value: '94%',                                            icon: CheckCircle2,  color: 'text-purple-500', bg: 'bg-purple-500/5' },
    ];

    // ── Render ─────────────────────────────────────────────────────────────

    return (
        <>
            <Head title="CRM Pipeline | Business WhatsApp" />

            {newCardColumn !== null && (
                <NewCardModal
                    instances={instances ?? []}
                    defaultColumnId={newCardColumn}
                    onClose={() => setNewCardColumn(null)}
                    onCreated={handleCardCreated}
                />
            )}

            <div className="flex-1 flex flex-col min-h-0 bg-[#fdfdfe] dark:bg-[#080c14] overflow-hidden">
                <div className="absolute top-0 right-0 w-[50%] h-[50%] bg-teal-500/5 blur-[120px] pointer-events-none rounded-full" />
                <div className="absolute bottom-0 left-0 w-[30%] h-[30%] bg-purple-500/5 blur-[100px] pointer-events-none rounded-full" />

                {/* Header */}
                <div className="px-6 lg:px-10 pt-8 pb-4 relative z-10">
                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-8">
                        <div>
                            <div className="flex items-center gap-4 mb-1">
                                <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tighter">CRM Comercial</h1>
                                <div className="flex items-center gap-1.5 bg-emerald-500/10 text-emerald-600 px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-[0.15em] border border-emerald-500/10">
                                    <span className="size-1 rounded-full bg-emerald-500 animate-pulse" />
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
                                    <Search className="size-3.5 text-slate-300 group-focus-within:text-teal-500 transition-colors" />
                                </div>
                                <input
                                    type="text"
                                    placeholder="Buscar..."
                                    value={searchQuery}
                                    onChange={e => setSearchQuery(e.target.value)}
                                    className="pl-10 pr-4 py-2.5 bg-white dark:bg-slate-900/50 backdrop-blur-md border border-slate-100 dark:border-slate-800 rounded-2xl text-xs focus:outline-none focus:ring-4 focus:ring-teal-500/5 focus:border-teal-500/40 transition-all w-[240px] lg:w-[300px] shadow-sm placeholder:text-slate-300"
                                />
                            </div>
                            <button onClick={addColumn} className="flex items-center gap-2 px-5 py-2.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl text-xs font-black shadow-xl hover:scale-[1.02] active:scale-95 transition-all">
                                <LayoutDashboard className="size-4" /> Nueva Etapa
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
                        {stats.map((stat, i) => (
                            <div key={i} className="bg-white dark:bg-slate-900/40 backdrop-blur-sm p-4 lg:p-5 rounded-3xl border border-slate-50 dark:border-slate-800/50 shadow-sm">
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
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCorners}
                        onDragStart={handleDragStart}
                        onDragOver={handleDragOver}
                        onDragEnd={handleDragEnd}
                    >
                        {columns.map(col => (
                            <BoardColumn
                                key={col.id}
                                col={col}
                                items={boardData[col.id] ?? []}
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

                        <DragOverlay dropAnimation={{ sideEffects: defaultDropAnimationSideEffects({ styles: { active: { opacity: '0.4' } } }) }}>
                            {activeConv ? <KanbanCard conv={activeConv} isOverlay /> : null}
                        </DragOverlay>
                    </DndContext>
                </div>
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
                .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.1); border-radius: 20px; }
                .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.2); }
            `}} />
        </>
    );
}

Kanban.layout = page => <AppLayout breadcrumb={['CRM Commercial Board']}>{page}</AppLayout>;
