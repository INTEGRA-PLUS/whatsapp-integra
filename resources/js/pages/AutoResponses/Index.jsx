import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Bot, Power, PowerOff, AlertCircle } from 'lucide-react';

const MATCH_LABELS = {
    exact: 'Coincidencia exacta',
    contains: 'Contiene',
    starts_with: 'Empieza con',
    always: 'Siempre responde',
    welcome: 'Mensaje de bienvenida',
};

const TRIGGERLESS_TYPES = ['always', 'welcome'];

const emptyForm = {
    name: '',
    trigger_text: '',
    match_type: 'contains',
    response_message: '',
    instance_id: '',
    active: true,
    cooldown_minutes: 60,
};

export default function AutoResponsesIndex({ autoResponses, instances }) {
    const { errors } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);
    const [createForm, setCreateForm] = useState(emptyForm);
    const [editForm, setEditForm] = useState(emptyForm);

    function payload(form) {
        return {
            ...form,
            instance_id: form.instance_id === '' ? null : Number(form.instance_id),
            cooldown_minutes: form.cooldown_minutes === '' || form.cooldown_minutes === null
                ? 0
                : Number(form.cooldown_minutes),
        };
    }

    function handleCreate(e) {
        e.preventDefault();
        router.post(route('auto-responses.store'), payload(createForm), {
            onSuccess: () => { setShowCreate(false); setCreateForm(emptyForm); },
        });
    }

    function handleEdit(e) {
        e.preventDefault();
        router.put(route('auto-responses.update', editing.id), payload(editForm), {
            onSuccess: () => setEditing(null),
        });
    }

    function handleDelete(item) {
        if (!confirm(`¿Eliminar la respuesta "${item.name}"?`)) return;
        router.delete(route('auto-responses.destroy', item.id));
    }

    function openEdit(item) {
        setEditForm({
            name: item.name ?? '',
            trigger_text: item.trigger_text ?? '',
            match_type: item.match_type ?? 'contains',
            response_message: item.response_message ?? '',
            instance_id: item.instance_id ? String(item.instance_id) : '',
            active: !!item.active,
            cooldown_minutes: item.cooldown_minutes ?? 60,
        });
        setEditing(item);
    }

    return (
        <>
            <Head title="Respuestas Automáticas" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Respuestas Automáticas</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Cuando un cliente escriba el texto que definas, se responderá con el mensaje configurado.
                        </p>
                    </div>
                    <Button onClick={() => setShowCreate(true)} className="gap-2">
                        <Plus className="size-4" /> Nueva respuesta
                    </Button>
                </div>

                {autoResponses.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <Bot className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes respuestas automáticas</p>
                        <p className="text-sm text-muted-foreground mt-1">Por ejemplo: cuando el cliente diga "Hola", responder con un saludo.</p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {autoResponses.map(item => (
                            <div key={item.id} className="rounded-xl border bg-card p-5 shadow-xs flex flex-col gap-4">
                                <div className="flex items-start justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="flex size-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30">
                                            {item.active
                                                ? <Power className="size-5 text-green-600 dark:text-green-400" />
                                                : <PowerOff className="size-5 text-muted-foreground" />
                                            }
                                        </div>
                                        <div>
                                            <p className="font-semibold text-foreground text-sm">{item.name}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {item.instance ? `Instancia: ${item.instance.name}` : 'Todas las instancias'}
                                            </p>
                                        </div>
                                    </div>
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${item.active ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-muted text-muted-foreground'}`}>
                                        {item.active ? 'Activa' : 'Inactiva'}
                                    </span>
                                </div>

                                <div className="rounded-lg bg-muted/50 px-3 py-2 text-xs space-y-1">
                                    <div>
                                        <span className="text-muted-foreground">{MATCH_LABELS[item.match_type] ?? item.match_type}</span>
                                        {!TRIGGERLESS_TYPES.includes(item.match_type) && (
                                            <>
                                                <span className="text-muted-foreground">: </span>
                                                <span className="font-mono text-foreground">"{item.trigger_text}"</span>
                                            </>
                                        )}
                                    </div>
                                    <div className="text-foreground line-clamp-3 whitespace-pre-wrap">
                                        ↳ {item.response_message}
                                    </div>
                                </div>

                                <div className="flex items-center justify-between text-[11px] text-muted-foreground -mt-2">
                                    <span>Disparada {item.fires_count ?? 0} {(item.fires_count ?? 0) === 1 ? 'vez' : 'veces'}</span>
                                    {item.last_fired_at && (
                                        <span>Última: {new Date(item.last_fired_at).toLocaleString()}</span>
                                    )}
                                </div>

                                <div className="flex gap-2 pt-1">
                                    <Button variant="outline" size="sm" className="flex-1 gap-1.5" onClick={() => openEdit(item)}>
                                        <Pencil className="size-3.5" /> Editar
                                    </Button>
                                    <Button variant="outline" size="sm" className="gap-1.5 text-destructive hover:bg-destructive/10" onClick={() => handleDelete(item)}>
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {showCreate && (
                <Modal title="Nueva respuesta automática" description="Define el disparador y el mensaje de respuesta" onClose={() => setShowCreate(false)}>
                    <FormFields 
                        form={createForm} 
                        setForm={setCreateForm} 
                        instances={instances} 
                        autoResponses={autoResponses}
                        errors={errors}
                        onSubmit={handleCreate} 
                        onCancel={() => setShowCreate(false)} 
                        submitLabel="Crear respuesta" 
                    />
                </Modal>
            )}

            {editing && (
                <Modal title="Editar respuesta" description={`Modificar: ${editing.name}`} onClose={() => setEditing(null)}>
                    <FormFields 
                        form={editForm} 
                        setForm={setEditForm} 
                        instances={instances} 
                        autoResponses={autoResponses}
                        editingId={editing.id}
                        errors={errors}
                        onSubmit={handleEdit} 
                        onCancel={() => setEditing(null)} 
                        submitLabel="Guardar cambios" 
                    />
                </Modal>
            )}
        </>
    );
}

function FormFields({ form, setForm, instances, autoResponses, editingId = null, errors, onSubmit, onCancel, submitLabel }) {
    const currentInstanceId = form.instance_id === '' ? null : Number(form.instance_id);
    const isExclusiveTakenByOther = (type) => autoResponses.some(r =>
        r.match_type === type &&
        r.id !== editingId &&
        r.instance_id === currentInstanceId
    );
    const isAlwaysTakenByOther = isExclusiveTakenByOther('always');
    const isWelcomeTakenByOther = isExclusiveTakenByOther('welcome');
    const isCurrentTypeBlocked = (form.match_type === 'always' && isAlwaysTakenByOther) ||
        (form.match_type === 'welcome' && isWelcomeTakenByOther);

    return (
        <form onSubmit={onSubmit} className="space-y-4">
            <Field label="Nombre" value={form.name} onChange={v => setForm(f => ({ ...f, name: v }))} required placeholder="Ej: Saludo de bienvenida" error={errors?.name} />

            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Tipo de coincidencia</label>
                <select
                    value={form.match_type}
                    onChange={e => setForm(f => ({ ...f, match_type: e.target.value }))}
                    className={`flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.match_type ? 'border-destructive' : 'border-input'}`}
                >
                    <option value="contains">Contiene la palabra</option>
                    <option value="exact">Coincidencia exacta</option>
                    <option value="starts_with">Empieza con</option>
                    {!isWelcomeTakenByOther && <option value="welcome">Mensaje de bienvenida (primer contacto)</option>}
                    {!isAlwaysTakenByOther && <option value="always">Siempre responde</option>}
                </select>
                {errors?.match_type && <p className="text-xs text-destructive mt-1">{errors.match_type}</p>}

                {form.match_type === 'always' && (
                    <p className="text-[11px] text-amber-600 dark:text-amber-400 mt-2 leading-relaxed">
                        Nota: Esta respuesta se enviará automáticamente con cualquier mensaje del usuario.
                        Si no hay respuesta dentro de la primera hora, se reenviará el mensaje una única vez como recordatorio.
                    </p>
                )}

                {form.match_type === 'welcome' && (
                    <p className="text-[11px] text-amber-600 dark:text-amber-400 mt-2 leading-relaxed">
                        Nota: Esta respuesta se enviará solo al primer mensaje de un contacto nuevo en esta instancia.
                    </p>
                )}
            </div>

            {!TRIGGERLESS_TYPES.includes(form.match_type) && (
                <div className="space-y-1.5">
                    <Field label="Texto disparador" value={form.trigger_text} onChange={v => setForm(f => ({ ...f, trigger_text: v }))} required placeholder='Ej: hola, buenas, saludos' error={errors?.trigger_text} />
                    <p className="text-[11px] text-muted-foreground">
                        Puedes definir varias palabras separadas por coma. Cualquiera que coincida disparará la respuesta.
                    </p>
                </div>
            )}

            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Mensaje de respuesta</label>
                <textarea
                    value={form.response_message}
                    onChange={e => setForm(f => ({ ...f, response_message: e.target.value }))}
                    required
                    rows={4}
                    placeholder="Hola {name}, gracias por escribirnos. ¿En qué podemos ayudarte?"
                    className={`flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.response_message ? 'border-destructive' : 'border-input'}`}
                />
                <p className="text-[11px] text-muted-foreground">
                    Variables disponibles: <code className="font-mono">{'{name}'}</code>, <code className="font-mono">{'{phone}'}</code>, <code className="font-mono">{'{wa_id}'}</code>.
                </p>
                {errors?.response_message && <p className="text-xs text-destructive mt-1">{errors.response_message}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Instancia</label>
                <select
                    value={form.instance_id}
                    onChange={e => setForm(f => ({ ...f, instance_id: e.target.value }))}
                    className={`flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.instance_id ? 'border-destructive' : 'border-input'}`}
                >
                    <option value="">Todas las instancias</option>
                    {instances.map(i => (
                        <option key={i.id} value={i.id}>{i.name}</option>
                    ))}
                </select>
                {errors?.instance_id && <p className="text-xs text-destructive mt-1">{errors.instance_id}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Cooldown (minutos)</label>
                <input
                    type="number"
                    min="0"
                    max="10080"
                    value={form.cooldown_minutes}
                    onChange={e => setForm(f => ({ ...f, cooldown_minutes: e.target.value }))}
                    className={`flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${errors?.cooldown_minutes ? 'border-destructive' : 'border-input'}`}
                />
                <p className="text-[11px] text-muted-foreground mt-1">
                    Tiempo mínimo entre auto-respuestas de esta regla a la misma conversación. 0 = sin límite.
                </p>
                {errors?.cooldown_minutes && <p className="text-xs text-destructive mt-1">{errors.cooldown_minutes}</p>}
            </div>

            <label className="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    checked={form.active}
                    onChange={e => setForm(f => ({ ...f, active: e.target.checked }))}
                    className="rounded border-input size-4 accent-green-600"
                />
                <span className="text-sm text-foreground">Activa</span>
            </label>

            <div className="flex gap-2 pt-2">
                <Button type="submit" className="flex-1" disabled={isCurrentTypeBlocked}>{submitLabel}</Button>
                <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
            </div>
        </form>
    );
}

function Modal({ title, description, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-md rounded-xl border bg-card shadow-2xl p-6 max-h-[90vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground mt-1">{description}</p>}
                </div>
                {children}
            </div>
        </div>
    );
}

function Field({ label, value, onChange, type = 'text', required = false, placeholder = '', error }) {
    return (
        <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">{label}</label>
            <input
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                required={required}
                placeholder={placeholder}
                className={`flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${error ? 'border-destructive' : 'border-input'}`}
            />
            {error && <p className="text-xs text-destructive mt-1">{error}</p>}
        </div>
    );
}

AutoResponsesIndex.layout = page => <AppLayout breadcrumb={['Respuestas Automáticas']}>{page}</AppLayout>;
