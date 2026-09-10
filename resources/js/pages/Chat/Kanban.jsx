import { useState, useMemo, useEffect, useRef, useCallback, memo } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import {
    Image as ImageIcon,
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
    Trash2,
    UserCircle,
    FileText,
    Mic,
    MapPin,
    Video,
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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { useAviso } from '@/components/ui/toast';
import { SelectorMultiple, SelectorBuscador } from '@/components/ui/selector';
import PanelConversacion from './PanelConversacion';

const PER_PAGE = 30;

// Icon map: backend string → React component
const ICON_MAP = { Plus, MessageSquare, Calendar, CheckCircle2, Zap, LayoutDashboard, Users, User };
const getIcon = (name) => ICON_MAP[name] ?? Zap;

/**
 * Qué se envió por último, cuando no fue texto.
 *
 * La tarjeta enseñaba `last_message`, que es sólo texto: en un recibo salía
 * «Recibo_8309.pdf» como si fuera un mensaje escrito, sin decir que era un
 * archivo. Aquí se ve el tipo, y el nombre cuando lo hay.
 */
const ADJUNTOS = {
    document: { icono: FileText,  texto: 'Documento' },
    image:    { icono: ImageIcon, texto: 'Imagen' },
    sticker:  { icono: ImageIcon, texto: 'Sticker' },
    audio:    { icono: Mic,       texto: 'Audio' },
    voice:    { icono: Mic,       texto: 'Nota de voz' },
    video:    { icono: Video,     texto: 'Video' },
    location: { icono: MapPin,    texto: 'Ubicación' },
};

function Adjunto({ tipo, archivo }) {
    const pinta = ADJUNTOS[tipo];
    if (!pinta) return null;

    const Icono = pinta.icono;

    return (
        <span className="mb-2 flex w-fit max-w-full items-center gap-1.5 rounded-lg bg-muted px-2 py-1">
            <Icono className="size-3 shrink-0 text-muted-foreground" />
            <span className="truncate text-[11px] font-bold text-muted-foreground">
                {archivo || pinta.texto}
            </span>
        </span>
    );
}

/**
 * Tooltip del tablero, con el mismo aspecto que los del menú lateral.
 *
 * Antes eran `title=` nativos: aparecían con medio segundo de retraso, en el
 * gris del sistema operativo y sin relación visual con la aplicación. Encima,
 * sobre un botón que ya estaba dentro de una barra flotante, el recuadro del
 * sistema tapaba los botones de al lado.
 */
function Pista({ texto, lado = 'top', children }) {
    if (!texto) return children;

    return (
        <Tooltip>
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipContent side={lado} align="center">
                {texto}
            </TooltipContent>
        </Tooltip>
    );
}

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

/** Deja sólo letras y números, para comparar «📄 Recibo.pdf» con «Recibo.pdf». */
const soloLoQueCuenta = texto => (texto ?? '').replace(/[^\p{L}\p{N}]+/gu, '').toLowerCase();

const KanbanCard = memo(({ conv, isOverlay, isDragging, onAbrir, ...props }) => {
    const dias = diasEnEtapa(conv.entro_en_etapa);
    const estancada = dias !== null && dias >= DIAS_PARA_AVISAR;

    const repiteElArchivo = !!conv.ultimo_archivo
        && soloLoQueCuenta(conv.last_message) === soloLoQueCuenta(conv.ultimo_archivo);

    /**
     * Un clic abre la conversación; un arrastre no.
     *
     * No se usa `onClick`: la misma pulsación es el asa de arrastre, y la
     * librería decide si hubo movimiento suficiente *después*, cancelando el
     * click a veces y otras no. Aquí se mide: si el puntero no se movió más de
     * cinco píxeles, era un clic.
     */
    const pulsacion = useRef(null);

    const alPulsar = e => {
        // Sólo el botón principal, y no cuando se pulsa sobre un enlace.
        if (e.button !== 0) return;
        pulsacion.current = { x: e.clientX, y: e.clientY };
    };

    const alSoltar = e => {
        const inicio = pulsacion.current;
        pulsacion.current = null;
        if (!inicio) return;

        const movido = Math.hypot(e.clientX - inicio.x, e.clientY - inicio.y);
        if (movido <= 5) onAbrir?.(conv);
    };

    return (
        <div
            {...props}
            onPointerDown={e => { props.onPointerDown?.(e); alPulsar(e); }}
            onPointerUp={alSoltar}
            role="button"
            tabIndex={0}
            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); onAbrir?.(conv); } }}
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

            <Adjunto tipo={conv.ultimo_tipo} archivo={conv.ultimo_archivo} />

            {/* Cuando el último mensaje es sólo el archivo, `last_message` ya
                trae «📄 Recibo_8362.pdf» y la pastilla lo repetía: el mismo
                nombre dos veces, una encima de la otra. */}
            {!repiteElArchivo && (
                <p className="text-[12px] text-muted-foreground line-clamp-2 leading-snug mb-2.5">
                    {conv.last_message || 'Sin mensajes todavía'}
                </p>
            )}

            <div className="flex items-center justify-between gap-2">
                {conv.assigned_agent ? (
                    <div className="flex items-center gap-1.5 min-w-0">
                        <Pista texto={`Atiende ${conv.assigned_agent.name}`}>
                            <div className="size-5 shrink-0 rounded-full bg-primary/20 text-accent-foreground flex items-center justify-center text-[8px] font-black">
                                {conv.assigned_agent.name.substring(0, 2).toUpperCase()}
                            </div>
                        </Pista>
                        <span className="text-[10px] font-bold text-muted-foreground truncate">
                            {conv.assigned_agent.name}
                        </span>
                    </div>
                ) : (
                    <span className="text-[10px] font-bold text-muted-foreground/60">Sin agente</span>
                )}

                {dias !== null && (
                    <Pista texto={estancada
                        ? `Lleva ${dias} días en esta etapa sin moverse`
                        : `Entró en esta etapa hace ${dias} ${dias === 1 ? 'día' : 'días'}`}>
                        <span
                            className={clsx(
                                'shrink-0 px-2 py-0.5 rounded-full text-[10px] font-black tabular-nums',
                                estancada
                                    ? 'bg-warning/15 text-warning'
                                    : 'bg-muted text-muted-foreground'
                            )}
                        >
                            {dias === 0 ? 'hoy' : `${dias} d`}
                        </span>
                    </Pista>
                )}
            </div>
        </div>
    );
});

const SortableKanbanCard = memo(({ conv, index, onAbrir }) => {
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
                        onAbrir={onAbrir}
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
    <div className="flex w-[86vw] max-w-[360px] shrink-0 snap-start flex-col sm:w-auto sm:min-w-[300px] sm:flex-1 sm:shrink">
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

const BoardColumn = memo(({ col, indice, items, totalCount, loading, hasMore, error, onLoadMore, onRename, onDelete, onAddCard, onCambiarGrupo, onCambiarBandeja, onAbrirTarjeta }) => {
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
            'flex h-full w-[86vw] max-w-[360px] shrink-0 snap-start flex-col overflow-hidden rounded-3xl border transition-colors group/column sm:w-auto sm:min-w-[290px] sm:flex-1 sm:shrink',
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
                            <Pista texto={`${col.name} — clic para renombrar`}>
                                <h2
                                    onClick={() => setIsEditing(true)}
                                    className="font-black text-[12px] text-foreground dark:text-muted-foreground uppercase tracking-[0.08em] cursor-text truncate"
                                >
                                    {col.name}
                                </h2>
                            </Pista>
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
                                <Pista texto="Recoge lo que no está clasificado en este grupo">
                                    <span className="inline-flex items-center gap-1 text-[9px] font-black text-muted-foreground uppercase tracking-wider">
                                        <Inbox className="size-2.5" /> Bandeja
                                    </span>
                                </Pista>
                            )}
                        </div>
                    </div>
                </div>

                <div className="absolute right-2.5 top-2.5 flex items-center gap-0.5 p-0.5 rounded-xl bg-card/90 backdrop-blur shadow-sm border border-border/60 opacity-0 group-hover/column:opacity-100 transition-opacity">
                    <Pista texto={col.es_bandeja
                        ? 'Recoge lo que no está clasificado en este grupo'
                        : 'Hacer que recoja lo que no está clasificado en este grupo'}>
                        <button
                            onClick={() => onCambiarBandeja(col.id, !col.es_bandeja)}
                            className={clsx(
                                'p-1.5 rounded-lg transition-colors',
                                col.es_bandeja
                                    ? 'bg-primary/15 text-accent-foreground'
                                    : 'hover:bg-muted text-muted-foreground hover:text-foreground'
                            )}
                        >
                            <Inbox className="size-3.5" />
                        </button>
                    </Pista>
                    <Pista texto="Grupo de la etapa">
                        <button onClick={() => setEditandoGrupo(true)} className="p-1.5 hover:bg-muted text-muted-foreground hover:text-foreground rounded-lg transition-colors">
                            <Layers className="size-3.5" />
                        </button>
                    </Pista>
                    <Pista texto="Eliminar etapa">
                        <button onClick={() => onDelete(col.id)} className="p-1.5 hover:bg-destructive/15 dark:hover:bg-destructive/20 text-muted-foreground hover:text-destructive rounded-lg transition-colors">
                            {/* Era un círculo de admiración, que anuncia un aviso, no un
                                borrado: nadie adivinaba que ese botón eliminaba la etapa. */}
                            <Trash2 className="size-3.5" />
                        </button>
                    </Pista>
                    <Pista texto="Agregar tarjeta">
                        <button onClick={() => onAddCard(col.id)} className="p-1.5 hover:bg-muted dark:hover:bg-muted text-muted-foreground hover:text-muted-foreground rounded-lg transition-colors">
                            <Plus className="size-3.5" />
                        </button>
                    </Pista>
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
                            <SortableKanbanCard key={conv.id} conv={conv} index={index} onAbrir={onAbrirTarjeta} />
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
    const [agentes, setAgentes]             = useState([]);   // ids de usuarios, y 'sin_asignar'
    const [soloEstancadas, setSoloEstancadas] = useState(false);
    const [contacto, setContacto]           = useState(null); // { id, nombre }
    const [usuarios, setUsuarios]           = useState([]);
    const [tarjetaAbierta, setTarjetaAbierta] = useState(null); // la conversación del panel
    const [borradorEtapa, setBorradorEtapa] = useState(null);   // null = no hay borrador abierto
    const [creandoEtapa, setCreandoEtapa]   = useState(false);
    const [errorEtapa, setErrorEtapa]       = useState(null);
    const [etapaABorrar, setEtapaABorrar]   = useState(null);   // id, para el diálogo
    const [borrando, setBorrando]           = useState(false);
    const aviso = useAviso();
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

    // Las etapas de los **otros** tableros, que son las que se pueden usar como
    // filtro: las del tablero que se está viendo ya son las columnas.
    const opcionesDeEtapas = useMemo(
        () => grupos
            .filter(g => g !== grupoActivo)
            .flatMap(g => columns
                .filter(c => (c.grupo ?? null) === g)
                .map(c => ({ valor: c.id, texto: c.name, seccion: g ?? 'Sin agrupar' }))),
        [grupos, grupoActivo, columns]
    );

    const opcionesDeAgentes = useMemo(() => [
        { valor: 'sin_asignar', texto: 'Sin asignar', ayuda: 'Nadie las está atendiendo' },
        ...usuarios.map(u => ({ valor: String(u.id), texto: u.name, ayuda: u.email })),
    ], [usuarios]);

    const cuantosFiltros = filtros.length + agentes.length + (contacto ? 1 : 0) + (soloEstancadas ? 1 : 0);
    const hayFiltros = cuantosFiltros > 0;

    const limpiarFiltros = () => {
        setFiltros([]);
        setAgentes([]);
        setContacto(null);
        setSoloEstancadas(false);
    };

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
    const agentesKey = agentes.join(',');

    /**
     * Todo lo que define «lo que se está viendo», en un solo sitio.
     *
     * Antes cada llamada armaba sus parámetros a mano y era fácil olvidarse de
     * uno: la recarga tras un movimiento fallido pedía las tarjetas **sin los
     * filtros**, así que reaparecían tarjetas que el filtro había escondido. Y
     * los conteos nunca incluían el buscador, de ahí una cabecera que decía
     * «3.184» sobre una columna con dos tarjetas.
     */
    const vistaKey = [grupoActivo ?? '', filtrosKey, agentesKey, soloEstancadas ? '1' : '', contacto?.id ?? ''].join('|');

    // loadCounts se llama también desde el canal de tiempo real, sin
    // argumentos, así que lee la vista actual de aquí en vez de recrearse.
    const vistaRef = useRef({ grupo: grupoActivo, filtros, agentes, soloEstancadas, contacto, search: '' });
    useEffect(() => {
        vistaRef.current = { grupo: grupoActivo, filtros, agentes, soloEstancadas, contacto, search: debouncedSearch };
    }, [vistaKey, debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

    /** Los parámetros de la vista actual, para cualquier petición del tablero. */
    const paramsDeVista = useCallback((extra = {}) => {
        const v = vistaRef.current;
        const params = new URLSearchParams();

        if (v.grupo) params.set('grupo', v.grupo);
        if (v.search) params.set('search', v.search);
        v.filtros.forEach(id => params.append('filtros[]', id));
        v.agentes.forEach(id => params.append('agentes[]', id));
        if (v.soloEstancadas) params.set('estancadas', '1');
        if (v.contacto) params.set('conversacion', v.contacto.id);

        Object.entries(extra).forEach(([k, valor]) => params.set(k, valor));

        return params;
    }, []);

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

    const loadColumnCards = useCallback(async (colId, page, reset = false) => {
        // Cancel any in-flight request for this column
        if (abortControllersRef.current[colId]) {
            abortControllersRef.current[colId].abort();
        }
        const controller = new AbortController();
        abortControllersRef.current[colId] = controller;

        setColMeta(prev => ({ ...prev, [colId]: { ...prev[colId], loading: true } }));
        try {
            const params = paramsDeVista({ page, per_page: PER_PAGE });

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
    }, [paramsDeVista]);

    // Fetch real card counts per column
    const loadCounts = useCallback(async () => {
        try {
            const data = await apiRequest('GET', `/api/kanban/counts?${paramsDeVista()}`);
            setColCounts(data);
        } catch (err) {
            console.error('Error cargando conteos:', err);
        }
    }, [paramsDeVista]);

    // Los agentes del filtro salen del mismo endpoint que usa el chat para
    // asignar, así que la lista es siempre la misma en las dos pantallas.
    useEffect(() => {
        let vivo = true;
        apiRequest('GET', '/api/chat/users')
            .then(datos => { if (vivo) setUsuarios(Array.isArray(datos) ? datos : []); })
            .catch(err => console.error('Error cargando agentes:', err));
        return () => { vivo = false; };
    }, []);

    const buscarContactos = useCallback(async texto => {
        const datos = await apiRequest('GET', `/api/kanban/contactos?q=${encodeURIComponent(texto)}`);
        return datos.map(c => ({
            valor: c.id,
            texto: c.nombre,
            ayuda: [c.telefono, c.agente].filter(Boolean).join(' · '),
        }));
    }, []);

    // Carga inicial, y recarga al cambiar de grupo o de filtros.
    useEffect(() => {
        loadCounts();
        columnasVisibles.forEach(col => loadColumnCards(col.id, 1, true));
    }, [vistaKey]); // eslint-disable-line react-hooks/exhaustive-deps

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

    // Al buscar se recargan las tarjetas y también los conteos: la cifra de la
    // cabecera cuenta lo mismo que se ve debajo.
    useEffect(() => {
        if (debouncedSearch !== undefined) {
            loadCounts();
            columnasVisibles.forEach(col => loadColumnCards(col.id, 1, true));
        }
    }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

    const handleLoadMore = (colId) => {
        const meta = colMeta[colId];
        if (!meta || meta.loading) return;
        if (!meta.error && !meta.hasMore) return;
        loadColumnCards(colId, meta.page + 1, false);
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
            aviso.error('No se pudo cambiar el grupo de la etapa', { detalle: err.message });
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
            columnasVisibles.forEach(c => loadColumnCards(c.id, 1, true));
        } catch (err) {
            console.error('Error al cambiar la bandeja:', err);
            aviso.error('No se pudo cambiar la bandeja', { detalle: err.message });
        }
    };

    const renameColumn = async (id, newName) => {
        try {
            const updated = await apiRequest('PUT', `/api/kanban/columns/${id}`, { name: newName });
            setColumns(prev => prev.map(c => c.id === id ? { ...c, name: updated.name } : c));
        } catch (err) {
            console.error('Error al renombrar columna:', err);
            aviso.error('No se pudo renombrar la etapa', { detalle: err.message });
        }
    };

    // El borrado pide confirmación en el diálogo de la aplicación, no en el
    // `confirm` del navegador, que se pinta pegado al borde con el dominio como
    // título y no se parece a nada de lo que hay alrededor.
    const deleteColumn = (id) => setEtapaABorrar(id);

    const confirmarBorrado = async () => {
        const id = etapaABorrar;
        if (!id) return;
        const nombre = columns.find(c => c.id === id)?.name;

        setBorrando(true);
        try {
            await apiRequest('DELETE', `/api/kanban/columns/${id}`);

            // Antes esto recargaba la página entera, lo que además se llevaba
            // por delante el aviso de que había salido bien. El servidor
            // recoloca las tarjetas huérfanas, así que basta con quitar la
            // etapa y volver a pedir lo que queda.
            const quedan = columns.filter(c => c.id !== id);
            setColumns(quedan);
            setBoardData(prev => { const { [id]: _fuera, ...resto } = prev; return resto; });
            setEtapaABorrar(null);
            setBorrando(false);
            aviso.exito(nombre ? `Etapa «${nombre}» eliminada` : 'Etapa eliminada', {
                detalle: 'Las tarjetas que tuviera pasaron a la primera etapa disponible.',
            });
            loadCounts();
            quedan
                .filter(c => (c.grupo ?? null) === (grupoActivo ?? null))
                .forEach(c => loadColumnCards(c.id, 1, true));
        } catch (err) {
            console.error('Error al eliminar columna:', err);
            setBorrando(false);
            setEtapaABorrar(null);
            aviso.error('No se pudo eliminar la etapa', { detalle: err.message });
        }
    };

    const handleCardCreated = (card) => {
        setNewCardColumn(null);
        const colId = card.kanban_column_id ?? columns[0]?.id;
        if (!colId) return;
        aviso.exito(`${card.name || card.phone_number} entró en ${columns.find(c => c.id === colId)?.name ?? 'el tablero'}`);
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
                    // La tarjeta ya se movió en pantalla y aquí vuelve a su
                    // sitio: sin este aviso, el salto no tiene explicación.
                    aviso.error(
                        `No se pudo mover a ${columns.find(c => String(c.id) === String(targetColId))?.name ?? 'la otra etapa'}`,
                        { detalle: err.message },
                    );
                    loadColumnCards(originColId, 1, true);
                    loadColumnCards(targetColId, 1, true);
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
        // «Etapas» estaba aquí y se fue: el subtítulo ya dice «4 etapas» dos
        // centímetros a la izquierda, y en la franja compacta cada pastilla
        // repetida es sitio que le quitas a los filtros.
        { label: total_conversations === 1 ? 'Conversación' : 'Conversaciones', value: total_conversations.toLocaleString('es-CO'), icon: User, color: 'text-info', bg: 'bg-info/5',
          hint: 'Todas las de la empresa' },
        { label: 'En el tablero',  value: en_tablero.toLocaleString('es-CO'),          icon: LayoutDashboard, color: 'text-accent-foreground', bg: 'bg-primary/5',
          hint: 'Colocadas en alguna etapa' },
        { label: 'Sin mover +7d',  value: estancadas.toLocaleString('es-CO'),          icon: Clock,           color: estancadas > 0 ? 'text-warning' : 'text-muted-foreground', bg: 'bg-warning/5',
          hint: 'Una semana sin actividad' },
    ], [total_conversations, en_tablero, estancadas]);

    // ── Render ─────────────────────────────────────────────────────────────

    return (
        <>
            <Head title="Tablero" />

            {tarjetaAbierta && (
                <PanelConversacion
                    conversacionId={tarjetaAbierta.id}
                    resumen={tarjetaAbierta}
                    onCerrar={() => setTarjetaAbierta(null)}
                    // Al enviar algo cambia el último mensaje y el «no leído»,
                    // así que la tarjeta de detrás tiene que enterarse.
                    onCambio={() => {
                        loadCounts();
                        columnasVisibles.forEach(c => loadColumnCards(c.id, 1, true));
                    }}
                />
            )}

            <ConfirmDialog
                open={etapaABorrar !== null}
                title="¿Eliminar esta etapa?"
                description={(() => {
                    const col = columns.find(c => c.id === etapaABorrar);
                    const cuantas = colCounts[etapaABorrar];
                    const nombre = col ? `«${col.name}»` : 'La etapa';
                    return cuantas
                        ? `${nombre} tiene ${cuantas.toLocaleString('es-CO')} ${cuantas === 1 ? 'tarjeta' : 'tarjetas'}. Pasarán a la primera etapa disponible; no se borra ninguna conversación.`
                        : `${nombre} se elimina del tablero. Las tarjetas que tuviera pasarán a la primera etapa disponible.`;
                })()}
                confirmLabel="Eliminar etapa"
                cancelLabel="Cancelar"
                variant="danger"
                loading={borrando}
                onConfirm={confirmarBorrado}
                onCancel={() => setEtapaABorrar(null)}
            />

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
                {/*
                    Cabecera compacta, en dos franjas.

                    Antes ocupaba tres bloques —título grande, cuatro tarjetas
                    de métricas y la barra de filtros— y las columnas empezaban
                    a 440px del borde: casi la mitad de una pantalla de
                    portátil gastada antes de ver la primera tarjeta. Un tablero
                    se lee por las columnas, así que las métricas bajan a
                    pastillas de una línea y todo cabe en ~96px.

                    En móvil las dos franjas se desplazan en horizontal en vez
                    de apilarse: apilar dejaba las columnas otra vez abajo.
                */}
                <div className="relative z-20 shrink-0 px-4 pb-2 pt-3 lg:px-6">
                    <div className="flex items-center gap-2">
                        <div className="flex min-w-0 shrink items-baseline gap-2">
                            <h1 className="shrink-0 text-lg font-black tracking-tighter text-foreground dark:text-white">Tablero</h1>
                            <p className="hidden truncate text-[11px] font-bold text-muted-foreground sm:flex sm:items-center sm:gap-1.5">
                                {columnasVisibles.length === 0
                                    ? 'Sin etapas todavía'
                                    : <>
                                        {columnasVisibles.length} {columnasVisibles.length === 1 ? 'etapa' : 'etapas'}
                                        {grupoActivo && <><ArrowRight className="size-2.5" /> {grupoActivo}</>}
                                        {hayFiltros && <span className="text-accent-foreground">· {cuantosFiltros} {cuantosFiltros === 1 ? 'filtro' : 'filtros'}</span>}
                                      </>}
                            </p>
                        </div>

                        <div className="ml-auto flex min-w-0 flex-1 items-center justify-end gap-2 sm:flex-none">
                            <div className="relative min-w-0 flex-1 sm:flex-none">
                                <div className="pointer-events-none absolute inset-y-0 left-3 flex items-center">
                                    <Search className="size-3.5 text-muted-foreground" />
                                </div>
                                <input
                                    type="text"
                                    placeholder="Buscar..."
                                    value={searchQuery}
                                    onChange={e => setSearchQuery(e.target.value)}
                                    className="w-full rounded-xl border border-border bg-white py-2 pl-9 pr-3 text-xs shadow-sm transition-all placeholder:text-muted-foreground focus:border-primary/40 focus:outline-none focus:ring-4 focus:ring-primary/5 sm:w-[220px] lg:w-[280px] dark:bg-muted"
                                />
                            </div>
                            <Pista texto="Crear una etapa nueva">
                                <button
                                    onClick={addColumn}
                                    aria-label="Nueva etapa"
                                    className="flex shrink-0 items-center gap-2 rounded-xl bg-foreground px-3 py-2 text-xs font-black text-background shadow-lg transition-all hover:scale-[1.02] active:scale-95 sm:px-4"
                                >
                                    <LayoutDashboard className="size-4" />
                                    <span className="hidden sm:inline">Nueva Etapa</span>
                                </button>
                            </Pista>
                        </div>
                    </div>

                    {/* Métricas y filtros comparten franja: una sola línea que
                        se desplaza en horizontal cuando no cabe. */}
                    <div className="-mx-4 mt-2 flex items-center gap-2 overflow-x-auto px-4 pb-0.5 [scrollbar-width:none] lg:-mx-6 lg:px-6 [&::-webkit-scrollbar]:hidden">
                        {stats.map((stat, i) => (
                            <Pista key={i} texto={`${stat.label}: ${stat.hint}`}>
                                <span className="flex shrink-0 items-center gap-1.5 rounded-xl border border-border bg-card px-2.5 py-2">
                                    <stat.icon className={clsx('size-3.5', stat.color)} />
                                    <span className="text-[12.5px] font-black leading-none tabular-nums text-foreground dark:text-white">{stat.value}</span>
                                    <span className="text-[9px] font-black uppercase leading-none tracking-widest text-muted-foreground">{stat.label}</span>
                                </span>
                            </Pista>
                        ))}

                        <span className="mx-1 h-6 w-px shrink-0 bg-border" />

                        {grupos.length > 1 && (
                            <div className="flex shrink-0 items-center gap-1 rounded-xl bg-muted/60 p-1">
                                {grupos.map(g => (
                                    <button
                                        key={g ?? '__sin__'}
                                        onClick={() => { setGrupoActivo(g); setFiltros([]); }}
                                        className={clsx(
                                            'rounded-lg px-2.5 py-1 text-[11px] font-black transition-all',
                                            g === grupoActivo
                                                ? 'bg-white text-foreground shadow-sm dark:bg-background'
                                                : 'text-muted-foreground hover:text-foreground'
                                        )}
                                    >
                                        {g ?? 'Sin agrupar'}
                                    </button>
                                ))}
                            </div>
                        )}

                        {opcionesDeEtapas.length > 0 && (
                            <div className="shrink-0">
                                <SelectorMultiple
                                    etiqueta="Otros tableros"
                                    icono={Layers}
                                    opciones={opcionesDeEtapas}
                                    seleccion={filtros}
                                    onCambio={setFiltros}
                                    buscador={opcionesDeEtapas.length > 8}
                                    textoVacio="Sin filtrar"
                                    nota="Dentro de un mismo tablero se suman (una u otra). Entre tableros distintos se acumulan."
                                />
                            </div>
                        )}

                        <div className="shrink-0">
                            <SelectorMultiple
                                etiqueta="Agente"
                                icono={UserCircle}
                                opciones={opcionesDeAgentes}
                                seleccion={agentes}
                                onCambio={setAgentes}
                                buscador={opcionesDeAgentes.length > 8}
                                textoVacio="Todos"
                            />
                        </div>

                        <div className="shrink-0">
                            <SelectorBuscador
                                etiqueta="Contacto"
                                icono={Search}
                                valor={contacto?.id ?? null}
                                etiquetaDelValor={contacto?.nombre}
                                onCambio={(valor, texto) => setContacto(valor ? { id: valor, nombre: texto } : null)}
                                buscar={buscarContactos}
                                textoVacio="Cualquiera"
                            />
                        </div>

                        <button
                            type="button"
                            onClick={() => setSoloEstancadas(v => !v)}
                            className={clsx(
                                'flex shrink-0 items-center gap-1.5 rounded-xl border px-2.5 py-2 text-[11.5px] font-bold transition-all',
                                soloEstancadas
                                    ? 'border-warning/40 bg-warning/15 text-warning'
                                    : 'border-border bg-card text-muted-foreground hover:border-warning/30 hover:text-foreground'
                            )}
                        >
                            <Clock className="size-3.5" />
                            Sin mover +7d
                        </button>

                        {hayFiltros && (
                            <button
                                onClick={limpiarFiltros}
                                className="shrink-0 whitespace-nowrap px-1 text-[10px] font-black text-muted-foreground underline underline-offset-4 hover:text-foreground"
                            >
                                Limpiar {cuantosFiltros} {cuantosFiltros === 1 ? 'filtro' : 'filtros'}
                            </button>
                        )}
                    </div>
                </div>

                {/* Board */}
                {puedeIzquierda && (
                    <button
                        onClick={() => desplazar(-1)}
                        aria-label="Ver las etapas anteriores"
                        className="absolute left-2 top-1/2 z-30 hidden size-10 items-center justify-center rounded-full border border-border bg-white/90 text-muted-foreground shadow-lg backdrop-blur transition-all hover:scale-105 hover:text-foreground active:scale-95 sm:flex dark:bg-muted/90"
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                )}
                {puedeDerecha && (
                    <button
                        onClick={() => desplazar(1)}
                        aria-label="Ver las etapas siguientes"
                        className="absolute right-2 top-1/2 z-30 hidden size-10 items-center justify-center rounded-full border border-border bg-white/90 text-muted-foreground shadow-lg backdrop-blur transition-all hover:scale-105 hover:text-foreground active:scale-95 sm:flex dark:bg-muted/90"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                )}

                <div ref={tableroRef} className="relative z-10 flex flex-1 gap-4 overflow-x-auto px-4 pb-4 pt-1 snap-x snap-mandatory scroll-pl-4 lg:snap-none custom-scrollbar lg:gap-6 lg:px-6 lg:scroll-pl-6">
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
                                onAbrirTarjeta={setTarjetaAbierta}
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
                            <div className="flex w-[100px] shrink-0 flex-col items-center justify-start pt-6">
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
