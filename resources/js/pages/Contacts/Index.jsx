import { useMemo, useState, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Contact as ContactIcon, Search, Phone, Mail, MessageSquare, Info, UserPlus, Loader2, Check } from 'lucide-react';

export default function ContactsIndex({ contacts: initialContacts, unregistered: initialUnregistered }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [tab, setTab] = useState('registered');
    const [contacts, setContacts] = useState(initialContacts ?? []);
    const [unregistered, setUnregistered] = useState(initialUnregistered ?? []);
    const [search, setSearch] = useState('');
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);
    const [registering, setRegistering] = useState(null);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return contacts;
        return contacts.filter(c =>
            (c.name ?? '').toLowerCase().includes(q) ||
            (c.phone_number ?? '').toLowerCase().includes(q) ||
            (c.email ?? '').toLowerCase().includes(q)
        );
    }, [contacts, search]);

    const filteredUnreg = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return unregistered;
        return unregistered.filter(c =>
            (c.name ?? '').toLowerCase().includes(q) ||
            (c.phone_number ?? '').toLowerCase().includes(q)
        );
    }, [unregistered, search]);

    function upsertLocal(contact) {
        setContacts(prev => {
            const idx = prev.findIndex(c => c.id === contact.id);
            if (idx === -1) return [...prev, contact].sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
            const next = [...prev];
            next[idx] = { ...next[idx], ...contact };
            return next.sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
        });
    }

    function removeLocal(id) {
        setContacts(prev => prev.filter(c => c.id !== id));
    }

    async function handleDelete(contact) {
        if (!confirm(`¿Eliminar el contacto "${contact.name}"?`)) return;
        try {
            await axios.delete(`/api/contacts/${contact.id}`);
            removeLocal(contact.id);
        } catch (err) {
            alert(err?.response?.data?.message ?? 'No se pudo eliminar el contacto.');
        }
    }

    const [savingIds, setSavingIds] = useState(() => new Set());

    // After registering / linking an unregistered chat number to a contact.
    function handleRegistered(contact, conversationPhone) {
        upsertLocal(contact);
        setUnregistered(prev => prev.filter(u => u.phone_number !== conversationPhone));
        setRegistering(null);
    }

    // One-click save: create a contact straight from the chat's name + number.
    async function quickSave(item) {
        setSavingIds(prev => new Set(prev).add(item.id));
        try {
            const res = await axios.post(`/api/chat/conversations/${item.id}/attach-contact`, {
                name: item.name || item.phone_number,
                phone_number: item.phone_number,
            });
            handleRegistered(res.data.contact, item.phone_number);
        } catch (err) {
            alert(err?.response?.data?.message ?? 'No se pudo registrar el número.');
        } finally {
            setSavingIds(prev => {
                const next = new Set(prev);
                next.delete(item.id);
                return next;
            });
        }
    }

    return (
        <>
            <Head title="Contactos" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <ContactIcon className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-foreground">Contactos</h1>
                            <p className="text-sm text-muted-foreground mt-0.5">
                                Tu agenda de clientes. Asocia los números que escriben a un contacto para identificarlos.
                            </p>
                        </div>
                    </div>
                    {can('contacts.create') && tab === 'registered' && (
                        <Button onClick={() => setShowCreate(true)} className="gap-2">
                            <Plus className="size-4" /> Nuevo Contacto
                        </Button>
                    )}
                </div>

                {/* Tabs */}
                <div className="flex items-center gap-1 border-b border-border">
                    <button
                        onClick={() => setTab('registered')}
                        className={tabClass(tab === 'registered')}
                    >
                        Registrados
                        <span className="ml-2 inline-flex items-center justify-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-bold text-muted-foreground">{contacts.length}</span>
                    </button>
                    <button
                        onClick={() => setTab('unregistered')}
                        className={tabClass(tab === 'unregistered')}
                    >
                        Sin registrar
                        <span className={`ml-2 inline-flex items-center justify-center rounded-full px-2 py-0.5 text-[11px] font-bold ${unregistered.length > 0 ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400' : 'bg-muted text-muted-foreground'}`}>{unregistered.length}</span>
                    </button>
                </div>

                {((tab === 'registered' && contacts.length > 0) || (tab === 'unregistered' && unregistered.length > 0)) && (
                    <div className="relative max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder={tab === 'registered' ? "Buscar por nombre, teléfono o correo..." : "Buscar número o nombre del chat..."}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </div>
                )}

                {tab === 'registered' ? (
                    <RegisteredTab
                        contacts={contacts}
                        filtered={filtered}
                        search={search}
                        can={can}
                        onCreate={() => setShowCreate(true)}
                        onEdit={setEditing}
                        onDelete={handleDelete}
                    />
                ) : (
                    <UnregisteredTab
                        unregistered={unregistered}
                        filtered={filteredUnreg}
                        search={search}
                        can={can}
                        onRegister={setRegistering}
                        onQuickSave={quickSave}
                        savingIds={savingIds}
                    />
                )}
            </div>

            {showCreate && (
                <ContactFormModal
                    title="Nuevo Contacto"
                    description="Guarda los datos de tu cliente."
                    submitLabel="Crear Contacto"
                    onClose={() => setShowCreate(false)}
                    onSaved={(contact) => { upsertLocal(contact); setShowCreate(false); }}
                />
            )}

            {editing && (
                <ContactFormModal
                    title="Editar Contacto"
                    description={`Modificar a ${editing.name}`}
                    submitLabel="Guardar Cambios"
                    initial={editing}
                    onClose={() => setEditing(null)}
                    onSaved={(contact) => { upsertLocal(contact); setEditing(null); }}
                />
            )}

            {registering && (
                <RegisterContactModal
                    item={registering}
                    onClose={() => setRegistering(null)}
                    onDone={(contact) => handleRegistered(contact, registering.phone_number)}
                />
            )}
        </>
    );
}

function tabClass(active) {
    return `relative px-4 py-2.5 text-sm font-semibold transition-colors -mb-px border-b-2 ${active ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'}`;
}

function RegisteredTab({ contacts, filtered, search, can, onCreate, onEdit, onDelete }) {
    if (contacts.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                <ContactIcon className="size-12 text-muted-foreground/40 mb-4" />
                <p className="text-lg font-medium text-foreground">Aún no tienes contactos</p>
                <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                    Crea contactos para guardar la información de tus clientes y asociarlos a sus conversaciones.
                </p>
                {can('contacts.create') && (
                    <Button onClick={onCreate} className="gap-2 mt-6">
                        <Plus className="size-4" /> Crear mi primer contacto
                    </Button>
                )}
            </div>
        );
    }
    if (filtered.length === 0) {
        return <div className="rounded-xl border border-dashed py-10 text-center text-sm text-muted-foreground">Sin resultados para «{search}».</div>;
    }
    return (
        <div className="rounded-xl border bg-card overflow-hidden">
            <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                        <th className="text-left font-semibold px-4 py-3">Nombre</th>
                        <th className="text-left font-semibold px-4 py-3">Teléfono</th>
                        <th className="text-left font-semibold px-4 py-3">Correo</th>
                        <th className="text-center font-semibold px-4 py-3 w-32">Conversaciones</th>
                        <th className="text-right font-semibold px-4 py-3 w-32">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    {filtered.map(contact => (
                        <tr key={contact.id} className="border-t hover:bg-muted/30 transition-colors">
                            <td className="px-4 py-3 align-top font-medium text-foreground">{contact.name}</td>
                            <td className="px-4 py-3 align-top text-muted-foreground">
                                <div className="flex flex-col gap-1">
                                    <span className="inline-flex items-center gap-1.5"><Phone className="size-3.5 text-muted-foreground/60" /> {contact.phone_number}</span>
                                    {contact.phone_numbers?.length > 0 && (
                                        <div className="flex flex-wrap gap-1 pl-5">
                                            {contact.phone_numbers.map((n, i) => (
                                                <span key={i} className="inline-flex items-center rounded bg-muted px-1.5 py-0.5 text-[11px] font-mono text-muted-foreground">{n}</span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </td>
                            <td className="px-4 py-3 align-top text-muted-foreground">
                                {contact.email ? (
                                    <span className="inline-flex items-center gap-1.5"><Mail className="size-3.5 text-muted-foreground/60" /> {contact.email}</span>
                                ) : <span className="text-muted-foreground/40">—</span>}
                            </td>
                            <td className="px-4 py-3 align-top text-center">
                                <span className="inline-flex items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">
                                    <MessageSquare className="size-3.5" /> {contact.conversations_count ?? 0}
                                </span>
                            </td>
                            <td className="px-4 py-3 align-top">
                                <div className="flex justify-end gap-1">
                                    {can('contacts.update') && (
                                        <Button variant="ghost" size="icon" onClick={() => onEdit(contact)}><Pencil className="size-4" /></Button>
                                    )}
                                    {can('contacts.delete') && (
                                        <Button variant="ghost" size="icon" className="text-destructive hover:bg-destructive/10" onClick={() => onDelete(contact)}><Trash2 className="size-4" /></Button>
                                    )}
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function UnregisteredTab({ unregistered, filtered, search, can, onRegister, onQuickSave, savingIds }) {
    const PAGE = 100;
    const [visible, setVisible] = useState(PAGE);
    useEffect(() => { setVisible(PAGE); }, [search]);
    const shown = filtered.slice(0, visible);

    if (unregistered.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                <Check className="size-12 text-emerald-500/50 mb-4" />
                <p className="text-lg font-medium text-foreground">¡Todo registrado!</p>
                <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                    Todos los números que han escrito en el chat ya están asociados a un contacto.
                </p>
            </div>
        );
    }
    if (filtered.length === 0) {
        return <div className="rounded-xl border border-dashed py-10 text-center text-sm text-muted-foreground">Sin resultados para «{search}».</div>;
    }
    return (
        <>
            <div className="flex items-start gap-3 p-4 bg-amber-500/5 border border-amber-500/20 rounded-xl text-xs text-muted-foreground max-w-3xl">
                <Info className="size-4 text-amber-600 shrink-0 mt-0.5" />
                <p>Estos números han escrito en el chat pero aún no están guardados como contacto. Regístralos para identificarlos en futuras conversaciones.</p>
            </div>
            <div className="rounded-xl border bg-card overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                        <tr>
                            <th className="text-left font-semibold px-4 py-3">Nombre en el chat</th>
                            <th className="text-left font-semibold px-4 py-3">Teléfono</th>
                            <th className="text-left font-semibold px-4 py-3">Último mensaje</th>
                            <th className="text-right font-semibold px-4 py-3 w-40">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map(item => {
                            const saving = savingIds.has(item.id);
                            return (
                                <tr key={item.id} className="border-t hover:bg-muted/30 transition-colors">
                                    <td className="px-4 py-3 align-top font-medium text-foreground">{item.name || <span className="text-muted-foreground/50 italic">Sin nombre</span>}</td>
                                    <td className="px-4 py-3 align-top text-muted-foreground">
                                        <span className="inline-flex items-center gap-1.5"><Phone className="size-3.5 text-muted-foreground/60" /> {item.phone_number}</span>
                                    </td>
                                    <td className="px-4 py-3 align-top text-muted-foreground max-w-xs truncate">{item.last_message || '—'}</td>
                                    <td className="px-4 py-3 align-top">
                                        <div className="flex justify-end gap-1.5">
                                            {can('contacts.create') ? (
                                                <>
                                                    <Button size="sm" className="gap-1.5" disabled={saving} onClick={() => onQuickSave(item)} title="Guardar como contacto nuevo (nombre y número del chat)">
                                                        {saving ? <Loader2 className="size-3.5 animate-spin" /> : <Check className="size-3.5" />} Guardar
                                                    </Button>
                                                    <Button size="sm" variant="outline" disabled={saving} onClick={() => onRegister(item)} title="Vincular a un contacto existente o editar antes de guardar">
                                                        <UserPlus className="size-3.5" />
                                                    </Button>
                                                </>
                                            ) : <span className="text-xs text-muted-foreground/50">—</span>}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                {filtered.length > visible && (
                    <div className="flex items-center justify-center gap-3 border-t py-4 text-sm">
                        <span className="text-muted-foreground">Mostrando {visible} de {filtered.length}</span>
                        <Button variant="outline" size="sm" onClick={() => setVisible(v => v + PAGE)}>Mostrar más</Button>
                    </div>
                )}
            </div>
        </>
    );
}

function ContactFormModal({ title, description, submitLabel, initial, onClose, onSaved }) {
    const [name, setName] = useState(initial?.name ?? '');
    const [phone, setPhone] = useState(initial?.phone_number ?? '');
    const [extraNumbers, setExtraNumbers] = useState(initial?.phone_numbers ?? []);
    const [email, setEmail] = useState(initial?.email ?? '');
    const [notes, setNotes] = useState(initial?.notes ?? '');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    const setExtra = (i, val) => setExtraNumbers(prev => prev.map((n, idx) => idx === i ? val : n));
    const addExtra = () => setExtraNumbers(prev => [...prev, '']);
    const removeExtra = (i) => setExtraNumbers(prev => prev.filter((_, idx) => idx !== i));

    function validate() {
        const next = {};
        if (!name.trim()) next.name = 'El nombre es obligatorio.';
        if (!phone.trim()) next.phone_number = 'El teléfono es obligatorio.';
        if (email.trim() && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) next.email = 'Correo inválido.';
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
            const payload = {
                name: name.trim(),
                phone_number: phone.trim(),
                phone_numbers: extraNumbers.map(n => n.trim()).filter(Boolean),
                email: email.trim() || null,
                notes: notes.trim() || null,
            };
            const res = initial
                ? await axios.put(`/api/contacts/${initial.id}`, payload)
                : await axios.post('/api/contacts', payload);
            onSaved(res.data);
        } catch (err) {
            if (err?.response?.status === 422) {
                const apiErrors = err.response.data?.errors ?? {};
                setErrors({
                    name: apiErrors.name?.[0],
                    phone_number: apiErrors.phone_number?.[0],
                    email: apiErrors.email?.[0],
                });
            } else {
                alert(err?.response?.data?.message ?? 'No se pudo guardar el contacto.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-xl border bg-card shadow-2xl p-6" onClick={e => e.stopPropagation()}>
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground mt-1">{description}</p>}
                </div>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Nombre</label>
                        <input type="text" value={name} onChange={e => setName(e.target.value)} placeholder="Juan Pérez" autoFocus className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                        {errors.name && <p className="text-xs text-destructive font-medium">{errors.name}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Teléfono principal</label>
                        <input type="text" value={phone} onChange={e => setPhone(e.target.value)} placeholder="573001234567" className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm font-mono placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                        {errors.phone_number && <p className="text-xs text-destructive font-medium">{errors.phone_number}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between">
                            <label className="text-sm font-medium text-foreground">Números adicionales <span className="text-muted-foreground font-normal">(opcional)</span></label>
                            <button type="button" onClick={addExtra} className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                                <Plus className="size-3.5" /> Agregar número
                            </button>
                        </div>
                        {extraNumbers.length === 0 ? (
                            <p className="text-xs text-muted-foreground">Un mismo contacto puede agrupar varios números de WhatsApp.</p>
                        ) : (
                            <div className="space-y-2">
                                {extraNumbers.map((n, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <input type="text" value={n} onChange={e => setExtra(i, e.target.value)} placeholder="573009876543" className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm font-mono placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                                        <Button type="button" variant="ghost" size="icon" className="text-destructive hover:bg-destructive/10 shrink-0" onClick={() => removeExtra(i)}>
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Correo <span className="text-muted-foreground font-normal">(opcional)</span></label>
                        <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="juan@ejemplo.com" className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                        {errors.email && <p className="text-xs text-destructive font-medium">{errors.email}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Notas <span className="text-muted-foreground font-normal">(opcional)</span></label>
                        <textarea value={notes} onChange={e => setNotes(e.target.value)} placeholder="Información adicional sobre el contacto..." rows={3} className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 resize-y" />
                    </div>
                    <div className="flex gap-2 pt-2">
                        <Button type="submit" className="flex-1" disabled={submitting}>{submitLabel}</Button>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancelar</Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// Modal to register an unregistered chat number: create a new contact or link
// it to an existing one. Both paths use the conversation's attach-contact endpoint.
function RegisterContactModal({ item, onClose, onDone }) {
    const [mode, setMode] = useState('new');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    // New contact form
    const [name, setName] = useState(item.name || '');
    const [phone, setPhone] = useState(item.phone_number || '');
    const [email, setEmail] = useState('');

    // Existing-contact search
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (mode !== 'existing') return;
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
    }, [query, mode]);

    async function attach(payload) {
        setSubmitting(true);
        setError(null);
        try {
            const res = await axios.post(`/api/chat/conversations/${item.id}/attach-contact`, payload);
            onDone(res.data.contact);
        } catch (err) {
            setError(err?.response?.data?.message || 'No se pudo registrar el contacto.');
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-xl border bg-card shadow-2xl overflow-hidden" onClick={e => e.stopPropagation()}>
                <div className="p-6 pb-4 border-b">
                    <h2 className="text-lg font-semibold text-foreground">Registrar número</h2>
                    <p className="text-sm text-muted-foreground mt-1">
                        <span className="font-mono">{item.phone_number}</span>{item.name ? ` · ${item.name}` : ''}
                    </p>
                    <div className="flex items-center gap-1 mt-4">
                        <button onClick={() => setMode('new')} className={`flex-1 px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition-colors ${mode === 'new' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}`}>Crear nuevo</button>
                        <button onClick={() => setMode('existing')} className={`flex-1 px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wide transition-colors ${mode === 'existing' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}`}>Vincular a existente</button>
                    </div>
                </div>

                <div className="p-6">
                    {error && <p className="mb-3 text-xs font-medium text-destructive">{error}</p>}

                    {mode === 'new' ? (
                        <form onSubmit={(e) => { e.preventDefault(); attach({ name: name.trim(), phone_number: phone.trim(), email: email.trim() || null }); }} className="space-y-4">
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-foreground">Nombre</label>
                                <input type="text" value={name} onChange={e => setName(e.target.value)} required placeholder="Nombre del contacto" autoFocus className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                            </div>
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-foreground">Teléfono</label>
                                <input type="text" value={phone} onChange={e => setPhone(e.target.value)} required className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm font-mono placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                            </div>
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-foreground">Correo <span className="text-muted-foreground font-normal">(opcional)</span></label>
                                <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="correo@ejemplo.com" className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                            </div>
                            <div className="flex gap-2 pt-2">
                                <Button type="submit" className="flex-1 gap-2" disabled={submitting}>
                                    {submitting ? <Loader2 className="size-4 animate-spin" /> : <UserPlus className="size-4" />} Crear y registrar
                                </Button>
                                <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancelar</Button>
                            </div>
                        </form>
                    ) : (
                        <>
                            <p className="mb-3 text-xs text-muted-foreground bg-muted/50 rounded-lg px-3 py-2">
                                El número <span className="font-mono font-semibold text-foreground">{item.phone_number}</span> se agregará al contacto que elijas, quedando ambos números en una sola ficha.
                            </p>
                            <div className="relative mb-3">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                <input type="text" value={query} onChange={e => setQuery(e.target.value)} placeholder="Buscar contacto existente..." autoFocus className="flex h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 py-1 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50" />
                            </div>
                            <div className="max-h-64 overflow-y-auto -mx-1 px-1">
                                {loading ? (
                                    <div className="flex items-center justify-center py-8 text-muted-foreground"><Loader2 className="size-5 animate-spin" /></div>
                                ) : results.length === 0 ? (
                                    <p className="text-center text-sm text-muted-foreground py-8">No hay contactos. Crea uno en la pestaña «Crear nuevo».</p>
                                ) : (
                                    <div className="space-y-1">
                                        {results.map(c => (
                                            <button key={c.id} disabled={submitting} onClick={() => attach({ contact_id: c.id })} className="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left hover:bg-muted transition-colors disabled:opacity-50">
                                                <div className="size-9 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold uppercase shrink-0">{(c.name ?? '?').slice(0, 2)}</div>
                                                <div className="min-w-0">
                                                    <p className="text-sm font-semibold text-foreground truncate">{c.name}</p>
                                                    <p className="text-xs text-muted-foreground truncate">{c.phone_number}</p>
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="flex justify-end pt-4">
                                <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancelar</Button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

ContactsIndex.layout = page => <AppLayout breadcrumb={['Contactos']}>{page}</AppLayout>;
