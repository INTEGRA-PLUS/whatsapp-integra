import { useState, useEffect, useRef, useMemo, useCallback, Fragment, memo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import axios from 'axios';
import { clsx } from 'clsx';
import { 
    Search, 
    Send, 
    Image as ImageIcon,
    MoreVertical,
    MoreHorizontal,
    Phone,
    PhoneCall,
    PhoneIncoming,
    PhoneOutgoing,
    PhoneMissed,
    Info,
    Check, 
    CheckCheck,
    Paperclip,
    Mic,
    Smile,
    ArrowLeft,
    Clock,
    User,
    MessageSquare,
    ChevronDown,
    Filter,
    ArrowUpDown,
    Plus,
    Tag as TagIcon,
    PlusCircle,
    X as XIcon,
    UserPlus,
    Contact,
    Zap,
    Square,
    Trash2,
    X,
    StickyNote,
    Pencil as PencilIcon,
    PenSquare,
    FileText,
    RotateCcw,
    AtSign,
    Wallet,
    CreditCard,
    Receipt,
    Loader2,
    CheckCircle2,
    CheckSquare,
    AlertTriangle,
    DollarSign,
    Mail,
    Reply,
    MapPin
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    DropdownMenuSeparator,
    DropdownMenuLabel,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
} from '@/components/ui/sheet';
import QuickReplyPicker from '@/components/quick-reply-picker';

const QUICK_REPLY_TOKEN = /(?:^|\s)\/([a-zA-Z0-9_-]*)$/;

// Filtro de conversaciones (estilo Chatwoot) ─────────────────────────────────
const STATUS_OPTIONS = [
    { value: 'open', label: 'Abiertas' },
    { value: 'closed', label: 'Cerradas' },
    { value: 'all', label: 'Todas' },
];
const STATUS_LABELS = { open: 'Abiertas', closed: 'Cerradas', all: 'Todas' };
const FILTER_FIELDS = [
    { value: 'estado', label: 'Estado' },
    { value: 'agente', label: 'Agente' },
    { value: 'etiqueta', label: 'Etiqueta' },
];
const SORT_OPTIONS = [
    { value: 'last_activity', label: 'Última actividad' },
    { value: 'newest', label: 'Más recientes' },
    { value: 'oldest', label: 'Más antiguas' },
];

function escapeRegExp(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// ── Helpers de plantillas de WhatsApp ────────────────────────────────────────
function templateBodyComponent(t) {
    return (t?.components || []).find(c => c.type === 'BODY');
}

// Formato del encabezado multimedia de la plantilla (IMAGE/VIDEO/DOCUMENT/LOCATION)
// o null si no tiene encabezado o es de texto.
function templateHeaderFormat(t) {
    const h = (t?.components || []).find(c => c.type === 'HEADER');
    const f = (h?.format || '').toUpperCase();
    return ['IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'].includes(f) ? f : null;
}

const HEADER_MEDIA_ACCEPT = {
    IMAGE: 'image/jpeg,image/png',
    VIDEO: 'video/mp4,video/3gpp',
    DOCUMENT: 'application/pdf',
};
const HEADER_MEDIA_LABEL = { IMAGE: 'Imagen', VIDEO: 'Video', DOCUMENT: 'Documento', LOCATION: 'Ubicación' };

// Construye el componente header para el envío. Para IMAGE/VIDEO/DOCUMENT se usa
// el media_id que Meta devuelve al subir el archivo a /{phone_number_id}/media.
function buildTemplateHeaderComponent(h) {
    if (!h) return null;
    if (h.format === 'LOCATION') {
        const location = { latitude: String(h.lat ?? ''), longitude: String(h.lng ?? '') };
        if (h.name?.trim()) location.name = h.name.trim();
        if (h.address?.trim()) location.address = h.address.trim();
        return { type: 'header', parameters: [{ type: 'location', location }] };
    }
    if (!h.mediaId) return null;
    const kind = h.format.toLowerCase(); // image | video | document
    const media = { id: h.mediaId };
    if (h.format === 'DOCUMENT') media.filename = h.filename || 'documento.pdf';
    return { type: 'header', parameters: [{ type: kind, [kind]: media }] };
}

// Número de variables distintas {{n}} en el cuerpo de la plantilla.
function countTemplateVars(text) {
    const matches = (text || '').match(/{{\s*\d+\s*}}/g);
    if (!matches) return 0;
    return new Set(matches.map(x => x.replace(/\D/g, ''))).size;
}

// Reemplaza {{n}} por los valores escritos para construir la vista previa.
function fillTemplate(text, vars) {
    return (text || '').replace(/{{\s*(\d+)\s*}}/g, (_, n) => vars[Number(n) - 1] || `{{${n}}}`);
}

// Detecta si el agente terminó de escribir el disparador de una integración
// (ej. "/pagos" o "@pagos") al final del texto antes del cursor.
function detectIntegrationTrigger(value, cursor, integrations) {
    const before = value.slice(0, cursor);
    for (const integration of integrations) {
        if (!integration.trigger) continue;
        const re = new RegExp(`(?:^|\\s)${escapeRegExp(integration.trigger)}$`);
        if (before.match(re)) {
            return { integration, tokenStart: cursor - integration.trigger.length };
        }
    }
    return null;
}

function detectQuickReplyToken(value, cursor) {
    const before = value.slice(0, cursor);
    const match = before.match(QUICK_REPLY_TOKEN);
    if (!match) return null;
    return { query: match[1], tokenStart: cursor - match[1].length - 1 };
}

const MENTION_TOKEN = /(?:^|\s)@([a-zA-Z0-9_.À-ſ-]*)$/;

function detectMentionToken(value, cursor) {
    const before = value.slice(0, cursor);
    const match = before.match(MENTION_TOKEN);
    if (!match) return null;
    return { query: match[1], tokenStart: cursor - match[1].length - 1 };
}

// ─── StatusIcons Sub-component ───────────────────────────────────────────────

const StatusIcons = memo(({ status }) => {
    // 'pending' = persistido y encolado, aún sin confirmación de Meta: mismo
    // spinner que el optimista 'sending' para que se lea como "enviando".
    if (status === 'sending' || status === 'pending') return <Loader2 className="size-3 text-muted-foreground/40 animate-spin" />;
    if (status === 'failed') return <AlertTriangle className="size-3 text-red-500" />;
    if (status === 'sent') return <Check className="size-3 text-muted-foreground/40" />;
    if (status === 'delivered') return <CheckCheck className="size-3 text-muted-foreground/40" />;
    if (status === 'read') return <CheckCheck className="size-3 text-sky-400" />;
    return <Clock className="size-2.5 text-muted-foreground/30" />;
});

// ─── LazyDropdown ────────────────────────────────────────────────────────────
// Monta el menú Radix solo cuando se abre. En reposo es un botón simple, así con
// miles de filas no se montan miles de menús (cada uno con su contexto/refs).
const LazyDropdown = memo(function LazyDropdown({ renderTrigger, contentClassName, children }) {
    const [open, setOpen] = useState(false);
    if (!open) {
        return renderTrigger((e) => { e.stopPropagation(); setOpen(true); });
    }
    return (
        <DropdownMenu open onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                {renderTrigger((e) => e.stopPropagation())}
            </DropdownMenuTrigger>
            <DropdownMenuContent onClick={(e) => e.stopPropagation()} align="end" className={contentClassName}>
                {children()}
            </DropdownMenuContent>
        </DropdownMenu>
    );
});

// ─── ConversationItem Component ──────────────────────────────────────────────

const ConversationItem = memo(({
    conv, 
    isActive, 
    onSelect, 
    onAttachTag, 
    onDetachTag, 
    onNewTag, 
    onAssign,
    tags,
    companyUsers,
    isAdmin,
    formatTime,
    selectionMode,
    selected,
    onToggleSelect,
}) => {
    return (
        <div
            onClick={() => selectionMode ? onToggleSelect(conv.id) : onSelect(conv)}
            className={clsx(
                "flex items-center gap-3 px-4 py-3.5 cursor-pointer transition-all border-b border-border/5 group/conv",
                selected ? 'bg-teal-50 dark:bg-teal-950/30' : isActive ? 'bg-[#f0f2f5] dark:bg-[#2a3942]' : 'hover:bg-[#f5f6f6] dark:hover:bg-[#202c33]'
            )}
        >
            {selectionMode && (
                <div className={clsx(
                    "size-5 rounded-md border-2 flex items-center justify-center shrink-0 transition-colors",
                    selected ? "bg-teal-600 border-teal-600 text-white" : "border-muted-foreground/40"
                )}>
                    {selected && <Check className="size-3.5" />}
                </div>
            )}
            <div className="relative flex-shrink-0">
                <div className="size-12 rounded-full bg-[#dfe5e7] dark:bg-[#4f5659] flex items-center justify-center text-white font-bold text-lg overflow-hidden uppercase">
                    {conv.initials}
                </div>
                {conv.unread_count > 0 && (
                    <div className="absolute top-0 -right-1 bg-[#25d366] text-white rounded-full min-w-[20px] h-5 px-1 flex items-center justify-center text-[10px] font-bold border-2 border-white dark:border-[#111b21]">
                        {conv.unread_count}
                    </div>
                )}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between gap-2 mb-1">
                    <div className="flex items-center gap-2 min-w-0 flex-1">
                        {conv.status === 'closed' && (
                            <span title="Conversación cerrada" className="shrink-0 text-slate-400 dark:text-slate-500">
                                <CheckCircle2 className="size-3.5" />
                            </span>
                        )}
                        <p className={clsx(
                            "text-sm font-bold truncate",
                            conv.status === 'closed' ? "text-muted-foreground/70" : "text-foreground"
                        )}>
                            {conv.contact?.name || conv.name || conv.phone_number}
                        </p>
                        {conv.assigned_agent && (
                            <span 
                                title={`Asignado a ${conv.assigned_agent.name}`}
                                className="shrink-0 text-[7px] leading-none bg-teal-500/10 text-teal-600 dark:text-teal-400 px-1.5 py-1 rounded-md font-black uppercase tracking-tighter border border-teal-500/10"
                            >
                                {conv.assigned_agent.name.split(' ')[0]}
                            </span>
                        )}
                    </div>
                    
                    <div className="flex items-center gap-1 shrink-0 ml-auto">
                        <span className={`text-[10px] whitespace-nowrap ${conv.unread_count > 0 ? 'text-[#25d366] font-bold' : 'text-muted-foreground/60'}`}>
                            {formatTime(conv.last_message_at)}
                        </span>
                        
                        {/* Admin Assignment Picker */}
                        {isAdmin && (
                            <LazyDropdown
                                contentClassName="w-56 rounded-lg border-border/10 shadow-xl"
                                renderTrigger={(onClick) => (
                                    <button
                                        onClick={onClick}
                                        className={clsx(
                                            "p-1 opacity-0 group-hover/conv:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-all",
                                            conv.assigned_to ? "text-teal-600" : "text-muted-foreground/60 hover:text-teal-600"
                                        )}
                                        title={conv.assigned_agent?.name ? `Asignado a ${conv.assigned_agent.name}` : "Asignar agente"}
                                    >
                                        <UserPlus className="size-3" />
                                    </button>
                                )}
                            >
                                {() => (
                                    <>
                                        <DropdownMenuLabel className="text-[9px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-1.5">Asignar Responsable</DropdownMenuLabel>
                                        <DropdownMenuSeparator className="bg-border/5" />
                                        <DropdownMenuItem
                                            onClick={(e) => { e.stopPropagation(); onAssign(conv.id, null); }}
                                            className="flex items-center gap-2 py-2 px-3 cursor-pointer"
                                        >
                                            <XIcon className="size-3 text-muted-foreground" />
                                            <span className="text-[11px] font-bold flex-1">Sin Asignar</span>
                                            {!conv.assigned_to && <Check className="size-3 text-teal-600" />}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator className="bg-border/5" />
                                        <div className="max-h-48 overflow-y-auto">
                                            {companyUsers.map(u => (
                                                <DropdownMenuItem
                                                    key={u.id}
                                                    onClick={(e) => { e.stopPropagation(); onAssign(conv.id, u.id); }}
                                                    className="flex items-center gap-2.5 py-2 px-3 cursor-pointer group/user"
                                                >
                                                    <div className={clsx(
                                                        "size-2.5 rounded-full",
                                                        Number(conv.assigned_to) === Number(u.id) ? "bg-teal-600" : "bg-slate-200 dark:bg-slate-700"
                                                    )} />
                                                    <span className="text-[11px] font-bold flex-1">{u.name}</span>
                                                    {Number(conv.assigned_to) === Number(u.id) && <Check className="size-3 text-teal-600" />}
                                                </DropdownMenuItem>
                                            ))}
                                        </div>
                                    </>
                                )}
                            </LazyDropdown>
                        )}

                        {/* Tag Picker Button */}
                        <LazyDropdown
                            contentClassName="w-48 rounded-lg border-border/10 shadow-xl"
                            renderTrigger={(onClick) => (
                                <button
                                    onClick={onClick}
                                    className="p-1 opacity-0 group-hover/conv:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-all text-muted-foreground/60 hover:text-teal-600"
                                >
                                    <TagIcon className="size-3" />
                                </button>
                            )}
                        >
                            {() => (
                                <>
                                    <DropdownMenuLabel className="text-[9px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-1.5">Etiquetas</DropdownMenuLabel>
                                    <DropdownMenuSeparator className="bg-border/5" />
                                    <div className="max-h-48 overflow-y-auto">
                                        {tags.map(tag => {
                                            const hasTag = (conv.tags || []).some(t => t.id === tag.id);
                                            return (
                                                <DropdownMenuItem
                                                    key={tag.id}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        hasTag ? onDetachTag(conv.id, tag.id) : onAttachTag(conv.id, tag.id);
                                                    }}
                                                    className="flex items-center gap-2.5 py-2 px-3 cursor-pointer group/tag"
                                                >
                                                    <div className="size-2.5 rounded-full" style={{ backgroundColor: tag.color }} />
                                                    <span className="text-[11px] font-bold flex-1">{tag.name}</span>
                                                    {hasTag && <Check className="size-3 text-teal-600" />}
                                                </DropdownMenuItem>
                                            );
                                        })}
                                    </div>
                                    <DropdownMenuSeparator className="bg-border/5" />
                                    <DropdownMenuItem
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onNewTag(conv.id);
                                        }}
                                        className="flex items-center gap-2 py-2 px-3 cursor-pointer text-teal-600"
                                    >
                                        <PlusCircle className="size-3" />
                                        <span className="text-[11px] font-bold">Nueva Etiqueta</span>
                                    </DropdownMenuItem>
                                </>
                            )}
                        </LazyDropdown>
                    </div>
                </div>
                
                {(conv.tags || []).length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-1 mb-1">
                        {conv.tags.map(tag => (
                            <span 
                                key={tag.id} 
                                className="text-[8px] font-black px-1.5 py-0.5 rounded-full text-white uppercase tracking-tighter shadow-sm"
                                style={{ backgroundColor: tag.color }}
                            >
                                {tag.name}
                            </span>
                        ))}
                    </div>
                )}

                <div className="flex items-center gap-1 mt-0.5">
                    {isActive && <StatusIcons status="read" />}
                    <div className="flex-1 flex items-center gap-1.5 min-w-0">
                        {conv.assigned_agent && (
                            <span className="text-[9px] font-black text-teal-600/60 uppercase tracking-tighter whitespace-nowrap">
                                @{conv.assigned_agent.name.split(' ')[0]}:
                            </span>
                        )}
                        <p className="text-xs text-muted-foreground truncate leading-relaxed">
                            {conv.last_message || '...'}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
});

// Estilo estable para el wrapper de cada fila (evita recrear el objeto en cada
// render y permite que el navegador omita el layout/paint de filas fuera de vista).
const LIST_ITEM_STYLE = { contentVisibility: 'auto', containIntrinsicSize: '0 80px' };

// ─── ConversationList ────────────────────────────────────────────────────────
// Lista memoizada: con miles de conversaciones, este componente NO se vuelve a
// renderizar mientras escribes en el compositor (sus props no cambian), evitando
// recrear/reconciliar miles de elementos en cada pulsación.
const ConversationList = memo(function ConversationList({
    conversations,
    selectedId,
    onSelect,
    onAttachTag,
    onDetachTag,
    onNewTag,
    onAssign,
    tags,
    companyUsers,
    isAdmin,
    formatTime,
    selectionMode,
    selectedIds,
    onToggleSelect,
}) {
    return conversations.map(conv => (
        <div key={conv.id} style={LIST_ITEM_STYLE}>
            <ConversationItem
                conv={conv}
                isActive={selectedId === conv.id}
                onSelect={onSelect}
                onAttachTag={onAttachTag}
                onDetachTag={onDetachTag}
                onNewTag={onNewTag}
                onAssign={onAssign}
                tags={tags}
                companyUsers={companyUsers}
                isAdmin={isAdmin}
                formatTime={formatTime}
                StatusIcons={StatusIcons}
                selectionMode={selectionMode}
                selected={selectionMode && selectedIds.has(conv.id)}
                onToggleSelect={onToggleSelect}
            />
        </div>
    ));
});

// ─── Main Component ──────────────────────────────────────────────────────────

// ─── PaymentModal: pagos a facturas (integración Integra 2.0) ────────────────
// Flujo: buscar cliente (auto-busca con el teléfono de la conversación) →
// facturas pendientes del cliente → registrar el pago de la factura elegida
// (cuenta + método de pago de los catálogos del entorno Integra).

// WhatsApp entrega números con indicativo (573001234567); Integra guarda el
// celular de 10 dígitos. Nos quedamos con los últimos 10.
function normalizePhoneForIntegra(phone) {
    const digits = String(phone ?? '').replace(/\D+/g, '');
    return digits.length > 10 ? digits.slice(-10) : digits;
}

function formatCOP(value) {
    if (value == null || isNaN(Number(value))) return null;
    return '$' + Number(value).toLocaleString('es-CO');
}

function PaymentModal({ integration, conversation, onClose }) {
    const initialPhone = normalizePhoneForIntegra(conversation?.phone_number);

    // Paso 1: búsqueda de cliente
    const [query, setQuery] = useState(initialPhone);
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);
    const [searched, setSearched] = useState(false);
    const [searchError, setSearchError] = useState(null);
    const [selected, setSelected] = useState(null);   // cliente Integra elegido

    // Paso 2: facturas pendientes
    const [invoices, setInvoices] = useState(null);   // respuesta de /invoices
    const [loadingInvoices, setLoadingInvoices] = useState(false);
    const [invoicesError, setInvoicesError] = useState(null);
    const [invoice, setInvoice] = useState(null);     // factura elegida

    // Paso 3: registro del pago
    const [catalogs, setCatalogs] = useState(null);   // cuentas + métodos de pago
    const [catalogsError, setCatalogsError] = useState(null);
    const [cuenta, setCuenta] = useState('');
    const [metodoPago, setMetodoPago] = useState('');
    const [monto, setMonto] = useState('');
    const [observaciones, setObservaciones] = useState('');
    const [saving, setSaving] = useState(false);
    const [payError, setPayError] = useState(null);
    const [success, setSuccess] = useState(null);

    const searchTimer = useRef(null);
    const autoSelectRef = useRef(true); // en la búsqueda inicial por teléfono, si hay 1 resultado lo elegimos solos

    async function runSearch(q) {
        setSearching(true);
        setSearchError(null);
        try {
            const { data } = await axios.get('/api/integrations/invoice-payments/clients', { params: { search: q } });
            const list = data.data ?? [];
            setResults(list);
            setSearched(true);
            if (autoSelectRef.current && list.length === 1) {
                selectClient(list[0]);
            }
        } catch (err) {
            setResults([]);
            setSearched(true);
            setSearchError(err?.response?.data?.message ?? 'No se pudo buscar en Integra.');
        } finally {
            autoSelectRef.current = false;
            setSearching(false);
        }
    }

    // Búsqueda con debounce; al abrir, busca de una vez por el teléfono del chat.
    useEffect(() => {
        if (selected) return;
        const q = query.trim();
        if (q.length < 2) { setResults([]); setSearched(false); return; }
        if (searchTimer.current) clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => runSearch(q), autoSelectRef.current ? 0 : 350);
        return () => searchTimer.current && clearTimeout(searchTimer.current);
    }, [query, selected]);

    async function fetchInvoices(client) {
        setLoadingInvoices(true);
        setInvoicesError(null);
        setInvoices(null);
        try {
            const params = client.id ? { cliente_id: client.id } : { nit: client.nit };
            const { data } = await axios.get('/api/integrations/invoice-payments/invoices', { params });
            if (!data.found) {
                setInvoicesError(data.message ?? 'El cliente no existe en Integra.');
            } else {
                setInvoices(data);
            }
        } catch (err) {
            setInvoicesError(err?.response?.data?.message ?? 'No se pudieron consultar las facturas en Integra.');
        } finally {
            setLoadingInvoices(false);
        }
    }

    function selectClient(c) {
        setSelected(c);
        setInvoice(null);
        setPayError(null);
        setSuccess(null);
        fetchInvoices(c);
    }

    function backToSearch() {
        setSelected(null);
        setInvoices(null);
        setInvoicesError(null);
        setInvoice(null);
        setPayError(null);
        setSuccess(null);
    }

    async function selectInvoice(f) {
        setInvoice(f);
        setPayError(null);
        setMonto(String(f.montos?.por_pagar ?? ''));
        setObservaciones('');
        // Catálogos del entorno (cuentas y métodos): una sola vez por modal.
        if (!catalogs) {
            try {
                const { data } = await axios.get('/api/integrations/invoice-payments/catalogs');
                setCatalogs(data);
                setCatalogsError(null);
                if ((data.cuentas ?? []).length === 1) setCuenta(String(data.cuentas[0].id));
                if ((data.metodos_pago ?? []).length === 1) setMetodoPago(String(data.metodos_pago[0].id));
            } catch (err) {
                setCatalogsError(err?.response?.data?.message ?? 'No se pudieron cargar los catálogos de pago.');
            }
        }
    }

    function backToInvoices({ refresh = false } = {}) {
        setInvoice(null);
        setPayError(null);
        setSuccess(null);
        if (refresh && selected) fetchInvoices(selected);
    }

    async function submit(e) {
        e.preventDefault();
        setPayError(null);
        if (!cuenta) { setPayError('Selecciona la cuenta/banco donde entra el pago.'); return; }
        if (!metodoPago) { setPayError('Selecciona el método de pago.'); return; }
        if (!monto || Number(monto) <= 0) { setPayError('Ingresa un valor mayor a 0.'); return; }
        setSaving(true);
        try {
            const { data } = await axios.post('/api/integrations/invoice-payments/pay', {
                factura_id: invoice.id,
                cuenta: Number(cuenta),
                metodo_pago: Number(metodoPago),
                monto: Number(monto),
                observaciones: observaciones.trim() || null,
            });
            setSuccess(data.result ?? { ok: true });
        } catch (err) {
            setPayError(err?.response?.data?.message ?? 'No se pudo registrar el pago.');
        } finally {
            setSaving(false);
        }
    }

    const clienteMeta = invoices?.cliente;
    const facturas = invoices?.facturas ?? [];

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200" onClick={onClose}>
            <div className="w-full max-w-lg max-h-[90vh] flex flex-col rounded-3xl border border-border/10 bg-white dark:bg-[#1c272e] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200" onClick={e => e.stopPropagation()}>
                {/* Header */}
                <div className="relative bg-gradient-to-br from-teal-600 to-emerald-600 px-6 py-5 text-white shrink-0">
                    <button onClick={onClose} className="absolute top-4 right-4 p-1.5 hover:bg-white/15 rounded-full transition-colors">
                        <XIcon className="size-4" />
                    </button>
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-2xl bg-white/15 flex items-center justify-center">
                            <Wallet className="size-6" />
                        </div>
                        <div>
                            <h3 className="font-bold text-lg leading-tight">{integration?.name ?? 'Pagos a facturas'}</h3>
                            <p className="text-xs text-white/80">
                                {success ? 'Pago registrado en Integra'
                                    : invoice ? `Registrar pago · Factura ${invoice.codigo ?? invoice.id}`
                                    : selected ? 'Facturas pendientes del cliente'
                                    : 'Busca el cliente por teléfono, cédula o nombre'}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="overflow-y-auto">
                {success ? (
                    /* ── Éxito ── */
                    <div className="px-6 py-8 text-center space-y-4">
                        <div className="mx-auto size-14 rounded-full bg-emerald-500/15 flex items-center justify-center">
                            <CheckCircle2 className="size-8 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div className="space-y-1">
                            <p className="font-semibold text-foreground">Pago registrado</p>
                            {success.recibo_caja != null && (
                                <p className="text-sm text-muted-foreground">Recibo de caja: <span className="font-mono font-medium text-foreground">#{success.recibo_caja}</span></p>
                            )}
                            {success.monto_aplicado != null && (
                                <p className="text-sm text-muted-foreground">Monto aplicado: <span className="font-medium text-foreground">{formatCOP(success.monto_aplicado)}</span></p>
                            )}
                            {success.factura_estado && (
                                <p className="text-sm text-muted-foreground">
                                    Factura: <span className={`font-medium ${success.factura_estado === 'cerrada' ? 'text-emerald-600 dark:text-emerald-400' : 'text-foreground'}`}>{success.factura_estado}</span>
                                    {success.factura_por_pagar != null && Number(success.factura_por_pagar) > 0 && (
                                        <> · queda por pagar <span className="font-medium text-foreground">{formatCOP(success.factura_por_pagar)}</span></>
                                    )}
                                </p>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <button onClick={() => backToInvoices({ refresh: true })} className="flex-1 rounded-xl border border-border/70 py-2.5 text-sm font-medium text-foreground hover:bg-muted/40 transition-colors">
                                Registrar otro pago
                            </button>
                            <button onClick={onClose} className="flex-1 rounded-xl bg-teal-600 hover:bg-teal-500 text-white font-medium py-2.5 transition-colors">
                                Listo
                            </button>
                        </div>
                    </div>
                ) : !selected ? (
                    /* ── Paso 1: búsqueda de cliente ── */
                    <div className="px-6 py-5 space-y-3">
                        <div className="relative">
                            <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <input
                                value={query}
                                onChange={e => setQuery(e.target.value)}
                                placeholder="Celular, cédula/NIT o nombre del cliente"
                                autoFocus
                                className="w-full rounded-xl border border-border/70 bg-background/80 pl-10 pr-10 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500/50"
                            />
                            {searching && <Loader2 className="absolute right-3 top-1/2 -translate-y-1/2 size-4 animate-spin text-muted-foreground" />}
                        </div>
                        {initialPhone && query === initialPhone && (
                            <p className="text-[11px] text-muted-foreground flex items-center gap-1.5">
                                <Phone className="size-3" /> Buscando por el número de esta conversación.
                            </p>
                        )}

                        {searchError && (
                            <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                                <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                <span>{searchError}</span>
                            </div>
                        )}

                        {results.length > 0 && (
                            <div className="rounded-xl border border-border/60 divide-y divide-border/60 overflow-hidden">
                                {results.map(c => (
                                    <button
                                        type="button"
                                        key={c.id ?? c.nit}
                                        onClick={() => selectClient(c)}
                                        className="w-full text-left px-3.5 py-2.5 hover:bg-muted/50 transition-colors flex items-center gap-3"
                                    >
                                        <div className="size-9 rounded-full bg-teal-500/10 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0">
                                            <User className="size-4" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium text-foreground truncate">{c.nombre || 'Sin nombre'}</p>
                                            <p className="text-xs text-muted-foreground truncate">
                                                {[c.nit && `CC/NIT ${c.nit}`, c.celular && `Cel ${c.celular}`].filter(Boolean).join(' · ') || 'Sin datos de contacto'}
                                            </p>
                                        </div>
                                        {c.total_por_pagar != null && (
                                            <span className={`shrink-0 text-xs font-semibold tabular-nums ${Number(c.total_por_pagar) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
                                                {Number(c.total_por_pagar) > 0 ? `Debe ${formatCOP(c.total_por_pagar)}` : 'Al día'}
                                            </span>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}

                        {searched && !searching && !searchError && results.length === 0 && (
                            <div className="rounded-xl border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
                                Sin resultados en Integra. Prueba con la cédula o el nombre del cliente.
                            </div>
                        )}
                    </div>
                ) : !invoice ? (
                    /* ── Paso 2: facturas pendientes ── */
                    <div className="px-6 py-5 space-y-3">
                        <div className="flex items-center gap-3">
                            <button type="button" onClick={backToSearch} title="Cambiar cliente" className="p-1.5 rounded-full hover:bg-muted/60 transition-colors shrink-0">
                                <ArrowLeft className="size-4 text-muted-foreground" />
                            </button>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-foreground truncate">{clienteMeta?.nombre ?? selected.nombre ?? 'Sin nombre'}</p>
                                <p className="text-xs text-muted-foreground truncate">
                                    {[(clienteMeta?.identificacion ?? selected.nit) && `CC/NIT ${clienteMeta?.identificacion ?? selected.nit}`, (clienteMeta?.celular ?? selected.celular) && `Cel ${clienteMeta?.celular ?? selected.celular}`].filter(Boolean).join(' · ')}
                                </p>
                            </div>
                            {invoices && facturas.length > 0 && (
                                <div className="text-right shrink-0">
                                    <p className="text-[10px] text-muted-foreground">Total por pagar</p>
                                    <p className="text-sm font-bold text-rose-600 dark:text-rose-400 tabular-nums">{formatCOP(invoices.total_por_pagar)}</p>
                                </div>
                            )}
                        </div>

                        {loadingInvoices && (
                            <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                                <Loader2 className="size-4 animate-spin" /> Consultando facturas en Integra…
                            </div>
                        )}

                        {invoicesError && !loadingInvoices && (
                            <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-sm text-amber-700 dark:text-amber-300 flex items-start gap-2">
                                <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                <span>{invoicesError}</span>
                            </div>
                        )}

                        {invoices && !loadingInvoices && facturas.length === 0 && (
                            <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-6 text-center space-y-1.5">
                                <CheckCircle2 className="size-7 mx-auto text-emerald-600 dark:text-emerald-400" />
                                <p className="text-sm font-medium text-emerald-700 dark:text-emerald-300">Cliente al día</p>
                                <p className="text-xs text-muted-foreground">No tiene facturas pendientes por pagar en Integra.</p>
                            </div>
                        )}

                        {facturas.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-[11px] text-muted-foreground">
                                    {facturas.length} factura{facturas.length > 1 ? 's' : ''} pendiente{facturas.length > 1 ? 's' : ''} — toca una para registrar su pago.
                                </p>
                                {facturas.map(f => (
                                    <button
                                        type="button"
                                        key={f.id}
                                        onClick={() => selectInvoice(f)}
                                        className="w-full text-left rounded-xl border border-border/60 hover:border-teal-500/50 hover:bg-teal-500/5 transition-colors px-3.5 py-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="flex items-center gap-2 min-w-0">
                                                <Receipt className="size-4 text-muted-foreground shrink-0" />
                                                <span className="text-sm font-medium text-foreground font-mono truncate">{f.codigo ?? `#${f.id}`}</span>
                                                {f.vencida && (
                                                    <span className="rounded-full bg-rose-500/15 text-rose-600 dark:text-rose-400 px-2 py-0.5 text-[10px] font-semibold shrink-0">Vencida</span>
                                                )}
                                            </div>
                                            <span className="text-sm font-bold text-foreground tabular-nums shrink-0">{formatCOP(f.montos?.por_pagar)}</span>
                                        </div>
                                        <div className="flex items-center justify-between gap-2 mt-1 text-[11px] text-muted-foreground">
                                            <span className="truncate">
                                                {[f.vencimiento && `Vence ${f.vencimiento}`, f.contratos?.[0]?.plan].filter(Boolean).join(' · ')}
                                            </span>
                                            {Number(f.montos?.pagado ?? 0) > 0 && (
                                                <span className="shrink-0">Abonado {formatCOP(f.montos.pagado)} de {formatCOP(f.montos.total)}</span>
                                            )}
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                ) : (
                    /* ── Paso 3: registrar el pago ── */
                    <div className="px-6 py-5 space-y-4">
                        <div className="flex items-center gap-3">
                            <button type="button" onClick={() => backToInvoices()} title="Volver a facturas" className="p-1.5 rounded-full hover:bg-muted/60 transition-colors shrink-0">
                                <ArrowLeft className="size-4 text-muted-foreground" />
                            </button>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-foreground truncate font-mono">{invoice.codigo ?? `Factura #${invoice.id}`}</p>
                                <p className="text-xs text-muted-foreground truncate">{clienteMeta?.nombre ?? selected.nombre}</p>
                            </div>
                            <div className="text-right shrink-0">
                                <p className="text-[10px] text-muted-foreground">Por pagar</p>
                                <p className="text-sm font-bold text-rose-600 dark:text-rose-400 tabular-nums">{formatCOP(invoice.montos?.por_pagar)}</p>
                            </div>
                        </div>

                        {catalogsError && (
                            <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                                <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                <span>{catalogsError}</span>
                            </div>
                        )}

                        <form onSubmit={submit} className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="text-sm font-medium text-foreground mb-1.5 block">Cuenta / banco</label>
                                    <select
                                        value={cuenta}
                                        onChange={e => setCuenta(e.target.value)}
                                        className="w-full rounded-xl border border-border/70 bg-background/80 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                    >
                                        <option value="">Selecciona…</option>
                                        {(catalogs?.cuentas ?? []).map(c => (
                                            <option key={c.id} value={c.id}>{c.nombre}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="text-sm font-medium text-foreground mb-1.5 block">Método de pago</label>
                                    <select
                                        value={metodoPago}
                                        onChange={e => setMetodoPago(e.target.value)}
                                        className="w-full rounded-xl border border-border/70 bg-background/80 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                    >
                                        <option value="">Selecciona…</option>
                                        {(catalogs?.metodos_pago ?? []).map(m => (
                                            <option key={m.id} value={m.id}>{m.nombre}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="text-sm font-medium text-foreground flex items-center gap-1.5 mb-1.5">
                                    <DollarSign className="size-3.5 text-muted-foreground" /> Valor a pagar
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    step="any"
                                    inputMode="decimal"
                                    value={monto}
                                    onChange={e => setMonto(e.target.value)}
                                    className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500/50"
                                />
                                <p className="text-[11px] text-muted-foreground mt-1">
                                    Si el valor supera el saldo, Integra lo topa al saldo pendiente. Si cubre el total, la factura se cierra y se reactiva el servicio.
                                </p>
                            </div>

                            <div>
                                <label className="text-sm font-medium text-foreground mb-1.5 block">Observaciones <span className="text-xs font-normal text-muted-foreground">· Opcional</span></label>
                                <input
                                    type="text"
                                    value={observaciones}
                                    onChange={e => setObservaciones(e.target.value)}
                                    maxLength={255}
                                    placeholder="Pago recibido por WhatsApp"
                                    className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500/50"
                                />
                            </div>

                            {payError && (
                                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                                    <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                    <span>{payError}</span>
                                </div>
                            )}

                            <div className="flex gap-2 pt-1">
                                <button type="button" onClick={() => backToInvoices()} className="flex-1 rounded-xl border border-border/70 py-2.5 text-sm font-medium text-foreground hover:bg-muted/40 transition-colors">
                                    Volver
                                </button>
                                <button type="submit" disabled={saving} className="flex-1 rounded-xl bg-teal-600 hover:bg-teal-500 text-white py-2.5 text-sm font-medium flex items-center justify-center gap-2 transition-colors disabled:opacity-60">
                                    {saving ? <Loader2 className="size-4 animate-spin" /> : <CreditCard className="size-4" />}
                                    Registrar pago
                                </button>
                            </div>
                        </form>
                    </div>
                )}
                </div>
            </div>
        </div>
    );
}

export default function ChatIndex({ instances, integrations = [] }) {
    const { auth } = usePage().props;
    
    // Helper to check permissions
    const can = (permission) => auth.user.permissions.includes(permission);
    const isAdmin = can('chat.update');

    const [selectedInstanceId, setSelectedInstanceId] = useState('');
    const [conversations, setConversations] = useState([]);
    const [messages, setMessages] = useState([]);
    const [selectedConversation, setSelectedConversation] = useState(null);
    const [newMessage, setNewMessage] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState(null);
    const [pendingImage, setPendingImage] = useState(null); // { file, url } imagen a previsualizar antes de enviar
    const [imageCaption, setImageCaption] = useState('');
    const [isDraggingFile, setIsDraggingFile] = useState(false);
    const dragDepthRef = useRef(0); // cuenta dragenter/dragleave para evitar parpadeo del overlay
    const [replyingTo, setReplyingTo] = useState(null); // mensaje al que se está respondiendo (cita)
    const [showLinkContact, setShowLinkContact] = useState(false);
    const [showContactPanel, setShowContactPanel] = useState(false);
    const [editingContact, setEditingContact] = useState(false);
    const [contactForm, setContactForm] = useState({ name: '', email: '', notes: '' });
    const [savingContact, setSavingContact] = useState(false);
    const [contactError, setContactError] = useState(null);
    const [showTemplates, setShowTemplates] = useState(false);
    const [showCallHistory, setShowCallHistory] = useState(false);
    const [incomingCall, setIncomingCall] = useState(null);
    const [selectionMode, setSelectionMode] = useState(false);
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [lastUpdateTimestamp, setLastUpdateTimestamp] = useState(null);
    const [lastUpdate, setLastUpdate] = useState('Nunca');
    const [isPolling, setIsPolling] = useState(false);
    const [selectedImage, setSelectedImage] = useState(null);
    const [selectedLocation, setSelectedLocation] = useState(null); // { latitude, longitude, name, address }
    const [filterMyAssignments, setFilterMyAssignments] = useState(false);
    const [assignmentTab, setAssignmentTab] = useState('all'); // 'mine' | 'unassigned' | 'all'
    const [folder, setFolder] = useState('all'); // 'all' | 'mentions' | 'unattended'
    const [folderCounts, setFolderCounts] = useState({ all: 0, mentions: 0, unattended: 0, tags: {} });
    // Filtro de estado (estilo Chatwoot): 'open' | 'closed' | 'all'
    const [statusFilter, setStatusFilter] = useState('open');
    // Orden de la lista: 'last_activity' | 'newest' | 'oldest'
    const [sortBy, setSortBy] = useState('last_activity');
    // Popover "Filtrar conversaciones" + sus filas en edición (borrador)
    const [filterOpen, setFilterOpen] = useState(false);
    const [draftFilters, setDraftFilters] = useState([{ field: 'estado', value: 'open' }]);

    // Nuevo chat hacia un número directo
    const [newChatOpen, setNewChatOpen] = useState(false);
    const [newChatStep, setNewChatStep] = useState('phone'); // 'phone' | 'template'
    const [newChatPhone, setNewChatPhone] = useState('');
    const [newChatName, setNewChatName] = useState('');
    const [newChatLoading, setNewChatLoading] = useState(false);
    const [newChatError, setNewChatError] = useState(null);
    const [newChatConv, setNewChatConv] = useState(null);
    const [chatTemplates, setChatTemplates] = useState([]);
    const [templatesLoading, setTemplatesLoading] = useState(false);
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [templateVars, setTemplateVars] = useState([]);
    // Encabezado multimedia de la plantilla seleccionada al enviar:
    // { format, mediaId, filename, uploading, error, lat, lng, name, address }
    const [templateHeader, setTemplateHeader] = useState(null);
    const [tags, setTags] = useState([]);
    const [selectedTagIds, setSelectedTagIds] = useState([]); // ids (string) de etiquetas filtradas (OR)
    const [editingTag, setEditingTag] = useState(null); // {id, name, color} cuando se edita una etiqueta
    const [selectedAgentId, setSelectedAgentId] = useState('');
    const [agentFilterQuery, setAgentFilterQuery] = useState('');
    const [isCreatingTag, setIsCreatingTag] = useState(false);
    const [newTagName, setNewTagName] = useState('');
    const [newTagColor, setNewTagColor] = useState('#0d9488');
    const [taggingConversationId, setTaggingConversationId] = useState(null);
    const [companyUsers, setCompanyUsers] = useState([]);

    // Recording States
    const [isRecording, setIsRecording] = useState(false);
    const [recordingDuration, setRecordingDuration] = useState(0);
    const [recordedAudio, setRecordedAudio] = useState(null); // { blob, url, type } — preview antes de enviar
    const mediaRecorderRef = useRef(null);
    const audioChunksRef = useRef([]);
    const recordingIntervalRef = useRef(null);

    const [quickReplies, setQuickReplies] = useState([]);
    const [qrOpen, setQrOpen] = useState(false);
    const [qrQuery, setQrQuery] = useState('');
    const [qrTokenStart, setQrTokenStart] = useState(0);
    const [qrIndex, setQrIndex] = useState(0);

    // Internal notes + @mentions
    const [composerMode, setComposerMode] = useState('reply'); // 'reply' | 'note'
    const [paymentModal, setPaymentModal] = useState(null);
    const [noteMentions, setNoteMentions] = useState([]); // [{id, name}]
    const [mentionOpen, setMentionOpen] = useState(false);
    const [mentionQuery, setMentionQuery] = useState('');
    const [mentionTokenStart, setMentionTokenStart] = useState(0);
    const [mentionIndex, setMentionIndex] = useState(0);

    const messagesContainerRef = useRef(null);
    // Cuando abrimos una conversación, forzamos el scroll al último mensaje en el
    // render en que llegan los mensajes (independiente de dónde esté el scroll).
    const forceScrollRef = useRef(false);
    // Si el usuario está pegado al fondo (para seguir mensajes/medios nuevos sin
    // arrancarlo cuando lee el historial más arriba).
    const atBottomRef = useRef(true);
    const pollingIntervalRef = useRef(null);
    const messageInputRef = useRef(null);
    const tempMsgCounter = useRef(0);
    // Espejo de selectedConversation para leerlo dentro del listener de Reverb
    // sin recrear la suscripción cada vez que cambia la conversación abierta.
    const selectedConversationRef = useRef(null);

    // ── Callbacks for Performance ──────────────────────────────────────────

    const loadTags = useCallback(async () => {
        try {
            const res = await axios.get('/api/tags');
            setTags(res.data);
        } catch (err) {
            console.error('Error cargando etiquetas:', err);
        }
    }, []);

    const loadUsers = useCallback(async () => {
        try {
            const res = await axios.get('/api/chat/users');
            setCompanyUsers(res.data);
        } catch (err) {
            console.error('Error cargando usuarios:', err);
        }
    }, []);

    const loadQuickReplies = useCallback(async () => {
        try {
            const res = await axios.get('/api/quick-replies');
            setQuickReplies(res.data);
        } catch (err) {
            console.error('Error cargando respuestas rápidas:', err);
        }
    }, []);

    const loadFolderCounts = useCallback(async () => {
        if (!selectedInstanceId) return;
        try {
            const res = await axios.get('/api/chat/folders', { params: { instance_id: selectedInstanceId } });
            setFolderCounts(res.data);
        } catch (err) {
            console.error('Error cargando contadores:', err);
        }
    }, [selectedInstanceId]);

    // ── Nuevo chat hacia un número directo ──────────────────────────────────
    const openNewChat = useCallback(() => {
        setNewChatStep('phone');
        setNewChatPhone('');
        setNewChatName('');
        setNewChatError(null);
        setNewChatConv(null);
        setSelectedTemplate(null);
        setTemplateVars([]);
        setNewChatOpen(true);
    }, []);

    const closeNewChat = useCallback(() => {
        setNewChatOpen(false);
        setNewChatLoading(false);
        setSelectedTemplate(null);
        setTemplateVars([]);
        setTemplateHeader(null);
    }, []);

    // Inserta/abre una conversación ya cargada (con sus mensajes) en la vista.
    const openConversationLocal = useCallback((conv, msgs) => {
        forceScrollRef.current = true;
        setSelectedConversation(conv);
        setMessages(msgs || []);
        setLastUpdateTimestamp(new Date().toISOString());
        setConversations(prev => (
            prev.some(c => c.id === conv.id)
                ? prev.map(c => (c.id === conv.id ? { ...c, ...conv } : c))
                : [conv, ...prev]
        ));
    }, []);

    const loadChatTemplates = useCallback(async () => {
        if (!selectedInstanceId) return;
        setTemplatesLoading(true);
        try {
            const res = await axios.get('/api/chat/templates', { params: { instance_id: selectedInstanceId } });
            const approved = (res.data.data || []).filter(t => (t.status || '').toUpperCase() === 'APPROVED');
            setChatTemplates(approved);
        } catch (err) {
            console.error('Error cargando plantillas:', err);
            setChatTemplates([]);
        } finally {
            setTemplatesLoading(false);
        }
    }, [selectedInstanceId]);

    const startNewChat = useCallback(async () => {
        if (!selectedInstanceId) { setNewChatError('Selecciona primero una instancia.'); return; }
        const digits = newChatPhone.replace(/\D/g, '');
        if (digits.length < 8) { setNewChatError('Número inválido. Incluye el código de país (ej: 57300...).'); return; }
        setNewChatError(null);
        setNewChatLoading(true);
        try {
            const res = await axios.post('/api/chat/conversations/start', {
                instance_id: selectedInstanceId,
                phone_number: digits,
                name: newChatName.trim() || null,
            });
            const { conversation, messages: msgs, session_open } = res.data;
            // Abre la conversación detrás del modal en cualquier caso.
            openConversationLocal(conversation, msgs);
            loadFolderCounts();
            if (session_open) {
                closeNewChat();
                requestAnimationFrame(() => messageInputRef.current?.focus());
            } else {
                // Fuera de la ventana de 24h: hay que abrir con una plantilla.
                setNewChatConv(conversation);
                setNewChatStep('template');
                loadChatTemplates();
            }
        } catch (err) {
            setNewChatError(err?.response?.data?.error || 'No se pudo iniciar la conversación.');
        } finally {
            setNewChatLoading(false);
        }
    }, [selectedInstanceId, newChatPhone, newChatName, openConversationLocal, loadFolderCounts, closeNewChat, loadChatTemplates]);

    const pickTemplate = useCallback((t) => {
        setSelectedTemplate(t);
        const body = templateBodyComponent(t);
        const n = countTemplateVars(body?.text);
        setTemplateVars(Array.from({ length: n }, () => ''));
        const fmt = templateHeaderFormat(t);
        setTemplateHeader(fmt
            ? { format: fmt, mediaId: '', filename: '', uploading: false, error: '', lat: '', lng: '', name: '', address: '' }
            : null);
    }, []);

    const uploadTemplateHeaderFile = useCallback(async (file) => {
        if (!file || !newChatConv) return;
        setTemplateHeader(h => ({ ...h, uploading: true, error: '', mediaId: '', filename: file.name }));
        try {
            const fd = new FormData();
            fd.append('file', file);
            const res = await axios.post(`/api/chat/conversations/${newChatConv.id}/template-media`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setTemplateHeader(h => ({ ...h, uploading: false, mediaId: res.data.media_id, filename: res.data.filename || file.name }));
        } catch (err) {
            setTemplateHeader(h => ({ ...h, uploading: false, mediaId: '', error: err?.response?.data?.error || 'No se pudo subir el archivo a Meta.' }));
        }
    }, [newChatConv]);

    const sendNewChatTemplate = useCallback(async () => {
        if (!selectedTemplate || !newChatConv) return;
        const body = templateBodyComponent(selectedTemplate);
        const n = countTemplateVars(body?.text);
        if (templateVars.some((v, i) => i < n && !v.trim())) {
            setNewChatError('Completa todas las variables de la plantilla.');
            return;
        }
        if (templateHeader) {
            if (templateHeader.format === 'LOCATION') {
                if (!String(templateHeader.lat).trim() || !String(templateHeader.lng).trim()) {
                    setNewChatError('Completa la latitud y longitud del encabezado de ubicación.');
                    return;
                }
            } else if (!templateHeader.mediaId) {
                setNewChatError('Sube el archivo del encabezado de la plantilla.');
                return;
            }
        }
        setNewChatError(null);
        setNewChatLoading(true);
        const components = [];
        const headerComp = buildTemplateHeaderComponent(templateHeader);
        if (headerComp) components.push(headerComp);
        if (n > 0) {
            components.push({ type: 'body', parameters: templateVars.slice(0, n).map(v => ({ type: 'text', text: v })) });
        }
        const preview = fillTemplate(body?.text, templateVars);
        try {
            const res = await axios.post(`/api/chat/conversations/${newChatConv.id}/send-template`, {
                template_name: selectedTemplate.name,
                language_code: selectedTemplate.language,
                components,
                preview,
            });
            if (res.data.success) {
                setMessages(prev => [...prev, res.data.data]);
                setConversations(prev => prev.map(c => c.id === newChatConv.id ? { ...c, last_message: preview, last_message_at: new Date().toISOString() } : c));
                closeNewChat();
                requestAnimationFrame(() => messageInputRef.current?.focus());
            }
        } catch (err) {
            setNewChatError(err?.response?.data?.error || 'No se pudo enviar la plantilla.');
        } finally {
            setNewChatLoading(false);
        }
    }, [selectedTemplate, newChatConv, templateVars, templateHeader, closeNewChat]);

    useEffect(() => {
        loadTags();
        loadUsers();
        loadQuickReplies();
    }, [loadTags, loadUsers, loadQuickReplies]);

    const qrMatches = useMemo(() => {
        if (!qrOpen) return [];
        const q = qrQuery.toLowerCase();
        const filtered = q
            ? quickReplies.filter(r => r.shortcut.toLowerCase().startsWith(q))
            : quickReplies;
        return filtered.slice(0, 8);
    }, [qrOpen, qrQuery, quickReplies]);

    useEffect(() => {
        if (qrIndex >= qrMatches.length) setQrIndex(0);
    }, [qrMatches.length, qrIndex]);

    const closeQuickReplies = useCallback(() => {
        setQrOpen(false);
        setQrQuery('');
        setQrIndex(0);
    }, []);

    const updateQuickReplyState = useCallback((value, cursor) => {
        const detected = detectQuickReplyToken(value, cursor);
        if (!detected) {
            if (qrOpen) closeQuickReplies();
            return;
        }
        setQrOpen(true);
        setQrQuery(detected.query);
        setQrTokenStart(detected.tokenStart);
        setQrIndex(0);
    }, [qrOpen, closeQuickReplies]);

    const applyQuickReply = useCallback((reply) => {
        const input = messageInputRef.current;
        const cursor = input ? input.selectionStart ?? newMessage.length : newMessage.length;
        const before = newMessage.slice(0, qrTokenStart);
        const after = newMessage.slice(cursor);
        const next = `${before}${reply.message}${after}`;
        setNewMessage(next);
        closeQuickReplies();
        requestAnimationFrame(() => {
            const el = messageInputRef.current;
            if (!el) return;
            el.focus();
            const pos = before.length + reply.message.length;
            try { el.setSelectionRange(pos, pos); } catch (_) {}
        });
    }, [newMessage, qrTokenStart, closeQuickReplies]);

    const mentionMatches = useMemo(() => {
        if (!mentionOpen) return [];
        const q = mentionQuery.toLowerCase();
        const list = q
            ? companyUsers.filter(u => u.name.toLowerCase().includes(q))
            : companyUsers;
        return list.slice(0, 8);
    }, [mentionOpen, mentionQuery, companyUsers]);

    useEffect(() => {
        if (mentionIndex >= mentionMatches.length) setMentionIndex(0);
    }, [mentionMatches.length, mentionIndex]);

    const closeMentions = useCallback(() => {
        setMentionOpen(false);
        setMentionQuery('');
        setMentionIndex(0);
    }, []);

    const applyMention = useCallback((user) => {
        const input = messageInputRef.current;
        const cursor = input ? input.selectionStart ?? newMessage.length : newMessage.length;
        const before = newMessage.slice(0, mentionTokenStart);
        const after = newMessage.slice(cursor);
        const insert = `@${user.name} `;
        const next = `${before}${insert}${after}`;
        setNewMessage(next);
        setNoteMentions(prev => (prev.some(m => m.id === user.id) ? prev : [...prev, { id: user.id, name: user.name }]));
        closeMentions();
        requestAnimationFrame(() => {
            const el = messageInputRef.current;
            if (!el) return;
            el.focus();
            const pos = before.length + insert.length;
            try { el.setSelectionRange(pos, pos); } catch (_) {}
        });
    }, [newMessage, mentionTokenStart, closeMentions]);

    const updateMentionState = useCallback((value, cursor) => {
        const detected = detectMentionToken(value, cursor);
        if (!detected) {
            if (mentionOpen) closeMentions();
            return;
        }
        setMentionOpen(true);
        setMentionQuery(detected.query);
        setMentionTokenStart(detected.tokenStart);
        setMentionIndex(0);
    }, [mentionOpen, closeMentions]);

    const handleComposerChange = useCallback((e) => {
        const value = e.target.value;
        const cursor = e.target.selectionStart ?? value.length;
        if (composerMode === 'note') {
            setNewMessage(value);
            updateMentionState(value, cursor);
            // Drop tracked mentions whose @Name no longer appears in the text.
            setNoteMentions(prev => prev.filter(m => value.includes(`@${m.name}`)));
            return;
        }
        // ¿Terminó de escribir el disparador de una integración? Abre su modal
        // y quita el comando del texto del mensaje.
        const hit = detectIntegrationTrigger(value, cursor, integrations);
        if (hit) {
            setNewMessage(value.slice(0, hit.tokenStart) + value.slice(cursor));
            closeQuickReplies();
            setPaymentModal({ integration: hit.integration });
            return;
        }
        setNewMessage(value);
        updateQuickReplyState(value, cursor);
    }, [composerMode, updateQuickReplyState, updateMentionState, integrations, closeQuickReplies]);

    const handleComposerKeyDown = useCallback((e) => {
        if (mentionOpen && mentionMatches.length > 0) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setMentionIndex(i => (i + 1) % mentionMatches.length); return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); setMentionIndex(i => (i - 1 + mentionMatches.length) % mentionMatches.length); return; }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                applyMention(mentionMatches[mentionIndex]);
                return;
            }
            if (e.key === 'Escape') { e.preventDefault(); closeMentions(); return; }
        }
        if (qrOpen && qrMatches.length > 0) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setQrIndex(i => (i + 1) % qrMatches.length); return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); setQrIndex(i => (i - 1 + qrMatches.length) % qrMatches.length); return; }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                applyQuickReply(qrMatches[qrIndex]);
                return;
            }
        }
        if (e.key === 'Escape' && qrOpen) {
            e.preventDefault();
            closeQuickReplies();
            return;
        }
        if (e.key === 'Enter' && !qrOpen && !mentionOpen && !e.shiftKey) {
            e.preventDefault();
            if (composerMode === 'note') sendNote(); else sendMessage();
        }
    }, [qrOpen, qrMatches, qrIndex, applyQuickReply, closeQuickReplies, mentionOpen, mentionMatches, mentionIndex, applyMention, closeMentions, composerMode]);

    useEffect(() => {
        const el = messageInputRef.current;
        if (!el) return;
        if (newMessage === '') {
            // Reset to a single row when the composer is cleared (e.g. after send).
            el.style.height = '';
        } else {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        }
    }, [newMessage]);

    // Re-focus the composer after a send finishes (sending: true → false) so the
    // user can keep typing without clicking back into the textarea.
    const prevSendingRef = useRef(false);
    useEffect(() => {
        if (prevSendingRef.current && !sending) {
            messageInputRef.current?.focus();
        }
        prevSendingRef.current = sending;
    }, [sending]);

    // ESC deselecciona la conversación (vuelve al panel vacío), estilo WhatsApp.
    // Si hay un overlay abierto (modal, lightbox o picker), ESC no deselecciona:
    // esos cierran lo suyo primero / con su propia interacción.
    useEffect(() => {
        function handleEscape(e) {
            if (e.key !== 'Escape') return;
            const overlayOpen = selectedImage || selectedLocation || newChatOpen || showLinkContact || isCreatingTag || qrOpen || mentionOpen;
            if (overlayOpen) return;
            if (selectedConversation) {
                e.preventDefault();
                setSelectedConversation(null);
            }
        }
        document.addEventListener('keydown', handleEscape);
        return () => document.removeEventListener('keydown', handleEscape);
    }, [selectedConversation, selectedImage, selectedLocation, newChatOpen, showLinkContact, isCreatingTag, qrOpen, mentionOpen]);

    const assignConversation = useCallback(async (convId, userId) => {
        try {
            const res = await axios.post(`/api/chat/conversations/${convId}/assign`, { user_id: userId });
            if (res.data.success) {
                const agent = res.data.assigned_agent;
                setConversations(prev => prev.map(c => c.id === convId ? { ...c, assigned_to: userId, assigned_agent: agent } : c));
                setSelectedConversation(prev => (prev?.id === convId ? { ...prev, assigned_to: userId, assigned_agent: agent } : prev));
            }
        } catch (err) {
            console.error('Error asignando conversación:', err);
        }
    }, []);

    // Cierra o reabre una conversación.
    const setConversationStatus = useCallback(async (convId, action) => {
        try {
            const res = await axios.post(`/api/chat/conversations/${convId}/${action}`);
            if (res.data.success) {
                const status = res.data.status || (action === 'close' ? 'closed' : 'open');
                setConversations(prev => prev.map(c => c.id === convId ? { ...c, status } : c));
                setSelectedConversation(prev => (prev?.id === convId ? { ...prev, status } : prev));
                loadFolderCounts();
            }
        } catch (err) {
            console.error('Error cambiando estado de la conversación:', err);
        }
    }, [loadFolderCounts]);

    // Abre el modo edición del panel de contacto, precargando los datos actuales.
    const openContactEdit = useCallback(() => {
        const c = selectedConversation?.contact;
        setContactForm({
            name: c?.name || selectedConversation?.name || '',
            email: c?.email || '',
            notes: c?.notes || '',
        });
        setContactError(null);
        setEditingContact(true);
    }, [selectedConversation]);

    // Guarda los datos del contacto: actualiza el contacto vinculado, o crea y
    // vincula uno nuevo a partir del número de la conversación.
    const saveContact = useCallback(async () => {
        if (!selectedConversation) return;
        const name = contactForm.name.trim();
        if (!name) { setContactError('El nombre es obligatorio.'); return; }

        setSavingContact(true);
        setContactError(null);
        try {
            let contact;
            if (selectedConversation.contact?.id) {
                const res = await axios.put(`/api/contacts/${selectedConversation.contact.id}`, {
                    name,
                    email: contactForm.email.trim() || null,
                    notes: contactForm.notes.trim() || null,
                });
                contact = res.data;
            } else {
                const res = await axios.post(`/api/chat/conversations/${selectedConversation.id}/attach-contact`, {
                    name,
                    phone_number: selectedConversation.phone_number,
                    email: contactForm.email.trim() || null,
                });
                contact = res.data.contact;
            }
            const linkedName = contact?.name || name;
            setSelectedConversation(prev => prev ? { ...prev, contact, name: linkedName } : prev);
            setConversations(prev => prev.map(c => c.id === selectedConversation.id ? { ...c, contact, name: linkedName } : c));
            setEditingContact(false);
        } catch (err) {
            setContactError(err?.response?.data?.message || 'No se pudo guardar el contacto.');
        } finally {
            setSavingContact(false);
        }
    }, [selectedConversation, contactForm]);

    // Elimina por completo una conversación (acción destructiva).
    const deleteConversation = useCallback(async (convId) => {
        try {
            const res = await axios.delete(`/api/chat/conversations/${convId}`);
            if (res.data.success) {
                setConversations(prev => prev.filter(c => c.id !== convId));
                setSelectedConversation(prev => (prev?.id === convId ? null : prev));
                setMessages(prev => (selectedConversation?.id === convId ? [] : prev));
                loadFolderCounts();
            }
        } catch (err) {
            console.error('Error eliminando conversación:', err);
            alert('No se pudo eliminar la conversación.');
        }
    }, [loadFolderCounts, selectedConversation]);

    const attachTag = useCallback(async (convId, tagId) => {
        try {
            const res = await axios.post(`/api/tags/conversations/${convId}/attach`, { tag_id: tagId });
            if (res.data.success) {
                setConversations(prev => prev.map(c => c.id === convId ? { ...c, tags: res.data.tags } : c));
                setSelectedConversation(prev => (prev?.id === convId ? { ...prev, tags: res.data.tags } : prev));
                loadFolderCounts();
            }
        } catch (err) {
            console.error('Error adjuntando etiqueta:', err);
        }
    }, [loadFolderCounts]);

    const detachTag = useCallback(async (convId, tagId) => {
        try {
            const res = await axios.post(`/api/tags/conversations/${convId}/detach`, { tag_id: tagId });
            if (res.data.success) {
                setConversations(prev => prev.map(c => c.id === convId ? { ...c, tags: res.data.tags } : c));
                setSelectedConversation(prev => (prev?.id === convId ? { ...prev, tags: res.data.tags } : prev));
                loadFolderCounts();
            }
        } catch (err) {
            console.error('Error quitando etiqueta:', err);
        }
    }, [loadFolderCounts]);

    const createTag = useCallback(async () => {
        if (!newTagName.trim()) return;
        try {
            const res = await axios.post('/api/tags', { name: newTagName, color: newTagColor });
            const createdTag = res.data;
            setTags(prev => [...prev, createdTag]);
            
            if (taggingConversationId) {
                await attachTag(taggingConversationId, createdTag.id);
            }

            setNewTagName('');
            setIsCreatingTag(false);
            setTaggingConversationId(null);
        } catch (err) {
            console.error('Error creando etiqueta:', err);
        }
    }, [newTagName, newTagColor, taggingConversationId, attachTag]);

    const handleNewTag = useCallback((convId) => {
        setTaggingConversationId(convId);
        setEditingTag(null);
        setNewTagName('');
        setNewTagColor('#0d9488');
        setIsCreatingTag(true);
    }, []);

    // Abre el modal en modo edición con los datos de la etiqueta.
    const openEditTag = useCallback((tag) => {
        setEditingTag(tag);
        setNewTagName(tag.name);
        setNewTagColor(tag.color || '#0d9488');
        setTaggingConversationId(null);
        setIsCreatingTag(true);
    }, []);

    const updateTag = useCallback(async () => {
        if (!editingTag || !newTagName.trim()) return;
        try {
            const res = await axios.put(`/api/tags/${editingTag.id}`, { name: newTagName, color: newTagColor });
            const updated = res.data;
            setTags(prev => prev.map(t => (t.id === updated.id ? updated : t)));
            // Refleja el nuevo nombre/color en las conversaciones ya cargadas.
            setConversations(prev => prev.map(c => ({
                ...c,
                tags: (c.tags || []).map(t => (t.id === updated.id ? { ...t, ...updated } : t)),
            })));
            setIsCreatingTag(false);
            setEditingTag(null);
            setNewTagName('');
        } catch (err) {
            console.error('Error actualizando etiqueta:', err);
        }
    }, [editingTag, newTagName, newTagColor]);

    const deleteTag = useCallback(async (tag) => {
        if (!window.confirm(`¿Eliminar la etiqueta "${tag.name}"? Se quitará de todas las conversaciones.`)) return;
        try {
            await axios.delete(`/api/tags/${tag.id}`);
            setTags(prev => prev.filter(t => t.id !== tag.id));
            setSelectedTagIds(prev => prev.filter(id => String(id) !== String(tag.id)));
            setConversations(prev => prev.map(c => ({
                ...c,
                tags: (c.tags || []).filter(t => t.id !== tag.id),
            })));
            if (editingTag?.id === tag.id) { setIsCreatingTag(false); setEditingTag(null); }
        } catch (err) {
            console.error('Error eliminando etiqueta:', err);
        }
    }, [editingTag]);

    // Activa/desactiva una etiqueta en el filtro multi-selección.
    const toggleTag = useCallback((tagId) => {
        const id = String(tagId);
        setSelectedTagIds(prev => (prev.includes(id) ? prev.filter(t => t !== id) : [...prev, id]));
    }, []);

    const closeTagModal = useCallback(() => {
        setIsCreatingTag(false);
        setEditingTag(null);
        setTaggingConversationId(null);
        setNewTagName('');
    }, []);

    const selectConversation = useCallback(async (conv) => {
        setSelectedConversation(conv);
        setReplyingTo(null);
        try {
            const res = await axios.get(`/api/chat/conversations/${conv.id}/messages`);
            forceScrollRef.current = true;
            setMessages(res.data.messages);
            setLastUpdateTimestamp(res.data.timestamp);
            setConversations(prev =>
                prev.map(c => (c.id === conv.id ? { ...c, unread_count: 0 } : c)),
            );
        } catch (err) {
            console.error('Error cargando mensajes:', err);
        }
    }, []);

    const openConversationById = useCallback(async (id) => {
        try {
            const res = await axios.get(`/api/chat/conversations/${id}/messages`);
            if (res.data.conversation) {
                forceScrollRef.current = true;
                setSelectedConversation(res.data.conversation);
                setMessages(res.data.messages);
                setLastUpdateTimestamp(res.data.timestamp);
            }
        } catch (err) {
            console.error('Error abriendo conversación:', err);
        }
    }, []);

    // Deep-link from the notification bell: /chat?conversation=ID&instance=ID
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const convId = params.get('conversation');
        const inst = params.get('instance');
        if (inst) setSelectedInstanceId(inst);
        if (convId) openConversationById(convId);
        if (convId || inst) {
            window.history.replaceState({}, '', '/chat');
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Tiempo real (Reverb): un solo canal privado por instancia con dos tipos de
    // evento — llamadas (.call.event) y mensajes (.message.event). Se suscribe
    // una vez para no pisar el canal compartido con dos `Echo.leave`.
    //
    // El de mensajes sustituye al polling de 10s como vía principal (el poll de
    // 30s queda de respaldo); todos los updates se aplican por id (idempotentes),
    // así que recibir lo mismo por Echo y por poll es inofensivo.
    useEffect(() => {
        if (!selectedInstanceId || !window.Echo) return;

        const channelName = `instance.${selectedInstanceId}`;
        const channel = window.Echo.private(channelName);

        channel.listen('.call.event', (e) => {
            const call = e?.call;
            if (!call) return;

            if (e.action === 'incoming') {
                setIncomingCall(call);
            } else if (e.action === 'ended') {
                setIncomingCall(prev => (prev && prev.wacid === call.wacid ? null : prev));
            }
        });

        channel.listen('.message.event', (e) => {
            if (!e?.message) return;

            if (e.action === 'status') {
                setMessages(prev => prev.map(m => (m.id === e.message.id ? { ...m, ...e.message } : m)));
                return;
            }

            // action === 'new': si es de la conversación abierta, la anexamos
            // (deduplicando por id y reconciliando la burbuja optimista por wamid).
            if (selectedConversationRef.current?.id === e.conversation_id) {
                setMessages(prev => {
                    if (prev.some(m => m.id === e.message.id)) return prev;
                    const optimisticIdx = e.message.wamid
                        ? prev.findIndex(m => typeof m.id === 'string' && String(m.id).startsWith('temp-') && m.wamid === e.message.wamid)
                        : -1;
                    if (optimisticIdx !== -1) {
                        const next = [...prev];
                        next[optimisticIdx] = e.message;
                        return next;
                    }
                    return [...prev, e.message];
                });
            }

            // Refresca la fila de la conversación en la lista (último mensaje y
            // hora); el reordenamiento fino lo termina de ajustar el poll de 30s.
            setConversations(prev => prev.map(c => (
                c.id === e.conversation_id
                    ? { ...c, last_message: e.message.content, last_message_at: e.message.created_at || new Date().toISOString() }
                    : c
            )));
        });

        return () => {
            window.Echo.leave(channelName);
        };
    }, [selectedInstanceId]);

    // ── Logic ──────────────────────────────────────────────────────────────

    const hasActiveFilters = Boolean(
        filterMyAssignments || selectedTagIds.length || selectedAgentId || searchQuery.trim() || statusFilter !== 'open'
    );

    const activeFilterCount =
        (filterMyAssignments ? 1 : 0) +
        selectedTagIds.length +
        (selectedAgentId ? 1 : 0) +
        (searchQuery.trim() ? 1 : 0) +
        (statusFilter !== 'open' ? 1 : 0);

    const resetFilters = useCallback(() => {
        setFilterMyAssignments(false);
        setSelectedTagIds([]);
        setSelectedAgentId('');
        setAgentFilterQuery('');
        setSearchQuery('');
        setStatusFilter('open');
        setSortBy('last_activity');
    }, []);

    // ── Popover "Filtrar conversaciones" ─────────────────────────────────────
    // Construye las filas del borrador a partir del estado de filtros aplicado.
    const buildDraftFromState = useCallback(() => {
        const rows = [{ field: 'estado', value: statusFilter }];
        if (filterMyAssignments) rows.push({ field: 'agente', value: 'mine' });
        else if (selectedAgentId) rows.push({ field: 'agente', value: selectedAgentId });
        selectedTagIds.forEach(id => rows.push({ field: 'etiqueta', value: String(id) }));
        return rows;
    }, [statusFilter, filterMyAssignments, selectedAgentId, selectedTagIds]);

    const openFilterPopover = useCallback((open) => {
        if (open) setDraftFilters(buildDraftFromState());
        setFilterOpen(open);
    }, [buildDraftFromState]);

    const addDraftRow = useCallback(() => {
        setDraftFilters(prev => {
            const used = new Set(prev.map(r => r.field));
            const next = FILTER_FIELDS.find(f => !used.has(f.value)) || FILTER_FIELDS[0];
            const defaultValue = next.value === 'estado' ? 'open' : next.value === 'agente' ? 'mine' : '';
            return [...prev, { field: next.value, value: defaultValue }];
        });
    }, []);

    const updateDraftRow = useCallback((idx, patch) => {
        setDraftFilters(prev => prev.map((r, i) => {
            if (i !== idx) return r;
            const merged = { ...r, ...patch };
            // Al cambiar de campo, reiniciamos el valor a uno válido para ese campo.
            if (patch.field && patch.field !== r.field) {
                merged.value = patch.field === 'estado' ? 'open' : patch.field === 'agente' ? 'mine' : '';
            }
            return merged;
        }));
    }, []);

    const removeDraftRow = useCallback((idx) => {
        setDraftFilters(prev => prev.filter((_, i) => i !== idx));
    }, []);

    // Traduce las filas del borrador al estado de filtros aplicado.
    const applyDraftFilters = useCallback(() => {
        let status = 'all', mine = false, agent = '', tagIds = [];
        let hasEstado = false;
        draftFilters.forEach(r => {
            if (r.field === 'estado') { status = r.value; hasEstado = true; }
            else if (r.field === 'agente') {
                if (r.value === 'mine') mine = true;
                else if (r.value) agent = String(r.value);
            } else if (r.field === 'etiqueta' && r.value) {
                tagIds.push(String(r.value));
            }
        });
        setStatusFilter(hasEstado ? status : 'all');
        setFilterMyAssignments(mine);
        setSelectedAgentId(mine ? '' : agent);
        setSelectedTagIds([...new Set(tagIds)]);
        setFilterOpen(false);
    }, [draftFilters]);

    const clearDraftFilters = useCallback(() => {
        setDraftFilters([{ field: 'estado', value: 'open' }]);
    }, []);

    // Cierre del popover de filtros al hacer clic fuera o con Escape.
    const filterPopoverRef = useRef(null);
    useEffect(() => {
        if (!filterOpen) return;
        const onDown = (e) => {
            if (filterPopoverRef.current && !filterPopoverRef.current.contains(e.target)) {
                setFilterOpen(false);
            }
        };
        const onKey = (e) => { if (e.key === 'Escape') setFilterOpen(false); };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [filterOpen]);

    // Base list: todos los filtros (búsqueda, etiqueta, agente, "mis asignaciones")
    // EXCEPTO la pestaña de asignación. Sirve para que los contadores de las
    // pestañas reflejen lo que el usuario realmente está viendo.
    const baseConversations = useMemo(() => {
        let items = conversations;
        if (filterMyAssignments) {
            items = items.filter(c => Number(c.assigned_to) === Number(auth.user.id));
        }
        if (selectedAgentId) {
            if (selectedAgentId === 'unassigned') {
                items = items.filter(c => !c.assigned_to);
            } else {
                items = items.filter(c => Number(c.assigned_to) === Number(selectedAgentId));
            }
        }
        if (selectedTagIds.length) {
            items = items.filter(c => (c.tags || []).some(t => selectedTagIds.includes(String(t.id))));
        }
        if (!searchQuery) return items;
        const q = searchQuery.toLowerCase();
        return items.filter(
            c => (c.name || '').toLowerCase().includes(q) || (c.phone_number || '').includes(q) || (c.last_message || '').toLowerCase().includes(q),
        );
    }, [conversations, searchQuery, filterMyAssignments, selectedTagIds, selectedAgentId, auth.user.id]);

    // Aplica el filtro de Estado (Abiertas/Cerradas/Todas) sobre la base.
    const statusConversations = useMemo(() => {
        if (statusFilter === 'open') return baseConversations.filter(c => c.status !== 'closed');
        if (statusFilter === 'closed') return baseConversations.filter(c => c.status === 'closed');
        return baseConversations;
    }, [baseConversations, statusFilter]);

    // Contadores de las pestañas Mías / Sin asignar / Todos, respetando el
    // estado seleccionado y los filtros activos.
    const tabCounts = useMemo(() => ({
        all: statusConversations.length,
        mine: statusConversations.filter(c => Number(c.assigned_to) === Number(auth.user.id)).length,
        unassigned: statusConversations.filter(c => !c.assigned_to).length,
    }), [statusConversations, auth.user.id]);

    const filteredConversations = useMemo(() => {
        let items = statusConversations;
        if (assignmentTab === 'mine') {
            items = items.filter(c => Number(c.assigned_to) === Number(auth.user.id));
        } else if (assignmentTab === 'unassigned') {
            items = items.filter(c => !c.assigned_to);
        }
        const ts = (v) => { const t = v ? new Date(v).getTime() : 0; return Number.isNaN(t) ? 0 : t; };
        const sorted = [...items];
        if (sortBy === 'newest') sorted.sort((a, b) => ts(b.created_at) - ts(a.created_at));
        else if (sortBy === 'oldest') sorted.sort((a, b) => ts(a.created_at) - ts(b.created_at));
        else sorted.sort((a, b) => ts(b.last_message_at) - ts(a.last_message_at));
        return sorted;
    }, [statusConversations, assignmentTab, sortBy, auth.user.id]);

    // ── Selección múltiple / cierre en lote ──────────────────────────────────
    const toggleSelect = useCallback((id) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }, []);

    const exitSelection = useCallback(() => {
        setSelectionMode(false);
        setSelectedIds(new Set());
    }, []);

    const selectAllVisible = useCallback(() => {
        setSelectedIds(new Set(filteredConversations.map(c => c.id)));
    }, [filteredConversations]);

    const runCloseBulk = useCallback(async (payload) => {
        try {
            const res = await axios.post('/api/chat/conversations/close-bulk', payload);
            if (res.data.success) {
                const closed = new Set(res.data.ids);
                setConversations(prev => prev.map(c => closed.has(c.id) ? { ...c, status: 'closed' } : c));
                setSelectedConversation(prev => (prev && closed.has(prev.id) ? { ...prev, status: 'closed' } : prev));
                loadFolderCounts();
                exitSelection();
                return res.data.closed_count;
            }
        } catch (err) {
            console.error('Error cerrando en lote:', err);
            alert(err?.response?.data?.error || 'No se pudieron cerrar los chats.');
        }
        return 0;
    }, [exitSelection, loadFolderCounts]);

    const closeSelected = useCallback(async () => {
        if (selectedIds.size === 0) return;
        if (!window.confirm(`¿Cerrar ${selectedIds.size} conversación(es) seleccionada(s)?`)) return;
        await runCloseBulk({ ids: Array.from(selectedIds) });
    }, [selectedIds, runCloseBulk]);

    const closeAllOpen = useCallback(async () => {
        if (!window.confirm('¿Cerrar TODAS las conversaciones abiertas de esta instancia? Podrás reabrirlas luego.')) return;
        const n = await runCloseBulk({ scope: 'all', instance_id: selectedInstanceId });
        if (n === 0) alert('No había conversaciones abiertas para cerrar.');
    }, [runCloseBulk, selectedInstanceId]);

    const scrollToBottom = useCallback(() => {
        const el = messagesContainerRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, []);

    // Registra si el scroll está cerca del fondo cada vez que el usuario se mueve.
    const handleMessagesScroll = useCallback(() => {
        const el = messagesContainerRef.current;
        if (!el) return;
        atBottomRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 150;
    }, []);

    // Cuando una imagen termina de cargar crece el contenido; si seguíamos
    // pegados al fondo, re-anclamos al último mensaje.
    const handleMediaLoad = useCallback(() => {
        if (forceScrollRef.current || atBottomRef.current) scrollToBottom();
    }, [scrollToBottom]);

    // Al abrir una conversación cae al último mensaje (varios intentos para
    // contemplar plantillas/imágenes que maquetan tarde). Con mensajes nuevos
    // solo baja si el usuario ya estaba cerca del fondo (no lo arranca de la
    // lectura del historial).
    useEffect(() => {
        const el = messagesContainerRef.current;
        if (!el) return;
        if (forceScrollRef.current) {
            forceScrollRef.current = false;
            scrollToBottom();
            requestAnimationFrame(scrollToBottom);
            const t = setTimeout(scrollToBottom, 80);
            return () => clearTimeout(t);
        }
        if (atBottomRef.current) scrollToBottom();
    }, [messages, scrollToBottom]);

    useEffect(() => {
        if (instances.length > 0 && !selectedInstanceId) {
            const first = instances[0];
            setSelectedInstanceId(String(first.id));
        }
    }, [instances, selectedInstanceId]);

    // ── Search Debounce ───────────────────────────────────────────────────
    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(searchQuery), 300);
        return () => clearTimeout(timer);
    }, [searchQuery]);

    useEffect(() => {
        // Reset and load when search/filters change
        setPage(1);
        setConversations([]);
        setHasMore(true);
    }, [debouncedSearch, selectedInstanceId, selectedTagIds, selectedAgentId, filterMyAssignments, folder, statusFilter]);

    const loadConversations = useCallback(async (pageNum = 1) => {
        if (!selectedInstanceId || loadingMore) return;

        try {
            setLoadingMore(true);
            const params = {
                instance_id: selectedInstanceId,
                page: pageNum,
                search: debouncedSearch,
                tag_ids: selectedTagIds.join(','),
                assigned_to: selectedAgentId === 'unassigned' ? 'unassigned' : selectedAgentId,
            };

            if (filterMyAssignments) params.assigned_to = auth.user.id;
            if (folder !== 'all') params.filter = folder;
            // El estado se filtra en el backend para que la paginación (hasMore)
            // coincida con lo que se ve: "Abiertas" = open+pending, "Cerradas" = closed.
            // "Todas" no envía estado. Evita paginar cientos de cerradas ocultas.
            if (statusFilter === 'closed') params.status = 'closed';
            else if (statusFilter === 'open') params.status = 'open,pending';

            const res = await axios.get('/api/chat/conversations', { params });

            const newItems = res.data.data;
            setConversations(prev => pageNum === 1 ? newItems : [...prev, ...newItems]);
            setHasMore(res.data.next_page_url !== null);
            setPage(pageNum);
        } catch (err) {
            console.error('Error cargando conversaciones:', err);
        } finally {
            setLoadingMore(false);
        }
    }, [selectedInstanceId, debouncedSearch, selectedTagIds, selectedAgentId, filterMyAssignments, folder, statusFilter, auth.user.id]);

    useEffect(() => {
        loadFolderCounts();
    }, [loadFolderCounts]);

    useEffect(() => {
        loadConversations(1);
    }, [debouncedSearch, selectedInstanceId, selectedTagIds, selectedAgentId, filterMyAssignments, folder, statusFilter, loadConversations]);

    const sidebarScrollRef = useRef(null);
    const observerTarget = useRef(null);

    useEffect(() => {
        const observer = new IntersectionObserver(
            entries => {
                if (entries[0].isIntersecting && hasMore && !loadingMore) {
                    loadConversations(page + 1);
                }
            },
            { threshold: 0.5 }
        );

        if (observerTarget.current) observer.observe(observerTarget.current);
        return () => observer.disconnect();
    }, [hasMore, loadingMore, page, loadConversations]);

    useEffect(() => {
        selectedConversationRef.current = selectedConversation;
    }, [selectedConversation]);

    useEffect(() => {
        startPolling();
        return () => stopPolling();
    }, [selectedInstanceId, lastUpdateTimestamp]);

    function startPolling() {
        stopPolling();
        // El tiempo real llega por Reverb (ver el useEffect de `.message.event`);
        // este polling queda como red de seguridad por si el websocket se cae,
        // por eso 30s en vez de 10s (menos carga sobre MySQL). El merge deduplica
        // por id, así que recibir lo mismo por Echo y por poll es inofensivo.
        pollingIntervalRef.current = setInterval(checkForUpdates, 30000);
    }

    function stopPolling() {
        if (pollingIntervalRef.current) {
            clearInterval(pollingIntervalRef.current);
            pollingIntervalRef.current = null;
        }
    }

    async function checkForUpdates() {
        try {
            setIsPolling(true);
            // El primer poll va sin `since`: el servidor responde solo con su propio
            // timestamp para sembrar la referencia (evita el desfase por el reloj o la
            // zona horaria del cliente). Los polls siguientes ya envían ese valor.
            const params = { instance_id: selectedInstanceId };
            if (lastUpdateTimestamp) params.since = lastUpdateTimestamp;
            if (selectedConversation) params.conversation_id = selectedConversation.id;
            if (folder !== 'all') params.filter = folder;

            const res = await axios.get('/api/chat/updates', { params });
            setLastUpdateTimestamp(res.data.timestamp);
            setLastUpdate(new Date().toLocaleTimeString('es-CO'));

            if (res.data.conversations?.length > 0) {
                mergeConversations(res.data.conversations);
                loadFolderCounts();
                // Sincroniza la conversación abierta en pantalla: si el cliente
                // vuelve a escribir y el backend la reabrió (closed → open), la
                // cabecera refleja el nuevo estado al instante (el botón pasa de
                // "Reabrir" a "Cerrar") sin que nadie la abra manualmente.
                setSelectedConversation(prev => {
                    if (!prev) return prev;
                    const updated = res.data.conversations.find(c => c.id === prev.id);
                    return updated ? { ...prev, ...updated } : prev;
                });
            }
            if (res.data.new_messages?.length > 0) {
                setMessages(prev => {
                    const ids = new Set(prev.map(m => m.id));
                    const incoming = res.data.new_messages.filter(m => !ids.has(m.id));
                    if (incoming.length === 0) return prev;
                    return [...prev, ...incoming];
                });
            }
            if (res.data.updated_statuses?.length > 0) {
                setMessages(prev =>
                    prev.map(msg => {
                        const update = res.data.updated_statuses.find(u => u.id === msg.id);
                        return update ? { ...msg, ...update } : msg;
                    }),
                );
            }
        } catch (err) {
            console.error('Error en polling:', err);
        } finally {
            setIsPolling(false);
        }
    }

    function mergeConversations(updated) {
        setConversations(prev => {
            const map = new Map(prev.map(c => [c.id, c]));
            updated.forEach(u => map.set(u.id, u));
            return Array.from(map.values()).sort(
                (a, b) => new Date(b.last_message_at) - new Date(a.last_message_at),
            );
        });
    }

    // Ventana de servicio de 24h de Meta: sin un inbound reciente, solo se puede
    // reabrir la conversación con una plantilla aprobada (texto/adjuntos libres
    // quedan bloqueados). Se deriva de los mensajes ya cargados, sin llamadas nuevas.
    const windowExpired = useMemo(() => {
        if (!selectedConversation) return false;
        const lastInbound = [...messages].reverse().find(m => m.direction === 'inbound');
        if (!lastInbound) return true;
        return (Date.now() - new Date(lastInbound.created_at).getTime()) > 24 * 60 * 60 * 1000;
    }, [messages, selectedConversation]);

    async function sendMessage() {
        if (!newMessage.trim() || sending) return;
        if (windowExpired) { setShowTemplates(true); return; }
        const msg = newMessage;
        const replyWamid = replyingTo?.wamid || null;
        setNewMessage('');
        setReplyingTo(null);
        setSendError(null);
        setSending(true);

        // Optimistic UI: show the message instantly with a "sending" state so the
        // agent doesn't wait for the Meta round-trip before seeing it.
        const tempId = `temp-${++tempMsgCounter.current}`;
        const optimistic = {
            id: tempId,
            conversation_id: selectedConversation.id,
            type: 'text',
            content: msg,
            reply_to_wamid: replyWamid,
            direction: 'outbound',
            status: 'sending',
            sender: { name: auth.user.name },
            created_at: new Date().toISOString(),
        };
        setMessages(prev => [...prev, optimistic]);

        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/send`,
                { message: msg, reply_to_wamid: replyWamid },
            );
            if (res.data.success) {
                // Replace the temp message with the real one from the server.
                setMessages(prev => prev.map(m => (m.id === tempId ? res.data.data : m)));
            } else {
                setMessages(prev => prev.map(m => (m.id === tempId ? { ...m, status: 'failed' } : m)));
                setNewMessage(msg);
            }
        } catch (err) {
            console.error('Error enviando:', err);
            setNewMessage(msg);
            if (err?.response?.data?.code === 'window_closed') {
                // No es un fallo de envío real: no se llegó a intentar. Se quita la
                // burbuja optimista y se abre el selector de plantillas.
                setMessages(prev => prev.filter(m => m.id !== tempId));
                setShowTemplates(true);
            } else {
                setMessages(prev => prev.map(m => (m.id === tempId ? { ...m, status: 'failed' } : m)));
                setSendError(err?.response?.data?.error || 'No se pudo enviar el mensaje.');
            }
        } finally {
            setSending(false);
        }
    }

    async function sendNote() {
        if (!newMessage.trim() || sending) return;
        const content = newMessage;
        const mentions = noteMentions
            .filter(m => content.includes(`@${m.name}`))
            .map(m => m.id);
        setNewMessage('');
        setNoteMentions([]);
        setSending(true);

        // Optimistic UI for internal notes too.
        const tempId = `temp-${++tempMsgCounter.current}`;
        const optimistic = {
            id: tempId,
            conversation_id: selectedConversation.id,
            type: 'text',
            content,
            direction: 'outbound',
            is_internal: true,
            status: 'sending',
            sender: { name: auth.user.name },
            created_at: new Date().toISOString(),
        };
        setMessages(prev => [...prev, optimistic]);

        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/note`,
                { content, mentions },
            );
            if (res.data.success) {
                setMessages(prev => prev.map(m => (m.id === tempId ? res.data.data : m)));
            } else {
                setMessages(prev => prev.map(m => (m.id === tempId ? { ...m, status: 'failed' } : m)));
                setNewMessage(content);
            }
        } catch (err) {
            console.error('Error guardando nota:', err);
            setMessages(prev => prev.map(m => (m.id === tempId ? { ...m, status: 'failed' } : m)));
            setNewMessage(content);
        } finally {
            setSending(false);
        }
    }

    // Abre la vista previa con el archivo elegido desde el botón de adjuntar.
    function handleFileUpload(e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (file) openImagePreview(file);
    }

    // Muestra la imagen seleccionada (adjunto / arrastrada / pegada) en una
    // previsualización antes de enviarla, con opción a escribir un pie de foto.
    function openImagePreview(file) {
        if (!file || !file.type?.startsWith('image/')) {
            alert('Solo se pueden adjuntar imágenes.');
            return;
        }
        // 5 MB es el límite del backend (sendImage: image|max:5120).
        if (file.size > 5 * 1024 * 1024) {
            alert('La imagen supera el límite de 5 MB.');
            return;
        }
        if (pendingImage?.url) URL.revokeObjectURL(pendingImage.url);
        setPendingImage({ file, url: URL.createObjectURL(file) });
        setImageCaption('');
    }

    function closeImagePreview() {
        if (pendingImage?.url) URL.revokeObjectURL(pendingImage.url);
        setPendingImage(null);
        setImageCaption('');
    }

    // Sube y envía la imagen previsualizada con su pie de foto opcional.
    async function confirmSendImage() {
        if (!pendingImage || !selectedConversation || sending) return;
        if (windowExpired) { closeImagePreview(); setShowTemplates(true); return; }
        const { file } = pendingImage;
        const caption = imageCaption;
        const replyWamid = replyingTo?.wamid || null;
        closeImagePreview();
        setReplyingTo(null);

        const formData = new FormData();
        formData.append('image', file);
        if (caption.trim()) formData.append('caption', caption.trim());
        if (replyWamid) formData.append('reply_to_wamid', replyWamid);
        setSending(true);
        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/send-image`,
                formData,
            );
            if (res.data.success) {
                setMessages(prev => [...prev, res.data.data]);
            } else if (res.data.code === 'window_closed') {
                setShowTemplates(true);
            } else {
                alert(res.data.error || 'No se pudo enviar la imagen.');
            }
        } catch (err) {
            console.error('Error enviando imagen:', err);
            if (err?.response?.data?.code === 'window_closed') {
                setShowTemplates(true);
            } else if (err?.response?.status === 413) {
                alert('La imagen es demasiado grande para el servidor.');
            } else if (!err?.response) {
                alert('Fallo de conexión al subir la imagen, intenta de nuevo.');
            } else {
                alert(err?.response?.data?.error || err?.response?.data?.errors?.image?.[0] || 'No se pudo enviar la imagen.');
            }
        } finally {
            setSending(false);
        }
    }

    // --- Arrastrar y soltar imágenes sobre el chat (estilo WhatsApp Web) ---
    function handleChatDragEnter(e) {
        if (!selectedConversation || composerMode !== 'reply') return;
        if (!Array.from(e.dataTransfer?.types || []).includes('Files')) return;
        e.preventDefault();
        dragDepthRef.current += 1;
        setIsDraggingFile(true);
    }

    function handleChatDragOver(e) {
        if (!isDraggingFile) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    }

    function handleChatDragLeave(e) {
        if (!isDraggingFile) return;
        e.preventDefault();
        dragDepthRef.current -= 1;
        if (dragDepthRef.current <= 0) {
            dragDepthRef.current = 0;
            setIsDraggingFile(false);
        }
    }

    function handleChatDrop(e) {
        if (!isDraggingFile) return;
        e.preventDefault();
        dragDepthRef.current = 0;
        setIsDraggingFile(false);
        if (!selectedConversation || composerMode !== 'reply') return;
        const file = Array.from(e.dataTransfer?.files || []).find(f => f.type?.startsWith('image/'));
        if (file) openImagePreview(file);
    }

    // --- Responder a un mensaje (cita estilo WhatsApp) ---
    // Índice wamid -> mensaje, para resolver la cita de cada mensaje.
    const messagesByWamid = useMemo(() => {
        const map = new Map();
        for (const m of messages) {
            if (m.wamid) map.set(m.wamid, m);
        }
        return map;
    }, [messages]);

    function quotedAuthor(m) {
        if (m.direction === 'outbound') return m.sender?.name || 'Tú';
        return selectedConversation?.contact?.name || selectedConversation?.name || 'Cliente';
    }

    function quotedSnippet(m) {
        if (!m) return '';
        if (m.type === 'image') return '📷 ' + (m.content || 'Foto');
        if (m.type === 'audio') return '🎵 Audio';
        if (m.type === 'video') return '🎥 ' + (m.content || 'Video');
        if (m.type === 'document') return '📄 ' + (m.filename || 'Documento');
        if (m.type === 'template') return m.content || 'Plantilla';
        if (m.type === 'location') return '📍 ' + (m.content || 'Ubicación');
        if (m.type === 'contacts') return '👤 ' + (m.content || 'Contacto');
        return m.content || '';
    }

    // Desplaza y resalta brevemente el mensaje original al tocar la cita.
    function scrollToMessage(id) {
        const el = document.getElementById(`msg-${id}`);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('ring-2', 'ring-teal-400');
        setTimeout(() => el.classList.remove('ring-2', 'ring-teal-400'), 1500);
    }

    // --- Pegar imágenes (Ctrl/Cmd + V) en el área de texto ---
    function handleComposerPaste(e) {
        if (composerMode !== 'reply' || !selectedConversation) return;
        const items = Array.from(e.clipboardData?.items || []);
        const imageItem = items.find(it => it.type?.startsWith('image/'));
        if (!imageItem) return;
        const file = imageItem.getAsFile();
        if (file) {
            e.preventDefault();
            openImagePreview(file);
        }
    }

    // Elige el mejor formato que el navegador pueda grabar. WhatsApp prefiere
    // OGG/Opus (Firefox lo graba nativo); Chrome solo graba WebM/Opus (el
    // backend lo transcodifica a OGG con ffmpeg).
    function pickAudioMime() {
        const candidates = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/mp4', 'audio/webm'];
        if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return '';
        return candidates.find(t => MediaRecorder.isTypeSupported(t)) || '';
    }

    function extForType(type = '') {
        if (type.includes('ogg')) return 'ogg';
        if (type.includes('mp4')) return 'm4a';
        if (type.includes('mpeg')) return 'mp3';
        return 'webm';
    }

    async function startRecording() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mimeType = pickAudioMime();
            const mediaRecorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
            mediaRecorderRef.current = mediaRecorder;
            audioChunksRef.current = [];

            mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    audioChunksRef.current.push(e.data);
                }
            };

            mediaRecorder.onstop = () => {
                stream.getTracks().forEach(track => track.stop());
                clearInterval(recordingIntervalRef.current);
                if (audioChunksRef.current.length === 0) return; // grabación cancelada
                const type = mediaRecorder.mimeType || mimeType || 'audio/webm';
                const blob = new Blob(audioChunksRef.current, { type });
                // En vez de enviar, mostramos la preview para escuchar antes.
                setRecordedAudio({ blob, url: URL.createObjectURL(blob), type });
            };

            mediaRecorder.start();
            setIsRecording(true);
            setRecordingDuration(0);
            recordingIntervalRef.current = setInterval(() => {
                setRecordingDuration(prev => prev + 1);
            }, 1000);
        } catch (err) {
            console.error('Error al acceder al micrófono:', err);
            alert('No se pudo acceder al micrófono. Por favor verifica los permisos.');
        }
    }

    function stopRecording() {
        if (mediaRecorderRef.current && isRecording) {
            mediaRecorderRef.current.stop();
            setIsRecording(false);
            clearInterval(recordingIntervalRef.current);
        }
    }

    function cancelRecording() {
        if (mediaRecorderRef.current && isRecording) {
            audioChunksRef.current = [];
            mediaRecorderRef.current.stop();
            setIsRecording(false);
            clearInterval(recordingIntervalRef.current);
        }
    }

    function discardRecordedAudio() {
        if (recordedAudio?.url) URL.revokeObjectURL(recordedAudio.url);
        setRecordedAudio(null);
    }

    async function sendRecordedAudio() {
        if (!recordedAudio) return;
        const { blob, url, type } = recordedAudio;
        setRecordedAudio(null);
        if (windowExpired) { if (url) URL.revokeObjectURL(url); setShowTemplates(true); return; }
        await sendAudioMessage(blob, type);
        if (url) URL.revokeObjectURL(url);
    }

    async function sendAudioMessage(blob, type = '') {
        const formData = new FormData();
        formData.append('audio', blob, `recording.${extForType(type)}`);
        setSending(true);
        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/send-audio`,
                formData,
            );
            if (res.data.success) {
                setMessages(prev => [...prev, res.data.data]);
            } else if (res.data.code === 'window_closed') {
                setShowTemplates(true);
            } else {
                alert(res.data.error || 'No se pudo enviar el audio.');
            }
        } catch (err) {
            console.error('Error enviando audio:', err);
            if (err?.response?.data?.code === 'window_closed') {
                setShowTemplates(true);
            } else if (err?.response?.status === 413) {
                alert('El audio es demasiado grande para el servidor.');
            } else if (!err?.response) {
                alert('Fallo de conexión al subir el audio, intenta de nuevo.');
            } else {
                alert(err?.response?.data?.error || err?.response?.data?.errors?.audio?.[0] || 'No se pudo enviar el audio.');
            }
        } finally {
            setSending(false);
        }
    }

    const formatTime = useCallback((ts) => {
        if (!ts) return '';
        const date = new Date(ts);
        const now = new Date();
        const diffMs = now - date;
        const diffHours = diffMs / (1000 * 60 * 60);
        
        if (diffHours < 24 && date.getDate() === now.getDate()) {
            return date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
        }
        if (diffHours < 48) return 'Ayer';
        return date.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit' });
    }, []);

    function formatMessageTimeOnly(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        return date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
    }

    function formatFriendlyDate(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);

        if (date.toDateString() === today.toDateString()) return 'HOY';
        if (date.toDateString() === yesterday.toDateString()) return 'AYER';

        return date.toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' }).toUpperCase();
    }

    function formatDuration(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
    function handleInstanceChange(e) {
        stopPolling();
        setConversations([]);
        setMessages([]);
        setSelectedConversation(null);
        setSelectedInstanceId(e.target.value);
    }

    return (
        <>
            <Head title="Chat WhatsApp Business" />
            <div className="h-[calc(100vh-64px)] flex flex-col overflow-hidden bg-[#f0f2f5] dark:bg-[#0b141a]">
                {/* Clean Header */}
                <div className="bg-[#f0f2f5] dark:bg-[#202c33] border-b border-border/10 px-3 sm:px-4 py-2 flex justify-between items-center gap-2 z-20 shadow-sm">
                    <div className="flex items-center gap-2 sm:gap-3 min-w-0">
                        <div className="size-9 sm:size-10 rounded-full bg-teal-600 flex items-center justify-center text-white shadow-sm shrink-0">
                            <MessageSquare className="size-5" />
                        </div>
                        <div className="min-w-0">
                            <h2 className="text-sm font-bold text-foreground truncate">Canales de WhatsApp Business</h2>
                            <p className="hidden sm:block text-[10px] text-teal-600 dark:text-teal-400 font-black uppercase tracking-widest">Servicio Multi-agente</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 sm:gap-4 shrink-0">
                        <div className="flex items-center gap-2 bg-background/50 dark:bg-black/20 px-2 sm:px-3 py-1.5 rounded-lg border border-border/10">
                            <span className="hidden sm:inline text-[11px] font-bold text-muted-foreground uppercase opacity-60">Instancia</span>
                            <select
                                value={selectedInstanceId}
                                onChange={handleInstanceChange}
                                className="bg-transparent text-xs font-black focus:outline-none cursor-pointer uppercase tracking-tight"
                            >
                                <option value="" className="bg-background">Elegir...</option>
                                {instances.map(inst => (
                                    <option key={inst.id} value={inst.id} className="bg-background">
                                        {inst.name || 'SIN NOMBRE'}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button className="p-2 rounded-lg transition-colors border border-border/10 flex items-center justify-center bg-background/50 dark:bg-black/20 text-muted-foreground hover:text-foreground">
                                    <MoreHorizontal className="size-5" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-64 rounded-xl border-border/10 shadow-2xl">
                                <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Opciones de Chat</DropdownMenuLabel>
                                <DropdownMenuSeparator className="bg-border/5" />

                                <DropdownMenuItem
                                    onClick={() => handleNewTag(null)}
                                    className="flex items-center gap-3 py-3 px-3 cursor-pointer group text-teal-600"
                                >
                                    <div className="size-8 rounded-lg bg-teal-50 dark:bg-teal-900/20 text-teal-600 flex items-center justify-center group-hover:bg-teal-600 group-hover:text-white transition-all shadow-sm">
                                        <PlusCircle className="size-4" />
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="text-xs font-bold leading-none mb-1">Nueva Etiqueta</span>
                                        <span className="text-[9px] font-medium opacity-60 leading-none">Crear segmentación</span>
                                    </div>
                                </DropdownMenuItem>

                                {hasActiveFilters && (
                                    <>
                                        <DropdownMenuSeparator className="bg-border/5" />
                                        <DropdownMenuItem
                                            className="flex items-center gap-3 py-3 px-3 cursor-pointer group"
                                            onClick={() => { resetFilters(); loadConversations(); }}
                                        >
                                            <div className="size-8 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center group-hover:bg-slate-200 dark:group-hover:bg-slate-700 transition-colors shadow-sm">
                                                <Filter className="size-4" />
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-xs font-bold leading-none mb-1">Limpiar Filtros</span>
                                                <span className="text-[9px] font-medium text-muted-foreground leading-none">Restablecer vista</span>
                                            </div>
                                        </DropdownMenuItem>
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                {!selectedInstanceId ? (
                    <div className="flex-1 flex items-center justify-center">
                        <div className="text-center max-w-sm">
                            <div className="mx-auto size-20 rounded-full bg-muted/20 flex items-center justify-center mb-6 text-muted-foreground/20">
                                <MessageSquare className="size-10" />
                            </div>
                            <h2 className="text-lg font-bold text-foreground">Conecta una instancia</h2>
                            <p className="mt-2 text-sm text-muted-foreground">Selecciona un canal de WhatsApp para comenzar a gestionar tus chats.</p>
                        </div>
                    </div>
                ) : (
                    <div className="flex-1 flex overflow-hidden">
                        {/* Navegación contextual (Conversaciones / Canales / Etiquetas) */}
                        <div className="hidden lg:flex w-60 shrink-0 flex-col bg-[#fafafa] dark:bg-[#0d1418] border-r border-border/10 overflow-y-auto custom-scrollbar">
                            {/* Carpetas de conversaciones */}
                            <div className="px-3 pt-4 pb-2">
                                <p className="px-2 mb-1 text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Conversaciones</p>
                                {[
                                    { key: 'all', label: 'Todas las conversaciones', icon: MessageSquare, count: folderCounts.all },
                                    { key: 'mentions', label: 'Menciones', icon: AtSign, count: folderCounts.mentions },
                                    { key: 'unattended', label: 'Desatendido', icon: Clock, count: folderCounts.unattended },
                                ].map(item => {
                                    const active = folder === item.key;
                                    const Icon = item.icon;
                                    return (
                                        <button
                                            key={item.key}
                                            type="button"
                                            onClick={() => setFolder(item.key)}
                                            className={clsx(
                                                "w-full flex items-center gap-2.5 px-2 py-1.5 rounded-lg text-[13px] font-medium transition-colors group/nav",
                                                active
                                                    ? "bg-teal-600/10 text-teal-700 dark:text-teal-300 font-semibold"
                                                    : "text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 hover:text-foreground"
                                            )}
                                        >
                                            <Icon className={clsx("size-4 shrink-0", active ? "text-teal-600 dark:text-teal-400" : "text-muted-foreground/60")} />
                                            <span className="flex-1 text-left truncate">{item.label}</span>
                                            {item.count > 0 && (
                                                <span className={clsx(
                                                    "min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center rounded-full text-[10px] font-bold leading-none",
                                                    active ? "bg-teal-600 text-white" : "bg-[#e9edef] dark:bg-[#2a3942] text-muted-foreground/80"
                                                )}>
                                                    {item.count}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Canales (instancias) */}
                            {instances.length > 0 && (
                                <div className="px-3 py-2 border-t border-border/5">
                                    <p className="px-2 mb-1 text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Canales</p>
                                    {instances.map(inst => {
                                        const active = String(selectedInstanceId) === String(inst.id);
                                        return (
                                            <button
                                                key={inst.id}
                                                type="button"
                                                onClick={() => {
                                                    if (active) return;
                                                    stopPolling();
                                                    setConversations([]);
                                                    setMessages([]);
                                                    setSelectedConversation(null);
                                                    setSelectedInstanceId(String(inst.id));
                                                }}
                                                className={clsx(
                                                    "w-full flex items-center gap-2.5 px-2 py-1.5 rounded-lg text-[13px] font-medium transition-colors",
                                                    active
                                                        ? "bg-teal-600/10 text-teal-700 dark:text-teal-300 font-semibold"
                                                        : "text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 hover:text-foreground"
                                                )}
                                            >
                                                <span className={clsx("size-2 rounded-full shrink-0", inst.active === false ? "bg-slate-300 dark:bg-slate-600" : "bg-[#25d366]")} />
                                                <span className="flex-1 text-left truncate">{inst.name || 'Sin nombre'}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Etiquetas */}
                            <div className="px-3 py-2 border-t border-border/5">
                                <div className="flex items-center justify-between px-2 mb-1">
                                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Etiquetas</p>
                                    <div className="flex items-center gap-1">
                                        {selectedTagIds.length > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => setSelectedTagIds([])}
                                                title="Quitar filtro de etiquetas"
                                                className="text-[9px] font-bold uppercase tracking-wide text-teal-600 hover:text-teal-500"
                                            >
                                                Limpiar
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => handleNewTag(null)}
                                            title="Nueva etiqueta"
                                            className="p-0.5 rounded-md text-muted-foreground/60 hover:text-teal-600 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                                        >
                                            <PlusCircle className="size-3.5" />
                                        </button>
                                    </div>
                                </div>
                                {tags.length === 0 && (
                                    <p className="px-2 py-1 text-[11px] italic text-muted-foreground/50">Sin etiquetas todavía</p>
                                )}
                                {tags.map(tag => {
                                    const active = selectedTagIds.includes(String(tag.id));
                                    const count = folderCounts.tags?.[tag.id] ?? folderCounts.tags?.[String(tag.id)] ?? 0;
                                    return (
                                        <div
                                            key={tag.id}
                                            className={clsx(
                                                "group/tag w-full flex items-center gap-2.5 px-2 py-1.5 rounded-lg text-[13px] font-medium transition-colors cursor-pointer",
                                                active
                                                    ? "bg-teal-600/10 text-teal-700 dark:text-teal-300 font-semibold"
                                                    : "text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 hover:text-foreground"
                                            )}
                                            onClick={() => toggleTag(tag.id)}
                                        >
                                            <span className="size-2.5 rounded-sm shrink-0" style={{ backgroundColor: tag.color }} />
                                            <span className="flex-1 text-left truncate">{tag.name}</span>
                                            {active && <Check className="size-3.5 text-teal-600 shrink-0" />}
                                            {/* Acciones de gestión (aparecen al hover) */}
                                            <span className="hidden group-hover/tag:flex items-center gap-0.5 shrink-0">
                                                <button
                                                    type="button"
                                                    onClick={(e) => { e.stopPropagation(); openEditTag(tag); }}
                                                    title="Editar etiqueta"
                                                    className="p-0.5 rounded text-muted-foreground/60 hover:text-teal-600"
                                                >
                                                    <PencilIcon className="size-3" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={(e) => { e.stopPropagation(); deleteTag(tag); }}
                                                    title="Eliminar etiqueta"
                                                    className="p-0.5 rounded text-muted-foreground/60 hover:text-rose-600"
                                                >
                                                    <Trash2 className="size-3" />
                                                </button>
                                            </span>
                                            {/* Contador (se oculta en hover para dar espacio a las acciones) */}
                                            {!active && count > 0 && (
                                                <span className="group-hover/tag:hidden min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center rounded-full text-[10px] font-bold leading-none bg-[#e9edef] dark:bg-[#2a3942] text-muted-foreground/80">
                                                    {count}
                                                </span>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Sidebar - WhatsApp Web Style */}
                        <div className={clsx(
                            "w-full sm:w-80 lg:w-96 shrink-0 bg-white dark:bg-[#111b21] flex-col border-r border-border/10",
                            // En móvil: mostrar la lista sólo cuando no hay chat abierto; en sm+ siempre visible.
                            selectedConversation ? "hidden sm:flex" : "flex"
                        )}>
                            <div className="px-3 pt-3 pb-3">
                                <div className="flex items-center gap-2">
                                    <div className="relative flex-1">
                                        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <Search className="size-4 text-muted-foreground/60" />
                                        </div>
                                        <input
                                            type="text"
                                            placeholder="Busca o empieza un chat nuevo"
                                            value={searchQuery}
                                            onChange={e => setSearchQuery(e.target.value)}
                                            className="w-full pl-10 pr-10 py-2.5 bg-[#f0f2f5] dark:bg-[#202c33] border-none rounded-lg text-sm transition-all outline-none placeholder:text-muted-foreground/60 text-foreground"
                                        />
                                        {searchQuery && (
                                            <button
                                                onClick={() => setSearchQuery('')}
                                                className="absolute inset-y-0 right-0 pr-3 flex items-center"
                                            >
                                                <XIcon className="size-4 text-muted-foreground/60 hover:text-foreground transition-colors" />
                                            </button>
                                        )}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={openNewChat}
                                        title="Escribir a un número nuevo"
                                        className="shrink-0 size-9 flex items-center justify-center rounded-lg bg-teal-600 text-white hover:bg-teal-500 shadow-sm shadow-teal-600/20 transition-colors"
                                    >
                                        <PenSquare className="size-4" />
                                    </button>
                                </div>

                                {hasActiveFilters && (
                                    <button
                                        type="button"
                                        onClick={resetFilters}
                                        className="mt-2 w-full flex items-center justify-between gap-2 px-3 py-1.5 rounded-lg bg-teal-50 dark:bg-teal-950/30 border border-teal-100 dark:border-teal-900/40 text-teal-700 dark:text-teal-400 hover:bg-teal-100 dark:hover:bg-teal-900/40 transition-colors text-xs font-semibold"
                                        title="Quitar todos los filtros y volver a la vista base"
                                    >
                                        <span className="flex items-center gap-1.5">
                                            <Filter className="size-3.5" />
                                            {activeFilterCount === 1 ? '1 filtro activo' : `${activeFilterCount} filtros activos`}
                                        </span>
                                        <span className="flex items-center gap-1 text-[10px] uppercase tracking-wider opacity-80">
                                            Limpiar <XIcon className="size-3" />
                                        </span>
                                    </button>
                                )}
                            </div>

                            {/* Encabezado de conversaciones: título + estado + filtros + orden */}
                            <div className="px-3 pt-2">
                                <div className="flex items-center justify-between gap-2 mb-4">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <h3 className="text-sm font-bold text-foreground truncate">Conversaciones</h3>
                                        <span className="shrink-0 px-2 py-0.5 rounded-md bg-[#e9edef] dark:bg-[#2a3942] text-[10px] font-black uppercase tracking-wide text-teal-600 dark:text-teal-400">
                                            {STATUS_LABELS[statusFilter]}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1 shrink-0">
                                        {/* Filtrar conversaciones */}
                                        <div className="relative" ref={filterPopoverRef}>
                                            <button
                                                type="button"
                                                title="Filtrar conversaciones"
                                                onClick={() => openFilterPopover(!filterOpen)}
                                                className={clsx(
                                                    "size-7 flex items-center justify-center rounded-md transition-colors",
                                                    hasActiveFilters
                                                        ? "bg-teal-600 text-white shadow-sm"
                                                        : "text-muted-foreground/70 hover:text-foreground hover:bg-muted/50"
                                                )}
                                            >
                                                <Filter className="size-[15px]" />
                                            </button>
                                            {filterOpen && (
                                                <div className="absolute right-0 top-full mt-2 z-50 w-[340px] rounded-xl border border-border/10 bg-white dark:bg-[#202c33] shadow-2xl p-4">
                                                    <p className="text-sm font-bold text-foreground mb-3">Filtrar conversaciones</p>
                                                    <div className="space-y-2">
                                                        {draftFilters.map((row, i) => (
                                                            <div key={i} className="flex items-center gap-1.5">
                                                                <select
                                                                    value={row.field}
                                                                    onChange={(e) => updateDraftRow(i, { field: e.target.value })}
                                                                    className="shrink-0 w-[88px] bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-lg px-2 py-1.5 text-xs font-bold outline-none cursor-pointer"
                                                                >
                                                                    {FILTER_FIELDS.map(f => (
                                                                        <option key={f.value} value={f.value}>{f.label}</option>
                                                                    ))}
                                                                </select>
                                                                <span className="shrink-0 text-[10px] font-semibold text-muted-foreground">Es igual a</span>
                                                                {row.field === 'estado' && (
                                                                    <select
                                                                        value={row.value}
                                                                        onChange={(e) => updateDraftRow(i, { value: e.target.value })}
                                                                        className="flex-1 min-w-0 bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-lg px-2 py-1.5 text-xs font-bold outline-none cursor-pointer"
                                                                    >
                                                                        {STATUS_OPTIONS.map(o => (
                                                                            <option key={o.value} value={o.value}>{o.label}</option>
                                                                        ))}
                                                                    </select>
                                                                )}
                                                                {row.field === 'agente' && (
                                                                    <select
                                                                        value={row.value}
                                                                        onChange={(e) => updateDraftRow(i, { value: e.target.value })}
                                                                        className="flex-1 min-w-0 bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-lg px-2 py-1.5 text-xs font-bold outline-none cursor-pointer"
                                                                    >
                                                                        <option value="mine">Yo</option>
                                                                        <option value="unassigned">Sin asignar</option>
                                                                        {companyUsers.map(u => (
                                                                            <option key={u.id} value={String(u.id)}>{u.name}</option>
                                                                        ))}
                                                                    </select>
                                                                )}
                                                                {row.field === 'etiqueta' && (
                                                                    <select
                                                                        value={row.value}
                                                                        onChange={(e) => updateDraftRow(i, { value: e.target.value })}
                                                                        className="flex-1 min-w-0 bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-lg px-2 py-1.5 text-xs font-bold outline-none cursor-pointer"
                                                                    >
                                                                        <option value="">Elegir…</option>
                                                                        {tags.map(t => (
                                                                            <option key={t.id} value={String(t.id)}>{t.name}</option>
                                                                        ))}
                                                                    </select>
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeDraftRow(i)}
                                                                    title="Quitar filtro"
                                                                    className="shrink-0 size-7 flex items-center justify-center rounded-lg text-muted-foreground/60 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors"
                                                                >
                                                                    <Trash2 className="size-3.5" />
                                                                </button>
                                                            </div>
                                                        ))}
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={addDraftRow}
                                                        className="mt-3 flex items-center gap-1 text-xs font-bold text-teal-600 hover:underline"
                                                    >
                                                        <Plus className="size-3.5" /> Añadir Filtro
                                                    </button>
                                                    <div className="flex items-center justify-end gap-2 mt-4 pt-3 border-t border-border/10">
                                                        <button
                                                            type="button"
                                                            onClick={clearDraftFilters}
                                                            className="text-xs font-bold text-muted-foreground hover:text-foreground transition-colors"
                                                        >
                                                            Limpiar filtros
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={applyDraftFilters}
                                                            className="text-xs font-black text-white bg-teal-600 px-3 py-1.5 rounded-lg hover:bg-teal-700 transition-colors"
                                                        >
                                                            Aplicar filtros
                                                        </button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>

                                        {/* Ordenar */}
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <button
                                                    type="button"
                                                    title="Ordenar conversaciones"
                                                    className="size-7 flex items-center justify-center rounded-md text-muted-foreground/70 hover:text-foreground hover:bg-muted/50 transition-colors"
                                                >
                                                    <ArrowUpDown className="size-[15px]" />
                                                </button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-52 rounded-xl border-border/10 shadow-2xl">
                                                <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Ordenar por</DropdownMenuLabel>
                                                <DropdownMenuSeparator className="bg-border/5" />
                                                {SORT_OPTIONS.map(o => (
                                                    <DropdownMenuItem
                                                        key={o.value}
                                                        onClick={() => setSortBy(o.value)}
                                                        className="flex items-center justify-between py-2 px-3 cursor-pointer"
                                                    >
                                                        <span className="text-xs font-bold">{o.label}</span>
                                                        {sortBy === o.value && <Check className="size-3.5 text-teal-600" />}
                                                    </DropdownMenuItem>
                                                ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>

                                <div className="flex items-center gap-4 border-b border-border/10">
                                    {[
                                        { key: 'mine', label: 'Mías', count: tabCounts.mine },
                                        { key: 'unassigned', label: 'Sin asignar', count: tabCounts.unassigned },
                                        { key: 'all', label: 'Todos', count: tabCounts.all },
                                    ].map(tab => {
                                        const active = assignmentTab === tab.key;
                                        return (
                                            <button
                                                key={tab.key}
                                                type="button"
                                                onClick={() => setAssignmentTab(tab.key)}
                                                title={`${tab.label} (${tab.count})`}
                                                className={clsx(
                                                    "relative flex items-center gap-1.5 pb-3 -mb-px text-[12px] whitespace-nowrap transition-colors border-b-2",
                                                    active
                                                        ? "text-teal-600 dark:text-teal-400 font-bold border-teal-600 dark:border-teal-400"
                                                        : "text-muted-foreground hover:text-foreground font-semibold border-transparent"
                                                )}
                                            >
                                                <span className="truncate">{tab.label}</span>
                                                <span className={clsx(
                                                    "shrink-0 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold leading-none tabular-nums",
                                                    active
                                                        ? "bg-teal-600/10 text-teal-600 dark:text-teal-400"
                                                        : "bg-muted/60 text-muted-foreground/70"
                                                )}>
                                                    {tab.count}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div ref={sidebarScrollRef} className="flex-1 overflow-y-auto custom-scrollbar pt-1.5">
                                <ConversationList
                                    conversations={filteredConversations}
                                    selectedId={selectedConversation?.id}
                                    onSelect={selectConversation}
                                    onAttachTag={attachTag}
                                    onDetachTag={detachTag}
                                    onNewTag={handleNewTag}
                                    onAssign={assignConversation}
                                    tags={tags}
                                    companyUsers={companyUsers}
                                    isAdmin={isAdmin}
                                    formatTime={formatTime}
                                    selectionMode={selectionMode}
                                    selectedIds={selectedIds}
                                    onToggleSelect={toggleSelect}
                                />

                                {/* Sentinel for Infinite Scroll — solo cuando hay más por cargar */}
                                {hasMore && (
                                    <div ref={observerTarget} className="h-10 w-full flex items-center justify-center">
                                        {loadingMore && (
                                            <div className="flex items-center gap-2 text-[10px] font-black text-teal-600/40 uppercase tracking-widest">
                                                <div className="size-3 border-2 border-teal-600/20 border-t-teal-600 rounded-full animate-spin" />
                                                Cargando más...
                                            </div>
                                        )}
                                    </div>
                                )}

                                {filteredConversations.length === 0 && !loadingMore && (
                                    <div className="p-8 text-center">
                                        <p className="text-xs text-muted-foreground font-medium italic opacity-60">
                                            {statusFilter === 'closed'
                                                ? 'No hay chats cerrados'
                                                : assignmentTab === 'mine'
                                                    ? 'No tienes chats asignados'
                                                    : assignmentTab === 'unassigned'
                                                        ? 'No hay chats sin asignar'
                                                        : 'No se encontraron chats'}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Chat Area - WhatsApp Web Theme */}
                        <div
                            className={clsx(
                                "flex-1 min-w-0 flex-col bg-[#e5ddd5] dark:bg-[#0b141a] relative",
                                // En móvil: mostrar el chat sólo cuando hay conversación abierta; en sm+ siempre visible.
                                selectedConversation ? "flex" : "hidden sm:flex"
                            )}
                            onDragEnter={handleChatDragEnter}
                            onDragOver={handleChatDragOver}
                            onDragLeave={handleChatDragLeave}
                            onDrop={handleChatDrop}
                        >
                            {/* Theme Pattern Overlay */}
                            <div className="absolute inset-0 opacity-[0.06] dark:opacity-[0.4] pointer-events-none" />

                            {/* Overlay al arrastrar una imagen sobre el chat */}
                            {isDraggingFile && selectedConversation && (
                                <div className="absolute inset-0 z-30 flex items-center justify-center bg-teal-600/15 dark:bg-teal-500/15 backdrop-blur-sm pointer-events-none">
                                    <div className="flex flex-col items-center gap-3 rounded-2xl border-2 border-dashed border-teal-500 bg-white/90 dark:bg-[#202c33]/90 px-10 py-8 shadow-xl">
                                        <ImageIcon className="size-10 text-teal-600" />
                                        <p className="text-sm font-bold text-foreground">Suelta la imagen para enviarla</p>
                                    </div>
                                </div>
                            )}

                            {!selectedConversation ? (
                                <div className="flex-1 flex items-center justify-center relative z-10">
                                    <div className="text-center max-w-md p-10 bg-white/40 dark:bg-black/10 backdrop-blur-md rounded-[3rem] border border-white/20">
                                        <div className="mx-auto size-24 rounded-full bg-teal-600/10 flex items-center justify-center mb-8">
                                            <MessageSquare className="size-12 text-teal-600/40" />
                                        </div>
                                        <h3 className="text-2xl font-black text-foreground mb-3">Integra Plus para WhatsApp</h3>
                                        <p className="text-sm text-muted-foreground leading-relaxed">Envía y recibe mensajes sin necesidad de mantener tu teléfono conectado. <br/>Centraliza toda tu operación en un solo lugar.</p>
                                        <div className="mt-10 pt-8 border-t border-border/10 text-[10px] font-black text-muted-foreground/40 uppercase tracking-[0.3em]">Cifrado de extremo a extremo</div>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    {/* Chat Header */}
                                    <div className="bg-[#f0f2f5] dark:bg-[#202c33] px-3 sm:px-4 py-3 flex items-center justify-between gap-2 sm:gap-3 z-10 shadow-sm">
                                        <div className="flex items-center gap-2 sm:gap-3 min-w-0">
                                            {/* Volver a la lista de chats (sólo móvil) */}
                                            <button
                                                type="button"
                                                onClick={() => setSelectedConversation(null)}
                                                title="Volver a la lista"
                                                className="sm:hidden -ml-1 p-1.5 rounded-full text-muted-foreground hover:text-foreground hover:bg-black/5 dark:hover:bg-white/10 transition shrink-0"
                                            >
                                                <ArrowLeft className="size-5" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setShowContactPanel(true)}
                                                title="Ver información del contacto"
                                                className="size-10 rounded-full bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white font-bold text-sm overflow-hidden uppercase shrink-0 shadow-sm hover:ring-2 hover:ring-teal-400/60 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                            >
                                                {selectedConversation.initials}
                                            </button>
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <h3
                                                        onClick={() => setShowContactPanel(true)}
                                                        title="Ver información del contacto"
                                                        className="text-sm font-bold text-foreground leading-tight truncate cursor-pointer hover:underline"
                                                    >
                                                        {selectedConversation.contact?.name || selectedConversation.name}
                                                    </h3>
                                                    {selectedConversation.status === 'closed' && (
                                                        <span className="shrink-0 text-[9px] bg-slate-400/15 text-slate-500 dark:text-slate-400 px-1.5 py-0.5 rounded-full font-bold uppercase tracking-wide">Cerrada</span>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2.5 mt-0.5 min-w-0">
                                                    <span className="text-[11px] text-muted-foreground leading-tight shrink-0">{selectedConversation.phone_number}</span>
                                                    {selectedConversation.contact ? (
                                                        <button
                                                            onClick={() => setShowLinkContact(true)}
                                                            title="Contacto vinculado — clic para cambiar"
                                                            className="inline-flex items-center gap-1 text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline min-w-0 max-w-[150px]"
                                                        >
                                                            <Contact className="size-3 shrink-0" /> <span className="truncate">{selectedConversation.contact.name}</span>
                                                        </button>
                                                    ) : (
                                                        <button
                                                            onClick={() => setShowLinkContact(true)}
                                                            title="Vincular este número a un contacto"
                                                            className="inline-flex items-center gap-1 text-[11px] text-muted-foreground/80 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors shrink-0"
                                                        >
                                                            <UserPlus className="size-3" /> Vincular
                                                        </button>
                                                    )}
                                                    {selectedConversation.assigned_agent && (
                                                        <span title={`Asignado a ${selectedConversation.assigned_agent.name}`} className="inline-flex items-center gap-1 text-[11px] text-teal-600 dark:text-teal-400 min-w-0 max-w-[130px]">
                                                            <span className="size-1.5 rounded-full bg-teal-500 shrink-0" /> <span className="truncate font-medium">{selectedConversation.assigned_agent.name}</span>
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-1.5 shrink-0">
                                            {/* Admin Assignment Button */}
                                            {isAdmin && (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <button
                                                            title={selectedConversation.assigned_agent ? `Asignado a ${selectedConversation.assigned_agent.name}` : 'Asignar agente'}
                                                            className={clsx(
                                                                "size-9 flex items-center justify-center rounded-lg transition-colors",
                                                                selectedConversation.assigned_to
                                                                    ? "text-teal-600 dark:text-teal-400 bg-teal-500/10 hover:bg-teal-500/20"
                                                                    : "text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5"
                                                            )}
                                                        >
                                                            <UserPlus className="size-[18px]" />
                                                        </button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-64 rounded-xl border-border/10 shadow-2xl">
                                                        <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Asignar Agente</DropdownMenuLabel>
                                                        <DropdownMenuSeparator className="bg-border/5" />
                                                        
                                                        {/* Unassign option */}
                                                        <DropdownMenuItem 
                                                            onClick={() => assignConversation(selectedConversation.id, null)}
                                                            className="flex items-center gap-3 py-2.5 px-3 cursor-pointer group"
                                                        >
                                                            <div className="size-8 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center group-hover:bg-red-50 group-hover:text-red-600 transition-colors shadow-sm">
                                                                <XIcon className="size-4" />
                                                            </div>
                                                            <div className="flex flex-col">
                                                                <span className="text-xs font-bold leading-none mb-1">Sin Asignar</span>
                                                                <span className="text-[9px] font-medium text-muted-foreground leading-none">Remover responsable</span>
                                                            </div>
                                                            {!selectedConversation.assigned_to && <Check className="size-4 text-teal-600 ml-auto" />}
                                                        </DropdownMenuItem>
                                                        
                                                        <DropdownMenuSeparator className="bg-border/5" />
                                                        <div className="max-h-60 overflow-y-auto px-1 py-1">
                                                            {companyUsers.map(u => (
                                                                <DropdownMenuItem 
                                                                    key={u.id}
                                                                    onClick={() => assignConversation(selectedConversation.id, u.id)}
                                                                    className="flex items-center gap-3 py-2.5 px-3 cursor-pointer group"
                                                                >
                                                                    <div className={clsx(
                                                                        "size-8 rounded-lg flex items-center justify-center transition-all shadow-sm",
                                                                        Number(selectedConversation.assigned_to) === Number(u.id) ? "bg-teal-600 text-white" : "bg-slate-100 dark:bg-slate-800 text-slate-500 group-hover:bg-teal-50 group-hover:text-teal-600"
                                                                    )}>
                                                                        <User className="size-4" />
                                                                    </div>
                                                                    <div className="flex flex-col">
                                                                        <span className="text-xs font-bold leading-none mb-1">{u.name}</span>
                                                                        <span className="text-[9px] font-medium text-muted-foreground leading-none">{u.email}</span>
                                                                    </div>
                                                                    {Number(selectedConversation.assigned_to) === Number(u.id) && <Check className="size-4 text-teal-600 ml-auto" />}
                                                                </DropdownMenuItem>
                                                            ))}
                                                        </div>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            )}

                                            {/* Etiquetas de la conversación */}
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <button
                                                        title={selectedConversation.tags?.length ? `${selectedConversation.tags.length} etiqueta(s)` : 'Etiquetas'}
                                                        className={clsx(
                                                            "relative size-9 flex items-center justify-center rounded-lg transition-colors",
                                                            selectedConversation.tags?.length
                                                                ? "text-teal-600 dark:text-teal-400 bg-teal-500/10 hover:bg-teal-500/20"
                                                                : "text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5"
                                                        )}
                                                    >
                                                        <TagIcon className="size-[18px]" />
                                                        {selectedConversation.tags?.length > 0 && (
                                                            <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 flex items-center justify-center rounded-full bg-teal-600 text-white text-[9px] font-black leading-none ring-2 ring-[#f0f2f5] dark:ring-[#202c33]">
                                                                {selectedConversation.tags.length}
                                                            </span>
                                                        )}
                                                    </button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-64 rounded-xl border-border/10 shadow-2xl">
                                                    <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Asignar etiquetas</DropdownMenuLabel>
                                                    <DropdownMenuSeparator className="bg-border/5" />
                                                    <div className="max-h-60 overflow-y-auto px-1 py-1">
                                                        {tags.length === 0 ? (
                                                            <p className="px-3 py-3 text-xs text-muted-foreground text-center">No hay etiquetas aún.</p>
                                                        ) : tags.map(tag => {
                                                            const active = (selectedConversation.tags || []).some(t => Number(t.id) === Number(tag.id));
                                                            return (
                                                                <DropdownMenuItem
                                                                    key={tag.id}
                                                                    onSelect={(e) => {
                                                                        e.preventDefault();
                                                                        if (active) detachTag(selectedConversation.id, tag.id);
                                                                        else attachTag(selectedConversation.id, tag.id);
                                                                    }}
                                                                    className="flex items-center gap-3 py-2 px-3 cursor-pointer"
                                                                >
                                                                    <span className="size-3 rounded-full shrink-0 ring-1 ring-black/5" style={{ backgroundColor: tag.color }} />
                                                                    <span className="text-xs font-bold leading-none flex-1 truncate">{tag.name}</span>
                                                                    {active && <Check className="size-4 text-teal-600 ml-auto shrink-0" />}
                                                                </DropdownMenuItem>
                                                            );
                                                        })}
                                                    </div>
                                                    <DropdownMenuSeparator className="bg-border/5" />
                                                    <DropdownMenuItem
                                                        onSelect={(e) => {
                                                            e.preventDefault();
                                                            setTaggingConversationId(selectedConversation.id);
                                                            setIsCreatingTag(true);
                                                        }}
                                                        className="flex items-center gap-3 py-2.5 px-3 cursor-pointer text-teal-600"
                                                    >
                                                        <PlusCircle className="size-4" />
                                                        <span className="text-xs font-bold">Crear etiqueta</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>

                                            {/* Separador sutil entre indicadores y acciones */}
                                            <span className="w-px h-5 bg-border/40 mx-0.5" />

                                            {/* Cerrar / Reabrir conversación */}
                                            {selectedConversation.status === 'closed' ? (
                                                <button
                                                    onClick={() => setConversationStatus(selectedConversation.id, 'reopen')}
                                                    title="Reabrir conversación"
                                                    className="flex items-center gap-2 h-9 px-3.5 rounded-lg text-[12px] font-bold text-white bg-amber-500 hover:bg-amber-400 shadow-sm shadow-amber-500/25 transition-colors"
                                                >
                                                    <RotateCcw className="size-4" />
                                                    <span className="hidden md:inline">Reabrir</span>
                                                </button>
                                            ) : (
                                                <button
                                                    onClick={() => {
                                                        if (window.confirm('¿Cerrar esta conversación? Podrás reabrirla cuando quieras.')) {
                                                            setConversationStatus(selectedConversation.id, 'close');
                                                        }
                                                    }}
                                                    title="Cerrar conversación"
                                                    className="flex items-center gap-2 h-9 px-3.5 rounded-lg text-[12px] font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-sm shadow-emerald-600/25 transition-colors"
                                                >
                                                    <CheckCircle2 className="size-4" />
                                                    <span className="hidden md:inline">Cerrar</span>
                                                </button>
                                            )}

                                            {/* Separador entre la acción principal y las utilidades */}
                                            <span className="w-px h-5 bg-border/40 mx-0.5" />

                                            <button onClick={() => setShowCallHistory(true)} title="Historial de llamadas" aria-label="Historial de llamadas" className="size-9 flex items-center justify-center text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"><PhoneCall className="size-[18px]" /></button>
                                            <button title="Buscar en conversación" aria-label="Buscar en conversación" className="size-9 flex items-center justify-center text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"><Search className="size-[18px]" /></button>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <button title="Más opciones" className="size-9 flex items-center justify-center text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition-colors"><MoreVertical className="size-[18px]" /></button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-56 rounded-xl border-border/10 shadow-2xl">
                                                    <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Opciones</DropdownMenuLabel>
                                                    <DropdownMenuSeparator className="bg-border/5" />
                                                    {selectedConversation.status === 'closed' ? (
                                                        <DropdownMenuItem
                                                            onClick={() => setConversationStatus(selectedConversation.id, 'reopen')}
                                                            className="flex items-center gap-3 py-2.5 px-3 cursor-pointer"
                                                        >
                                                            <RotateCcw className="size-4 text-amber-600" />
                                                            <span className="text-xs font-bold">Reabrir conversación</span>
                                                        </DropdownMenuItem>
                                                    ) : (
                                                        <DropdownMenuItem
                                                            onClick={() => {
                                                                if (window.confirm('¿Cerrar esta conversación? Podrás reabrirla cuando quieras.')) {
                                                                    setConversationStatus(selectedConversation.id, 'close');
                                                                }
                                                            }}
                                                            className="flex items-center gap-3 py-2.5 px-3 cursor-pointer"
                                                        >
                                                            <CheckCircle2 className="size-4 text-emerald-600" />
                                                            <span className="text-xs font-bold">Cerrar conversación</span>
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuSeparator className="bg-border/5" />
                                                    <DropdownMenuItem
                                                        onClick={() => {
                                                            if (window.confirm('¿Eliminar esta conversación y TODOS sus mensajes? Esta acción es irreversible.')) {
                                                                deleteConversation(selectedConversation.id);
                                                            }
                                                        }}
                                                        className="flex items-center gap-3 py-2.5 px-3 cursor-pointer text-rose-600 focus:text-rose-600"
                                                    >
                                                        <Trash2 className="size-4" />
                                                        <span className="text-xs font-bold">Eliminar conversación</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </div>

                                    {/* Messages Area */}
                                    <div
                                        ref={messagesContainerRef}
                                        onScroll={handleMessagesScroll}
                                        className="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-8 space-y-2 custom-scrollbar relative z-10 flex flex-col"
                                    >
                                        {messages.map((msg, i) => {
                                            const isOut = msg.direction === 'outbound';
                                            const quoted = msg.reply_to_wamid ? messagesByWamid.get(msg.reply_to_wamid) : null;
                                            const canReply = !!msg.wamid && !msg.is_internal;

                                            // Handle Date Separator
                                            const prevMsg = i > 0 ? messages[i-1] : null;
                                            const currDate = new Date(msg.created_at).toDateString();
                                            const prevDate = prevMsg ? new Date(prevMsg.created_at).toDateString() : null;
                                            const showDateSeparator = currDate !== prevDate;
                                            
                                            return (
                                                <Fragment key={msg.id}>
                                                    {showDateSeparator && (
                                                        <div className="flex justify-center my-4">
                                                            <div className="bg-white/80 dark:bg-[#202c33]/80 backdrop-blur-sm px-3 py-1.5 rounded-lg text-[10.5px] font-bold text-muted-foreground/80 dark:text-white/40 shadow-sm uppercase tracking-widest pointer-events-none">
                                                                {formatFriendlyDate(msg.created_at)}
                                                            </div>
                                                        </div>
                                                    )}
                                                    
                                                    {msg.is_internal ? (
                                                        <div className="flex justify-center my-2 px-2">
                                                            <div className="w-full max-w-[90%] lg:max-w-[75%] bg-amber-50 dark:bg-amber-900/20 border border-amber-300/60 dark:border-amber-700/40 rounded-lg px-3 py-2 shadow-sm">
                                                                <div className="flex items-center gap-1.5 mb-1">
                                                                    <StickyNote className="size-3.5 text-amber-600 dark:text-amber-400" />
                                                                    <span className="text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                                                        Nota interna · {msg.sender?.name || 'Agente'}
                                                                    </span>
                                                                    <span className="ml-auto text-[9px] font-semibold text-amber-700/60 dark:text-amber-300/50 uppercase">{formatMessageTimeOnly(msg.created_at)}</span>
                                                                </div>
                                                                <p className="text-[13px] leading-[18px] whitespace-pre-wrap break-words text-amber-950 dark:text-amber-100">{msg.content}</p>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                    <div
                                                        className={`flex mb-2 sm:mb-3 items-center gap-1 group/msg ${isOut ? 'justify-end pr-4' : 'justify-start pl-4'}`}
                                                    >
                                                        {isOut && canReply && (
                                                            <button
                                                                type="button"
                                                                onClick={() => { setReplyingTo(msg); setTimeout(() => messageInputRef.current?.focus(), 0); }}
                                                                title="Responder"
                                                                className="opacity-0 group-hover/msg:opacity-100 transition-opacity size-7 shrink-0 flex items-center justify-center rounded-full text-muted-foreground hover:text-teal-600 hover:bg-black/5 dark:hover:bg-white/10"
                                                            >
                                                                <Reply className="size-4" />
                                                            </button>
                                                        )}
                                                        <div
                                                            id={`msg-${msg.id}`}
                                                            className={`relative px-2.5 py-1.5 shadow-sm min-w-[96px] max-w-[80%] lg:max-w-[65%] group rounded-lg transition-shadow ${
                                                                isOut
                                                                    ? 'bg-[#dcf8c6] dark:bg-[#005c4b] text-[#111b21] dark:text-[#e9edef] rounded-tr-none'
                                                                    : 'bg-white dark:bg-[#202c33] text-[#111b21] dark:text-[#e9edef] rounded-tl-none'
                                                            }`}
                                                            title={new Date(msg.created_at).toLocaleString('es-CO')}
                                                        >
                                                            {/* Triangle/Tail tip */}
                                                            {isOut ? (
                                                                <div className="absolute top-0 -right-2 w-0 h-0 border-t-[10px] border-t-transparent border-l-[12px] border-l-[#dcf8c6] dark:border-l-[#005c4b]" />
                                                            ) : (
                                                                <div className="absolute top-0 -left-2 w-0 h-0 border-t-[10px] border-t-transparent border-r-[12px] border-r-white dark:border-r-[#202c33]" />
                                                            )}

                                                            <div className="flex flex-col relative">
                                                                {quoted && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => scrollToMessage(quoted.id)}
                                                                        className="mb-1 w-full text-left rounded-md border-l-4 border-teal-500 bg-black/10 dark:bg-white/10 px-2 py-1 hover:bg-black/15 dark:hover:bg-white/15 transition-colors overflow-hidden"
                                                                    >
                                                                        <p className="text-[10.5px] font-bold text-teal-700 dark:text-teal-300 truncate">{quotedAuthor(quoted)}</p>
                                                                        <p className="text-[11px] text-muted-foreground dark:text-white/50 truncate">{quotedSnippet(quoted) || 'Mensaje'}</p>
                                                                    </button>
                                                                )}
                                                                {msg.reply_to_wamid && !quoted && (
                                                                    <div className="mb-1 w-full rounded-md border-l-4 border-teal-500/60 bg-black/10 dark:bg-white/10 px-2 py-1 overflow-hidden">
                                                                        <p className="text-[11px] italic text-muted-foreground dark:text-white/50 truncate">↩︎ Mensaje citado</p>
                                                                    </div>
                                                                )}
                                                                {isOut && msg.sender?.name && (
                                                                    <span className="text-[10.5px] font-bold text-teal-700 dark:text-teal-300 mb-0.5 leading-tight">
                                                                        {msg.sender.name}
                                                                    </span>
                                                                )}
                                                                {msg.type === 'text' && (
                                                                    <p className="text-[12.5px] leading-[17px] whitespace-pre-wrap break-words pr-20 pb-1">{msg.content}</p>
                                                                )}
                                                                
                                                                {msg.type === 'image' && (
                                                                    <div className="p-1 pb-1">
                                                                        <div className="relative group overflow-hidden rounded-md bg-black/5 mb-2">
                                                                            <img
                                                                                src={msg.media_url}
                                                                                onLoad={handleMediaLoad}
                                                                                className="max-h-[300px] w-full object-cover cursor-pointer hover:opacity-90 transition-opacity"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    setSelectedImage(msg.media_url);
                                                                                }}
                                                                                alt="media"
                                                                            />
                                                                        </div>
                                                                        {msg.content && <p className="text-[12.5px] leading-[17px] mb-6 pr-10">{msg.content}</p>}
                                                                    </div>
                                                                )}
                                                                
                                                                {msg.type === 'audio' && (
                                                                    <div className="min-w-[220px] py-1 pr-14">
                                                                        <audio controls src={msg.media_url} className="w-full h-8 opacity-90 scale-90 origin-left" />
                                                                    </div>
                                                                )}

                                                                {msg.type === 'video' && (
                                                                    <div className="p-1 pb-1">
                                                                        <video
                                                                            controls
                                                                            src={msg.media_url}
                                                                            onLoadedData={handleMediaLoad}
                                                                            className="max-h-[320px] w-full rounded-md bg-black mb-2"
                                                                        />
                                                                        {msg.content && <p className="text-[12.5px] leading-[17px] mb-6 pr-10">{msg.content}</p>}
                                                                    </div>
                                                                )}

                                                                {msg.type === 'sticker' && (
                                                                    <div className="p-1 pb-5">
                                                                        <img
                                                                            src={msg.media_url}
                                                                            onLoad={handleMediaLoad}
                                                                            className="max-h-[140px] max-w-[140px] object-contain"
                                                                            alt="sticker"
                                                                        />
                                                                    </div>
                                                                )}

                                                                {msg.type === 'document' && (
                                                                    <a
                                                                        href={msg.media_url}
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                        className={`flex items-center gap-3 p-3 rounded-lg my-1 w-full transition-colors ${isOut ? 'bg-black/5 hover:bg-black/10' : 'bg-[#f0f2f5] dark:bg-[#111b21] hover:bg-black/5'}`}
                                                                    >
                                                                        <div className="size-10 rounded bg-[#4f5659] text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                                                            <Paperclip className="size-5" />
                                                                        </div>
                                                                        <div className="min-w-0 pr-10">
                                                                            <p className="text-[12.5px] font-semibold truncate">{msg.filename || 'Documento'}</p>
                                                                            <p className="text-[10px] opacity-50 uppercase tracking-wide">Descargar</p>
                                                                        </div>
                                                                    </a>
                                                                )}

                                                                {(msg.type === 'location' || (msg.type !== 'contacts' && msg.metadata?.location)) && (() => {
                                                                    const loc = msg.metadata?.location || {};
                                                                    const hasCoords = loc.latitude != null && loc.longitude != null;
                                                                    return (
                                                                        <div
                                                                            onClick={(e) => { if (hasCoords) { e.stopPropagation(); setSelectedLocation(loc); } }}
                                                                            className={`flex flex-col rounded-lg my-1 w-full min-w-[220px] overflow-hidden transition-colors ${hasCoords ? 'cursor-pointer' : ''} ${isOut ? 'bg-black/5 hover:bg-black/10' : 'bg-[#f0f2f5] dark:bg-[#111b21] hover:bg-black/5'}`}
                                                                        >
                                                                            {hasCoords && (
                                                                                <div className="relative h-[130px] w-full bg-black/10">
                                                                                    {/* pointer-events-none: el clic lo captura la tarjeta y abre el visor interno */}
                                                                                    <iframe
                                                                                        src={`https://www.google.com/maps?q=${loc.latitude},${loc.longitude}&hl=es&z=15&output=embed`}
                                                                                        className="w-full h-full border-0 pointer-events-none"
                                                                                        loading="lazy"
                                                                                        title="Mapa"
                                                                                    />
                                                                                </div>
                                                                            )}
                                                                            <div className="flex items-center gap-3 p-3">
                                                                                <div className="size-10 rounded bg-[#4f5659] text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                                                                    <MapPin className="size-5" />
                                                                                </div>
                                                                                <div className="min-w-0 pr-10">
                                                                                    <p className="text-[12.5px] font-semibold truncate">{loc.name || 'Ubicación compartida'}</p>
                                                                                    {loc.address && <p className="text-[11px] opacity-70 truncate">{loc.address}</p>}
                                                                                    {hasCoords && <p className="text-[10px] opacity-50 uppercase tracking-wide">Ver mapa</p>}
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    );
                                                                })()}

                                                                {(msg.type === 'contacts' || (msg.type !== 'location' && msg.metadata?.contacts)) && (
                                                                    <div className={`flex flex-col gap-1.5 p-3 rounded-lg my-1 w-full ${isOut ? 'bg-black/5' : 'bg-[#f0f2f5] dark:bg-[#111b21]'}`}>
                                                                        {(msg.metadata?.contacts?.length ? msg.metadata.contacts : [null]).map((c, ci) => {
                                                                            const name = c?.name?.formatted_name || msg.content || 'Contacto';
                                                                            const phones = (c?.phones || []).map(p => p.phone).filter(Boolean);
                                                                            return (
                                                                                <div key={ci} className="flex items-center gap-3 pr-10">
                                                                                    <div className="size-10 rounded-full bg-[#4f5659] text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                                                                        <User className="size-5" />
                                                                                    </div>
                                                                                    <div className="min-w-0">
                                                                                        <p className="text-[12.5px] font-semibold truncate">{name}</p>
                                                                                        {phones.map((p, pi) => (
                                                                                            <p key={pi} className="text-[11px] opacity-70 truncate flex items-center gap-1"><Phone className="size-3" /> {p}</p>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                )}

                                                                {msg.type === 'template' && (
                                                                    <div className={`flex flex-col gap-2 p-3 rounded-lg my-1 w-full ${isOut ? 'bg-black/5' : 'bg-[#f0f2f5] dark:bg-[#111b21]'}`}>
                                                                        {msg.media_url ? (
                                                                            <a
                                                                                href={msg.media_url}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                className={`flex items-center gap-3 -m-1 p-1 rounded-lg transition-colors ${isOut ? 'hover:bg-black/10' : 'hover:bg-black/5'}`}
                                                                            >
                                                                                <div className="size-10 rounded bg-[#4f5659] text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                                                                    <Paperclip className="size-5" />
                                                                                </div>
                                                                                <div className="min-w-0">
                                                                                    <p className="text-[10px] font-black opacity-40 uppercase tracking-[0.2em]">Plantilla WhatsApp</p>
                                                                                    <p className="text-[12.5px] font-semibold truncate">{msg.filename || 'Documento'}</p>
                                                                                    <p className="text-[10px] opacity-50 uppercase tracking-wide">Descargar</p>
                                                                                </div>
                                                                            </a>
                                                                        ) : (
                                                                            <div className="flex items-center gap-3">
                                                                                <div className="size-10 rounded bg-[#4f5659] text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                                                                    <Paperclip className="size-5" />
                                                                                </div>
                                                                                <div className="min-w-0">
                                                                                    <p className="text-[10px] font-black opacity-40 uppercase tracking-[0.2em]">Plantilla WhatsApp</p>
                                                                                </div>
                                                                            </div>
                                                                        )}
                                                                        <div className="mt-1 pb-6">
                                                                            <p className="text-[12.5px] leading-relaxed whitespace-pre-wrap break-words opacity-90">{msg.content || 'Sin descripción'}</p>
                                                                        </div>
                                                                    </div>
                                                                )}

                                                                {/* Fallback: tipos no reconocidos (o type vacío) muestran el contenido en vez de una burbuja vacía */}
                                                                {!['text', 'image', 'audio', 'video', 'sticker', 'document', 'location', 'contacts', 'template'].includes(msg.type)
                                                                    && !msg.metadata?.location && !msg.metadata?.contacts && (
                                                                    <p className="text-[12.5px] leading-[17px] whitespace-pre-wrap break-words pr-20 pb-1">{msg.content || 'Mensaje sin contenido'}</p>
                                                                )}

                                                                {/* Internal timestamp inside bubble - ALWAYS HH:mm */}
                                                                <div className="absolute bottom-[0px] right-[0px] flex items-center gap-1.5 p-1">
                                                                    <span className="text-[9px] font-bold text-muted-foreground/60 dark:text-white/30 whitespace-nowrap uppercase tracking-tighter">{formatMessageTimeOnly(msg.created_at)}</span>
                                                                    {isOut && <StatusIcons status={msg.status} />}
                                                                </div>

                                                                {/* Reacción (emoji) del contacto a este mensaje */}
                                                                {msg.metadata?.reaction && (
                                                                    <span className={`absolute -bottom-3 ${isOut ? 'right-1' : 'left-1'} text-[13px] leading-none bg-white dark:bg-[#202c33] border border-border/40 rounded-full px-1.5 py-0.5 shadow-sm`}>
                                                                        {msg.metadata.reaction}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                        {!isOut && canReply && (
                                                            <button
                                                                type="button"
                                                                onClick={() => { setReplyingTo(msg); setTimeout(() => messageInputRef.current?.focus(), 0); }}
                                                                title="Responder"
                                                                className="opacity-0 group-hover/msg:opacity-100 transition-opacity size-7 shrink-0 flex items-center justify-center rounded-full text-muted-foreground hover:text-teal-600 hover:bg-black/5 dark:hover:bg-white/10"
                                                            >
                                                                <Reply className="size-4" />
                                                            </button>
                                                        )}
                                                    </div>
                                                    )}
                                                </Fragment>
                                            );
                                        })}
                                    </div>

                                    {/* Banner de error de envío (Meta) */}
                                    {sendError && (
                                        <div className="bg-[#f0f2f5] dark:bg-[#202c33] px-3 pt-2 z-10">
                                            <div className="flex items-start gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-[12px] text-rose-700 dark:text-rose-300">
                                                <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                                <span className="flex-1 leading-snug">{sendError}</span>
                                                <button onClick={() => setSendError(null)} className="shrink-0 hover:text-rose-900 dark:hover:text-rose-100">
                                                    <XIcon className="size-3.5" />
                                                </button>
                                            </div>
                                        </div>
                                    )}

                                    {/* Ventana de 24h vencida: hay que reabrir con una plantilla aprobada */}
                                    {windowExpired && composerMode === 'reply' && !isRecording && (
                                        <div className="bg-[#f0f2f5] dark:bg-[#202c33] px-3 pt-2 z-10">
                                            <div className="flex items-start gap-2 rounded-lg border border-amber-300/50 bg-amber-50 dark:bg-amber-900/15 px-3 py-2 text-[12px] text-amber-800 dark:text-amber-200">
                                                <Clock className="size-4 mt-0.5 shrink-0" />
                                                <span className="flex-1 leading-snug">
                                                    La ventana de 24h para responder libremente a este contacto ya expiró. Debes enviar primero una <b>plantilla aprobada</b>.
                                                </span>
                                                <button
                                                    onClick={() => setShowTemplates(true)}
                                                    className="shrink-0 rounded-md bg-amber-600 hover:bg-amber-500 text-white text-[11px] font-bold px-2.5 py-1 transition-colors"
                                                >
                                                    Enviar plantilla
                                                </button>
                                            </div>
                                        </div>
                                    )}

                                    {/* Composer mode strip: Responder / Nota interna */}
                                    {!isRecording && (
                                        <div className="bg-[#f0f2f5] dark:bg-[#202c33] px-3 pt-2.5 flex items-center gap-1.5 z-10">
                                            <button
                                                onClick={() => { setComposerMode('reply'); closeMentions(); }}
                                                className={clsx(
                                                    "px-2 py-0.5 rounded-md text-[11px] font-semibold transition-colors flex items-center gap-1",
                                                    composerMode === 'reply'
                                                        ? "bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-300 ring-1 ring-teal-200/80 dark:ring-teal-800/50"
                                                        : "text-muted-foreground hover:text-foreground hover:bg-black/5 dark:hover:bg-white/5"
                                                )}
                                            >
                                                <MessageSquare className="size-3" /> Responder
                                            </button>
                                            <button
                                                onClick={() => { setComposerMode('note'); closeQuickReplies(); }}
                                                className={clsx(
                                                    "px-2 py-0.5 rounded-md text-[11px] font-semibold transition-colors flex items-center gap-1",
                                                    composerMode === 'note'
                                                        ? "bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 ring-1 ring-amber-200/80 dark:ring-amber-800/50"
                                                        : "text-muted-foreground hover:text-foreground hover:bg-black/5 dark:hover:bg-white/5"
                                                )}
                                            >
                                                <StickyNote className="size-3" /> Nota interna
                                            </button>
                                        </div>
                                    )}

                                    {/* Input Area - WhatsApp Web Style */}
                                    <div className={clsx(
                                        "px-3 pt-2 pb-3 flex items-stretch gap-2 z-10 text-foreground min-h-[62px] transition-colors",
                                        composerMode === 'note' && !isRecording
                                            ? "bg-amber-50 dark:bg-amber-900/20"
                                            : "bg-[#f0f2f5] dark:bg-[#202c33]"
                                    )}>
                                        {recordedAudio ? (
                                            <div className="flex-1 flex items-center gap-2 bg-white dark:bg-[#2a3942] rounded-lg px-3 py-1.5 animate-in fade-in slide-in-from-bottom-2 duration-200">
                                                <button
                                                    onClick={discardRecordedAudio}
                                                    disabled={sending}
                                                    className="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition-colors shrink-0 disabled:opacity-40"
                                                    title="Descartar grabación"
                                                >
                                                    <Trash2 className="size-5" />
                                                </button>
                                                <audio controls src={recordedAudio.url} className="flex-1 h-9 min-w-0" />
                                                <button
                                                    onClick={sendRecordedAudio}
                                                    disabled={sending}
                                                    className="p-2 bg-teal-600 text-white rounded-full hover:bg-teal-700 transition-colors shadow-sm shrink-0 disabled:opacity-50"
                                                    title="Enviar audio"
                                                >
                                                    <Send className={`size-5 ${sending ? 'animate-pulse' : ''}`} />
                                                </button>
                                            </div>
                                        ) : !isRecording ? (
                                            <div className="flex-1 flex flex-col gap-2 min-w-0">
                                                {/* Barra de cita: mensaje al que se está respondiendo */}
                                                {replyingTo && (
                                                    <div className="flex items-stretch gap-2 rounded-lg bg-black/5 dark:bg-white/5 border-l-4 border-teal-500 pl-2 pr-1 py-1.5">
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-[11px] font-bold text-teal-700 dark:text-teal-300 truncate">
                                                                Respondiendo a {quotedAuthor(replyingTo)}
                                                            </p>
                                                            <p className="text-[11.5px] text-muted-foreground truncate">{quotedSnippet(replyingTo) || 'Mensaje'}</p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => setReplyingTo(null)}
                                                            title="Cancelar respuesta"
                                                            className="self-center size-7 shrink-0 flex items-center justify-center rounded-full text-muted-foreground hover:text-foreground hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                                                        >
                                                            <X className="size-4" />
                                                        </button>
                                                    </div>
                                                )}
                                                {/* Área de texto a todo el ancho */}
                                                <div className="relative">
                                                    {composerMode === 'reply' && qrOpen && (
                                                        <QuickReplyPicker
                                                            matches={qrMatches}
                                                            activeIndex={qrIndex}
                                                            onSelect={applyQuickReply}
                                                            query={qrQuery}
                                                        />
                                                    )}
                                                    {composerMode === 'note' && mentionOpen && mentionMatches.length > 0 && (
                                                        <div className="absolute bottom-full mb-2 left-0 w-72 max-h-56 overflow-y-auto bg-white dark:bg-[#2a3942] border border-border rounded-lg shadow-lg z-50">
                                                            {mentionMatches.map((u, idx) => (
                                                                <button
                                                                    key={u.id}
                                                                    onMouseDown={(e) => { e.preventDefault(); applyMention(u); }}
                                                                    className={clsx(
                                                                        "w-full flex items-center gap-2 px-3 py-2 text-left transition-colors",
                                                                        idx === mentionIndex ? "bg-amber-50 dark:bg-amber-900/20" : "hover:bg-muted"
                                                                    )}
                                                                >
                                                                    <AtSign className="size-3.5 text-amber-600" />
                                                                    <span className="text-sm font-semibold">{u.name}</span>
                                                                    <span className="text-[10px] text-muted-foreground ml-auto truncate">{u.email}</span>
                                                                </button>
                                                            ))}
                                                        </div>
                                                    )}
                                                    <textarea
                                                        ref={messageInputRef}
                                                        rows={1}
                                                        placeholder={composerMode === 'note'
                                                            ? "Nota privada para el equipo (escribe @ para mencionar a un agente) — el cliente no la verá"
                                                            : "Escribe un mensaje aquí (escribe / para respuestas rápidas — Shift+Enter para nueva línea)"}
                                                        value={newMessage}
                                                        onChange={handleComposerChange}
                                                        onKeyDown={handleComposerKeyDown}
                                                        onPaste={handleComposerPaste}
                                                        className={clsx(
                                                            "block w-full border-none rounded-lg px-4 py-2.5 text-[14.5px] leading-snug outline-none placeholder:text-muted-foreground/60 text-foreground resize-none overflow-y-auto whitespace-pre-wrap break-words",
                                                            composerMode === 'note'
                                                                ? "bg-white dark:bg-[#2a3942] ring-1 ring-amber-300/70 dark:ring-amber-700/50"
                                                                : "bg-white dark:bg-[#2a3942]"
                                                        )}
                                                        style={{ maxHeight: '160px' }}
                                                    />
                                                </div>

                                                {/* Barra de herramientas inferior (estilo Chatwoot) */}
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="flex items-center gap-0.5">
                                                        {composerMode === 'reply' ? (
                                                            <>
                                                                <button title="Insertar emoji" aria-label="Insertar emoji" className="size-9 flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-black/5 dark:hover:bg-white/5 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"><Smile className="size-[19px]" /></button>
                                                                <label title="Adjuntar imagen" aria-label="Adjuntar imagen" className="size-9 flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-black/5 dark:hover:bg-white/5 cursor-pointer transition-colors focus-within:outline-none focus-within:ring-2 focus-within:ring-teal-500">
                                                                    <Paperclip className="size-[19px]" />
                                                                    <input type="file" onChange={handleFileUpload} accept="image/*" className="hidden" />
                                                                </label>
                                                                <button
                                                                    onClick={startRecording}
                                                                    className="size-9 flex items-center justify-center rounded-lg text-muted-foreground hover:text-teal-600 hover:bg-black/5 dark:hover:bg-white/5 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                                                    title="Grabar audio"
                                                                    aria-label="Grabar audio"
                                                                >
                                                                    <Mic className="size-[19px]" />
                                                                </button>
                                                                <button
                                                                    onClick={() => setShowTemplates(true)}
                                                                    className="size-9 flex items-center justify-center rounded-lg text-[#25d366] hover:bg-[#25d366]/10 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#25d366]"
                                                                    title="Enviar plantilla de WhatsApp"
                                                                    aria-label="Enviar plantilla de WhatsApp"
                                                                >
                                                                    <FileText className="size-[19px]" />
                                                                </button>
                                                            </>
                                                        ) : (
                                                            <button
                                                                disabled
                                                                title="Programar nota (próximamente)"
                                                                className="size-9 flex items-center justify-center rounded-lg text-amber-400/70 cursor-not-allowed"
                                                            >
                                                                <Clock className="size-[19px]" />
                                                            </button>
                                                        )}
                                                    </div>

                                                    <button
                                                        onClick={composerMode === 'note' ? sendNote : sendMessage}
                                                        disabled={!newMessage.trim() || sending}
                                                        title={composerMode === 'note' ? 'Guardar nota interna' : 'Enviar mensaje'}
                                                        aria-label={composerMode === 'note' ? 'Guardar nota interna' : 'Enviar mensaje'}
                                                        className={clsx(
                                                            "flex items-center gap-2 h-9 px-4 rounded-lg text-[13px] font-bold text-white shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none",
                                                            composerMode === 'note'
                                                                ? "bg-amber-500 hover:bg-amber-400 shadow-amber-500/25 focus-visible:ring-amber-400"
                                                                : "bg-teal-600 hover:bg-teal-500 shadow-teal-600/25 focus-visible:ring-teal-500"
                                                        )}
                                                    >
                                                        <span>{composerMode === 'note' ? 'Guardar nota' : 'Enviar'}</span>
                                                        <Send className={`size-4 ${sending ? 'animate-pulse' : ''}`} />
                                                    </button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="flex-1 flex items-center justify-between bg-white dark:bg-[#2a3942] rounded-lg px-4 py-2 animate-in fade-in slide-in-from-bottom-2 duration-200">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex items-center gap-2">
                                                        <div className="size-2.5 bg-red-500 rounded-full animate-pulse" />
                                                        <span className="text-sm font-bold tabular-nums">{formatDuration(recordingDuration)}</span>
                                                    </div>
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    <button 
                                                        onClick={cancelRecording}
                                                        className="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition-colors"
                                                        title="Cancelar grabación"
                                                    >
                                                        <Trash2 className="size-5" />
                                                    </button>
                                                    <button 
                                                        onClick={stopRecording}
                                                        className="p-2 bg-teal-600 text-white rounded-full hover:bg-teal-700 transition-colors shadow-sm"
                                                        title="Enviar grabación"
                                                    >
                                                        <Send className="size-5" />
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                </>
                            )}
                        </div>
                    </div>
                )}

                {paymentModal && (
                    <PaymentModal
                        integration={paymentModal.integration}
                        conversation={selectedConversation}
                        onClose={() => setPaymentModal(null)}
                    />
                )}

                {/* Lightbox / Image Zoom */}
                {selectedImage && (
                    <div
                        className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-black/95 animate-in fade-in duration-300"
                        onClick={() => setSelectedImage(null)}
                    >
                        <div className="absolute top-0 w-full p-4 flex justify-between items-center text-white bg-gradient-to-b from-black/50 to-transparent">
                            <div className="flex items-center gap-3">
                                <button className="p-2 hover:bg-white/10 rounded-full transition-colors"><ArrowLeft className="size-6" /></button>
                                <span className="text-sm font-medium">Visualización de archivo</span>
                            </div>
                            <button className="p-2 hover:bg-white/10 rounded-full transition-colors"><MoreVertical className="size-6" /></button>
                        </div>
                        <img
                            src={selectedImage}
                            className="max-w-[90%] max-h-[80vh] object-contain shadow-2xl animate-in zoom-in-105 duration-300"
                            onClick={e => e.stopPropagation()}
                            alt="full view"
                        />
                    </div>
                )}

                {/* Visor de ubicación embebido: el mapa se ve dentro del aplicativo */}
                {selectedLocation && (
                    <div
                        className="fixed inset-0 z-[100] flex items-center justify-center bg-black/95 p-4 animate-in fade-in duration-300"
                        onClick={() => setSelectedLocation(null)}
                    >
                        <div
                            className="w-full max-w-3xl rounded-2xl bg-white dark:bg-[#202c33] shadow-2xl overflow-hidden flex flex-col animate-in zoom-in-105 duration-300"
                            onClick={e => e.stopPropagation()}
                        >
                            <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/40">
                                <div className="flex items-center gap-3 min-w-0">
                                    <div className="size-9 rounded-full bg-teal-600 text-white flex items-center justify-center flex-shrink-0">
                                        <MapPin className="size-4.5" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold text-foreground truncate">{selectedLocation.name || 'Ubicación compartida'}</p>
                                        {selectedLocation.address && <p className="text-xs text-muted-foreground truncate">{selectedLocation.address}</p>}
                                    </div>
                                </div>
                                <button
                                    onClick={() => setSelectedLocation(null)}
                                    className="p-2 rounded-full hover:bg-black/5 dark:hover:bg-white/10 transition-colors text-muted-foreground"
                                >
                                    <XIcon className="size-5" />
                                </button>
                            </div>
                            <iframe
                                src={`https://www.google.com/maps?q=${selectedLocation.latitude},${selectedLocation.longitude}&hl=es&z=17&output=embed`}
                                className="w-full h-[65vh] border-0"
                                loading="lazy"
                                title="Ubicación compartida"
                            />
                        </div>
                    </div>
                )}

                {/* Previsualización de imagen antes de enviar (arrastrar/pegar/adjuntar) */}
                {pendingImage && (
                    <div
                        className="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 p-4 animate-in fade-in duration-200"
                        onClick={closeImagePreview}
                    >
                        <div
                            className="w-full max-w-lg rounded-2xl bg-[#202c33] shadow-2xl overflow-hidden flex flex-col"
                            onClick={e => e.stopPropagation()}
                        >
                            <div className="flex items-center justify-between px-4 py-3 border-b border-white/10">
                                <span className="text-sm font-bold text-white">Enviar imagen</span>
                                <button
                                    onClick={closeImagePreview}
                                    className="p-1.5 text-white/70 hover:text-white hover:bg-white/10 rounded-full transition-colors"
                                    title="Cancelar"
                                >
                                    <X className="size-5" />
                                </button>
                            </div>

                            <div className="flex items-center justify-center bg-black/40 p-4">
                                <img
                                    src={pendingImage.url}
                                    alt="previsualización"
                                    className="max-h-[55vh] max-w-full object-contain rounded-lg"
                                />
                            </div>

                            <div className="flex items-center gap-2 p-3 border-t border-white/10">
                                <input
                                    type="text"
                                    autoFocus
                                    value={imageCaption}
                                    onChange={e => setImageCaption(e.target.value)}
                                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); confirmSendImage(); } }}
                                    placeholder="Añade un pie de foto (opcional)"
                                    maxLength={1024}
                                    className="flex-1 h-10 rounded-lg bg-[#2a3942] text-white placeholder:text-white/40 px-4 text-sm outline-none focus:ring-2 focus:ring-teal-500/50"
                                />
                                <button
                                    onClick={confirmSendImage}
                                    disabled={sending}
                                    title="Enviar imagen"
                                    className="size-10 shrink-0 flex items-center justify-center rounded-full bg-teal-600 hover:bg-teal-500 text-white disabled:opacity-50 transition-colors"
                                >
                                    <Send className="size-5" />
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* Link / associate contact modal */}
                {showLinkContact && selectedConversation && (
                    <LinkContactModal
                        conversationId={selectedConversation.id}
                        defaultPhone={selectedConversation.phone_number}
                        defaultName={selectedConversation.name}
                        currentContact={selectedConversation.contact}
                        onClose={() => setShowLinkContact(false)}
                        onLinked={(contact) => {
                            const linkedName = contact?.name;
                            setSelectedConversation(prev => prev ? { ...prev, contact, name: linkedName || prev.name } : prev);
                            setConversations(prev => prev.map(c => c.id === selectedConversation.id ? { ...c, contact, name: linkedName || c.name } : c));
                            setShowLinkContact(false);
                        }}
                    />
                )}

                {/* Panel lateral con la información del contacto */}
                {selectedConversation && (
                    <Sheet open={showContactPanel} onOpenChange={(open) => { setShowContactPanel(open); if (!open) setEditingContact(false); }}>
                        <SheetContent side="right" className="w-full sm:max-w-md p-0 gap-0">
                            <div className="flex flex-col h-full overflow-y-auto">
                                {/* Encabezado del panel */}
                                <div className="flex flex-col items-center text-center gap-3 px-6 pt-10 pb-6 bg-gradient-to-b from-teal-500/10 to-transparent border-b border-border/40">
                                    <div className="size-20 rounded-full bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white font-bold text-2xl uppercase shadow-md">
                                        {selectedConversation.initials}
                                    </div>
                                    <div className="min-w-0 w-full">
                                        <h2 className="text-lg font-bold text-foreground truncate">{selectedConversation.contact?.name || selectedConversation.name}</h2>
                                        <p className="text-sm text-muted-foreground">{selectedConversation.phone_number}</p>
                                    </div>
                                    <span className={clsx(
                                        "inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full",
                                        selectedConversation.status === 'closed'
                                            ? "bg-slate-400/15 text-slate-500 dark:text-slate-400"
                                            : "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400"
                                    )}>
                                        <span className={clsx("size-1.5 rounded-full", selectedConversation.status === 'closed' ? "bg-slate-400" : "bg-emerald-500")} />
                                        {selectedConversation.status === 'closed' ? 'Cerrada' : 'Abierta'}
                                    </span>
                                </div>

                                {editingContact ? (
                                    /* ----- Modo edición ----- */
                                    <div className="flex flex-col gap-4 px-6 py-6">
                                        <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Editar contacto</p>

                                        <div className="space-y-1.5">
                                            <label className="text-sm font-medium text-foreground">Nombre</label>
                                            <input
                                                type="text"
                                                value={contactForm.name}
                                                onChange={e => setContactForm(f => ({ ...f, name: e.target.value }))}
                                                placeholder="Nombre del contacto"
                                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/50"
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <label className="text-sm font-medium text-foreground">Teléfono</label>
                                            <input
                                                type="text"
                                                value={selectedConversation.phone_number}
                                                disabled
                                                className="flex h-9 w-full rounded-md border border-input bg-muted/40 px-3 py-1 text-sm text-muted-foreground cursor-not-allowed"
                                            />
                                            <p className="text-[11px] text-muted-foreground">El número de WhatsApp no se puede cambiar.</p>
                                        </div>

                                        <div className="space-y-1.5">
                                            <label className="text-sm font-medium text-foreground">Email</label>
                                            <input
                                                type="email"
                                                value={contactForm.email}
                                                onChange={e => setContactForm(f => ({ ...f, email: e.target.value }))}
                                                placeholder="correo@ejemplo.com"
                                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/50"
                                            />
                                        </div>

                                        {selectedConversation.contact?.id && (
                                            <div className="space-y-1.5">
                                                <label className="text-sm font-medium text-foreground">Notas</label>
                                                <textarea
                                                    value={contactForm.notes}
                                                    onChange={e => setContactForm(f => ({ ...f, notes: e.target.value }))}
                                                    rows={3}
                                                    placeholder="Notas internas sobre el contacto"
                                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/50"
                                                />
                                            </div>
                                        )}

                                        {contactError && <p className="text-xs text-rose-600">{contactError}</p>}

                                        <div className="flex gap-2 pt-1">
                                            <button
                                                onClick={saveContact}
                                                disabled={savingContact}
                                                className="flex-1 flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-bold text-white bg-teal-600 hover:bg-teal-500 disabled:opacity-60 transition-colors"
                                            >
                                                {savingContact ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
                                                Guardar
                                            </button>
                                            <button
                                                onClick={() => setEditingContact(false)}
                                                disabled={savingContact}
                                                className="h-10 px-4 rounded-lg text-sm font-bold text-foreground bg-black/5 dark:bg-white/5 hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                                            >
                                                Cancelar
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        {/* ----- Modo lectura ----- */}
                                        <div className="flex flex-col gap-5 px-6 py-6">
                                            {/* Datos de contacto */}
                                            <div className="space-y-3">
                                                <div className="flex items-center justify-between">
                                                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Datos de contacto</p>
                                                    <button
                                                        onClick={openContactEdit}
                                                        className="inline-flex items-center gap-1 text-[11px] font-bold text-teal-600 dark:text-teal-400 hover:underline"
                                                    >
                                                        <PencilIcon className="size-3" /> Editar
                                                    </button>
                                                </div>
                                                <div className="flex items-center gap-3 text-sm">
                                                    <Phone className="size-4 text-muted-foreground shrink-0" />
                                                    <span className="text-foreground truncate">{selectedConversation.phone_number}</span>
                                                </div>
                                                <div className="flex items-center gap-3 text-sm">
                                                    <Mail className="size-4 text-muted-foreground shrink-0" />
                                                    {selectedConversation.contact?.email ? (
                                                        <span className="text-foreground truncate">{selectedConversation.contact.email}</span>
                                                    ) : (
                                                        <span className="text-muted-foreground italic">Sin email</span>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-3 text-sm">
                                                    <Contact className="size-4 text-muted-foreground shrink-0" />
                                                    {selectedConversation.contact ? (
                                                        <span className="text-foreground truncate">{selectedConversation.contact.name}</span>
                                                    ) : (
                                                        <span className="text-muted-foreground italic">Sin contacto vinculado</span>
                                                    )}
                                                </div>
                                                {selectedConversation.contact?.notes && (
                                                    <div className="flex items-start gap-3 text-sm">
                                                        <StickyNote className="size-4 text-muted-foreground shrink-0 mt-0.5" />
                                                        <span className="text-foreground whitespace-pre-wrap">{selectedConversation.contact.notes}</span>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Agente asignado */}
                                            <div className="space-y-3">
                                                <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Agente asignado</p>
                                                <div className="flex items-center gap-3 text-sm">
                                                    <User className="size-4 text-muted-foreground shrink-0" />
                                                    {selectedConversation.assigned_agent ? (
                                                        <span className="text-foreground truncate">{selectedConversation.assigned_agent.name}</span>
                                                    ) : (
                                                        <span className="text-muted-foreground italic">Sin asignar</span>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Etiquetas */}
                                            <div className="space-y-3">
                                                <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50">Etiquetas</p>
                                                {selectedConversation.tags?.length ? (
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {selectedConversation.tags.map(tag => (
                                                            <span
                                                                key={tag.id}
                                                                className="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full"
                                                                style={{ backgroundColor: `${tag.color}1a`, color: tag.color }}
                                                            >
                                                                <span className="size-2 rounded-full" style={{ backgroundColor: tag.color }} />
                                                                {tag.name}
                                                            </span>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <div className="flex items-center gap-3 text-sm">
                                                        <TagIcon className="size-4 text-muted-foreground shrink-0" />
                                                        <span className="text-muted-foreground italic">Sin etiquetas</span>
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        {/* Acciones rápidas */}
                                        <div className="mt-auto border-t border-border/40 px-6 py-4 flex flex-col gap-2">
                                            <button
                                                onClick={openContactEdit}
                                                className="flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-bold text-white bg-teal-600 hover:bg-teal-500 transition-colors"
                                            >
                                                <PencilIcon className="size-4" /> Editar contacto
                                            </button>
                                            <button
                                                onClick={() => { setShowContactPanel(false); setShowLinkContact(true); }}
                                                className="flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-bold text-foreground bg-black/5 dark:bg-white/5 hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                                            >
                                                {selectedConversation.contact ? <Contact className="size-4" /> : <UserPlus className="size-4" />}
                                                {selectedConversation.contact ? 'Cambiar contacto vinculado' : 'Vincular contacto'}
                                            </button>
                                            {selectedConversation.status === 'closed' ? (
                                                <button
                                                    onClick={() => { setConversationStatus(selectedConversation.id, 'reopen'); setShowContactPanel(false); }}
                                                    className="flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-bold text-white bg-amber-500 hover:bg-amber-400 transition-colors"
                                                >
                                                    <RotateCcw className="size-4" /> Reabrir conversación
                                                </button>
                                            ) : (
                                                <button
                                                    onClick={() => {
                                                        if (window.confirm('¿Cerrar esta conversación? Podrás reabrirla cuando quieras.')) {
                                                            setConversationStatus(selectedConversation.id, 'close');
                                                            setShowContactPanel(false);
                                                        }
                                                    }}
                                                    className="flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 transition-colors"
                                                >
                                                    <CheckCircle2 className="size-4" /> Cerrar conversación
                                                </button>
                                            )}
                                        </div>
                                    </>
                                )}
                            </div>
                        </SheetContent>
                    </Sheet>
                )}

                {/* Selector de plantillas de WhatsApp para la conversación activa */}
                {showTemplates && selectedConversation && (
                    <TemplatePickerModal
                        conversationId={selectedConversation.id}
                        instanceId={selectedInstanceId}
                        windowClosedHint={windowExpired}
                        contactName={selectedConversation.name}
                        onClose={() => setShowTemplates(false)}
                        onSent={(message, preview) => {
                            if (message) setMessages(prev => [...prev, message]);
                            setConversations(prev => prev.map(c => c.id === selectedConversation.id
                                ? { ...c, last_message: preview, last_message_at: new Date().toISOString() }
                                : c));
                            setShowTemplates(false);
                            requestAnimationFrame(() => messageInputRef.current?.focus());
                        }}
                    />
                )}

                {showCallHistory && selectedConversation && (
                    <CallHistoryModal
                        conversationId={selectedConversation.id}
                        contactName={selectedConversation.name || selectedConversation.phone_number}
                        onClose={() => setShowCallHistory(false)}
                    />
                )}

                {incomingCall && (
                    <div className="fixed top-4 left-1/2 -translate-x-1/2 z-[130] w-[min(92vw,380px)] animate-in slide-in-from-top duration-300">
                        <div className="rounded-2xl bg-card border border-teal-500/40 shadow-2xl shadow-teal-500/10 p-4 flex items-center gap-3">
                            <div className="size-11 rounded-full bg-teal-500/15 flex items-center justify-center shrink-0">
                                <PhoneIncoming className="size-5 text-teal-600 dark:text-teal-400 animate-pulse" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-[11px] font-bold uppercase tracking-wide text-teal-600 dark:text-teal-400">Llamada entrante</p>
                                <p className="text-sm font-semibold text-foreground truncate">{incomingCall.from || 'Número desconocido'}</p>
                            </div>
                            <button
                                onClick={() => setIncomingCall(null)}
                                title="Descartar"
                                className="size-9 flex items-center justify-center text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-lg shrink-0"
                            >
                                <X className="size-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Create Tag Modal */}
                {/* Modal: Nuevo chat hacia un número */}
                {newChatOpen && (
                    <div className="fixed inset-0 z-[115] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200" onClick={closeNewChat}>
                        <div className="w-full max-w-md rounded-3xl border border-border/10 bg-white dark:bg-[#1c272e] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200" onClick={e => e.stopPropagation()}>
                            {/* Header */}
                            <div className="relative bg-gradient-to-br from-teal-600 to-emerald-600 px-6 py-5 text-white">
                                <button onClick={closeNewChat} className="absolute top-4 right-4 p-1.5 hover:bg-white/15 rounded-full transition-colors">
                                    <XIcon className="size-4" />
                                </button>
                                <div className="flex items-center gap-3">
                                    <div className="size-11 rounded-2xl bg-white/15 flex items-center justify-center">
                                        <PenSquare className="size-6" />
                                    </div>
                                    <div>
                                        <h3 className="font-bold text-lg leading-tight">Nuevo chat</h3>
                                        <p className="text-xs text-white/80">
                                            {newChatStep === 'phone' ? 'Escribe a un número directamente' : 'Abre la conversación con una plantilla'}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {newChatStep === 'phone' ? (
                                <div className="px-6 py-5 space-y-4">
                                    <div>
                                        <label className="text-sm font-medium text-foreground flex items-center gap-1.5 mb-1.5">
                                            <Phone className="size-3.5 text-muted-foreground" /> Número de WhatsApp
                                        </label>
                                        <input
                                            value={newChatPhone}
                                            onChange={e => setNewChatPhone(e.target.value)}
                                            onKeyDown={e => { if (e.key === 'Enter' && !newChatLoading) startNewChat(); }}
                                            placeholder="Ej: 57 300 123 4567"
                                            autoFocus
                                            inputMode="tel"
                                            className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500/50"
                                        />
                                        <p className="mt-1.5 text-[11px] text-muted-foreground">Incluye el código de país (Colombia = 57), sin signos ni espacios obligatorios.</p>
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium text-foreground flex items-center gap-1.5 mb-1.5">
                                            <User className="size-3.5 text-muted-foreground" /> Nombre <span className="text-muted-foreground font-normal">(opcional)</span>
                                        </label>
                                        <input
                                            value={newChatName}
                                            onChange={e => setNewChatName(e.target.value)}
                                            onKeyDown={e => { if (e.key === 'Enter' && !newChatLoading) startNewChat(); }}
                                            placeholder="Nombre del contacto"
                                            className="w-full rounded-xl border border-border/70 bg-background/80 px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500/50"
                                        />
                                    </div>

                                    {newChatError && (
                                        <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                                            <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                            <span>{newChatError}</span>
                                        </div>
                                    )}

                                    <div className="flex gap-2 pt-1">
                                        <button type="button" onClick={closeNewChat} className="flex-1 rounded-xl border border-border/70 py-2.5 text-sm font-medium text-foreground hover:bg-muted/40 transition-colors">
                                            Cancelar
                                        </button>
                                        <button type="button" onClick={startNewChat} disabled={newChatLoading} className="flex-1 rounded-xl bg-teal-600 hover:bg-teal-500 text-white py-2.5 text-sm font-medium flex items-center justify-center gap-2 transition-colors disabled:opacity-60">
                                            {newChatLoading ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                                            Continuar
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="px-6 py-5 space-y-4">
                                    <div className="rounded-xl border border-amber-300/50 bg-amber-50 dark:bg-amber-900/15 px-3 py-2.5 text-[12px] text-amber-800 dark:text-amber-200 flex items-start gap-2">
                                        <Clock className="size-4 mt-0.5 shrink-0" />
                                        <span>Este número no tiene una conversación abierta (ventana de 24h). Para iniciar debes enviar una <b>plantilla aprobada</b>.</span>
                                    </div>

                                    {templatesLoading ? (
                                        <div className="py-8 flex items-center justify-center text-muted-foreground">
                                            <Loader2 className="size-5 animate-spin" />
                                        </div>
                                    ) : chatTemplates.length === 0 ? (
                                        <div className="py-6 text-center">
                                            <FileText className="size-8 mx-auto text-muted-foreground/30 mb-2" />
                                            <p className="text-sm text-muted-foreground">No hay plantillas aprobadas en esta instancia.</p>
                                        </div>
                                    ) : (
                                        <>
                                            <div>
                                                <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-1.5 block">Plantilla</label>
                                                <div className="max-h-44 overflow-y-auto custom-scrollbar rounded-xl border border-border/60 divide-y divide-border/40">
                                                    {chatTemplates.map(t => {
                                                        const active = selectedTemplate?.name === t.name && selectedTemplate?.language === t.language;
                                                        const body = templateBodyComponent(t);
                                                        return (
                                                            <button
                                                                key={`${t.name}-${t.language}`}
                                                                type="button"
                                                                onClick={() => pickTemplate(t)}
                                                                className={clsx(
                                                                    "w-full text-left px-3 py-2.5 transition-colors",
                                                                    active ? "bg-teal-600/10" : "hover:bg-muted/40"
                                                                )}
                                                            >
                                                                <div className="flex items-center gap-2">
                                                                    <span className="text-[13px] font-semibold text-foreground truncate">{t.name}</span>
                                                                    <span className="text-[9px] font-bold uppercase tracking-wide text-muted-foreground/70 bg-muted/60 rounded px-1.5 py-0.5 shrink-0">{t.language}</span>
                                                                    {active && <Check className="size-3.5 text-teal-600 ml-auto shrink-0" />}
                                                                </div>
                                                                {body?.text && <p className="text-[11px] text-muted-foreground truncate mt-0.5">{body.text}</p>}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>

                                            {selectedTemplate && templateHeader && (
                                                <div className="space-y-2">
                                                    <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground block">
                                                        Encabezado · {HEADER_MEDIA_LABEL[templateHeader.format]}
                                                    </label>
                                                    {templateHeader.format !== 'LOCATION' ? (
                                                        <>
                                                            <input
                                                                type="file"
                                                                accept={HEADER_MEDIA_ACCEPT[templateHeader.format]}
                                                                disabled={templateHeader.uploading}
                                                                onChange={e => uploadTemplateHeaderFile(e.target.files?.[0])}
                                                                className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-teal-600/10 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-teal-600 hover:file:bg-teal-600/20 disabled:opacity-60"
                                                            />
                                                            {templateHeader.uploading && (
                                                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                                    <Loader2 className="size-3.5 animate-spin" /> Subiendo a Meta…
                                                                </p>
                                                            )}
                                                            {!templateHeader.uploading && templateHeader.mediaId && (
                                                                <p className="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                                                                    <Check className="size-3.5" /> Archivo listo{templateHeader.filename ? `: ${templateHeader.filename}` : ''}
                                                                </p>
                                                            )}
                                                            {templateHeader.error && <p className="text-xs text-rose-600 dark:text-rose-400">{templateHeader.error}</p>}
                                                        </>
                                                    ) : (
                                                        <div className="grid grid-cols-2 gap-2">
                                                            <input
                                                                value={templateHeader.lat}
                                                                onChange={e => setTemplateHeader(h => ({ ...h, lat: e.target.value }))}
                                                                placeholder="Latitud"
                                                                className="rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                                            />
                                                            <input
                                                                value={templateHeader.lng}
                                                                onChange={e => setTemplateHeader(h => ({ ...h, lng: e.target.value }))}
                                                                placeholder="Longitud"
                                                                className="rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                                            />
                                                            <input
                                                                value={templateHeader.name}
                                                                onChange={e => setTemplateHeader(h => ({ ...h, name: e.target.value }))}
                                                                placeholder="Nombre (opcional)"
                                                                className="col-span-2 rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                                            />
                                                            <input
                                                                value={templateHeader.address}
                                                                onChange={e => setTemplateHeader(h => ({ ...h, address: e.target.value }))}
                                                                placeholder="Dirección (opcional)"
                                                                className="col-span-2 rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                                            />
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            {selectedTemplate && templateVars.length > 0 && (
                                                <div className="space-y-2">
                                                    <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground block">Variables</label>
                                                    {templateVars.map((v, i) => (
                                                        <input
                                                            key={i}
                                                            value={v}
                                                            onChange={e => setTemplateVars(prev => prev.map((x, j) => (j === i ? e.target.value : x)))}
                                                            placeholder={`Variable {{${i + 1}}}`}
                                                            className="w-full rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                                        />
                                                    ))}
                                                </div>
                                            )}

                                            {selectedTemplate && (
                                                <div className="rounded-xl bg-[#dcf8c6] dark:bg-[#005c4b] px-3 py-2 text-[13px] text-[#111b21] dark:text-[#e9edef] whitespace-pre-wrap break-words">
                                                    {fillTemplate(templateBodyComponent(selectedTemplate)?.text, templateVars) || '(Plantilla sin cuerpo de texto)'}
                                                </div>
                                            )}
                                        </>
                                    )}

                                    {newChatError && (
                                        <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                                            <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                            <span>{newChatError}</span>
                                        </div>
                                    )}

                                    <div className="flex gap-2 pt-1">
                                        <button type="button" onClick={closeNewChat} className="flex-1 rounded-xl border border-border/70 py-2.5 text-sm font-medium text-foreground hover:bg-muted/40 transition-colors">
                                            Cerrar
                                        </button>
                                        <button type="button" onClick={sendNewChatTemplate} disabled={newChatLoading || !selectedTemplate || templateHeader?.uploading} className="flex-1 rounded-xl bg-teal-600 hover:bg-teal-500 text-white py-2.5 text-sm font-medium flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                                            {newChatLoading ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                                            Enviar y abrir
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {isCreatingTag && (
                    <div className="fixed inset-0 z-[110] flex items-center justify-center bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-[#1c272e] rounded-3xl shadow-2xl w-full max-w-sm mx-4 p-6 border border-border/10">
                            <div className="flex items-center justify-between mb-6">
                                <h3 className="text-lg font-black text-foreground">{editingTag ? 'Editar Etiqueta' : 'Nueva Etiqueta'}</h3>
                                <button onClick={closeTagModal} className="p-2 hover:bg-black/5 dark:hover:bg-white/5 rounded-full">
                                    <XIcon className="size-4 text-muted-foreground" />
                                </button>
                            </div>
                            
                            <div className="space-y-4">
                                <div>
                                    <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-2 block">Nombre</label>
                                    <input 
                                        type="text" 
                                        autoFocus
                                        value={newTagName}
                                        onChange={e => setNewTagName(e.target.value)}
                                        placeholder="Ej: Cliente VIP, Cobro..."
                                        className="w-full bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-teal-600/20 outline-none"
                                    />
                                </div>
                                
                                <div>
                                    <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-2 block">Color Distintivo</label>
                                    <div className="flex flex-wrap gap-2">
                                        {['#0d9488', '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#d97706', '#059669', '#4b5563'].map(color => (
                                            <button 
                                                key={color}
                                                onClick={() => setNewTagColor(color)}
                                                className={clsx(
                                                    "size-8 rounded-full border-2 transition-all shadow-sm",
                                                    newTagColor === color ? "border-white dark:border-[#1c272e] ring-2 ring-teal-600 scale-110" : "border-transparent opacity-80 hover:opacity-100"
                                                )}
                                                style={{ backgroundColor: color }}
                                            />
                                        ))}
                                    </div>
                                </div>
                                
                                <div className="pt-4 flex gap-3">
                                    {editingTag ? (
                                        <button
                                            onClick={() => deleteTag(editingTag)}
                                            title="Eliminar etiqueta"
                                            className="py-3 px-4 text-sm font-bold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-2xl transition-colors flex items-center justify-center"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    ) : (
                                        <button
                                            onClick={closeTagModal}
                                            className="flex-1 py-3 text-sm font-bold text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-2xl transition-colors"
                                        >
                                            Cancelar
                                        </button>
                                    )}
                                    <button
                                        onClick={editingTag ? updateTag : createTag}
                                        disabled={!newTagName.trim()}
                                        className="flex-1 py-3 bg-teal-600 text-white text-sm font-black rounded-2xl shadow-lg shadow-teal-600/20 disabled:opacity-50 hover:scale-[1.02] active:scale-95 transition-all"
                                    >
                                        {editingTag ? 'Guardar Cambios' : 'Crear Etiqueta'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar {
                    width: 6px;
                }
                .custom-scrollbar::-webkit-scrollbar-track {
                    background: transparent;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(134, 150, 160, 0.2);
                    border-radius: 10px;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb:hover {
                    background: rgba(134, 150, 160, 0.4);
                }
            `}} />
        </>
    );
}

function LinkContactModal({ conversationId, defaultPhone, defaultName, currentContact, onClose, onLinked }) {
    const [tab, setTab] = useState('existing');
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    // New-contact form
    const [name, setName] = useState(defaultName ?? '');
    const [phone, setPhone] = useState(defaultPhone ?? '');
    const [email, setEmail] = useState('');

    useEffect(() => {
        if (tab !== 'existing') return;
        let active = true;
        setLoading(true);
        const t = setTimeout(async () => {
            try {
                const res = await axios.get('/api/contacts/list', { params: { search: query } });
                if (active) setResults(res.data ?? []);
            } catch {
                if (active) setResults([]);
            } finally {
                if (active) setLoading(false);
            }
        }, 250);
        return () => { active = false; clearTimeout(t); };
    }, [query, tab]);

    async function attach(payload) {
        setSubmitting(true);
        setError(null);
        try {
            const res = await axios.post(`/api/chat/conversations/${conversationId}/attach-contact`, payload);
            onLinked(res.data.contact);
        } catch (err) {
            setError(err?.response?.data?.message || 'No se pudo vincular el contacto.');
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200" onClick={onClose}>
            <div className="w-full max-w-md rounded-3xl border border-border/10 bg-white dark:bg-[#1c272e] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200" onClick={e => e.stopPropagation()}>
                <div className="relative bg-gradient-to-br from-indigo-600 to-violet-600 px-6 py-5 text-white">
                    <button onClick={onClose} className="absolute top-4 right-4 p-1.5 hover:bg-white/15 rounded-full transition-colors">
                        <XIcon className="size-4" />
                    </button>
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-2xl bg-white/15 flex items-center justify-center">
                            <Contact className="size-6" />
                        </div>
                        <div>
                            <h3 className="font-bold text-lg leading-tight">Vincular contacto</h3>
                            <p className="text-xs text-white/80">
                                {currentContact ? `Vinculado a ${currentContact.name}` : `Asocia ${defaultPhone} a un contacto`}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-1 px-4 pt-4">
                    <button
                        onClick={() => setTab('existing')}
                        className={clsx("flex-1 px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition-colors", tab === 'existing' ? "bg-indigo-600 text-white" : "text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5")}
                    >
                        Existente
                    </button>
                    <button
                        onClick={() => setTab('new')}
                        className={clsx("flex-1 px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition-colors", tab === 'new' ? "bg-indigo-600 text-white" : "text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5")}
                    >
                        Nuevo
                    </button>
                </div>

                <div className="p-4">
                    {error && <p className="mb-3 text-xs font-medium text-rose-600">{error}</p>}

                    {tab === 'existing' ? (
                        <>
                            <div className="relative mb-3">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                <input
                                    type="text"
                                    value={query}
                                    onChange={e => setQuery(e.target.value)}
                                    placeholder="Buscar contacto por nombre o teléfono..."
                                    autoFocus
                                    className="w-full bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-xl pl-9 pr-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-600/20 outline-none"
                                />
                            </div>
                            <div className="max-h-64 overflow-y-auto custom-scrollbar -mx-1 px-1">
                                {loading ? (
                                    <div className="flex items-center justify-center py-8 text-muted-foreground"><Loader2 className="size-5 animate-spin" /></div>
                                ) : results.length === 0 ? (
                                    <p className="text-center text-sm text-muted-foreground py-8">Sin contactos. Crea uno en la pestaña «Nuevo».</p>
                                ) : (
                                    <div className="space-y-1">
                                        {results.map(c => (
                                            <button
                                                key={c.id}
                                                disabled={submitting}
                                                onClick={() => attach({ contact_id: c.id })}
                                                className="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors disabled:opacity-50"
                                            >
                                                <div className="size-9 rounded-full bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold uppercase shrink-0">
                                                    {(c.name ?? '?').slice(0, 2)}
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="text-sm font-semibold text-foreground truncate">{c.name}</p>
                                                    <p className="text-xs text-muted-foreground truncate">{c.phone_number}</p>
                                                </div>
                                                {currentContact?.id === c.id && <Check className="size-4 text-indigo-600 ml-auto shrink-0" />}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </>
                    ) : (
                        <form onSubmit={(e) => { e.preventDefault(); attach({ name: name.trim(), phone_number: phone.trim(), email: email.trim() || null }); }} className="space-y-3">
                            <div>
                                <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-1.5 block">Nombre</label>
                                <input type="text" value={name} onChange={e => setName(e.target.value)} required placeholder="Nombre del contacto" className="w-full bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-600/20 outline-none" />
                            </div>
                            <div>
                                <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-1.5 block">Teléfono</label>
                                <input type="text" value={phone} onChange={e => setPhone(e.target.value)} required className="w-full bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-xl px-4 py-2.5 text-sm font-mono focus:ring-2 focus:ring-indigo-600/20 outline-none" />
                            </div>
                            <div>
                                <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-1.5 block">Correo (opcional)</label>
                                <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="correo@ejemplo.com" className="w-full bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-600/20 outline-none" />
                            </div>
                            <button type="submit" disabled={submitting} className="w-full py-3 bg-indigo-600 text-white text-sm font-black rounded-2xl shadow-lg shadow-indigo-600/20 disabled:opacity-50 hover:scale-[1.02] active:scale-95 transition-all flex items-center justify-center gap-2">
                                {submitting ? <Loader2 className="size-4 animate-spin" /> : <UserPlus className="size-4" />} Crear y vincular
                            </button>
                        </form>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── CallHistoryModal ────────────────────────────────────────────────────────
// Muestra el historial de llamadas de la conversación activa (entrantes y
// salientes), con dirección, estado, duración y fecha.
const CALL_STATUS_LABEL = {
    ringing: 'Timbrando',
    connecting: 'Conectando',
    in_progress: 'En curso',
    completed: 'Completada',
    missed: 'Perdida',
    rejected: 'Rechazada',
    failed: 'Fallida',
    canceled: 'Cancelada',
};

function formatCallDuration(seconds) {
    if (!seconds) return null;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function CallHistoryModal({ conversationId, contactName, onClose }) {
    const [calls, setCalls] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let active = true;
        setLoading(true);
        axios.get(`/api/chat/conversations/${conversationId}/calls`)
            .then(r => { if (active) setCalls(r.data.calls ?? []); })
            .catch(() => { if (active) setError('No se pudo cargar el historial de llamadas.'); })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [conversationId]);

    return (
        <div className="fixed inset-0 z-[115] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200" onClick={onClose}>
            <div className="w-full max-w-md max-h-[80vh] flex flex-col rounded-2xl bg-card border border-border/40 shadow-2xl overflow-hidden" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-4 border-b border-border/30">
                    <div className="flex items-center gap-2 min-w-0">
                        <PhoneCall className="size-4 text-teal-600 dark:text-teal-400 shrink-0" />
                        <div className="min-w-0">
                            <p className="text-sm font-bold text-foreground truncate">Historial de llamadas</p>
                            <p className="text-[11px] text-muted-foreground truncate">{contactName}</p>
                        </div>
                    </div>
                    <button onClick={onClose} className="size-8 flex items-center justify-center text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-lg"><X className="size-4" /></button>
                </div>

                <div className="flex-1 overflow-y-auto p-3">
                    {loading ? (
                        <div className="flex items-center justify-center py-10 text-muted-foreground"><Loader2 className="size-5 animate-spin" /></div>
                    ) : error ? (
                        <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300 m-2">{error}</div>
                    ) : !calls?.length ? (
                        <div className="flex flex-col items-center justify-center py-10 text-center text-muted-foreground">
                            <PhoneCall className="size-8 mb-2 opacity-30" />
                            <p className="text-sm">Sin llamadas registradas.</p>
                        </div>
                    ) : (
                        <div className="space-y-1.5">
                            {calls.map(call => {
                                const isInbound = call.direction === 'inbound';
                                const isMissed = ['missed', 'rejected', 'failed', 'canceled'].includes(call.status);
                                const Icon = isMissed ? PhoneMissed : (isInbound ? PhoneIncoming : PhoneOutgoing);
                                const color = isMissed ? 'text-rose-500' : (isInbound ? 'text-emerald-500' : 'text-sky-500');
                                const duration = formatCallDuration(call.duration_seconds);
                                return (
                                    <div key={call.id} className="flex items-center gap-3 rounded-xl border border-border/40 bg-card/50 px-3 py-2.5">
                                        <Icon className={`size-4 shrink-0 ${color}`} />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-xs font-semibold text-foreground">
                                                {isInbound ? 'Entrante' : 'Saliente'} · {CALL_STATUS_LABEL[call.status] ?? call.status}
                                            </p>
                                            <p className="text-[11px] text-muted-foreground">
                                                {call.started_at ? new Date(call.started_at).toLocaleString() : '—'}
                                                {duration && ` · ${duration}`}
                                                {call.handled_by && ` · ${call.handled_by}`}
                                            </p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── TemplatePickerModal ─────────────────────────────────────────────────────
// Selector de plantillas de WhatsApp para la conversación activa. Al hacer clic
// en una plantilla sin variables ni encabezado multimedia, se envía de inmediato.
// Si requiere variables/encabezado, se expande un formulario para completarlas.
// Plantilla por defecto de Integra CRM para reabrir conversaciones fuera de la
// ventana de 24h (ver config/whatsapp_default_templates.php). Se preselecciona
// automáticamente cuando el modal se abre por ventana vencida.
const RESUME_WINDOW_TEMPLATE_NAME = 'reanudar_conversacion_cliente';
const RESUME_WINDOW_REASON_SUGGESTIONS = [
    'tu servicio de internet', 'tu instalación', 'tu factura', 'tu soporte técnico', 'tu cotización',
];

function TemplatePickerModal({ conversationId, instanceId, onClose, onSent, windowClosedHint = false, contactName = '' }) {
    const [templates, setTemplates] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [error, setError] = useState(null);
    const [sending, setSending] = useState(false);
    const [selected, setSelected] = useState(null); // plantilla en modo "completar"
    const [vars, setVars] = useState([]);
    const [header, setHeader] = useState(null);
    const [autoPicked, setAutoPicked] = useState(false);

    useEffect(() => {
        let active = true;
        (async () => {
            try {
                const res = await axios.get('/api/chat/templates', { params: { instance_id: instanceId } });
                const approved = (res.data.data || []).filter(t => (t.status || '').toUpperCase() === 'APPROVED');
                if (active) setTemplates(approved);
            } catch {
                if (active) { setTemplates([]); setError('No se pudieron cargar las plantillas.'); }
            } finally {
                if (active) setLoading(false);
            }
        })();
        return () => { active = false; };
    }, [instanceId]);

    // Al abrir por ventana vencida, preselecciona la plantilla de reanudación si
    // ya está aprobada en esta instancia (el agente puede igual elegir otra).
    useEffect(() => {
        if (!windowClosedHint || loading || autoPicked) return;
        setAutoPicked(true);
        const t = templates.find(x => (x.name || '') === RESUME_WINDOW_TEMPLATE_NAME);
        if (t) handlePick(t, { 0: contactName || '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [windowClosedHint, loading, templates, autoPicked]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return templates;
        return templates.filter(t =>
            (t.name || '').toLowerCase().includes(q) ||
            (templateBodyComponent(t)?.text || '').toLowerCase().includes(q));
    }, [templates, search]);

    const needsFill = (t) => {
        const body = templateBodyComponent(t);
        return countTemplateVars(body?.text) > 0 || !!templateHeaderFormat(t);
    };

    async function sendTemplate(t, varsArr = [], hdr = null) {
        setSending(true);
        setError(null);
        const body = templateBodyComponent(t);
        const n = countTemplateVars(body?.text);
        const components = [];
        const headerComp = buildTemplateHeaderComponent(hdr);
        if (headerComp) components.push(headerComp);
        if (n > 0) {
            components.push({ type: 'body', parameters: varsArr.slice(0, n).map(v => ({ type: 'text', text: v })) });
        }
        const preview = fillTemplate(body?.text, varsArr);
        try {
            const res = await axios.post(`/api/chat/conversations/${conversationId}/send-template`, {
                template_name: t.name,
                language_code: t.language,
                components,
                preview,
            });
            if (res.data.success) {
                onSent(res.data.data, preview);
            } else {
                setError(res.data.error || 'No se pudo enviar la plantilla.');
                setSending(false);
            }
        } catch (err) {
            setError(err?.response?.data?.error || 'No se pudo enviar la plantilla.');
            setSending(false);
        }
    }

    function handlePick(t, prefill = {}) {
        if (sending) return;
        // Sin variables ni encabezado → envío directo al seleccionar.
        if (!needsFill(t)) { sendTemplate(t); return; }
        setError(null);
        setSelected(t);
        const body = templateBodyComponent(t);
        const n = countTemplateVars(body?.text);
        setVars(Array.from({ length: n }, (_, i) => prefill[i] ?? ''));
        const fmt = templateHeaderFormat(t);
        setHeader(fmt
            ? { format: fmt, mediaId: '', filename: '', uploading: false, error: '', lat: '', lng: '', name: '', address: '' }
            : null);
    }

    async function uploadHeader(file) {
        if (!file) return;
        setHeader(h => ({ ...h, uploading: true, error: '', mediaId: '', filename: file.name }));
        try {
            const fd = new FormData();
            fd.append('file', file);
            const res = await axios.post(`/api/chat/conversations/${conversationId}/template-media`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setHeader(h => ({ ...h, uploading: false, mediaId: res.data.media_id, filename: res.data.filename || file.name }));
        } catch (err) {
            setHeader(h => ({ ...h, uploading: false, mediaId: '', error: err?.response?.data?.error || 'No se pudo subir el archivo a Meta.' }));
        }
    }

    function confirmSend() {
        const body = templateBodyComponent(selected);
        const n = countTemplateVars(body?.text);
        if (vars.some((v, i) => i < n && !v.trim())) { setError('Completa todas las variables de la plantilla.'); return; }
        if (header) {
            if (header.format === 'LOCATION') {
                if (!String(header.lat).trim() || !String(header.lng).trim()) { setError('Completa la latitud y longitud del encabezado.'); return; }
            } else if (!header.mediaId) { setError('Sube el archivo del encabezado de la plantilla.'); return; }
        }
        sendTemplate(selected, vars, header);
    }

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200" onClick={onClose}>
            <div className="w-full max-w-md rounded-3xl border border-border/10 bg-white dark:bg-[#1c272e] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200 flex flex-col max-h-[85vh]" onClick={e => e.stopPropagation()}>
                <div className="relative bg-gradient-to-br from-emerald-600 to-teal-600 px-6 py-5 text-white shrink-0">
                    <button onClick={onClose} className="absolute top-4 right-4 p-1.5 hover:bg-white/15 rounded-full transition-colors">
                        <XIcon className="size-4" />
                    </button>
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-2xl bg-white/15 flex items-center justify-center">
                            <FileText className="size-6" />
                        </div>
                        <div>
                            <h3 className="font-bold text-lg leading-tight">Plantillas de WhatsApp</h3>
                            <p className="text-xs text-white/80">
                                {selected ? 'Completa los datos y envía' : 'Selecciona una plantilla para enviarla'}
                            </p>
                        </div>
                    </div>
                </div>

                {windowClosedHint && (
                    <div className="px-4 pt-3 shrink-0">
                        <div className="flex items-start gap-2 rounded-lg border border-amber-300/50 bg-amber-50 dark:bg-amber-900/15 px-3 py-2 text-[12px] text-amber-800 dark:text-amber-200">
                            <Clock className="size-4 mt-0.5 shrink-0" />
                            <span>La ventana de 24h de este contacto ya expiró. Envía una plantilla aprobada para reabrir la conversación.</span>
                        </div>
                    </div>
                )}

                {!selected ? (
                    <>
                        <div className="px-4 pt-4 shrink-0">
                            <div className="relative">
                                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <Search className="size-4 text-muted-foreground/60" />
                                </div>
                                <input
                                    autoFocus
                                    value={search}
                                    onChange={e => setSearch(e.target.value)}
                                    placeholder="Buscar plantilla…"
                                    className="w-full pl-10 pr-3 py-2 bg-[#f0f2f5] dark:bg-[#202c33] border-none rounded-lg text-sm outline-none placeholder:text-muted-foreground/60 text-foreground"
                                />
                            </div>
                        </div>

                        <div className="px-4 py-4 overflow-y-auto custom-scrollbar">
                            {loading ? (
                                <div className="py-10 flex items-center justify-center text-muted-foreground">
                                    <Loader2 className="size-5 animate-spin" />
                                </div>
                            ) : filtered.length === 0 ? (
                                <div className="py-8 text-center">
                                    <FileText className="size-8 mx-auto text-muted-foreground/30 mb-2" />
                                    <p className="text-sm text-muted-foreground">
                                        {templates.length === 0 ? 'No hay plantillas aprobadas en esta instancia.' : 'Sin resultados para tu búsqueda.'}
                                    </p>
                                </div>
                            ) : (
                                <div className="rounded-xl border border-border/60 divide-y divide-border/40 overflow-hidden">
                                    {filtered.map(t => {
                                        const body = templateBodyComponent(t);
                                        return (
                                            <button
                                                key={`${t.name}-${t.language}`}
                                                type="button"
                                                disabled={sending}
                                                onClick={() => handlePick(t)}
                                                className="w-full text-left px-3 py-2.5 hover:bg-muted/40 transition-colors disabled:opacity-50"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <span className="text-[13px] font-semibold text-foreground truncate">{t.name}</span>
                                                    <span className="text-[9px] font-bold uppercase tracking-wide text-muted-foreground/70 bg-muted/60 rounded px-1.5 py-0.5 shrink-0">{t.language}</span>
                                                    {needsFill(t) && (
                                                        <span className="ml-auto text-[9px] font-bold uppercase tracking-wide text-amber-600 bg-amber-500/10 rounded px-1.5 py-0.5 shrink-0">Requiere datos</span>
                                                    )}
                                                </div>
                                                {body?.text && <p className="text-[11px] text-muted-foreground line-clamp-2 mt-0.5">{body.text}</p>}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}

                            {error && (
                                <div className="mt-3 rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                                    <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                    <span>{error}</span>
                                </div>
                            )}

                            {sending && (
                                <p className="mt-3 flex items-center justify-center gap-2 text-xs text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" /> Enviando plantilla…
                                </p>
                            )}
                        </div>
                    </>
                ) : (
                    <div className="px-4 py-4 space-y-3 overflow-y-auto custom-scrollbar">
                        <button
                            type="button"
                            onClick={() => { setSelected(null); setError(null); }}
                            className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground transition-colors"
                        >
                            <ArrowLeft className="size-3.5" /> Volver a la lista
                        </button>

                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-[13px] font-bold text-foreground truncate">{selected.name}</span>
                                <span className="text-[9px] font-bold uppercase tracking-wide text-muted-foreground/70 bg-muted/60 rounded px-1.5 py-0.5">{selected.language}</span>
                            </div>
                        </div>

                        {header && (
                            <div className="space-y-2">
                                <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground block">
                                    Encabezado · {HEADER_MEDIA_LABEL[header.format]}
                                </label>
                                {header.format !== 'LOCATION' ? (
                                    <>
                                        <input
                                            type="file"
                                            accept={HEADER_MEDIA_ACCEPT[header.format]}
                                            disabled={header.uploading}
                                            onChange={e => uploadHeader(e.target.files?.[0])}
                                            className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-teal-600/10 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-teal-600 hover:file:bg-teal-600/20 disabled:opacity-60"
                                        />
                                        {header.uploading && (
                                            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                <Loader2 className="size-3.5 animate-spin" /> Subiendo a Meta…
                                            </p>
                                        )}
                                        {!header.uploading && header.mediaId && (
                                            <p className="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                                                <Check className="size-3.5" /> Archivo listo{header.filename ? `: ${header.filename}` : ''}
                                            </p>
                                        )}
                                        {header.error && <p className="text-xs text-rose-600 dark:text-rose-400">{header.error}</p>}
                                    </>
                                ) : (
                                    <div className="grid grid-cols-2 gap-2">
                                        <input value={header.lat} onChange={e => setHeader(h => ({ ...h, lat: e.target.value }))} placeholder="Latitud" className="rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30" />
                                        <input value={header.lng} onChange={e => setHeader(h => ({ ...h, lng: e.target.value }))} placeholder="Longitud" className="rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30" />
                                        <input value={header.name} onChange={e => setHeader(h => ({ ...h, name: e.target.value }))} placeholder="Nombre (opcional)" className="col-span-2 rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30" />
                                        <input value={header.address} onChange={e => setHeader(h => ({ ...h, address: e.target.value }))} placeholder="Dirección (opcional)" className="col-span-2 rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30" />
                                    </div>
                                )}
                            </div>
                        )}

                        {vars.length > 0 && (
                            <div className="space-y-2">
                                <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground block">Variables</label>
                                {vars.map((v, i) => {
                                    const isResumeReason = selected?.name === RESUME_WINDOW_TEMPLATE_NAME && i === 1;
                                    return (
                                        <div key={i}>
                                            <input
                                                value={v}
                                                onChange={e => setVars(prev => prev.map((x, j) => (j === i ? e.target.value : x)))}
                                                placeholder={
                                                    selected?.name === RESUME_WINDOW_TEMPLATE_NAME
                                                        ? (i === 0 ? 'Nombre del cliente' : 'Motivo de la conversación')
                                                        : `Variable {{${i + 1}}}`
                                                }
                                                className="w-full rounded-lg border border-border/70 bg-background/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/30"
                                            />
                                            {isResumeReason && (
                                                <div className="flex flex-wrap gap-1.5 mt-1.5">
                                                    {RESUME_WINDOW_REASON_SUGGESTIONS.map(s => (
                                                        <button
                                                            key={s}
                                                            type="button"
                                                            onClick={() => setVars(prev => prev.map((x, j) => (j === i ? s : x)))}
                                                            className="text-[11px] rounded-full border border-border/60 px-2.5 py-1 text-muted-foreground hover:text-foreground hover:bg-muted/40 transition-colors"
                                                        >
                                                            {s}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        <div className="rounded-xl bg-[#dcf8c6] dark:bg-[#005c4b] px-3 py-2 text-[13px] text-[#111b21] dark:text-[#e9edef] whitespace-pre-wrap break-words">
                            {fillTemplate(templateBodyComponent(selected)?.text, vars) || '(Plantilla sin cuerpo de texto)'}
                        </div>

                        {error && (
                            <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300 flex items-start gap-2">
                                <AlertTriangle className="size-4 mt-0.5 shrink-0" />
                                <span>{error}</span>
                            </div>
                        )}

                        <div className="flex gap-2 pt-1">
                            <button type="button" onClick={() => { setSelected(null); setError(null); }} className="flex-1 rounded-xl border border-border/70 py-2.5 text-sm font-medium text-foreground hover:bg-muted/40 transition-colors">
                                Cancelar
                            </button>
                            <button type="button" onClick={confirmSend} disabled={sending || header?.uploading} className="flex-1 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white py-2.5 text-sm font-bold flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                                {sending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                                Enviar plantilla
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

ChatIndex.layout = page => <AppLayout breadcrumb={['Chat']}>{page}</AppLayout>;
