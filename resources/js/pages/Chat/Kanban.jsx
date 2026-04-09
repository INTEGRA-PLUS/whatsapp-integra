import { useState, useMemo, useEffect, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { 
    Search, 
    MoreVertical, 
    MessageSquare, 
    Filter,
    Plus,
    User,
    Clock,
    DollarSign,
    TrendingUp,
    CheckCircle2,
    AlertCircle,
    GripVertical,
    Calendar,
    ArrowRight,
    Users,
    Zap,
    LayoutDashboard
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

const COLUMNS = [
    { id: 'nuevo', title: 'Nuevo Prospecto', color: 'bg-blue-500', icon: Plus, subtitle: 'Primer contacto' },
    { id: 'atencion', title: 'En Atención', color: 'bg-amber-500', icon: MessageSquare, subtitle: 'Chat activo' },
    { id: 'seguimiento', title: 'Seguimiento', color: 'bg-purple-500', icon: Calendar, subtitle: 'Nutrición' },
    { id: 'cerrado', title: 'Venta / Cerrado', color: 'bg-emerald-500', icon: CheckCircle2, subtitle: 'Éxito' }
];

// --- Sub-components ---

const KanbanCard = ({ conv, isOverlay, ...props }) => {
    return (
        <div 
            {...props}
            className={clsx(
                "group relative bg-white dark:bg-slate-900/60 backdrop-blur-xl p-4 rounded-[1.5rem] border transition-all duration-300 select-none",
                isOverlay 
                    ? "border-teal-500/50 shadow-2xl scale-[1.02] z-50 cursor-grabbing ring-4 ring-teal-500/5" 
                    : "border-slate-200/50 dark:border-slate-800/50 shadow-sm hover:shadow-xl hover:shadow-slate-200/50 dark:hover:shadow-black/20 hover:border-teal-500/30 cursor-grab active:cursor-grabbing"
            )}
        >
            {/* Grab Handle Indicator */}
            <div className="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity">
                <GripVertical className="size-4 text-slate-300 dark:text-slate-600" />
            </div>

            <div className="flex justify-between items-start mb-4">
                <div className="flex items-center gap-3.5">
                    <div className="relative">
                        <div className="size-11 rounded-2xl bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 text-slate-700 dark:text-slate-200 flex items-center justify-center font-black text-[13px] uppercase border border-slate-200/50 dark:border-slate-700/50 shadow-inner">
                            {conv.initials}
                        </div>
                        {conv.unread_count > 0 && (
                            <div className="absolute -top-1.5 -right-1.5 size-5 bg-teal-500 text-white text-[10px] font-black rounded-full border-2 border-white dark:border-slate-900 flex items-center justify-center shadow-lg shadow-teal-500/30 animate-in zoom-in duration-300">
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
                            {new Date(conv.last_message_at).toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })}
                        </div>
                    </div>
                </div>
            </div>

            <div className="relative mb-5">
                <p className="text-[12px] text-slate-500 dark:text-slate-400 line-clamp-2 leading-relaxed font-medium">
                    {conv.last_message || 'No hay mensajes previos...'}
                </p>
                <div className="absolute bottom-0 left-0 w-full h-2 bg-gradient-to-t from-white dark:from-slate-900/60 to-transparent opacity-50" />
            </div>

            <div className="pt-4 border-t border-slate-100 dark:border-slate-800/60 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="flex -space-x-2.5">
                        <div className="size-7 rounded-full border-2 border-white dark:border-slate-900 bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[9px] font-black text-slate-400 overflow-hidden shadow-sm">
                            <Users className="size-3.5" />
                        </div>
                        {conv.assigned_agent && (
                            <div className="size-7 rounded-full border-2 border-white dark:border-slate-900 bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-[9px] font-black text-white shadow-md shadow-teal-500/10" title={conv.assigned_agent.name}>
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

                <div className="flex items-center gap-1.5">
                    <div className="bg-emerald-500/10 dark:bg-emerald-500/20 px-2.5 py-1 rounded-full text-[9px] font-black text-emerald-600 dark:text-emerald-400 uppercase tracking-tighter flex items-center gap-1 border border-emerald-500/10">
                        <Zap className="size-2.5 fill-current" />
                        Lead
                    </div>
                </div>
            </div>
        </div>
    );
};

const SortableKanbanCard = ({ conv }) => {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: conv.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.3 : 1,
        zIndex: isDragging ? 100 : 'auto',
    };

    return (
        <div ref={setNodeRef} style={style} {...attributes} {...listeners}>
            <KanbanCard conv={conv} />
        </div>
    );
};

const BoardColumn = ({ col, items, searchQuery, onRename, onDelete }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [title, setTitle] = useState(col.title);

    const filteredItems = useMemo(() => {
        if (!searchQuery) return items;
        const q = searchQuery.toLowerCase();
        return items.filter(
            c => (c.name || '').toLowerCase().includes(q) || (c.phone_number || '').includes(q)
        );
    }, [items, searchQuery]);

    const { setNodeRef } = useSortable({
        id: col.id,
        data: {
            type: 'Column',
            col,
        },
    });

    const handleRenameSubmit = (e) => {
        e.preventDefault();
        onRename(col.id, title);
        setIsEditing(false);
    };

    return (
        <div className="flex-1 min-w-[300px] max-w-[400px] flex flex-col h-full group/column">
            {/* Column Header */}
            <div className="flex items-center justify-between mb-6 px-3">
                <div className="flex items-center gap-4">
                    <div className={clsx(
                        "p-2.5 rounded-2xl shadow-lg shadow-slate-200/50 dark:shadow-none text-white transition-transform group-hover/column:scale-105 duration-500",
                        col.color
                    )}>
                        <col.icon className="size-4" />
                    </div>
                    <div className="flex flex-col">
                        {isEditing ? (
                            <form onSubmit={handleRenameSubmit}>
                                <input
                                    autoFocus
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    onBlur={handleRenameSubmit}
                                    className="bg-transparent border-none p-0 font-black text-[12px] text-slate-800 dark:text-slate-100 uppercase tracking-[0.1em] focus:ring-0 w-32"
                                />
                            </form>
                        ) : (
                            <h2 
                                onClick={() => setIsEditing(true)}
                                className="font-black text-[12px] text-slate-800 dark:text-slate-100 uppercase tracking-[0.1em] mb-0.5 cursor-text"
                            >
                                {col.title}
                            </h2>
                        )}
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-bold text-slate-400/70">
                                {col.subtitle || 'Procesos'}
                            </span>
                            <span className="size-1 rounded-full bg-slate-300 dark:bg-slate-700" />
                            <span className="text-[10px] font-black text-teal-600 dark:text-teal-400">
                                {filteredItems.length}
                            </span>
                        </div>
                    </div>
                </div>
                
                <div className="flex items-center gap-1 opacity-0 group-hover/column:opacity-100 transition-all">
                    <button 
                        onClick={() => onDelete(col.id)}
                        className="p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 text-slate-400 hover:text-red-500 rounded-lg transition-colors"
                    >
                        <AlertCircle className="size-3.5" />
                    </button>
                    <button className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 rounded-lg transition-colors">
                        <Plus className="size-3.5" />
                    </button>
                </div>
            </div>

            {/* Column Body */}
            <div 
                ref={setNodeRef}
                className="flex-1 overflow-y-auto space-y-4 custom-scrollbar px-2 pb-20 min-h-[200px]"
            >
                <SortableContext items={filteredItems.map(i => i.id)} strategy={verticalListSortingStrategy}>
                    {filteredItems.map((conv) => (
                        <SortableKanbanCard key={conv.id} conv={conv} />
                    ))}
                </SortableContext>
                
                {filteredItems.length === 0 && (
                    <div className="group/empty border-2 border-dashed border-slate-200 dark:border-slate-800/60 rounded-[2.5rem] py-16 flex flex-col items-center justify-center text-slate-400/40 transition-all hover:bg-slate-50 dark:hover:bg-slate-900/30 hover:border-teal-500/20">
                        <div className="p-4 bg-white dark:bg-slate-800 shadow-lg shadow-slate-200/50 dark:shadow-none rounded-full mb-3 group-hover/empty:scale-110 group-hover/empty:rotate-12 transition-all duration-700">
                            <LayoutDashboard className="size-6 text-slate-200 dark:text-slate-700" />
                        </div>
                        <p className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-300 dark:text-slate-700">Arrastra aquí</p>
                    </div>
                )}
            </div>
        </div>
    );
};

// --- Main Component ---

const DEFAULT_COLUMNS = [
    { id: 'nuevo', title: 'Nuevo Prospecto', color: 'bg-blue-500', icon: Plus, subtitle: 'Primer contacto' },
    { id: 'atencion', title: 'En Atención', color: 'bg-amber-500', icon: MessageSquare, subtitle: 'Chat activo' },
    { id: 'seguimiento', title: 'Seguimiento', color: 'bg-purple-500', icon: Calendar, subtitle: 'Nutrición' },
    { id: 'cerrado', title: 'Venta / Cerrado', color: 'bg-emerald-500', icon: CheckCircle2, subtitle: 'Éxito' }
];

export default function Kanban({ conversations }) {
    const [searchQuery, setSearchQuery] = useState('');
    const [columns, setColumns] = useState(DEFAULT_COLUMNS);
    const [boardData, setBoardData] = useState({});
    const [activeId, setActiveId] = useState(null);

    // Distribute data based on current columns
    useEffect(() => {
        const data = {};
        columns.forEach(col => {
            data[col.id] = [];
        });

        conversations.forEach((conv, index) => {
            // Logic to distribute based on existing mapping or demo logic
            if (conv.status === 'closed' && data.cerrado) {
                data.cerrado.push(conv);
            } else {
                // Distributed by availability
                const availableIds = columns.map(c => c.id).filter(id => id !== 'cerrado');
                const colId = availableIds[index % availableIds.length] || columns[0].id;
                if (data[colId]) data[colId].push(conv);
            }
        });
        setBoardData(data);
    }, [conversations, columns.length]); // Re-distribute if column count changes for demo

    const addColumn = () => {
        const id = `col-${Date.now()}`;
        const newCol = {
            id,
            title: 'Nueva Etapa',
            color: 'bg-slate-500',
            icon: Zap,
            subtitle: 'Personalizado'
        };
        setColumns([...columns, newCol]);
        setBoardData(prev => ({ ...prev, [id]: [] }));
    };

    const renameColumn = (id, newTitle) => {
        setColumns(prev => prev.map(c => c.id === id ? { ...c, title: newTitle } : c));
    };

    const deleteColumn = (id) => {
        if (confirm('¿Estás seguro de eliminar esta etapa? Las tarjetas se perderán (en este demo).')) {
            setColumns(prev => prev.filter(c => c.id !== id));
            setBoardData(prev => {
                const newData = { ...prev };
                delete newData[id];
                return newData;
            });
        }
    };

    const activeConv = useMemo(() => {
        if (!activeId) return null;
        for (const colId in boardData) {
            const found = boardData[colId].find(c => c.id === activeId);
            if (found) return found;
        }
        return null;
    }, [activeId, boardData]);

    // dnd-kit sensors
    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: {
                distance: 5,
            },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    const handleDragStart = (event) => {
        setActiveId(event.active.id);
    };

    const handleDragOver = (event) => {
        const { active, over } = event;
        if (!over) return;

        const activeId = active.id;
        const overId = over.id;

        const activeContainer = findContainer(activeId);
        const overContainer = findContainer(overId);

        if (!activeContainer || !overContainer || activeContainer === overContainer) {
            return;
        }

        setBoardData((prev) => {
            const activeItems = prev[activeContainer] || [];
            const overItems = prev[overContainer] || [];

            const activeIndex = activeItems.findIndex((i) => i.id === activeId);
            const overIndex = overItems.findIndex((i) => i.id === overId);

            let newIndex;
            if (overId in prev) {
                newIndex = overItems.length + 1;
            } else {
                const isBelowLastItem =
                    over &&
                    overIndex === overItems.length - 1;

                const modifier = isBelowLastItem ? 1 : 0;
                newIndex = overIndex >= 0 ? overIndex + modifier : overItems.length + 1;
            }

            return {
                ...prev,
                [activeContainer]: [
                    ...activeItems.filter((i) => i.id !== activeId),
                ],
                [overContainer]: [
                    ...overItems.slice(0, newIndex),
                    activeItems[activeIndex],
                    ...overItems.slice(newIndex, overItems.length),
                ],
            };
        });
    };

    const handleDragEnd = (event) => {
        const { active, over } = event;
        const activeId = active.id;
        const overId = over?.id;

        const activeContainer = findContainer(activeId);
        const overContainer = findContainer(overId);

        if (!activeContainer || !overContainer || activeContainer !== overContainer) {
            setActiveId(null);
            return;
        }

        const activeIndex = boardData[activeContainer].findIndex((i) => i.id === activeId);
        const overIndex = boardData[overContainer].findIndex((i) => i.id === overId);

        if (activeIndex !== overIndex) {
            setBoardData((prev) => ({
                ...prev,
                [overContainer]: arrayMove(prev[overContainer], activeIndex, overIndex),
            }));
        }

        setActiveId(null);
    };

    function findContainer(id) {
        if (id in boardData) return id;
        return Object.keys(boardData).find((key) =>
            boardData[key].find((item) => item.id === id)
        );
    }

    const stats = [
        { label: 'Proyectos', value: conversations.length, icon: User, color: 'text-blue-500', bg: 'bg-blue-500/5' },
        { label: 'Etapas', value: columns.length, icon: LayoutDashboard, color: 'text-emerald-500', bg: 'bg-emerald-500/5' },
        { label: 'Pipeline Total', value: `$ ${(conversations.length * 150000).toLocaleString()}`, icon: DollarSign, color: 'text-amber-500', bg: 'bg-amber-500/5' },
        { label: 'Conversión', value: '94%', icon: CheckCircle2, color: 'text-purple-500', bg: 'bg-purple-500/5' }
    ];

    return (
        <>
            <Head title="CRM Pipeline | Business WhatsApp" />
            
            <div className="flex-1 flex flex-col min-h-0 bg-[#fdfdfe] dark:bg-[#080c14] overflow-hidden">
                {/* Visual Accent */}
                <div className="absolute top-0 right-0 w-[50%] h-[50%] bg-teal-500/5 blur-[120px] pointer-events-none rounded-full" />
                <div className="absolute bottom-0 left-0 w-[30%] h-[30%] bg-purple-500/5 blur-[100px] pointer-events-none rounded-full" />

                {/* Header Section */}
                <div className="px-6 lg:px-10 pt-8 pb-4 relative z-10">
                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-8">
                        <div>
                            <div className="flex items-center gap-4 mb-1">
                                <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tighter">
                                    CRM Comercial
                                </h1>
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
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="pl-10 pr-4 py-2.5 bg-white dark:bg-slate-900/50 backdrop-blur-md border border-slate-100 dark:border-slate-800 rounded-2xl text-xs focus:outline-none focus:ring-4 focus:ring-teal-500/5 focus:border-teal-500/40 transition-all w-[240px] lg:w-[300px] shadow-sm placeholder:text-slate-300"
                                />
                            </div>
                            <button 
                                onClick={addColumn}
                                className="flex items-center gap-2 px-5 py-2.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl text-xs font-black shadow-xl hover:scale-[1.02] active:scale-95 transition-all"
                            >
                                <LayoutDashboard className="size-4" /> Nueva Etapa
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
                        {stats.map((stat, i) => (
                            <div key={i} className="group bg-white dark:bg-slate-900/40 backdrop-blur-sm p-4 lg:p-5 rounded-3xl border border-slate-50 dark:border-slate-800/50 shadow-sm transition-all">
                                <div className="flex items-center gap-4">
                                    <div className={clsx("p-3 rounded-2xl transition-all shadow-inner", stat.bg, stat.color)}>
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

                {/* Board Section */}
                <div className="flex-1 overflow-x-auto px-6 lg:px-10 pt-4 pb-8 flex gap-6 lg:gap-8 custom-scrollbar relative z-10">
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCorners}
                        onDragStart={handleDragStart}
                        onDragOver={handleDragOver}
                        onDragEnd={handleDragEnd}
                    >
                        {columns.map((col) => (
                            <BoardColumn 
                                key={col.id} 
                                col={col} 
                                items={boardData[col.id] || []} 
                                searchQuery={searchQuery}
                                onRename={renameColumn}
                                onDelete={deleteColumn}
                            />
                        ))}

                        <div className="flex-shrink-0 w-[100px] flex flex-col items-center justify-start pt-12">
                            <button 
                                onClick={addColumn}
                                className="size-12 rounded-full border-2 border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center text-slate-300 hover:text-teal-500 hover:border-teal-500 hover:bg-teal-500/5 transition-all group"
                            >
                                <Plus className="size-6 group-hover:rotate-90 transition-transform duration-300" />
                            </button>
                            <p className="text-[10px] font-black text-slate-300 mt-4 uppercase tracking-widest">Añadir</p>
                        </div>
                        
                        {/* Spacer for proper right padding on scroll */}
                        <div className="flex-shrink-0 w-2 lg:w-4" />

                        <DragOverlay dropAnimation={{
                            sideEffects: defaultDropAnimationSideEffects({
                                styles: {
                                    active: {
                                        opacity: '0.4',
                                    },
                                },
                            }),
                        }}>
                            {activeConv ? (
                                <KanbanCard conv={activeConv} isOverlay />
                            ) : null}
                        </DragOverlay>
                    </DndContext>
                </div>
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar {
                    width: 6px;
                    height: 6px;
                }
                .custom-scrollbar::-webkit-scrollbar-track {
                    background: transparent;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(148, 163, 184, 0.1);
                    border-radius: 20px;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb:hover {
                    background: rgba(148, 163, 184, 0.2);
                }
                
                @keyframes shine {
                    from { transform: translateX(-100%); }
                    to { transform: translateX(100%); }
                }
            `}} />
        </>
    );
}

Kanban.layout = page => <AppLayout breadcrumb={['CRM Commercial Board']}>{page}</AppLayout>;
