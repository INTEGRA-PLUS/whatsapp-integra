import { useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    Plus, Pencil, Trash2, Wand2, Search, Info, ArrowUp, ArrowDown,
    MessageSquareText, TagIcon, TagsIcon, UserPlus, CheckCircle2, Sparkles,
} from 'lucide-react';

// Un estilo (ícono + color) por tipo de acción, para que la lista se pueda
// "leer" de un vistazo en vez de tener que abrir cada fila para saber qué hace.
const ACTION_STYLES = {
    send_message: {
        label: 'Enviar mensaje',
        icon: MessageSquareText,
        active: 'bg-sky-500/15 border-sky-500/40 text-sky-700 dark:text-sky-300',
        border: 'border-l-sky-500',
    },
    add_tag: {
        label: 'Aplicar etiqueta',
        icon: TagIcon,
        active: 'bg-teal-500/15 border-teal-500/40 text-teal-700 dark:text-teal-300',
        border: 'border-l-teal-500',
    },
    remove_tag: {
        label: 'Quitar etiqueta',
        icon: TagsIcon,
        active: 'bg-amber-500/15 border-amber-500/40 text-amber-700 dark:text-amber-300',
        border: 'border-l-amber-500',
    },
    assign: {
        label: 'Asignar agente',
        icon: UserPlus,
        active: 'bg-indigo-500/15 border-indigo-500/40 text-indigo-700 dark:text-indigo-300',
        border: 'border-l-indigo-500',
    },
    change_status: {
        label: 'Cambiar estado',
        icon: CheckCircle2,
        active: 'bg-emerald-500/15 border-emerald-500/40 text-emerald-700 dark:text-emerald-300',
        border: 'border-l-emerald-500',
    },
};

const ACTION_TYPES = Object.entries(ACTION_STYLES).map(([value, style]) => ({ value, ...style }));

function defaultActionFor(type) {
    switch (type) {
        case 'send_message': return { type, message: '' };
        case 'add_tag': return { type, tag_id: '' };
        case 'remove_tag': return { type, tag_id: '' };
        case 'assign': return { type, user_id: '' };
        case 'change_status': return { type, status: 'closed' };
        default: return { type };
    }
}

function describeAction(action, tags, companyUsers) {
    switch (action.type) {
        case 'send_message':
            return `Enviar: "${(action.message || '').slice(0, 60)}${(action.message || '').length > 60 ? '…' : ''}"`;
        case 'add_tag':
            return `Aplicar etiqueta: ${tags.find(t => String(t.id) === String(action.tag_id))?.name ?? '(sin definir)'}`;
        case 'remove_tag':
            return `Quitar etiqueta: ${tags.find(t => String(t.id) === String(action.tag_id))?.name ?? '(sin definir)'}`;
        case 'assign':
            return action.user_id
                ? `Asignar a: ${companyUsers.find(u => String(u.id) === String(action.user_id))?.name ?? '(sin definir)'}`
                : 'Dejar sin asignar';
        case 'change_status':
            return action.status === 'open' ? 'Reabrir conversación' : 'Cerrar conversación';
        default:
            return action.type;
    }
}

export default function MacrosIndex({ macros: initialMacros, tags, companyUsers }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [macros, setMacros] = useState(initialMacros ?? []);
    const [search, setSearch] = useState('');
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return macros;
        return macros.filter(m => m.name.toLowerCase().includes(q));
    }, [macros, search]);

    function upsertLocal(macro) {
        setMacros(prev => {
            const idx = prev.findIndex(m => m.id === macro.id);
            if (idx === -1) return [...prev, macro].sort((a, b) => a.name.localeCompare(b.name));
            const next = [...prev];
            next[idx] = macro;
            return next.sort((a, b) => a.name.localeCompare(b.name));
        });
    }

    function removeLocal(id) {
        setMacros(prev => prev.filter(m => m.id !== id));
    }

    async function handleDelete(macro) {
        if (!confirm(`¿Eliminar el macro "${macro.name}"?`)) return;
        try {
            await axios.delete(`/api/macros/${macro.id}`);
            removeLocal(macro.id);
        } catch (err) {
            alert(err?.response?.data?.message ?? 'No se pudo eliminar el macro.');
        }
    }

    return (
        <>
            <Head title="Macros" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <Wand2 className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-foreground">Macros</h1>
                            <p className="text-sm text-muted-foreground mt-0.5">
                                Encadena varias acciones (mensaje, etiquetas, asignación, estado) y ejecútalas con un clic desde el chat.
                            </p>
                        </div>
                    </div>
                    {can('macros.create') && (
                        <Button onClick={() => setShowCreate(true)} className="gap-2">
                            <Plus className="size-4" /> Nuevo Macro
                        </Button>
                    )}
                </div>

                {macros.length > 0 && (
                    <div className="relative max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder="Buscar macro..."
                            className="flex h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </div>
                )}

                {macros.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <Wand2 className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes macros</p>
                        <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                            Crea un macro para ejecutar varias acciones sobre una conversación con un solo clic.
                        </p>
                        {can('macros.create') && (
                            <Button onClick={() => setShowCreate(true)} className="gap-2 mt-6">
                                <Plus className="size-4" /> Crear mi primer macro
                            </Button>
                        )}
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="rounded-xl border border-dashed py-10 text-center text-sm text-muted-foreground">
                        Sin resultados para «{search}».
                    </div>
                ) : (
                    <div className="space-y-3">
                        {filtered.map(macro => (
                            <div key={macro.id} className="rounded-2xl border bg-card p-5 flex flex-col sm:flex-row gap-4 sm:items-center">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <h3 className="font-semibold text-foreground">{macro.name}</h3>
                                        {macro.active ? (
                                            <span className="inline-flex items-center rounded-md bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 ring-1 ring-inset ring-emerald-500/30 px-1.5 py-0.5 text-[10px] font-semibold">Activo</span>
                                        ) : (
                                            <span className="inline-flex items-center rounded-md bg-zinc-500/15 text-zinc-700 dark:text-zinc-300 ring-1 ring-inset ring-zinc-500/30 px-1.5 py-0.5 text-[10px] font-semibold">Pausado</span>
                                        )}
                                    </div>
                                    <ol className="mt-2 space-y-0.5 text-xs text-muted-foreground list-decimal list-inside">
                                        {macro.actions.map((action, idx) => (
                                            <li key={idx}>{describeAction(action, tags, companyUsers)}</li>
                                        ))}
                                    </ol>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {can('macros.update') && (
                                        <Button onClick={() => setEditing(macro)} variant="outline" size="sm" className="gap-1.5 rounded-lg">
                                            <Pencil className="size-3.5" />
                                            Editar
                                        </Button>
                                    )}
                                    {can('macros.delete') && (
                                        <Button onClick={() => handleDelete(macro)} variant="outline" size="sm" className="gap-1.5 rounded-lg text-rose-600 dark:text-rose-400 hover:text-rose-700">
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <div className="flex items-start gap-3 p-4 bg-muted/40 rounded-xl border text-xs text-muted-foreground max-w-3xl">
                    <Info className="size-4 text-primary shrink-0 mt-0.5" />
                    <p>
                        Las acciones se ejecutan en el orden en que aparecen. Un macro pausado no se muestra en el chat.
                    </p>
                </div>
            </div>

            {showCreate && (
                <MacroFormModal
                    title="Nuevo Macro"
                    description="Dale un nombre y define las acciones que ejecutará, en orden."
                    submitLabel="Crear Macro"
                    tags={tags}
                    companyUsers={companyUsers}
                    onClose={() => setShowCreate(false)}
                    onSaved={(macro) => { upsertLocal(macro); setShowCreate(false); }}
                />
            )}

            {editing && (
                <MacroFormModal
                    title="Editar Macro"
                    description={`Modificar "${editing.name}"`}
                    submitLabel="Guardar Cambios"
                    initial={editing}
                    tags={tags}
                    companyUsers={companyUsers}
                    onClose={() => setEditing(null)}
                    onSaved={(macro) => { upsertLocal(macro); setEditing(null); }}
                />
            )}
        </>
    );
}

function MacroFormModal({ title, description, submitLabel, initial, tags, companyUsers, onClose, onSaved }) {
    const [name, setName] = useState(initial?.name ?? '');
    const [active, setActive] = useState(initial?.active ?? true);
    const [actions, setActions] = useState(initial?.actions ?? [defaultActionFor('send_message')]);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    function addAction() {
        setActions(prev => [...prev, defaultActionFor('send_message')]);
    }

    function updateAction(idx, patch) {
        setActions(prev => prev.map((a, i) => (i === idx ? { ...a, ...patch } : a)));
    }

    function changeActionType(idx, type) {
        setActions(prev => prev.map((a, i) => (i === idx ? defaultActionFor(type) : a)));
    }

    function removeAction(idx) {
        setActions(prev => prev.filter((_, i) => i !== idx));
    }

    function moveAction(idx, delta) {
        setActions(prev => {
            const next = [...prev];
            const target = idx + delta;
            if (target < 0 || target >= next.length) return prev;
            [next[idx], next[target]] = [next[target], next[idx]];
            return next;
        });
    }

    function validate() {
        const next = {};
        if (!name.trim()) next.name = 'El nombre es obligatorio.';
        if (actions.length === 0) next.actions = 'Agrega al menos una acción.';
        actions.forEach((a, idx) => {
            if (a.type === 'send_message' && !a.message?.trim()) next.actions = `Acción ${idx + 1}: el mensaje no puede estar vacío.`;
            if ((a.type === 'add_tag' || a.type === 'remove_tag') && !a.tag_id) next.actions = `Acción ${idx + 1}: selecciona una etiqueta.`;
        });
        return next;
    }

    async function handleSubmit(e) {
        e.preventDefault();
        const localErrors = validate();
        if (Object.keys(localErrors).length) {
            setErrors(localErrors);
            return;
        }
        setErrors({});
        setSubmitting(true);
        try {
            const payload = { name: name.trim(), active, actions };
            const res = initial
                ? await axios.put(`/api/macros/${initial.id}`, payload)
                : await axios.post('/api/macros', payload);
            onSaved(res.data);
        } catch (err) {
            if (err?.response?.status === 422) {
                const apiErrors = err.response.data?.errors ?? {};
                setErrors({
                    name: apiErrors.name?.[0],
                    actions: apiErrors.actions?.[0],
                });
            } else {
                alert(err?.response?.data?.message ?? 'No se pudo guardar el macro.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-2xl rounded-xl border bg-card shadow-2xl p-6 max-h-[90vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground mt-1">{description}</p>}
                </div>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Nombre</label>
                        <input
                            type="text"
                            value={name}
                            onChange={e => setName(e.target.value)}
                            placeholder="Ej. Escalar a soporte"
                            autoFocus
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                        {errors.name && <p className="text-xs text-destructive font-medium">{errors.name}</p>}
                    </div>

                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <label className="text-sm font-medium text-foreground">Acciones (en orden)</label>
                            {actions.length > 0 && (
                                <button
                                    type="button"
                                    onClick={addAction}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    <Plus className="size-3.5" /> Agregar acción
                                </button>
                            )}
                        </div>

                        {actions.length === 0 ? (
                            <button
                                type="button"
                                onClick={addAction}
                                className="w-full rounded-xl border border-dashed py-8 flex flex-col items-center gap-2 text-muted-foreground hover:border-primary hover:text-primary transition-colors"
                            >
                                <Plus className="size-5" />
                                <span className="text-xs font-medium">Agregar la primera acción</span>
                            </button>
                        ) : (
                            <div className="space-y-2.5">
                                {actions.map((action, idx) => {
                                    const style = ACTION_STYLES[action.type];
                                    return (
                                        <div key={idx} className={clsx('rounded-lg border-l-4 border bg-muted/20 p-2.5 space-y-2.5', style.border)}>
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-xs font-bold text-muted-foreground">Paso {idx + 1}</span>
                                                <div className="flex items-center gap-0.5">
                                                    <button type="button" onClick={() => moveAction(idx, -1)} disabled={idx === 0} className="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Mover arriba">
                                                        <ArrowUp className="size-3.5" />
                                                    </button>
                                                    <button type="button" onClick={() => moveAction(idx, 1)} disabled={idx === actions.length - 1} className="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Mover abajo">
                                                        <ArrowDown className="size-3.5" />
                                                    </button>
                                                    <button type="button" onClick={() => removeAction(idx)} className="p-1 text-muted-foreground hover:text-destructive" title="Quitar acción">
                                                        <Trash2 className="size-3.5" />
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="flex flex-wrap gap-1.5">
                                                {ACTION_TYPES.map(t => {
                                                    const Icon = t.icon;
                                                    const isActive = action.type === t.value;
                                                    return (
                                                        <button
                                                            type="button"
                                                            key={t.value}
                                                            onClick={() => changeActionType(idx, t.value)}
                                                            className={clsx(
                                                                'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[11px] font-semibold transition-colors',
                                                                isActive ? t.active : 'border-border/60 bg-background text-muted-foreground hover:border-border'
                                                            )}
                                                        >
                                                            <Icon className="size-3.5" />
                                                            {t.label}
                                                        </button>
                                                    );
                                                })}
                                            </div>

                                            {action.type === 'send_message' && (
                                                <textarea
                                                    value={action.message}
                                                    onChange={e => updateAction(idx, { message: e.target.value })}
                                                    placeholder="Mensaje que se enviará a la conversación..."
                                                    rows={2}
                                                    maxLength={4096}
                                                    className="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring/50 resize-y"
                                                />
                                            )}

                                            {(action.type === 'add_tag' || action.type === 'remove_tag') && (
                                                <select
                                                    value={action.tag_id}
                                                    onChange={e => updateAction(idx, { tag_id: e.target.value })}
                                                    className="w-full h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                                                >
                                                    <option value="">Selecciona una etiqueta...</option>
                                                    {tags.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                                                </select>
                                            )}

                                            {action.type === 'assign' && (
                                                <select
                                                    value={action.user_id}
                                                    onChange={e => updateAction(idx, { user_id: e.target.value })}
                                                    className="w-full h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                                                >
                                                    <option value="">Sin asignar</option>
                                                    {companyUsers.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                                </select>
                                            )}

                                            {action.type === 'change_status' && (
                                                <select
                                                    value={action.status}
                                                    onChange={e => updateAction(idx, { status: e.target.value })}
                                                    className="w-full h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                                                >
                                                    <option value="closed">Cerrar conversación</option>
                                                    <option value="open">Reabrir conversación</option>
                                                </select>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        {errors.actions && <p className="text-xs text-destructive font-medium">{errors.actions}</p>}
                    </div>

                    {actions.length > 0 && (
                        <div className="rounded-lg border border-dashed bg-muted/30 p-3">
                            <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground mb-1.5">
                                <Sparkles className="size-3.5" /> Este macro hará, en orden
                            </p>
                            <ol className="space-y-1 text-xs text-foreground list-decimal list-inside marker:text-muted-foreground marker:font-semibold">
                                {actions.map((a, idx) => <li key={idx}>{describeAction(a, tags, companyUsers)}</li>)}
                            </ol>
                        </div>
                    )}

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={active}
                            onChange={e => setActive(e.target.checked)}
                            className="size-4 rounded border-input"
                        />
                        <span className="text-foreground">Activo (visible en el chat)</span>
                    </label>

                    <div className="flex gap-2 pt-2">
                        <Button type="submit" className="flex-1" disabled={submitting}>{submitLabel}</Button>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancelar</Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

MacrosIndex.layout = page => <AppLayout breadcrumb={['Macros']}>{page}</AppLayout>;
