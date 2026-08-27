import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    Plus, Pencil, Trash2, ListTree, Power, PowerOff,
    ChevronUp, ChevronDown, X, MessageSquare, CornerDownRight, UserRound, AlertTriangle,
    FileText, CreditCard, Wifi, Wrench, CircleSlash, Smartphone, List, Construction,
    Activity, Plug, Users,
} from 'lucide-react';

const MATCH_LABELS = {
    exact: 'Coincidencia exacta',
    contains: 'Contiene',
    starts_with: 'Empieza con',
    welcome: 'Mensaje de bienvenida',
};

const MATCH_OPTIONS = [
    { value: 'welcome', label: 'Primer mensaje del cliente (bienvenida)' },
    { value: 'contains', label: 'Contiene la palabra' },
    { value: 'exact', label: 'Coincidencia exacta' },
    { value: 'starts_with', label: 'Empieza con' },
];

const KEYWORD_TYPES = ['exact', 'contains', 'starts_with'];

// La lista de tipos la manda el backend (WhatsAppMenuOption::catalog); aquí
// sólo vive lo visual, para que añadir un tipo nuevo no obligue a tocar el front.
const ACTION_ICONS = {
    reply_text: MessageSquare,
    submenu: CornerDownRight,
    handoff: UserRound,
    consultar_factura: FileText,
    pagar_en_linea: CreditCard,
    cambiar_clave: Wifi,
    reportar_falla: Wrench,
    estado_servicio: Activity,
    none: CircleSlash,
};

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

/** Ayuda de cada acción de Integra, para que el admin sepa qué va a pasar. */
const INTEGRA_HELP = {
    consultar_factura: 'Busca al cliente por su número de WhatsApp en Integra y le responde sus facturas pendientes con el total. Si no lo encuentra, le pide el documento.',
    pagar_en_linea: 'Avisa a tus sistemas por el webhook payment.requested y le entrega al cliente el enlace de pago que configures aquí.',
    reportar_falla: 'Antes de abrir el radicado revisa el estado del contrato: si está suspendido por mora se lo dice y no crea nada. Si no, le pide que describa la falla y crea el radicado en Integra.',
    estado_servicio: 'Le responde si su internet y su televisión están activos, el plan que tiene y cuánto debe.',
};

const GROUP_LABELS = {
    core: 'Acciones',
    integra: 'Autoservicio (consulta Integra)',
    pending: 'Opciones de negocio (integración pendiente)',
    none: 'Otros',
};

const GROUP_ORDER = ['core', 'integra', 'pending', 'none'];

const iconFor = value => ACTION_ICONS[value] ?? Construction;

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

export default function WhatsAppMenusIndex({ menus, instances, agents, limits, actionTypes = [], integra = {} }) {
    const { errors } = usePage().props;
    // value → { label, group, reply }: lo usan la tarjeta (para nombrar la
    // acción) y el formulario (para el aviso por defecto de cada pendiente).
    const actionMeta = Object.fromEntries(actionTypes.map(a => [a.value, a]));
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);
    const [createForm, setCreateForm] = useState(emptyForm);
    const [editForm, setEditForm] = useState(emptyForm);

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

    function openEdit(menu) {
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
                    <Button onClick={() => { setCreateForm(emptyForm()); setShowCreate(true); }} className="gap-2">
                        <Plus className="size-4" /> Nuevo menú
                    </Button>
                </div>

                {menus.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <ListTree className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes menús</p>
                        <p className="text-sm text-muted-foreground mt-1">
                            Por ejemplo: al primer mensaje del cliente, ofrecerle "Consultar factura", "Pagar en línea" o "Hablar con un asesor".
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {menus.map(menu => (
                            <MenuCard
                                key={menu.id}
                                menu={menu}
                                actionMeta={actionMeta}
                                onEdit={() => openEdit(menu)}
                                onDelete={() => handleDelete(menu)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {showCreate && (
                <Modal wide title="Nuevo menú" description="Define el mensaje, las opciones y cuándo aparece" onClose={() => setShowCreate(false)}>
                    <MenuForm
                        form={createForm} setForm={setCreateForm}
                        instances={instances} agents={agents} menus={menus} limits={limits} errors={errors}
                        actionTypes={actionTypes} actionMeta={actionMeta} integra={integra}
                        onSubmit={handleCreate} onCancel={() => setShowCreate(false)} submitLabel="Crear menú"
                    />
                </Modal>
            )}

            {editing && (
                <Modal wide title="Editar menú" description={`Modificar: ${editing.name}`} onClose={() => setEditing(null)}>
                    <MenuForm
                        form={editForm} setForm={setEditForm}
                        instances={instances} agents={agents} menus={menus} limits={limits} errors={errors}
                        actionTypes={actionTypes} actionMeta={actionMeta} integra={integra}
                        editingId={editing.id}
                        onSubmit={handleEdit} onCancel={() => setEditing(null)} submitLabel="Guardar cambios"
                    />
                </Modal>
            )}
        </>
    );
}

function MenuCard({ menu, actionMeta, onEdit, onDelete }) {
    const options = menu.options ?? [];
    const isList = menu.format === 'list';

    return (
        <div className="rounded-xl border bg-card p-5 shadow-xs flex flex-col gap-4">
            <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30">
                        {menu.active
                            ? <Power className="size-5 text-green-600 dark:text-green-400" />
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
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${menu.active ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-muted text-muted-foreground'}`}>
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
                    ) : (
                        <span className="italic">Submenú — sólo se abre desde otro menú</span>
                    )}
                </div>
                <div className="text-foreground whitespace-pre-wrap line-clamp-2">{menu.body_text}</div>
                <ul className="space-y-0.5 pt-0.5">
                    {options.map((o, i) => {
                        const meta = actionMeta?.[o.action_type];
                        return (
                            <li key={o.id} className="text-foreground/80 truncate">
                                {i + 1}. {o.title}
                                <span className="text-muted-foreground"> — {meta?.label ?? o.action_type}</span>
                                {meta?.group === 'pending' && (
                                    <span className="ml-1 rounded bg-amber-100 px-1 text-[9px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
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

function MenuForm({ form, setForm, instances, agents, menus, limits, errors, actionTypes, actionMeta, integra = {}, editingId = null, onSubmit, onCancel, submitLabel }) {
    const options = form.options ?? [];
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

    const blocked =
        options.length === 0 ||
        options.some(o => o.title.trim() === '') ||
        options.some(o => o.action_type === 'reply_text' && (o.reply_text ?? '').trim() === '') ||
        options.some(o => o.action_type === 'submenu' && o.target_menu_id === '') ||
        (form.is_root && selectedTypes.length === 0) ||
        (showTrigger && form.trigger_text.trim() === '');

    // Un menú no puede llevar a sí mismo, y ofrecer los menús raíz como destino
    // sólo invita a que el cliente entre en un circuito del que no sabe salir.
    const submenuChoices = menus.filter(m => m.id !== editingId && !m.is_root);

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
            <form onSubmit={onSubmit} className="space-y-5 min-w-0">
                <Field label="Nombre" value={form.name} onChange={v => setForm(f => ({ ...f, name: v }))}
                    required placeholder="Ej: Menú principal" error={errors?.name} />

                <div className="space-y-1.5">
                    <label className="text-sm font-medium text-foreground">Instancia</label>
                    <Select value={form.instance_id} onChange={v => setForm(f => ({ ...f, instance_id: v }))}>
                        <option value="">Todas las instancias</option>
                        {instances.map(i => <option key={i.id} value={i.id}>{i.name}</option>)}
                    </Select>
                </div>

                <div className="space-y-1.5">
                    <label className="text-sm font-medium text-foreground">¿Cuándo aparece?</label>
                    <label className="flex items-start gap-2 rounded-md border border-input p-2.5 text-sm cursor-pointer">
                        <input type="checkbox" className="mt-0.5" checked={!form.is_root}
                            onChange={e => setForm(f => ({ ...f, is_root: !e.target.checked }))} />
                        <span>
                            Es un submenú
                            <span className="block text-[11px] text-muted-foreground">
                                No se dispara solo: se abre desde una opción de otro menú.
                            </span>
                        </span>
                    </label>

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

                <Field label="Encabezado (opcional)" value={form.header_text}
                    onChange={v => setForm(f => ({ ...f, header_text: v }))}
                    placeholder="Ej: ColombiaWISP" maxLength={60} error={errors?.header_text} />

                <div className="space-y-1.5">
                    <label className="text-sm font-medium text-foreground">Mensaje</label>
                    <textarea
                        value={form.body_text}
                        onChange={e => setForm(f => ({ ...f, body_text: e.target.value }))}
                        required rows={3} maxLength={limits.max_body}
                        placeholder={'¡Hola! 👋\nSoy tu asistente virtual.\n¿En qué puedo ayudarte hoy?'}
                        className={`flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.body_text ? 'border-destructive' : 'border-input'}`}
                    />
                    <p className="text-[11px] text-muted-foreground">
                        Puedes usar {'{name}'}, {'{phone}'} y {'{wa_id}'}. {form.body_text.length}/{limits.max_body}
                    </p>
                    {errors?.body_text && <p className="text-xs text-destructive">{errors.body_text}</p>}
                </div>

                <Field label="Pie (opcional)" value={form.footer_text}
                    onChange={v => setForm(f => ({ ...f, footer_text: v }))}
                    placeholder="Ej: Atención 24/7" maxLength={60} error={errors?.footer_text} />

                <div className="space-y-2 rounded-lg border p-3">
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
                        <p className="flex items-start gap-1.5 rounded-md bg-amber-50 dark:bg-amber-900/20 px-2.5 py-2 text-[11px] text-amber-700 dark:text-amber-400">
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

                    {options.map((option, index) => (
                        <OptionRow
                            key={index}
                            index={index}
                            option={option}
                            isList={isList}
                            limits={limits}
                            agents={agents}
                            actionTypes={actionTypes}
                            actionMeta={actionMeta}
                            integra={integra}
                            catalogs={catalogs}
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

                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
                    <Button type="submit" disabled={blocked}>{submitLabel}</Button>
                </div>
            </form>

            <MenuPreview form={form} limits={limits} />
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
function useIntegraCatalogs(enabled) {
    const [state, setState] = useState({ loading: false, data: null, error: null });

    useEffect(() => {
        if (!enabled || state.data || state.error || state.loading) return;

        let cancelled = false;
        setState(s => ({ ...s, loading: true }));

        fetch(route('whatsapp-menus.integra-catalogs'), { headers: { Accept: 'application/json' } })
            .then(async res => {
                const body = await res.json().catch(() => ({}));
                if (cancelled) return;
                setState(res.ok
                    ? { loading: false, data: body, error: null }
                    : { loading: false, data: null, error: body.message ?? 'No se pudieron cargar los catálogos de Integra.' });
            })
            .catch(() => {
                if (!cancelled) {
                    setState({ loading: false, data: null, error: 'No se pudieron cargar los catálogos de Integra.' });
                }
            });

        return () => { cancelled = true; };
    }, [enabled, state.data, state.error, state.loading]);

    return state;
}

function OptionRow({ index, option, isList, limits, agents, submenuChoices, actionTypes = [], actionMeta = {}, integra = {}, catalogs = {}, errors, canMoveUp, canMoveDown, onChange, onRemove, onMove }) {
    const ActionIcon = iconFor(option.action_type);
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

    return (
        <div className="rounded-md border bg-muted/30 p-2.5 space-y-2">
            <div className="flex items-center gap-2">
                <span className="flex size-6 shrink-0 items-center justify-center rounded bg-background text-xs font-medium text-muted-foreground">
                    {index + 1}
                </span>
                <input
                    value={option.title}
                    onChange={e => onChange({ title: e.target.value })}
                    maxLength={limits.max_row_title}
                    placeholder="Título de la opción (ej: 📄 Consultar factura)"
                    className={`flex h-8 w-full rounded-md border bg-background px-2.5 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.[`options.${index}.title`] ? 'border-destructive' : 'border-input'}`}
                />
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

            {option.action_type === 'reply_text' && (
                <textarea
                    value={option.reply_text ?? ''}
                    onChange={e => onChange({ reply_text: e.target.value })}
                    rows={2} maxLength={4096}
                    placeholder="Mensaje que recibirá el cliente al elegir esta opción"
                    className={`flex w-full rounded-md border bg-background px-2.5 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.[`options.${index}.reply_text`] ? 'border-destructive' : 'border-input'}`}
                />
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
                    <p className="text-[11px] text-muted-foreground">{INTEGRA_HELP[option.action_type]}</p>

                    {!integra.connected && (
                        <p className="flex items-start gap-1.5 rounded-md bg-amber-50 dark:bg-amber-900/20 px-2.5 py-2 text-[11px] text-amber-700 dark:text-amber-400">
                            <Plug className="size-3.5 shrink-0 mt-px" />
                            Integra no está conectado. Conéctalo en Integraciones → "Pagos a facturas"; mientras
                            tanto, quien elija esta opción será derivado a un asesor.
                        </p>
                    )}

                    {needsPaymentUrl && (
                        <>
                            <input
                                value={config.payment_url ?? ''}
                                onChange={e => setConfig({ payment_url: e.target.value })}
                                maxLength={500}
                                placeholder="https://pagos.tuempresa.com/?nit={nit}&valor={total}"
                                className={`flex h-8 w-full rounded-md border bg-background px-2.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.[`options.${index}.config.payment_url`] ? 'border-destructive' : 'border-input'}`}
                            />
                            <p className="text-[10px] text-muted-foreground">
                                Enlace de pago. Variables: <code>{'{nit}'}</code> <code>{'{cliente_id}'}</code>{' '}
                                <code>{'{nombre}'}</code> <code>{'{total}'}</code> <code>{'{factura}'}</code>.
                                Déjalo vacío si el enlace lo envía tu sistema al recibir el webhook.
                            </p>
                        </>
                    )}

                    {option.action_type === 'reportar_falla' && (
                        <div className="grid grid-cols-2 gap-2">
                            <div className="space-y-1">
                                <label className="text-[10px] font-medium text-muted-foreground">Tipo de falla (servicio)</label>
                                <Select value={config.radicado_servicio ?? ''} onChange={v => setConfig({ radicado_servicio: v })}
                                    className="h-8 text-xs" disabled={!servicios.length}>
                                    <option value="">{catalogs.loading ? 'Cargando…' : 'Elige el servicio…'}</option>
                                    {servicios.map(sv => <option key={sv.id} value={sv.id}>{sv.nombre}</option>)}
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <label className="text-[10px] font-medium text-muted-foreground">Prioridad</label>
                                <Select value={config.radicado_prioridad ?? '2'} onChange={v => setConfig({ radicado_prioridad: v })}
                                    className="h-8 text-xs">
                                    {RADICADO_PRIORITIES.map(pr => <option key={pr.value} value={pr.value}>{pr.label}</option>)}
                                </Select>
                            </div>
                        </div>
                    )}

                    {option.action_type === 'reportar_falla' && catalogs.error && (
                        <p className="text-[11px] text-destructive">{catalogs.error}</p>
                    )}

                    {option.action_type === 'reportar_falla' && !config.radicado_servicio && (
                        <p className="flex items-start gap-1.5 rounded-md bg-amber-50 dark:bg-amber-900/20 px-2.5 py-2 text-[11px] text-amber-700 dark:text-amber-400">
                            <AlertTriangle className="size-3.5 shrink-0 mt-px" />
                            Sin tipo de falla no se puede crear el radicado: el cliente será derivado a un asesor.
                        </p>
                    )}

                    <textarea
                        value={option.reply_text ?? ''}
                        onChange={e => onChange({ reply_text: e.target.value })}
                        rows={2} maxLength={4096}
                        placeholder="Texto adicional al final de la respuesta (opcional). Ej: Escribe MENU para volver."
                        className="flex w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    />
                </>
            )}

            {meta?.group === 'pending' && (
                <>
                    <p className="flex items-start gap-1.5 rounded-md bg-amber-50 dark:bg-amber-900/20 px-2.5 py-2 text-[11px] text-amber-700 dark:text-amber-400">
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
function MenuPreview({ form, limits }) {
    const [listOpen, setListOpen] = useState(false);

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

            <div className="overflow-hidden rounded-[1.75rem] border-[6px] border-zinc-800 bg-zinc-800 shadow-lg">
                <div className="flex items-center gap-2 bg-[#075E54] px-3 py-2 text-white">
                    <div className="size-6 rounded-full bg-white/25" />
                    <div className="leading-tight">
                        <p className="text-[11px] font-medium">{SAMPLE.name}</p>
                        <p className="text-[9px] text-white/70">en línea</p>
                    </div>
                </div>

                <div className="min-h-[18rem] space-y-1.5 bg-[#ECE5DD] p-2.5 dark:bg-zinc-900">
                    <div className="max-w-[88%] rounded-lg rounded-tl-sm bg-white px-2.5 py-2 shadow-sm dark:bg-zinc-800">
                        {header && (
                            <p className="mb-1 text-[11px] font-semibold text-zinc-900 dark:text-zinc-100">{cut(header, limits.max_row_title)}</p>
                        )}
                        <p className={`whitespace-pre-wrap break-words text-[11px] leading-snug ${body ? 'text-zinc-800 dark:text-zinc-200' : 'italic text-zinc-400'}`}>
                            {body || 'Aquí va el mensaje del menú…'}
                        </p>
                        {footer && <p className="mt-1 text-[10px] text-zinc-500">{footer}</p>}
                        <p className="mt-0.5 text-right text-[9px] text-zinc-400">9:41</p>
                    </div>

                    {rows.length === 0 && (
                        <p className="pt-2 text-center text-[10px] italic text-zinc-500">
                            Escribe el título de las opciones para verlas aquí.
                        </p>
                    )}

                    {/* Hasta tres opciones WhatsApp las pinta como botones sueltos
                        debajo de la burbuja; de cuatro en adelante, un único botón
                        que abre el listado. */}
                    {!isList && rows.map((option, i) => (
                        <div key={i} className="max-w-[88%] rounded-lg bg-white py-1.5 text-center text-[11px] font-medium text-[#00A5F4] shadow-sm dark:bg-zinc-800">
                            {cut(option.title, limits.max_button_title)}
                        </div>
                    ))}

                    {isList && rows.length > 0 && (
                        <>
                            <button
                                type="button"
                                onClick={() => setListOpen(open => !open)}
                                className="flex w-[88%] items-center justify-center gap-1.5 rounded-lg bg-white py-1.5 text-[11px] font-medium text-[#00A5F4] shadow-sm dark:bg-zinc-800"
                            >
                                <List className="size-3" />
                                {cut(form.list_button_text || 'Ver opciones', limits.max_button_title)}
                            </button>

                            {listOpen && (
                                <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-zinc-800">
                                    <p className="border-b border-zinc-100 px-2.5 py-1.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-700">
                                        Opciones
                                    </p>
                                    {rows.map((option, i) => (
                                        <div key={i} className="flex items-start gap-2 border-b border-zinc-100 px-2.5 py-2 last:border-0 dark:border-zinc-700">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-[11px] text-zinc-800 dark:text-zinc-200">
                                                    {cut(option.title, limits.max_row_title)}
                                                </p>
                                                {(option.description ?? '').trim() !== '' && (
                                                    <p className="truncate text-[10px] text-zinc-500">
                                                        {cut(option.description, limits.max_row_description)}
                                                    </p>
                                                )}
                                            </div>
                                            <span className="mt-0.5 size-3 shrink-0 rounded-full border border-zinc-300 dark:border-zinc-600" />
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
                {isList && ' Toca "' + (form.list_button_text || 'Ver opciones') + '" para ver el listado.'}
            </p>
        </div>
    );
}

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

function Field({ label, value, onChange, type = 'text', required = false, placeholder = '', error, hint, maxLength }) {
    return (
        <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">{label}</label>
            <input
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                required={required}
                placeholder={placeholder}
                maxLength={maxLength}
                className={`flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${error ? 'border-destructive' : 'border-input'}`}
            />
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
