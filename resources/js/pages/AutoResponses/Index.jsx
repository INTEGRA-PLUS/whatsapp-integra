import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Bot, Power, PowerOff } from 'lucide-react';

const MATCH_LABELS = {
    exact: 'Coincidencia exacta',
    contains: 'Contiene',
    starts_with: 'Empieza con',
};

const emptyForm = {
    name: '',
    trigger_text: '',
    match_type: 'contains',
    response_message: '',
    instance_id: '',
    active: true,
};

export default function AutoResponsesIndex({ autoResponses, instances }) {
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);
    const [createForm, setCreateForm] = useState(emptyForm);
    const [editForm, setEditForm] = useState(emptyForm);

    function payload(form) {
        return {
            ...form,
            instance_id: form.instance_id === '' ? null : Number(form.instance_id),
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
                                        <span className="text-muted-foreground">{MATCH_LABELS[item.match_type] ?? item.match_type}: </span>
                                        <span className="font-mono text-foreground">"{item.trigger_text}"</span>
                                    </div>
                                    <div className="text-foreground line-clamp-3 whitespace-pre-wrap">
                                        ↳ {item.response_message}
                                    </div>
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
                    <FormFields form={createForm} setForm={setCreateForm} instances={instances} onSubmit={handleCreate} onCancel={() => setShowCreate(false)} submitLabel="Crear respuesta" />
                </Modal>
            )}

            {editing && (
                <Modal title="Editar respuesta" description={`Modificar: ${editing.name}`} onClose={() => setEditing(null)}>
                    <FormFields form={editForm} setForm={setEditForm} instances={instances} onSubmit={handleEdit} onCancel={() => setEditing(null)} submitLabel="Guardar cambios" />
                </Modal>
            )}
        </>
    );
}

function FormFields({ form, setForm, instances, onSubmit, onCancel, submitLabel }) {
    return (
        <form onSubmit={onSubmit} className="space-y-4">
            <Field label="Nombre" value={form.name} onChange={v => setForm(f => ({ ...f, name: v }))} required placeholder="Ej: Saludo de bienvenida" />
            <Field label="Texto disparador" value={form.trigger_text} onChange={v => setForm(f => ({ ...f, trigger_text: v }))} required placeholder='Ej: "Hola"' />

            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Tipo de coincidencia</label>
                <select
                    value={form.match_type}
                    onChange={e => setForm(f => ({ ...f, match_type: e.target.value }))}
                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                >
                    <option value="contains">Contiene la palabra</option>
                    <option value="exact">Coincidencia exacta</option>
                    <option value="starts_with">Empieza con</option>
                </select>
            </div>

            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Mensaje de respuesta</label>
                <textarea
                    value={form.response_message}
                    onChange={e => setForm(f => ({ ...f, response_message: e.target.value }))}
                    required
                    rows={4}
                    placeholder="Hola, gracias por escribirnos. ¿En qué podemos ayudarte?"
                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                />
            </div>

            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Instancia</label>
                <select
                    value={form.instance_id}
                    onChange={e => setForm(f => ({ ...f, instance_id: e.target.value }))}
                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                >
                    <option value="">Todas las instancias</option>
                    {instances.map(i => (
                        <option key={i.id} value={i.id}>{i.name}</option>
                    ))}
                </select>
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
                <Button type="submit" className="flex-1">{submitLabel}</Button>
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

function Field({ label, value, onChange, type = 'text', required = false, placeholder = '' }) {
    return (
        <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">{label}</label>
            <input
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                required={required}
                placeholder={placeholder}
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
            />
        </div>
    );
}

AutoResponsesIndex.layout = page => <AppLayout breadcrumb={['Respuestas Automáticas']}>{page}</AppLayout>;
