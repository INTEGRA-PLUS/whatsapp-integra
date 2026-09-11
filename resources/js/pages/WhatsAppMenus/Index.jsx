import { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    Plus, Pencil, Trash2, ListTree, Power, PowerOff, CornerDownRight,
    ChevronUp, ChevronDown, X, AlertTriangle, Smartphone, List, Construction,
    Plug, Users, HelpCircle, ImagePlus, CheckCircle2, Bot,
    Check, ChevronRight, MessageSquare,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import MenuHelp from './MenuHelp';
import SelectorEmoji from '@/components/emoji/SelectorEmoji';
import { insertarEnCursor } from '@/components/emoji/insertarEmoji';
import ProviderConnectForm from '@/components/ProviderConnectForm';
import {
    MATCH_LABELS, MATCH_OPTIONS, KEYWORD_TYPES,
    GROUP_LABELS, GROUP_ORDER, iconFor,
    ACTION_SAMPLES, SEGMENT_SAMPLES,
} from './catalog';



/** Estrategias de reparto del handoff (WhatsAppMenuOption::ASSIGN_*). */
const ASSIGN_OPTIONS = [
    { value: 'least_busy', label: 'El asesor con menos chats abiertos' },
    { value: 'fixed', label: 'Siempre el mismo asesor' },
    { value: 'inbox', label: 'Dejar en la bandeja general (sin asignar)' },
];

const RADICADO_PRIORITIES = [
    { value: '1', label: 'Baja' },
    { value: '2', label: 'Media' },
    { value: '3', label: 'Alta' },
];


/** Valores de ejemplo para la vista previa: el menú se escribe con variables. */
const SAMPLE = { name: 'Katherine', phone: '3007852081', wa_id: '573007852081' };

const fillVars = text => (text ?? '')
    .split('{name}').join(SAMPLE.name)
    .split('{phone}').join(SAMPLE.phone)
    .split('{wa_id}').join(SAMPLE.wa_id);

/** Mismo recorte que hace el backend antes de mandar el menú a Meta. */
const cut = (text, max) => (text.length > max ? text.slice(0, max) : text);

/**
 * Los selects devuelven strings y el backend espera enteros en los ids de
 * Integra. Las claves vacías se quitan en vez de mandarse como '': así una
 * opción sin configurar guarda null y no un objeto lleno de huecos.
 */
const NUMERIC_CONFIG_KEYS = ['radicado_servicio', 'radicado_prioridad', 'radicado_tecnico'];

const normalizeConfig = (config = {}) => {
    const out = {};
    for (const [key, value] of Object.entries(config ?? {})) {
        if (value === '' || value === null || value === undefined) continue;
        out[key] = NUMERIC_CONFIG_KEYS.includes(key) ? Number(value) : value;
    }
    return Object.keys(out).length ? out : null;
};

const emptyOption = () => ({
    id: null,
    title: '',
    description: '',
    action_type: 'reply_text',
    reply_text: '',
    target_menu_id: '',
    assign_to_user_id: '',
    // Ajustes propios de cada acción (servicio del radicado, enlace de pago,
    // estrategia de reparto…). Se mandan tal cual y el backend se queda sólo
    // con las claves que entiende el tipo elegido.
    config: {},
});

const emptyForm = () => ({
    name: '',
    instance_id: '',
    is_root: true,
    match_types: ['welcome'],
    trigger_text: '',
    header_text: '',
    body_text: '',
    footer_text: '',
    list_button_text: 'Ver opciones',
    active: true,
    cooldown_minutes: 60,
    options: [emptyOption()],
});

export default function WhatsAppMenusIndex({ menus, instances, agents, limits, actionTypes = [], statusSegments = [], integra = {}, ai = {} }) {
    const { errors } = usePage().props;
    // value → { label, group, reply }: lo usan la tarjeta (para nombrar la
    // acción) y el formulario (para el aviso por defecto de cada pendiente).
    const actionMeta = Object.fromEntries(actionTypes.map(a => [a.value, a]));
    const [showCreate, setShowCreate] = useState(false);
    const [showHelp, setShowHelp] = useState(false);
    const [editing, setEditing] = useState(null);
    const [createForm, setCreateForm] = useState(emptyForm);
    const [editForm, setEditForm] = useState(emptyForm);
    // Cuando se entra desde un aviso de la revisión, la opción que hay que
    // corregir: el formulario baja hasta ella y la resalta.
    const [focusOption, setFocusOption] = useState(null);

    function payload(form) {
        return {
            ...form,
            instance_id: form.instance_id === '' ? null : Number(form.instance_id),
            match_types: form.is_root ? form.match_types : [],
            cooldown_minutes: form.cooldown_minutes === '' ? 0 : Number(form.cooldown_minutes),
            options: form.options.map(o => ({
                ...o,
                target_menu_id: o.action_type === 'submenu' && o.target_menu_id !== ''
                    ? Number(o.target_menu_id)
                    : null,
                assign_to_user_id: o.action_type === 'handoff' && o.assign_to_user_id !== ''
                    ? Number(o.assign_to_user_id)
                    : null,
                config: normalizeConfig(o.config),
            })),
        };
    }

    function handleCreate(e) {
        e.preventDefault();
        router.post(route('whatsapp-menus.store'), payload(createForm), {
            onSuccess: () => { setShowCreate(false); setCreateForm(emptyForm()); },
        });
    }

    function handleEdit(e) {
        e.preventDefault();
        router.put(route('whatsapp-menus.update', editing.id), payload(editForm), {
            onSuccess: () => setEditing(null),
        });
    }

    function handleDelete(menu) {
        if (!confirm(`¿Eliminar el menú "${menu.name}"?`)) return;
        router.delete(route('whatsapp-menus.destroy', menu.id));
    }

    function openEdit(menu, focusOptionId = null) {
        setEditForm({
            name: menu.name ?? '',
            instance_id: menu.instance_id ? String(menu.instance_id) : '',
            is_root: !!menu.is_root,
            match_types: menu.match_types?.length ? menu.match_types : ['welcome'],
            trigger_text: menu.trigger_text ?? '',
            header_text: menu.header_text ?? '',
            body_text: menu.body_text ?? '',
            footer_text: menu.footer_text ?? '',
            list_button_text: menu.list_button_text ?? 'Ver opciones',
            active: !!menu.active,
            cooldown_minutes: menu.cooldown_minutes ?? 60,
            options: (menu.options ?? []).map(o => ({
                id: o.id,
                title: o.title ?? '',
                description: o.description ?? '',
                action_type: o.action_type ?? 'reply_text',
                reply_text: o.reply_text ?? '',
                target_menu_id: o.target_menu_id ? String(o.target_menu_id) : '',
                assign_to_user_id: o.assign_to_user_id ? String(o.assign_to_user_id) : '',
                config: Object.fromEntries(
                    Object.entries(o.config ?? {}).map(([k, v]) => [k, v === null ? '' : String(v)])
                ),
            })),
        });
        setFocusOption(focusOptionId);
        setEditing(menu);
    }

    return (
        <>
            <Head title="Menús de WhatsApp" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Menús de WhatsApp</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            El cliente elige una opción tocándola en vez de escribir lo que necesita.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => setShowHelp(true)} className="gap-2">
                            <HelpCircle className="size-4" /> ¿Cómo funciona?
                        </Button>
                        <Button onClick={() => { setCreateForm(emptyForm()); setShowCreate(true); }} className="gap-2">
                            <Plus className="size-4" /> Nuevo menú
                        </Button>
                    </div>
                </div>

                <AiSwitch ai={ai} />

                {menus.length > 0 && (
                    <ReviewPanel onEditMenu={(id, optionId) => {
                        const menu = menus.find(m => m.id === id);
                        if (menu) openEdit(menu, optionId);
                    }} />
                )}

                {menus.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <ListTree className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes menús</p>
                        <p className="text-sm text-muted-foreground mt-1">
                            Por ejemplo: al primer mensaje del cliente, ofrecerle "Consultar factura", "Pagar en línea" o "Hablar con un asesor".
                        </p>
                        <Button variant="outline" onClick={() => setShowHelp(true)} className="gap-2 mt-5">
                            <HelpCircle className="size-4" /> Ver cómo se arma uno
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {menus.map(menu => (
                            <MenuCard
                                key={menu.id}
                                menu={menu}
                                menus={menus}
                                actionMeta={actionMeta}
                                onEdit={() => openEdit(menu)}
                                onDelete={() => handleDelete(menu)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {showHelp && (
                <Modal
                    wide
                    title="Cómo funcionan los menús"
                    description="Del mensaje del cliente a la respuesta, y qué hace cada pieza del formulario"
                    onClose={() => setShowHelp(false)}
                >
                    <MenuHelp
                        actionTypes={actionTypes}
                        limits={limits}
                        statusSegments={statusSegments}
                        integra={integra}
                        menus={menus}
                        onClose={() => setShowHelp(false)}
                    />
                </Modal>
            )}

            {showCreate && (
                <Modal wide title="Nuevo menú" description="Define el mensaje, las opciones y cuándo aparece" onClose={() => setShowCreate(false)}>
                    <MenuForm
                        form={createForm} setForm={setCreateForm}
                        instances={instances} agents={agents} menus={menus} limits={limits} errors={errors}
                        actionTypes={actionTypes} actionMeta={actionMeta} integra={integra} statusSegments={statusSegments}
                        onSubmit={handleCreate} onCancel={() => setShowCreate(false)} submitLabel="Crear menú"
                    />
                </Modal>
            )}

            {editing && (
                <Modal wide title="Editar menú" description={`Modificar: ${editing.name}`} onClose={() => setEditing(null)}>
                    <MenuForm
                        form={editForm} setForm={setEditForm}
                        instances={instances} agents={agents} menus={menus} limits={limits} errors={errors}
                        actionTypes={actionTypes} actionMeta={actionMeta} integra={integra} statusSegments={statusSegments}
                        editingId={editing.id} focusOption={focusOption}
                        onSubmit={handleEdit} onCancel={() => setEditing(null)} submitLabel="Guardar cambios"
                    />
                </Modal>
            )}
        </>
    );
}

function MenuCard({ menu, menus = [], actionMeta, onEdit, onDelete }) {
    const options = menu.options ?? [];
    const isList = menu.format === 'list';

    // De quién es este submenú: qué menús —y por qué opción— llevan hasta aquí.
    // Sin esto, una tarjeta suelta que dice "sólo se abre desde otro menú" deja
    // al admin adivinando cuál, y no hay forma de saberlo sin abrir los demás.
    const openedFrom = menu.is_root ? [] : menus.flatMap(other =>
        (other.options ?? [])
            .filter(o => o.action_type === 'submenu' && String(o.target_menu_id) === String(menu.id))
            .map(o => ({ menu: other.name, option: o.title, active: other.active }))
    );

    return (
        <div className="rounded-xl border bg-card p-5 shadow-xs flex flex-col gap-4">
            <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-lg bg-success/15">
                        {menu.active
                            ? <Power className="size-5 text-success" />
                            : <PowerOff className="size-5 text-muted-foreground" />}
                    </div>
                    <div>
                        <p className="font-semibold text-foreground text-sm">{menu.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {menu.instance ? `Instancia: ${menu.instance.name}` : 'Todas las instancias'}
                        </p>
                    </div>
                </div>
                <div className="flex flex-col items-end gap-1">
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${menu.active ? 'bg-success/15 text-success dark:bg-success/30 dark:text-success' : 'bg-muted text-muted-foreground'}`}>
                        {menu.active ? 'Activo' : 'Inactivo'}
                    </span>
                    <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                        {isList ? 'Lista' : 'Botones'}
                    </span>
                </div>
            </div>

            <div className="rounded-lg bg-muted/50 px-3 py-2 text-xs space-y-1.5">
                <div className="text-muted-foreground">
                    {menu.is_root ? (
                        <>
                            {(menu.match_types ?? []).map(t => MATCH_LABELS[t] ?? t).join(' · ')}
                            {menu.trigger_text && (
                                <span className="font-mono text-foreground"> "{menu.trigger_text}"</span>
                            )}
                        </>
                    ) : openedFrom.length > 0 ? (
                        <div className="space-y-0.5">
                            {openedFrom.map((from, i) => (
                                <p key={i} className="flex items-start gap-1.5">
                                    <CornerDownRight className="mt-px size-3 shrink-0" />
                                    <span>
                                        Se abre desde <span className="font-medium text-foreground">{from.menu}</span>
                                        {' › '}<span className="text-foreground">{from.option}</span>
                                        {!from.active && <span className="text-warning"> (ese menú está apagado)</span>}
                                    </span>
                                </p>
                            ))}
                        </div>
                    ) : (
                        <span className="flex items-start gap-1.5 text-warning">
                            <AlertTriangle className="mt-px size-3 shrink-0" />
                            Ningún menú lleva aquí todavía: los clientes no pueden llegar a este submenú.
                        </span>
                    )}
                </div>
                <div className="text-foreground whitespace-pre-wrap line-clamp-2">{menu.body_text}</div>
                <ul className="space-y-0.5 pt-0.5">
                    {options.map((o, i) => {
                        const meta = actionMeta?.[o.action_type];
                        // "Abrir otro menú" no dice cuál. El nombre del destino
                        // es justo lo que hay que ver de un vistazo para
                        // entender cómo encaja el menú con sus submenús.
                        const target = o.action_type === 'submenu'
                            ? menus.find(m => String(m.id) === String(o.target_menu_id))
                            : null;

                        return (
                            <li key={o.id} className="text-foreground/80 truncate">
                                {i + 1}. {o.title}
                                <span className="text-muted-foreground">
                                    {' — '}
                                    {target
                                        ? <>abre <span className="text-foreground">{target.name}</span></>
                                        : (meta?.label ?? o.action_type)}
                                </span>
                                {o.action_type === 'submenu' && !target && (
                                    <span className="ml-1 rounded bg-warning/15 px-1 text-[9px] font-medium text-warning dark:bg-warning/30 dark:text-warning">
                                        sin destino
                                    </span>
                                )}
                                {meta?.group === 'pending' && (
                                    <span className="ml-1 rounded bg-warning/15 px-1 text-[9px] font-medium text-warning dark:bg-warning/30 dark:text-warning">
                                        pendiente
                                    </span>
                                )}
                                {meta?.group === 'none' && (
                                    <span className="ml-1 rounded bg-muted px-1 text-[9px] font-medium text-muted-foreground">
                                        sin acción
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className="flex items-center justify-between text-[11px] text-muted-foreground -mt-2">
                <span>Enviado {menu.fires_count ?? 0} {(menu.fires_count ?? 0) === 1 ? 'vez' : 'veces'}</span>
                {menu.last_fired_at && <span>Último: {new Date(menu.last_fired_at).toLocaleString()}</span>}
            </div>

            <div className="flex gap-2 pt-1">
                <Button variant="outline" size="sm" className="flex-1 gap-1.5" onClick={onEdit}>
                    <Pencil className="size-3.5" /> Editar
                </Button>
                <Button variant="outline" size="sm" className="gap-1.5 text-destructive hover:bg-destructive/10" onClick={onDelete}>
                    <Trash2 className="size-3.5" />
                </Button>
            </div>
        </div>
    );
}

function MenuForm({ form, setForm, instances, agents, menus, limits, errors, actionTypes, actionMeta, integra = {}, statusSegments = [], editingId = null, focusOption = null, onSubmit, onCancel, submitLabel }) {
    const options = form.options ?? [];
    const mensajeRef = useRef(null);

    // Cuando se entra desde un aviso de la revisión, el formulario abre
    // directamente en las opciones: es donde está lo que hay que arreglar.
    const [paso, setPaso] = useState(focusOption != null ? 2 : 0);

    // Si el servidor devuelve un error, el asistente va al paso donde está ese
    // campo. La clave del objeto de errores cambia en cada respuesta, así que
    // se compara serializada y no por identidad.
    const clavesDeError = Object.keys(errors ?? {}).sort().join(',');

    useEffect(() => {
        const destino = pasoDelError(errors);
        if (destino !== null) setPaso(destino);
    }, [clavesDeError]); // eslint-disable-line react-hooks/exhaustive-deps
    const catalogs = useIntegraCatalogs(
        integra.connected && options.some(o => o.action_type === 'reportar_falla')
    );
    const isList = options.length > limits.max_buttons;
    const selectedTypes = form.match_types ?? [];
    const showTrigger = form.is_root && selectedTypes.some(t => KEYWORD_TYPES.includes(t));

    const currentInstanceId = form.instance_id === '' ? null : Number(form.instance_id);
    const welcomeTakenByOther = menus.some(m =>
        m.is_root && (m.match_types ?? []).includes('welcome') &&
        m.id !== editingId && m.instance_id === currentInstanceId
    );

    // Como botón sólo caben 20 caracteres, pero el campo admite 24 porque en
    // lista sí caben. Añadir una cuarta opción cambia el formato del menú, así
    // que el aviso aparece y desaparece solo según cuántas opciones haya.
    const tooLongForButton = !isList && options.some(o => o.title.length > limits.max_button_title);

    const setOption = (index, patch) => setForm(f => ({
        ...f,
        options: f.options.map((o, i) => (i === index ? { ...o, ...patch } : o)),
    }));

    const addOption = () => setForm(f => ({ ...f, options: [...f.options, emptyOption()] }));

    const removeOption = (index) => setForm(f => ({
        ...f,
        options: f.options.filter((_, i) => i !== index),
    }));

    const moveOption = (index, delta) => setForm(f => {
        const next = [...f.options];
        const target = index + delta;
        if (target < 0 || target >= next.length) return f;
        [next[index], next[target]] = [next[target], next[index]];
        return { ...f, options: next };
    });

    const toggleType = (value) => setForm(f => {
        const set = new Set(f.match_types ?? []);
        set.has(value) ? set.delete(value) : set.add(value);
        return { ...f, match_types: Array.from(set) };
    });

    // Las mismas comprobaciones que antes, pero repartidas por paso: cada una
    // decide si se puede pasar al siguiente, y de las tres sale el bloqueo del
    // botón de guardar. Así el aviso llega donde está el campo que falta, y no
    // al final con el formulario entero por revisar.
    const tipoListo =
        form.name.trim() !== '' &&
        !(form.is_root && selectedTypes.length === 0) &&
        !(showTrigger && form.trigger_text.trim() === '');

    const mensajeListo = form.body_text.trim() !== '';

    const opcionesListas =
        options.length > 0 &&
        !options.some(o => o.title.trim() === '') &&
        !options.some(o => o.action_type === 'reply_text' && (o.reply_text ?? '').trim() === '') &&
        !options.some(o => o.action_type === 'submenu' && o.target_menu_id === '');

    const blocked = !tipoListo || !mensajeListo || !opcionesListas;
    const listoHastaPaso = [tipoListo, mensajeListo, opcionesListas, true];

    // Un menú no puede llevar a sí mismo, y ofrecer los menús raíz como destino
    // sólo invita a que el cliente entre en un circuito del que no sabe salir.
    const submenuChoices = menus.filter(m => m.id !== editingId && !m.is_root);

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
            {/* El formulario NUNCA guarda por sí solo: un Enter en cualquier
                campo creaba el menú a medias. Guardar es el botón del último
                paso, que llama a onSubmit a mano. */}
            <form onSubmit={e => e.preventDefault()} className="space-y-5 min-w-0">
                <PasosMenu paso={paso} setPaso={setPaso} listoHastaPaso={listoHastaPaso} />

                {/* ── Paso 1: qué clase de menú es ── */}
                <div className={paso === 0 ? 'space-y-5' : 'hidden'}>
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">¿Qué estás creando?</label>
                        <p className="text-[11px] text-muted-foreground">
                            Es la decisión que cambia todo lo demás, y antes era una casilla
                            perdida llamada «Es un submenú».
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <TipoDeMenu
                                elegido={form.is_root}
                                onElegir={() => setForm(f => ({ ...f, is_root: true }))}
                                icono={MessageSquare}
                                titulo="Menú principal"
                                descripcion="Se envía solo cuando el cliente escribe. Es la puerta de entrada."
                            />
                            <TipoDeMenu
                                elegido={!form.is_root}
                                onElegir={() => setForm(f => ({ ...f, is_root: false, match_types: [], trigger_text: '' }))}
                                icono={CornerDownRight}
                                titulo="Submenú"
                                descripcion="No se dispara solo. Se abre cuando el cliente elige una opción de otro menú."
                            />
                        </div>
                    </div>

                    <Field label="Nombre" value={form.name} onChange={v => setForm(f => ({ ...f, name: v }))}
                        required placeholder={form.is_root ? 'Ej: Menú principal' : 'Ej: Submenú de facturación'}
                        hint="Este nombre es sólo para ti: el cliente no lo ve."
                        error={errors?.name} />

                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Instancia</label>
                        <Select value={form.instance_id} onChange={v => setForm(f => ({ ...f, instance_id: v }))}>
                            <option value="">Todas las instancias</option>
                            {instances.map(i => <option key={i.id} value={i.id}>{i.name}</option>)}
                        </Select>
                        <p className="text-[11px] text-muted-foreground">
                            Con «todas», el menú vale para cualquier número de la empresa.
                        </p>
                    </div>

                    {!form.is_root && (
                        <p className="flex items-start gap-1.5 rounded-md bg-info/10 px-2.5 py-2 text-[11px] text-info">
                            <CornerDownRight className="size-3.5 shrink-0 mt-px" />
                            Un submenú no aparece por sí solo. Cuando lo tengas creado, ve al menú
                            de donde debe salir y pon una opción con la acción «Abrir otro menú».
                        </p>
                    )}

                    <div className="space-y-1.5">
                        {form.is_root && <label className="text-sm font-medium text-foreground">¿Cuándo aparece?</label>}

                    {form.is_root && (
                        <div className={`flex flex-col gap-1.5 rounded-md border p-2.5 ${errors?.match_types ? 'border-destructive' : 'border-input'}`}>
                            {MATCH_OPTIONS.map(opt => {
                                const isSelected = selectedTypes.includes(opt.value);
                                const disabled = opt.value === 'welcome' && !isSelected && welcomeTakenByOther;
                                return (
                                    <label key={opt.value}
                                        className={`flex items-center gap-2 text-sm ${disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}`}>
                                        <input type="checkbox" checked={isSelected} disabled={disabled}
                                            onChange={() => toggleType(opt.value)} />
                                        <span>{opt.label}</span>
                                        {disabled && <span className="text-[11px] text-muted-foreground">(ya hay uno para esta instancia)</span>}
                                    </label>
                                );
                            })}
                        </div>
                    )}
                        {errors?.match_types && <p className="text-xs text-destructive">{errors.match_types}</p>}
                    </div>

                    {showTrigger && (
                        <Field label="Palabras clave" value={form.trigger_text}
                            onChange={v => setForm(f => ({ ...f, trigger_text: v }))}
                            placeholder="menu, opciones, ayuda" error={errors?.trigger_text}
                            hint="Sepáralas con comas. No distingue mayúsculas ni tildes." />
                    )}
                </div>

                {/* ── Paso 2: lo que lee el cliente ── */}
                <div className={paso === 1 ? 'space-y-5' : 'hidden'}>
                <Field label="Encabezado (opcional)" value={form.header_text}
                    onChange={v => setForm(f => ({ ...f, header_text: v }))}
                    placeholder="Ej: ColombiaWISP" maxLength={60} error={errors?.header_text} conEmojis />

                <div className="space-y-1.5">
                    <label className="text-sm font-medium text-foreground">Mensaje</label>
                    <div className="relative">
                        <textarea
                            ref={mensajeRef}
                            value={form.body_text}
                            onChange={e => setForm(f => ({ ...f, body_text: e.target.value }))}
                            required rows={3} maxLength={limits.max_body}
                            placeholder={'¡Hola! 👋\nSoy tu asistente virtual.\n¿En qué puedo ayudarte hoy?'}
                            className={`flex w-full rounded-md border bg-transparent px-3 py-2 pr-10 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.body_text ? 'border-destructive' : 'border-input'}`}
                        />
                        <SelectorEmoji
                            className="absolute right-1.5 top-1.5"
                            onElegir={emoji => setForm(f => ({ ...f, body_text: insertarEnCursor(mensajeRef.current, f.body_text, emoji) }))}
                        />
                    </div>
                    <p className="text-[11px] text-muted-foreground">
                        Puedes usar {'{name}'}, {'{phone}'} y {'{wa_id}'}. {form.body_text.length}/{limits.max_body}
                    </p>
                    {errors?.body_text && <p className="text-xs text-destructive">{errors.body_text}</p>}
                </div>

                <Field label="Pie (opcional)" value={form.footer_text}
                    onChange={v => setForm(f => ({ ...f, footer_text: v }))}
                    placeholder="Ej: Atención 24/7" maxLength={60} error={errors?.footer_text} conEmojis />

                </div>

                {/* ── Paso 3: qué puede elegir ── */}
                <div className={paso === 2 ? 'space-y-2 rounded-lg border p-3' : 'hidden'}>
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-foreground">Opciones</p>
                            <p className="text-[11px] text-muted-foreground">
                                {isList
                                    ? `Con ${options.length} opciones el menú sale como lista desplegable.`
                                    : `Con ${options.length} ${options.length === 1 ? 'opción' : 'opciones'} el menú sale como botones (hasta ${limits.max_buttons}).`}
                            </p>
                        </div>
                        <Button type="button" variant="outline" size="sm" className="gap-1.5"
                            onClick={addOption} disabled={options.length >= limits.max_rows}>
                            <Plus className="size-3.5" /> Añadir
                        </Button>
                    </div>

                    <p className="text-[11px] text-muted-foreground">
                        WhatsApp no pone iconos en las opciones: si los quieres, empieza el título
                        con un emoji (ej: 📄 Consultar factura).
                    </p>

                    {tooLongForButton && (
                        <p className="flex items-start gap-1.5 rounded-md bg-warning/15 px-2.5 py-2 text-[11px] text-warning">
                            <AlertTriangle className="size-3.5 shrink-0 mt-px" />
                            Como botón sólo se muestran {limits.max_button_title} caracteres del título. Los más largos se recortarán.
                        </p>
                    )}

                    {isList && (
                        <Field label="Texto del botón que abre la lista" value={form.list_button_text}
                            onChange={v => setForm(f => ({ ...f, list_button_text: v }))}
                            maxLength={limits.max_button_title} placeholder="Ver opciones"
                            error={errors?.list_button_text} />
                    )}

                    <OptionLegend />

                    {options.map((option, index) => (
                        <OptionRow
                            key={index}
                            index={index}
                            option={option}
                            focused={focusOption != null && String(option.id) === String(focusOption)}
                            isList={isList}
                            limits={limits}
                            agents={agents}
                            actionTypes={actionTypes}
                            actionMeta={actionMeta}
                            integra={integra}
                            catalogs={catalogs}
                            statusSegments={statusSegments}
                            submenuChoices={submenuChoices}
                            errors={errors}
                            canMoveUp={index > 0}
                            canMoveDown={index < options.length - 1}
                            onChange={patch => setOption(index, patch)}
                            onRemove={() => removeOption(index)}
                            onMove={delta => moveOption(index, delta)}
                        />
                    ))}
                </div>

                {/* ── Paso 4: ajustes y guardar ── */}
                <div className={paso === 3 ? 'space-y-5' : 'hidden'}>
                    <ResumenMenu form={form} options={options} isList={isList} limits={limits} menus={menus} />

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Espera entre envíos (minutos)" type="number" value={form.cooldown_minutes}
                            onChange={v => setForm(f => ({ ...f, cooldown_minutes: v }))}
                            hint="Evita reenviar el mismo menú si el cliente escribe varias veces seguidas."
                            error={errors?.cooldown_minutes} />
                        <label className="flex items-center gap-2 text-sm self-end pb-2 cursor-pointer">
                            <input type="checkbox" checked={form.active}
                                onChange={e => setForm(f => ({ ...f, active: e.target.checked }))} />
                            Menú activo
                        </label>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
                    <Button type="button" variant="outline" onClick={paso === 0 ? onCancel : () => setPaso(p => p - 1)}>
                        {paso === 0 ? 'Cancelar' : 'Atrás'}
                    </Button>

                    {paso < 3 ? (
                        <Button
                            type="button"
                            disabled={!listoHastaPaso[paso]}
                            onClick={() => setPaso(p => p + 1)}
                            className="gap-1.5"
                        >
                            Continuar <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" disabled={blocked} onClick={onSubmit}>{submitLabel}</Button>
                    )}
                </div>
            </form>

            <MenuPreview form={form} limits={limits} actionMeta={actionMeta} menus={menus} />
        </div>
    );
}

/**
 * Tipos de falla, prioridades y técnicos del entorno Integra de la empresa.
 *
 * Se piden sólo cuando hacen falta —al elegir "Reportar falla"— y una única vez
 * por formulario abierto: es una llamada HTTP a otro servidor y cargarla con la
 * página retrasaría el listado de menús por un select que casi nadie abre.
 */
/**
 * Los catálogos de Integra (tipos de falla, prioridades) para el formulario.
 *
 * Ojo con las dependencias: la versión anterior llevaba `state.loading` en el
 * array y se marcaba a sí misma como cargando antes de pedir nada. Eso cambiaba
 * la dependencia, React desmontaba el efecto, la limpieza ponía `cancelled` y
 * la respuesta se descartaba al llegar. El select se quedaba en "Cargando…"
 * para siempre, en todas las empresas, y parecía un problema de Integra cuando
 * la petición había ido y vuelto perfectamente.
 *
 * Ahora el "ya lo pedí" vive en una ref, que no provoca renders, y el efecto
 * sólo depende de si hace falta pedirlo.
 */
/**
 * En qué paso vive cada campo que puede dar error en el servidor.
 *
 * Sin esto el asistente tiene un final ciego: el servidor rechaza el menú por
 * `match_types` —«ya existe un menú de bienvenida para esta instancia»—, ese
 * campo está en el paso 1, y tú estás en el 4 pulsando «Crear menú» sin ver
 * nada. El botón parece no hacer nada. Pasó en la primera prueba.
 */
const PASO_DEL_CAMPO = {
    name: 0, instance_id: 0, match_types: 0, trigger_text: 0,
    header_text: 1, body_text: 1, footer_text: 1,
    list_button_text: 2,
    cooldown_minutes: 3, active: 3,
};

const pasoDelError = (errores = {}) => {
    const pasos = Object.keys(errores).map(campo => (
        campo.startsWith('options') ? 2 : PASO_DEL_CAMPO[campo]
    )).filter(p => p !== undefined);

    return pasos.length ? Math.min(...pasos) : null;
};

const PASOS_MENU = [
    { titulo: 'Tipo', ayuda: 'Principal o submenú' },
    { titulo: 'Mensaje', ayuda: 'Lo que lee el cliente' },
    { titulo: 'Opciones', ayuda: 'Qué puede elegir' },
    { titulo: 'Revisar', ayuda: 'Ajustes y guardar' },
];

/**
 * La barra de pasos del formulario de menú.
 *
 * El formulario era un scroll de diez secciones —tipo, disparadores, textos,
 * opciones con su acción y su configuración, espera, activo— y había que
 * entenderlo entero antes de escribir la primera letra. Partido en cuatro, cada
 * pantalla hace una pregunta.
 *
 * Se puede volver a un paso ya hecho haciendo clic, pero no saltar hacia
 * delante sin completar el actual: adelantarse es cómo se acaba con un menú
 * guardado a medias.
 */
function PasosMenu({ paso, setPaso, listoHastaPaso }) {
    return (
        <ol className="flex items-center gap-1.5">
            {PASOS_MENU.map((p, i) => {
                const hecho = i < paso;
                const actual = i === paso;
                const alcanzable = i < paso || (i === paso + 1 && listoHastaPaso[paso]);

                return (
                    <li key={p.titulo} className="flex min-w-0 flex-1">
                        <button
                            type="button"
                            disabled={!alcanzable && !actual}
                            onClick={() => alcanzable && setPaso(i)}
                            className={cn(
                                'flex min-w-0 flex-1 items-center gap-2 rounded-xl border p-2 text-left transition-colors',
                                actual && 'border-primary bg-primary/5',
                                hecho && !actual && 'border-border hover:bg-muted',
                                !actual && !hecho && 'border-dashed opacity-60',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                                    actual && 'bg-primary text-primary-foreground',
                                    hecho && !actual && 'bg-primary/15 text-primary',
                                    !actual && !hecho && 'bg-muted text-muted-foreground',
                                )}
                            >
                                {hecho && !actual ? <Check className="size-3.5" /> : i + 1}
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate text-xs font-semibold text-foreground">{p.titulo}</span>
                                <span className="hidden truncate text-[10px] text-muted-foreground sm:block">{p.ayuda}</span>
                            </span>
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}

/** Menú principal o submenú, como dos tarjetas y no como una casilla. */
function TipoDeMenu({ elegido, onElegir, icono: Icono, titulo, descripcion }) {
    return (
        <button
            type="button"
            onClick={onElegir}
            aria-pressed={elegido}
            className={cn(
                'flex flex-col gap-1.5 rounded-xl border p-3 text-left transition-colors',
                elegido ? 'border-primary bg-primary/5 ring-1 ring-primary/20' : 'border-input hover:bg-muted',
            )}
        >
            <span className="flex items-center gap-2">
                <span className={cn(
                    'flex size-7 items-center justify-center rounded-lg',
                    elegido ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                )}>
                    <Icono className="size-4" />
                </span>
                <span className="text-sm font-semibold text-foreground">{titulo}</span>
            </span>
            <span className="text-[11px] leading-snug text-muted-foreground">{descripcion}</span>
        </button>
    );
}

/**
 * El resumen del último paso, en una frase por cosa.
 *
 * Guardar sin releer los diez campos era un acto de fe: aquí se ve, con
 * palabras, cuándo se va a enviar el menú y qué forma va a tener en el
 * teléfono.
 */
function ResumenMenu({ form, options, isList, limits, menus }) {
    const cuando = form.is_root
        ? (form.match_types ?? []).map(t => MATCH_OPTIONS.find(o => o.value === t)?.label ?? t)
        : [];

    const lineas = [
        form.is_root
            ? { que: 'Se envía', valor: cuando.length ? cuando.join(', ').toLowerCase() : 'todavía sin definir' }
            : { que: 'Se abre', valor: 'desde una opción de otro menú, nunca por sí solo' },
        {
            que: 'Sale como',
            valor: isList
                ? `lista desplegable, con ${options.length} opciones`
                : `${options.length} ${options.length === 1 ? 'botón' : 'botones'}`,
        },
        {
            que: 'Aplica a',
            valor: form.instance_id === ''
                ? 'todas las instancias de la empresa'
                : 'una sola instancia',
        },
    ];

    const conSubmenu = options.filter(o => o.action_type === 'submenu').length;

    if (conSubmenu > 0) {
        lineas.push({
            que: 'Lleva a',
            valor: `${conSubmenu} ${conSubmenu === 1 ? 'submenú' : 'submenús'}`,
        });
    }

    return (
        <div className="space-y-2 rounded-xl border border-border bg-muted/40 p-4">
            <p className="text-sm font-semibold text-foreground">Esto es lo que va a pasar</p>
            <dl className="space-y-1.5">
                {lineas.map(l => (
                    <div key={l.que} className="flex gap-2 text-[12px]">
                        <dt className="w-20 shrink-0 font-semibold text-muted-foreground">{l.que}</dt>
                        <dd className="text-foreground">{l.valor}</dd>
                    </div>
                ))}
            </dl>
            {!form.active && (
                <p className="flex items-start gap-1.5 text-[11px] text-warning">
                    <AlertTriangle className="size-3.5 shrink-0 mt-px" />
                    Se guardará desactivado: no se enviará a nadie hasta que lo actives.
                </p>
            )}
        </div>
    );
}

function useIntegraCatalogs(enabled) {
    const [state, setState] = useState({ loading: false, data: null, error: null });
    const requested = useRef(false);

    useEffect(() => {
        if (!enabled || requested.current) return;

        requested.current = true;
        setState({ loading: true, data: null, error: null });

        let alive = true;

        fetch(route('whatsapp-menus.integra-catalogs'), { headers: { Accept: 'application/json' } })
            .then(async res => {
                const body = await res.json().catch(() => ({}));
                if (!alive) return;
                setState(res.ok
                    ? { loading: false, data: body, error: null }
                    : { loading: false, data: null, error: body.message ?? 'No se pudieron cargar los catálogos de Integra.' });
            })
            .catch(() => {
                if (alive) {
                    setState({ loading: false, data: null, error: 'No se pudieron cargar los catálogos de Integra.' });
                }
            });

        return () => { alive = false; };
    }, [enabled]);

    return state;
}


/**
 * Qué hace esta opción y cómo la verá el cliente, debajo del propio selector.
 *
 * Elegir una acción en un desplegable no dice nada: "Estado del contrato" o
 * "Pasar a un asesor" son etiquetas, y hasta que no ves el mensaje que le llega
 * al cliente no sabes si es lo que querías. Antes esto vivía en un modal
 * aparte, que es tanto como no tenerlo: la duda aparece mientras se configura,
 * no después.
 *
 * Lo que escribe el admin se refleja en vivo; lo que arma el sistema se muestra
 * con datos de muestra.
 */


/**
 * Colores suaves por familia de acción.
 *
 * Con ocho opciones seguidas, todas del mismo gris, cuesta ver dónde acaba una y
 * empieza la siguiente. El color las separa y de paso dice algo: verde lo que
 * consulta Integra, azul lo que resuelve la plataforma sola, ámbar lo que
 * todavía no existe.
 */
/**
 * Qué significa el color de cada tarjeta.
 *
 * Un código de color sin leyenda es un adorno: el admin ve verdes y azules y se
 * pregunta por qué, que es exactamente lo que pasó. Explicado, se convierte en
 * información — de un vistazo se ve cuánto del menú depende de Integra.
 */
function OptionLegend() {
    const items = [
        { tone: 'bg-success', label: 'Consulta Integra', hint: 'Necesita el complemento conectado' },
        { tone: 'bg-info', label: 'Lo resuelve la plataforma', hint: 'Funciona siempre, sin depender de nadie' },
        { tone: 'bg-warning', label: 'Todavía no disponible', hint: 'Responde un aviso de «próximamente»' },
        { tone: 'bg-muted', label: 'Sin acción', hint: 'El cliente la ve y no recibe nada' },
    ];

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-md border border-dashed px-2.5 py-2">
            <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                Color de cada opción
            </span>
            {items.map(i => (
                <span key={i.label} className="flex items-center gap-1.5 text-[11px] text-muted-foreground" title={i.hint}>
                    <span className={`size-2.5 rounded-sm ${i.tone}`} />
                    {i.label}
                </span>
            ))}
        </div>
    );
}

/**
 * El color de cada tarjeta de opción dice de qué familia es la acción.
 *
 * El fondo era `bg-info/60`: **60% de opacidad de un azul saturado**, que en
 * tema claro tapaba la tarjeta entera de azul fuerte y dejaba los campos
 * flotando dentro de un bloque de color (9-sep-2026). El color tiene que
 * marcar, no gritar: se queda en el borde izquierdo, que ya estaba, y el fondo
 * baja a un lavado que se lee igual en los dos temas.
 */
const GROUP_TONES = {
    core: {
        card: 'border-l-info bg-info/[0.04] dark:bg-info/[0.10]',
        badge: 'bg-info/15 text-info dark:bg-info/25',
    },
    integra: {
        card: 'border-l-success bg-success/[0.04] dark:bg-success/[0.10]',
        badge: 'bg-success/15 text-success dark:bg-success/25',
    },
    pending: {
        card: 'border-l-warning bg-warning/[0.05] dark:bg-warning/[0.10]',
        badge: 'bg-warning/15 text-warning dark:bg-warning/25',
    },
    none: {
        card: 'border-l-border bg-muted/40',
        badge: 'bg-background text-muted-foreground',
    },
};

/**
 * Qué poner en el desplegable de tipos de falla según lo que haya pasado.
 *
 * Son tres situaciones distintas y antes las tres se veían igual —un desplegable
 * vacío—, que es la peor forma de dejar a alguien en el aire: no sabe si está
 * cargando, si falló, o si es que no hay nada que elegir.
 */
function faultTypesPlaceholder(catalogs, servicios) {
    if (catalogs.loading) return 'Consultando Integra…';
    if (catalogs.error) return 'No se pudo consultar Integra';
    if (!servicios.length) return 'Tu Integra no tiene servicios de soporte';

    return 'Elige el servicio…';
}

function faultTypesHint(catalogs, servicios) {
    if (catalogs.loading || catalogs.error) return null;

    if (!servicios.length) {
        return 'Integra respondió, pero no tiene ningún servicio de soporte creado. Créalos en tu Integra '
            + '(Soporte › Servicios) y vuelve aquí: aparecerán solos.';
    }

    // La confusión típica: parece que aquí se elige la falla del cliente, y es
    // al revés. Él la describe con sus palabras; esto es la cola de Integra.
    return 'La falla la describe el cliente con sus palabras y eso va como texto del radicado. '
        + 'Esto es la categoría con la que entra en Integra, igual para todos los reportes de esta opción. '
        + '¿Quieres separarlos? Crea un submenú con una opción por cada servicio.';
}

/** La caja de ajustes de una acción: un solo sitio, y sólo si hay algo dentro. */
function Settings({ children }) {
    const any = Array.isArray(children)
        ? children.flat().some(Boolean)
        : Boolean(children);

    if (!any) return null;

    return (
        <div className="space-y-2 rounded-md border p-2.5">
            <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Ajustes</p>
            <div className="grid grid-cols-2 gap-2">{children}</div>
        </div>
    );
}

/** Un campo de la rejilla, con su etiqueta encima y su ayuda debajo. */
function SettingField({ label, hint, wide = false, required = false, children }) {
    return (
        <div className={`space-y-1 ${wide ? 'col-span-2' : ''}`}>
            <label className="text-[10px] font-medium text-muted-foreground">
                {label}{required && <span className="text-warning"> · falta</span>}
            </label>
            {children}
            {hint && <p className="text-[10px] leading-relaxed text-muted-foreground">{hint}</p>}
        </div>
    );
}

function OptionExplainer({ option, actionMeta, submenuChoices = [] }) {
    const type = option.action_type;
    const target = type === 'submenu'
        ? submenuChoices.find(m => String(m.id) === String(option.target_menu_id))
        : null;

    const written = (option.reply_text ?? '').trim();

    // El mensaje que verá el cliente: el del admin cuando lo escribe él, y si no
    // el que arma el sistema.
    let bubble = null;

    if (type === 'reply_text' || type === 'reply_image') {
        bubble = written !== '' ? fillVars(written) : null;
    } else if (type === 'handoff') {
        bubble = written !== '' ? fillVars(written) : null;
    } else if (type === 'estado_servicio') {
        bubble = SEGMENT_SAMPLES[option.config?.segmento || 'resumen'] ?? null;
    } else {
        bubble = ACTION_SAMPLES[type] ?? null;
        // Las acciones de Integra admiten un texto extra al final.
        if (bubble && written !== '') bubble += '\n\n' + fillVars(written);
    }

    const does = describeOption(option, actionMeta, target);
    const warns = does.startsWith('⚠️');

    return (
        <div className="space-y-2 rounded-md border bg-muted/30 p-2.5">
            <p className={`text-[11px] ${warns ? 'text-warning' : 'text-muted-foreground'}`}>
                <span className="font-medium text-foreground">Qué pasa: </span>{does}
            </p>

            {bubble && (
                <div>
                    <p className="mb-1 text-[10px] font-medium text-muted-foreground">
                        Así lo verá el cliente:
                    </p>
                    <div className="rounded-lg rounded-tl-none bg-white p-2 shadow-sm dark:bg-muted">
                        <p className="whitespace-pre-wrap text-[11px] leading-relaxed text-foreground dark:text-muted-foreground">
                            {bubble}
                        </p>
                    </div>
                    {type === 'estado_servicio' && (
                        <p className="mt-1 text-[10px] text-muted-foreground">
                            Ejemplo con datos de muestra; los de tu cliente salen de Integra.
                        </p>
                    )}
                </div>
            )}

            {type === 'reply_image' && option.config?.image_url && (
                <div>
                    <p className="mb-1 text-[10px] font-medium text-muted-foreground">Y esta imagen:</p>
                    <img src={option.config.image_url} alt="" className="max-h-32 rounded-lg border object-contain" />
                </div>
            )}
        </div>
    );
}

function OptionRow({ index, option, focused = false, isList, limits, agents, submenuChoices, actionTypes = [], actionMeta = {}, integra = {}, catalogs = {}, statusSegments = [], errors, canMoveUp, canMoveDown, onChange, onRemove, onMove }) {
    const ActionIcon = iconFor(option.action_type);
    const tituloRef = useRef(null);
    const respuestaRef = useRef(null);
    const meta = actionMeta[option.action_type];
    const config = option.config ?? {};
    const setConfig = patch => onChange({ config: { ...config, ...patch } });
    // Mismo criterio que WhatsAppMenuOption::assignStrategy(): las opciones
    // creadas antes de que existiera este ajuste siguen comportándose igual.
    const strategy = config.assign_strategy ?? (option.assign_to_user_id ? 'fixed' : 'inbox');
    const servicios = catalogs.data?.servicios ?? [];
    // El enlace de pago también se ofrece en "Reportar falla": cuando la falla
    // resulta ser un corte por mora, es lo único accionable que el cliente
    // puede hacer.
    const needsPaymentUrl = ['pagar_en_linea', 'reportar_falla'].includes(option.action_type);
    const grouped = GROUP_ORDER
        .map(group => [group, actionTypes.filter(a => a.group === group)])
        .filter(([, list]) => list.length > 0);

    // El color separa las tarjetas de un vistazo y además significa algo: qué
    // familia de acción es. Decorarlas al azar habría ordenado la vista sin
    // enseñar nada.
    const tone = GROUP_TONES[meta?.group] ?? GROUP_TONES.core;

    // Lo que va mal en esta opción, con las mismas palabras que la revisión de
    // arriba: si el aviso te trajo hasta aquí, tienes que reconocerlo.
    const problem = describeOption(option, actionMeta,
        submenuChoices.find(m => String(m.id) === String(option.target_menu_id)));
    const broken = problem.startsWith('⚠️');

    // Cuando se entra desde un aviso, el formulario baja hasta la opción y la
    // resalta. Abrirlo por arriba y dejar al admin buscando cuál de las ocho
    // era es justo el paso que se quería ahorrar.
    const ref = useRef(null);

    useEffect(() => {
        if (!focused || !ref.current) return;

        ref.current.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [focused]);

    return (
        <div ref={ref}
            className={`space-y-2 rounded-md border border-l-[3px] p-2.5 transition-shadow ${tone.card} ${
                focused ? 'ring-2 ring-primary ring-offset-2' : broken ? 'ring-1 ring-warning/30' : ''
            }`}>
            <div className="flex items-center gap-2">
                <span className={`flex size-6 shrink-0 items-center justify-center rounded text-xs font-semibold ${tone.badge}`}>
                    {index + 1}
                </span>
                <div className="relative w-full">
                    <input
                        ref={tituloRef}
                        value={option.title}
                        onChange={e => onChange({ title: e.target.value })}
                        maxLength={limits.max_row_title}
                        placeholder="Título de la opción (ej: 📄 Consultar factura)"
                        className={`flex h-8 w-full rounded-md border bg-background px-2.5 pr-9 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.[`options.${index}.title`] ? 'border-destructive' : 'border-input'}`}
                    />
                    <SelectorEmoji
                        className="absolute right-0.5 top-0.5"
                        titulo="Poner un emoji en el título"
                        onElegir={emoji => onChange({ title: insertarEnCursor(tituloRef.current, option.title, emoji) })}
                    />
                </div>
                <div className="flex shrink-0">
                    <button type="button" onClick={() => onMove(-1)} disabled={!canMoveUp}
                        className="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Subir">
                        <ChevronUp className="size-3.5" />
                    </button>
                    <button type="button" onClick={() => onMove(1)} disabled={!canMoveDown}
                        className="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Bajar">
                        <ChevronDown className="size-3.5" />
                    </button>
                    <button type="button" onClick={onRemove}
                        className="p-1 text-muted-foreground hover:text-destructive" title="Quitar">
                        <X className="size-3.5" />
                    </button>
                </div>
            </div>

            {broken && (
                <p className="flex items-center gap-1.5 text-[11px] font-medium text-warning">
                    <AlertTriangle className="size-3.5 shrink-0" /> Le falta algo para funcionar
                </p>
            )}

            {isList && (
                <input
                    value={option.description ?? ''}
                    onChange={e => onChange({ description: e.target.value })}
                    maxLength={limits.max_row_description}
                    placeholder="Descripción corta (opcional, sólo se ve en lista)"
                    className="flex h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                />
            )}

            <div className="flex items-center gap-2">
                <ActionIcon className="size-3.5 shrink-0 text-muted-foreground" />
                <Select value={option.action_type} onChange={v => onChange({ action_type: v })} className="h-8 text-xs">
                    {grouped.map(([group, list]) => (
                        <optgroup key={group} label={GROUP_LABELS[group] ?? group}>
                            {list.map(a => <option key={a.value} value={a.value}>{a.label}</option>)}
                        </optgroup>
                    ))}
                </Select>
            </div>

            <OptionExplainer option={option} actionMeta={actionMeta} submenuChoices={submenuChoices} />

            {option.action_type === 'reply_text' && (
                <div className="relative">
                    <textarea
                        ref={respuestaRef}
                        value={option.reply_text ?? ''}
                        onChange={e => onChange({ reply_text: e.target.value })}
                        rows={2} maxLength={4096}
                        placeholder="Mensaje que recibirá el cliente al elegir esta opción"
                        className={`flex w-full rounded-md border bg-background px-2.5 py-1.5 pr-9 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.[`options.${index}.reply_text`] ? 'border-destructive' : 'border-input'}`}
                    />
                    <SelectorEmoji
                        className="absolute right-1 top-1"
                        onElegir={emoji => onChange({ reply_text: insertarEnCursor(respuestaRef.current, option.reply_text ?? '', emoji) })}
                    />
                </div>
            )}

            {option.action_type === 'reply_image' && (
                <>
                    <ImageField
                        url={config.image_url ?? ''}
                        error={errors?.[`options.${index}.config.image_url`]}
                        onChange={url => setConfig({ image_url: url })}
                    />
                    <textarea
                        value={option.reply_text ?? ''}
                        onChange={e => onChange({ reply_text: e.target.value })}
                        rows={2} maxLength={1024}
                        placeholder="Pie de foto (opcional). Ej: Paga en cualquiera de estos puntos 👆"
                        className="flex w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    />
                    <p className="text-[10px] text-muted-foreground">
                        El pie viaja con la imagen. Escríbelo pensando en quien no puede verla:
                        si la imagen no carga, es lo único que le queda.
                    </p>
                </>
            )}

            {option.action_type === 'submenu' && (
                <>
                    <Select value={option.target_menu_id} onChange={v => onChange({ target_menu_id: v })} className="h-8 text-xs">
                        <option value="">Elige el submenú…</option>
                        {submenuChoices.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </Select>
                    {submenuChoices.length === 0 && (
                        <p className="text-[11px] text-muted-foreground">
                            Aún no hay submenús. Crea primero un menú marcado como "Es un submenú".
                        </p>
                    )}
                </>
            )}

            {option.action_type === 'handoff' && (
                <>
                    <div className="flex items-center gap-2">
                        <Users className="size-3.5 shrink-0 text-muted-foreground" />
                        <Select value={strategy} onChange={v => setConfig({ assign_strategy: v })} className="h-8 text-xs">
                            {ASSIGN_OPTIONS.map(a => <option key={a.value} value={a.value}>{a.label}</option>)}
                        </Select>
                    </div>

                    {strategy === 'fixed' && (
                        <Select value={option.assign_to_user_id} onChange={v => onChange({ assign_to_user_id: v })}
                            className={`h-8 text-xs ${errors?.[`options.${index}.assign_to_user_id`] ? 'border-destructive' : ''}`}>
                            <option value="">Elige el asesor…</option>
                            {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </Select>
                    )}

                    {strategy === 'least_busy' && (
                        <p className="text-[11px] text-muted-foreground">
                            Se asigna al asesor activo con menos conversaciones abiertas. Si varios empatan,
                            gana el que lleva más tiempo sin recibir un chat. Si no hay nadie disponible, el
                            chat queda en la bandeja general con una nota.
                        </p>
                    )}

                    <textarea
                        value={option.reply_text ?? ''}
                        onChange={e => onChange({ reply_text: e.target.value })}
                        rows={2} maxLength={4096}
                        placeholder="Mensaje de confirmación (opcional). Ej: Te comunico con un asesor…"
                        className="flex w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    />
                </>
            )}

            {meta?.group === 'integra' && (
                <>
                    {!integra.connected && (
                        <p className="flex items-start gap-1.5 rounded-md bg-warning/15 px-2.5 py-2 text-[11px] text-warning">
                            <Plug className="size-3.5 shrink-0 mt-px" />
                            Tu software Integra no está conectado. Conéctalo desde Integraciones; mientras
                            tanto, quien elija esta opción será derivado a un asesor.
                        </p>
                    )}

                    {/* Un solo sitio para los ajustes, cada campo con su
                        etiqueta y todos del mismo alto. Antes caían sueltos
                        entre párrafos de ayuda y no se sabía a qué pertenecía
                        cada caja. */}
                    <Settings>
                        {option.action_type === 'estado_servicio' && (
                            <SettingField label="¿Qué le muestra al cliente?" wide
                                hint="Una sola consulta trae todo el contrato; esto elige qué parte se le cuenta.">
                                <Select value={config.segmento ?? 'resumen'} onChange={v => setConfig({ segmento: v })} className="h-8 text-xs">
                                    {statusSegments.map(sg => <option key={sg.value} value={sg.value}>{sg.label}</option>)}
                                </Select>
                            </SettingField>
                        )}

                        {option.action_type === 'reportar_falla' && (
                            <>
                                <SettingField label="¿Bajo qué servicio de Integra entra?"
                                    required={!config.radicado_servicio}
                                    hint={faultTypesHint(catalogs, servicios)}>
                                    <Select value={config.radicado_servicio ?? ''} onChange={v => setConfig({ radicado_servicio: v })}
                                        className="h-8 text-xs" disabled={!servicios.length}>
                                        <option value="">{faultTypesPlaceholder(catalogs, servicios)}</option>
                                        {servicios.map(sv => <option key={sv.id} value={sv.id}>{sv.nombre}</option>)}
                                    </Select>
                                </SettingField>
                                <SettingField label="Prioridad">
                                    <Select value={config.radicado_prioridad ?? '2'} onChange={v => setConfig({ radicado_prioridad: v })}
                                        className="h-8 text-xs">
                                        {RADICADO_PRIORITIES.map(pr => <option key={pr.value} value={pr.value}>{pr.label}</option>)}
                                    </Select>
                                </SettingField>
                            </>
                        )}

                        {needsPaymentUrl && (
                            <SettingField
                                label="Enlace de pago"
                                wide
                                hint={option.action_type === 'reportar_falla'
                                    ? 'Sólo se usa si el cliente está cortado por mora: en vez de abrir el radicado, se le ofrece pagar.'
                                    : 'Variables: {nit} {cliente_id} {nombre} {total} {factura}. Vacío si el enlace lo manda tu sistema.'}
                            >
                                <input
                                    value={config.payment_url ?? ''}
                                    onChange={e => setConfig({ payment_url: e.target.value })}
                                    maxLength={500}
                                    placeholder="https://pagos.tuempresa.com/?nit={nit}&valor={total}"
                                    className={`flex h-8 w-full rounded-md border bg-background px-2.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.[`options.${index}.config.payment_url`] ? 'border-destructive' : 'border-input'}`}
                                />
                            </SettingField>
                        )}
                    </Settings>

                    {option.action_type === 'reportar_falla' && catalogs.error && (
                        <div className="rounded-md bg-destructive/10 px-2.5 py-2 text-[11px] text-destructive">
                            <p>{catalogs.error}</p>
                            {/* El tipo de falla sale del catálogo de Integra, así
                                que sin conexión el desplegable no se puede llenar:
                                lo que hay que arreglar está en otra pantalla. */}
                            <a href={route('integrations.index')}
                                className="mt-1 inline-flex items-center gap-1 underline underline-offset-2">
                                <Plug className="size-3" /> Reconectar Integra
                            </a>
                        </div>
                    )}

                    <div className="space-y-1">
                        <label className="text-[10px] font-medium text-muted-foreground">
                            Texto tuyo al final (opcional)
                        </label>
                        <textarea
                            value={option.reply_text ?? ''}
                            onChange={e => onChange({ reply_text: e.target.value })}
                            rows={2} maxLength={4096}
                            placeholder="Ej: Escribe MENU para volver."
                            className="flex w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </div>
                </>
            )}

            {meta?.group === 'pending' && (
                <>
                    <p className="flex items-start gap-1.5 rounded-md bg-warning/15 px-2.5 py-2 text-[11px] text-warning">
                        <Construction className="size-3.5 shrink-0 mt-px" />
                        La opción ya sale en el menú, pero todavía no consulta nada. Mientras se
                        conecte, el cliente recibe este aviso.
                    </p>
                    <textarea
                        value={option.reply_text ?? ''}
                        onChange={e => onChange({ reply_text: e.target.value })}
                        rows={2} maxLength={4096}
                        placeholder={meta.reply ?? ''}
                        className="flex w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    />
                    <p className="text-[10px] text-muted-foreground">
                        Déjalo vacío para usar el aviso por defecto.
                    </p>
                </>
            )}

            {meta?.group === 'none' && (
                <p className="flex items-start gap-1.5 rounded-md bg-muted px-2.5 py-2 text-[11px] text-muted-foreground">
                    <AlertTriangle className="size-3.5 shrink-0 mt-px" />
                    El cliente no recibirá nada al elegirla. Úsalo sólo mientras decides qué debe hacer.
                </p>
            )}
        </div>
    );
}

/**
 * Cómo le llega el menú al cliente.
 *
 * Existe porque el menú no se puede probar sin mandarlo: la única forma de ver
 * si el texto cabe, si el título se recorta o si con cuatro opciones deja de
 * salir como botones era enviárselo a alguien de verdad.
 *
 * Es una aproximación —WhatsApp cambia de aspecto entre versiones y no admite
 * iconos en las filas— pero el contenido, el formato y los recortes son
 * exactamente los que aplica el backend al construir el payload.
 */
/**
 * El botón que resuelve el aviso.
 *
 * Sin él la revisión era un callejón sin salida: decía "reconéctalo desde
 * Integraciones" y dejaba al admin buscando la pantalla a mano. Un diagnóstico
 * que no lleva a donde se arregla no es mejor que no diagnosticar.
 */
function IssueAction({ action, menuId, optionId, onEditMenu, onConnect }) {
    if (!action) return null;

    if (action.kind === 'integrations') {
        return (
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" onClick={onConnect}
                    className="inline-flex items-center gap-1 rounded-md border bg-background px-2 py-1 text-[11px] font-medium text-foreground hover:bg-accent">
                    <Plug className="size-3" /> {action.label}
                </button>
                {/* Por si prefiere el módulo completo: revisar el estado, la
                    sincronización de contactos o el comando del chat. */}
                <a href={route('integrations.index')}
                    className="text-[11px] text-muted-foreground underline underline-offset-2 hover:text-foreground">
                    o abrir Integraciones
                </a>
            </div>
        );
    }

    if (action.kind === 'menu' && menuId) {
        return (
            <button type="button" onClick={() => onEditMenu?.(menuId, optionId)}
                className="inline-flex items-center gap-1 rounded-md border bg-background px-2 py-1 text-[11px] font-medium text-foreground hover:bg-accent">
                <Pencil className="size-3" /> {action.label}
            </button>
        );
    }

    return null;
}

/**
 * La revisión del menú: qué va a fallar antes de que lo toque un cliente.
 *
 * Se pide aparte de la página porque comprueba contra el servidor de Integra
 * qué permisos tiene de verdad el token. Es la diferencia entre "conectado" y
 * "funciona": un token con facturas pero sin contratos deja el panel en verde y
 * cada cliente que pregunte por su servicio acaba derivado a un asesor.
 */
function ReviewPanel({ onEditMenu }) {
    const [state, setState] = useState({ loading: true, data: null, error: null });
    const [open, setOpen] = useState(true);
    const [connecting, setConnecting] = useState(false);
    const [connectError, setConnectError] = useState(null);

    const load = (fresh = false) => {
        setState(s => ({ ...s, loading: true }));

        fetch(route('whatsapp-menus.revision') + (fresh ? '?fresh=1' : ''), { headers: { Accept: 'application/json' } })
            .then(async res => {
                const body = await res.json().catch(() => ({}));
                setState(res.ok
                    ? { loading: false, data: body, error: null }
                    : { loading: false, data: null, error: body.message ?? 'No se pudo revisar el menú.' });
            })
            .catch(() => setState({ loading: false, data: null, error: 'No se pudo revisar el menú.' }));
    };

    useEffect(() => { load(); }, []);

    if (state.loading && !state.data) {
        return (
            <div className="rounded-xl border px-4 py-3 text-sm text-muted-foreground">
                Revisando tu menú y los permisos de Integra…
            </div>
        );
    }

    if (state.error) return null;

    const { capabilities = {}, issues = [] } = state.data ?? {};
    const blockers = issues.filter(i => i.level === 'blocker');
    const warnings = issues.filter(i => i.level === 'warning');
    const clean = issues.length === 0;

    return (
        <div className={`rounded-xl border ${blockers.length ? 'border-destructive/40 bg-destructive/5' : clean ? 'border-success/30 bg-success/5' : 'border-warning/30 bg-warning/5'}`}>
            <button type="button" onClick={() => setOpen(o => !o)}
                className="flex w-full items-center gap-2.5 px-4 py-3 text-left">
                {clean
                    ? <CheckCircle2 className="size-4 shrink-0 text-success" />
                    : <AlertTriangle className={`size-4 shrink-0 ${blockers.length ? 'text-destructive' : 'text-warning'}`} />}
                <span className="text-sm font-medium text-foreground">
                    {clean
                        ? 'Tu menú está listo para responder'
                        : blockers.length
                            ? `${blockers.length} ${blockers.length === 1 ? 'cosa impide' : 'cosas impiden'} que tu menú responda`
                            : `${warnings.length} ${warnings.length === 1 ? 'detalle' : 'detalles'} por revisar`}
                </span>
                {capabilities.connected === false && (
                    <span className="text-xs text-muted-foreground">· Integra no está conectado</span>
                )}
                <ChevronDown className={`ml-auto size-4 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="space-y-3 border-t px-4 py-3">
                    {capabilities.checked && (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-[11px] text-muted-foreground">Tu token de Integra puede:</span>
                            {Object.entries(state.data.labels ?? {}).map(([key, label]) => (
                                <span key={key}
                                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ${capabilities.can?.[key] ? 'bg-success/10 text-success' : 'bg-destructive/10 text-destructive'}`}>
                                    {capabilities.can?.[key] ? '✓' : '✕'} {label}
                                </span>
                            ))}
                        </div>
                    )}

                    {issues.map((issue, i) => (
                        <div key={i} className="flex gap-2 text-xs">
                            <span className={`mt-1 size-1.5 shrink-0 rounded-full ${issue.level === 'blocker' ? 'bg-destructive' : 'bg-warning'}`} />
                            <div className="min-w-0 space-y-1">
                                <p className="text-foreground">
                                    {issue.menu && (
                                        <>
                                            <span className="font-medium">{issue.menu}</span>
                                            {issue.option && <span className="text-muted-foreground"> › {issue.option}</span>}
                                            {' — '}
                                        </>
                                    )}
                                    {issue.says}
                                </p>
                                <p className="text-muted-foreground">{issue.fix}</p>
                                <IssueAction action={issue.action} menuId={issue.menu_id} optionId={issue.option_id}
                                    onEditMenu={onEditMenu} onConnect={() => setConnecting(true)} />
                            </div>
                        </div>
                    ))}

                    <button type="button" onClick={() => load(true)} disabled={state.loading}
                        className="text-[11px] text-muted-foreground hover:underline">
                        {state.loading ? 'Revisando…' : 'Volver a revisar'}
                    </button>
                </div>
            )}

            {connecting && (
                <Modal
                    title="Conectar Integra"
                    description="Una sola conexión habilita las facturas, los contactos y las respuestas del menú"
                    onClose={() => { setConnecting(false); setConnectError(null); }}
                >
                    {connectError && (
                        <p className="mb-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            {connectError}
                        </p>
                    )}
                    <ProviderConnectForm
                        onConnected={() => {
                            setConnecting(false);
                            setConnectError(null);
                            // Se revisa de nuevo en el acto: el sentido de
                            // conectar desde aquí es ver los avisos desaparecer
                            // sin cambiar de pantalla.
                            load(true);
                        }}
                        onError={setConnectError}
                    />
                </Modal>
            )}
        </div>
    );
}

/**
 * La imagen de una opción: se sube al elegirla y se guarda su URL.
 *
 * Sube al momento y no al guardar el menú porque Meta descarga la imagen desde
 * esa URL cuando envía el mensaje: si el almacenamiento no la publica bien, el
 * fallo aparecería con el primer cliente que tocara la opción. Subiéndola ya,
 * el admin la ve —o ve el error— antes de encender nada.
 */
function ImageField({ url, error, onChange }) {
    const [uploading, setUploading] = useState(false);
    const [failed, setFailed] = useState(null);

    const upload = async file => {
        if (!file) return;

        setUploading(true);
        setFailed(null);

        const body = new FormData();
        body.append('image', file);

        try {
            const res = await fetch(route('whatsapp-menus.imagen'), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body,
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                setFailed(data.errors?.image?.[0] ?? data.message ?? 'No se pudo subir la imagen.');
                return;
            }

            onChange(data.url);
        } catch {
            setFailed('No se pudo subir la imagen. Revisa tu conexión e intenta de nuevo.');
        } finally {
            setUploading(false);
        }
    };

    if (url) {
        return (
            <div className="space-y-1">
                <div className="flex items-start gap-2 rounded-md border border-input p-2">
                    <img src={url} alt="Imagen de la opción"
                        className="size-16 shrink-0 rounded object-cover bg-muted" />
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-[11px] text-muted-foreground">
                            Así la recibirá el cliente. Compruébala: si no se ve aquí, tampoco le llegará a él.
                        </p>
                        <button type="button" onClick={() => { onChange(''); setFailed(null); }}
                            className="text-[11px] text-destructive hover:underline">
                            Quitar imagen
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <label className={`flex h-16 cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed text-xs text-muted-foreground hover:bg-accent/50 ${error || failed ? 'border-destructive' : 'border-input'}`}>
                <ImagePlus className="size-4" />
                {uploading ? 'Subiendo…' : 'Elegir imagen (JPG o PNG, máx. 5 MB)'}
                <input type="file" accept="image/jpeg,image/png" className="hidden" disabled={uploading}
                    onChange={e => upload(e.target.files?.[0])} />
            </label>
            {(failed || error) && (
                <p className="text-[11px] text-destructive">{failed ?? error}</p>
            )}
        </div>
    );
}

function MenuPreview({ form, limits, actionMeta = {}, menus = [] }) {
    // Nace abierto: la vista previa existe para ver lo que recibe el cliente,
    // y con el listado plegado la mitad del menú quedaba escondida detrás de un
    // clic que nadie daba.
    const [listOpen, setListOpen] = useState(true);

    const all = form.options ?? [];
    const isList = all.length > limits.max_buttons;
    const rows = all.filter(o => (o.title ?? '').trim() !== '');

    const header = fillVars(form.header_text).trim();
    const body = fillVars(form.body_text).trim();
    const footer = fillVars(form.footer_text).trim();

    return (
        <div className="lg:sticky lg:top-4 space-y-2">
            <div className="flex items-center justify-between">
                <p className="flex items-center gap-1.5 text-xs font-medium text-foreground">
                    <Smartphone className="size-3.5" /> Vista previa
                </p>
                <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                    {isList ? 'Lista' : 'Botones'}
                </span>
            </div>

            <div className="overflow-hidden rounded-[1.75rem] border-[6px] border-border bg-muted shadow-lg">
                <div className="flex items-center gap-2 bg-[#075E54] px-3 py-2 text-white">
                    <div className="size-6 rounded-full bg-white/25" />
                    <div className="leading-tight">
                        <p className="text-[11px] font-medium">{SAMPLE.name}</p>
                        <p className="text-[9px] text-white/70">en línea</p>
                    </div>
                </div>

                <div className="min-h-[18rem] space-y-1.5 bg-[#ECE5DD] p-2.5 dark:bg-muted">
                    <div className="max-w-[88%] rounded-lg rounded-tl-sm bg-white px-2.5 py-2 shadow-sm dark:bg-muted">
                        {header && (
                            <p className="mb-1 text-[11px] font-semibold text-foreground dark:text-muted-foreground">{cut(header, limits.max_row_title)}</p>
                        )}
                        <p className={`whitespace-pre-wrap break-words text-[11px] leading-snug ${body ? 'text-foreground dark:text-muted-foreground' : 'italic text-muted-foreground'}`}>
                            {body || 'Aquí va el mensaje del menú…'}
                        </p>
                        {footer && <p className="mt-1 text-[10px] text-muted-foreground">{footer}</p>}
                        <p className="mt-0.5 text-right text-[9px] text-muted-foreground">9:41</p>
                    </div>

                    {rows.length === 0 && (
                        <p className="pt-2 text-center text-[10px] italic text-muted-foreground">
                            Escribe el título de las opciones para verlas aquí.
                        </p>
                    )}

                    {/* Hasta tres opciones WhatsApp las pinta como botones sueltos
                        debajo de la burbuja; de cuatro en adelante, un único botón
                        que abre el listado. */}
                    {!isList && rows.map((option, i) => (
                        <div key={i} className="max-w-[88%] rounded-lg bg-white py-1.5 text-center text-[11px] font-medium text-[#00A5F4] shadow-sm dark:bg-muted">
                            {cut(option.title, limits.max_button_title)}
                        </div>
                    ))}

                    {isList && rows.length > 0 && (
                        <>
                            <button
                                type="button"
                                onClick={() => setListOpen(open => !open)}
                                className="flex w-[88%] items-center justify-center gap-1.5 rounded-lg bg-white py-1.5 text-[11px] font-medium text-[#00A5F4] shadow-sm dark:bg-muted"
                            >
                                <List className="size-3" />
                                {cut(form.list_button_text || 'Ver opciones', limits.max_button_title)}
                            </button>

                            {listOpen && (
                                <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-muted">
                                    <p className="border-b border-border px-2.5 py-1.5 text-[10px] font-semibold text-muted-foreground dark:border-border">
                                        Opciones
                                    </p>
                                    {rows.map((option, i) => (
                                        <div key={i} className="flex items-start gap-2 border-b border-border px-2.5 py-2 last:border-0 dark:border-border">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-[11px] text-foreground dark:text-muted-foreground">
                                                    {cut(option.title, limits.max_row_title)}
                                                </p>
                                                {(option.description ?? '').trim() !== '' && (
                                                    <p className="truncate text-[10px] text-muted-foreground">
                                                        {cut(option.description, limits.max_row_description)}
                                                    </p>
                                                )}
                                            </div>
                                            <span className="mt-0.5 size-3 shrink-0 rounded-full border border-border" />
                                        </div>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>

            <p className="text-[10px] leading-relaxed text-muted-foreground">
                Aproximación con datos de ejemplo ({SAMPLE.name}). Los títulos se muestran
                recortados igual que en WhatsApp: {limits.max_button_title} caracteres
                como botón y {limits.max_row_title} en lista.
            </p>

        </div>
    );
}

/** Qué le pasa al cliente al tocar esta opción, en una frase. */
function describeOption(option, actionMeta, target) {
    const label = actionMeta[option.action_type]?.label ?? option.action_type;
    const text = (option.reply_text ?? '').trim();

    switch (option.action_type) {
        case 'reply_text':
            return text !== ''
                ? 'Recibe este mensaje: «' + cut(fillVars(text), 120) + '»'
                : '⚠️ Responde con un mensaje, pero está vacío: no recibiría nada.';
        case 'reply_image':
            return option.config?.image_url
                ? 'Recibe la imagen que subiste' + (text !== '' ? ', con el pie «' + cut(text, 80) + '»' : ', sin pie de foto.')
                : '⚠️ Es una opción de imagen y no tiene imagen: sólo recibiría el pie de foto.';
        case 'submenu':
            return target
                ? 'Se le abre el menú «' + target.name + '»' + (target.active ? '.' : ' ⚠️ que está apagado: no recibiría nada.')
                : '⚠️ No tiene submenú elegido: al tocarla no pasaría nada.';
        case 'handoff':
            return 'El chat pasa a una persona y el bot deja de responder' + (text !== '' ? ', tras recibir «' + cut(text, 80) + '»' : '.');
        case 'none':
            return '⚠️ La ve y la toca, pero no recibe nada. Sólo sirve para armar el menú.';
        case 'estado_servicio':
            return 'Consultamos su contrato en Integra y le respondemos: '
                + (option.config?.segmento ? SEGMENT_HINTS[option.config.segmento] ?? option.config.segmento : 'el resumen completo') + '.';
        case 'reportar_falla':
            return option.config?.radicado_servicio
                ? 'Revisamos su contrato; si no tiene un reporte en curso ni está cortado por mora, le pedimos que nos cuente qué le pasa y abrimos el radicado en Integra con lo que escriba.'
                : '⚠️ Falta elegir bajo qué servicio de Integra entra el radicado: sin eso no se puede crear y acabaría con un asesor.';
        case 'consultar_factura':
            return 'Lo identificamos por su número de WhatsApp y le respondemos sus facturas pendientes, el total y su saldo a favor. Si no lo encontramos, le pedimos el documento.';
        case 'pagar_en_linea':
            return 'Le decimos cuánto debe y le entregamos tu enlace de pago'
                + (option.config?.payment_url ? '.' : ' ⚠️ que todavía no has configurado: vería el total sin dónde pagar.');
        case 'cambiar_clave':
            return 'Todavía no existe la integración detrás: recibe un aviso de «próximamente». Escríbele tu propio texto para que sepa a dónde acudir.';
        default:
            return label + '.';
    }
}

/** Qué cuenta cada segmento, para la explicación de arriba. */
const SEGMENT_HINTS = {
    resumen: 'el resumen completo del servicio',
    internet: 'si su internet está activo y, si no, por qué y cuánto cuesta reactivarlo',
    facturas: 'sus facturas pendientes y su saldo a favor',
    pagos: 'sus últimos pagos con recibo y medio',
    soportes: 'los reportes de falla que ya tiene abiertos',
    consumo: 'cuántos GB lleva este mes y por día',
    corte: 'los días de facturación, pago y corte',
    plan: 'su plan, velocidad y valor mensual',
    contrato: 'su permanencia, el costo de reconexión y su contrato firmado',
    wifi: 'la clave WiFi registrada en su instalación',
    television: 'si tiene televisión y si está activa',
    datos: 'su número de contrato y la dirección instalada',
};

function Modal({ title, description, onClose, wide = false, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 py-10" onClick={onClose}>
            <div className={`w-full rounded-xl border bg-card p-6 shadow-lg ${wide ? 'max-w-4xl' : 'max-w-2xl'}`} onClick={e => e.stopPropagation()}>
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground mt-1">{description}</p>}
                </div>
                {children}
            </div>
        </div>
    );
}

function Field({ label, value, onChange, type = 'text', required = false, placeholder = '', error, hint, maxLength, conEmojis = false }) {
    const campo = useRef(null);

    return (
        <div className="space-y-1.5">
            {label && <label className="text-sm font-medium text-foreground">{label}</label>}
            <div className="relative">
                <input
                    ref={campo}
                    type={type}
                    value={value}
                    onChange={e => onChange(e.target.value)}
                    required={required}
                    placeholder={placeholder}
                    maxLength={maxLength}
                    className={`flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${conEmojis ? 'pr-10' : ''} ${error ? 'border-destructive' : 'border-input'}`}
                />
                {conEmojis && (
                    <SelectorEmoji
                        className="absolute right-1 top-1"
                        onElegir={emoji => onChange(insertarEnCursor(campo.current, value, emoji))}
                    />
                )}
            </div>
            {hint && !error && <p className="text-[11px] text-muted-foreground">{hint}</p>}
            {error && <p className="text-xs text-destructive mt-1">{error}</p>}
        </div>
    );
}

function Select({ value, onChange, children, className = '' }) {
    return (
        <select
            value={value}
            onChange={e => onChange(e.target.value)}
            className={`flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${className}`}
        >
            {children}
        </select>
    );
}

WhatsAppMenusIndex.layout = page => <AppLayout breadcrumb={['Menús de WhatsApp']}>{page}</AppLayout>;

/**
 * El interruptor de la IA y hasta dónde llega.
 *
 * El servidor y el modelo son los mismos para toda la plataforma y se
 * configuran en el flujo. Los permisos no: consultar una factura y radicar una
 * falla a nombre del cliente no son la misma decisión, y la toma cada empresa.
 * Antes vivían en el flujo e iguales para todos, así que encender el
 * interruptor concedía las tres cosas de golpe.
 *
 * Vive en esta pantalla y no en Integraciones porque es la IA *de los menús*:
 * se enciende donde se configuran, y así se lee junto a lo que complementa.
 */
function AiSwitch({ ai }) {
    const [busy, setBusy] = useState(false);
    const encendida = ai.enabled === true;
    const permisos = ai.permissions ?? [];
    const catalogo = ai.permissionCatalog ?? [];

    function toggle() {
        setBusy(true);
        router.post(route('whatsapp-menus.ai'), { enabled: !encendida }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    }

    function togglePermiso(value) {
        const next = permisos.includes(value)
            ? permisos.filter(p => p !== value)
            : [...permisos, value];

        setBusy(true);
        router.post(route('whatsapp-menus.ai.permissions'), { permissions: next }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    }

    return (
        <div className={`rounded-xl border p-4 ${encendida ? 'border-primary/40 bg-primary/5' : 'bg-card'}`}>
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-start gap-3 min-w-0">
                    <div className={`size-10 shrink-0 rounded-xl flex items-center justify-center ${
                        encendida ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                    }`}>
                        <Bot className="size-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-sm font-semibold text-foreground">
                            {encendida ? 'La IA está atendiendo' : 'IA para los menús'}
                        </p>
                        <p className="text-xs text-muted-foreground mt-0.5 max-w-2xl">
                            {encendida
                                ? 'Cuando el cliente escribe con sus propias palabras y ningún menú lo reconoce, la IA entiende qué pide y ejecuta la opción que corresponde. Si un agente toma el chat, se calla.'
                                : 'Entiende al cliente que escribe con sus propias palabras —"no me funciona el internet desde ayer"— y ejecuta la opción del menú que corresponde, en vez de dejarlo sin respuesta.'}
                        </p>
                        <p className="text-[11px] text-muted-foreground mt-1.5">
                            Tus menús y disparadores mandan sobre ella: la IA sólo entra cuando ninguno reconoce el mensaje.
                            Las cifras y las fechas las sigue calculando el sistema, no el modelo.
                        </p>
                        {!ai.available && (
                            <p className="text-[11px] text-warning mt-1.5">
                                Falta configurar el flujo de IA en el servidor. Avisa al equipo técnico.
                            </p>
                        )}
                    </div>
                </div>

                <Button
                    variant={encendida ? 'outline' : 'default'}
                    onClick={toggle}
                    disabled={busy || (!encendida && !ai.available)}
                    className="gap-2"
                >
                    <Power className="size-4" />
                    {encendida ? 'Apagar la IA' : 'Encender la IA'}
                </Button>
            </div>

            {/* Los permisos sólo se muestran con la IA encendida: apagada, no
                hay nada que acotar y sólo serían tres casillas sin efecto. */}
            {encendida && catalogo.length > 0 && (
                <div className="mt-4 border-t pt-4">
                    <p className="text-xs font-semibold text-foreground">Hasta dónde puede llegar</p>
                    <p className="text-[11px] text-muted-foreground mt-0.5">
                        Lo que no le concedas, la IA lo pasa a un asesor en vez de hacerlo por su cuenta.
                    </p>
                    <div className="mt-2.5 grid gap-2 sm:grid-cols-3">
                        {catalogo.map(permiso => {
                            const activo = permisos.includes(permiso.value);
                            return (
                                <label
                                    key={permiso.value}
                                    className={`flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 transition-colors ${
                                        activo ? 'border-primary/40 bg-primary/10' : 'bg-card hover:bg-muted/50'
                                    } ${busy ? 'pointer-events-none opacity-60' : ''}`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={activo}
                                        disabled={busy}
                                        onChange={() => togglePermiso(permiso.value)}
                                        className="mt-0.5 size-3.5 shrink-0 accent-primary"
                                    />
                                    <span className="min-w-0">
                                        <span className="block text-[12px] font-semibold text-foreground">{permiso.label}</span>
                                        <span className="block text-[11px] leading-tight text-muted-foreground mt-0.5">
                                            {permiso.description}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}
