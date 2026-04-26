import { useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Zap, Search, MessageSquareText, Info } from 'lucide-react';

const SHORTCUT_PATTERN = /^[a-zA-Z0-9_-]+$/;

export default function QuickRepliesIndex({ replies: initialReplies }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [replies, setReplies] = useState(initialReplies ?? []);
    const [search, setSearch] = useState('');
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return replies;
        return replies.filter(r =>
            r.shortcut.toLowerCase().includes(q) ||
            r.message.toLowerCase().includes(q)
        );
    }, [replies, search]);

    function upsertLocal(reply) {
        setReplies(prev => {
            const idx = prev.findIndex(r => r.id === reply.id);
            if (idx === -1) return [...prev, reply].sort((a, b) => a.shortcut.localeCompare(b.shortcut));
            const next = [...prev];
            next[idx] = reply;
            return next.sort((a, b) => a.shortcut.localeCompare(b.shortcut));
        });
    }

    function removeLocal(id) {
        setReplies(prev => prev.filter(r => r.id !== id));
    }

    async function handleDelete(reply) {
        if (!confirm(`¿Eliminar la respuesta rápida "/${reply.shortcut}"?`)) return;
        try {
            await axios.delete(`/api/quick-replies/${reply.id}`);
            removeLocal(reply.id);
        } catch (err) {
            alert(err?.response?.data?.message ?? 'No se pudo eliminar la respuesta.');
        }
    }

    return (
        <>
            <Head title="Respuestas Rápidas" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <Zap className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-foreground">Respuestas Rápidas</h1>
                            <p className="text-sm text-muted-foreground mt-0.5">
                                Guarda mensajes frecuentes y úsalos en el chat escribiendo <code className="rounded bg-muted px-1.5 py-0.5 text-xs font-mono">/atajo</code>.
                            </p>
                        </div>
                    </div>
                    {can('quick_replies.create') && (
                        <Button onClick={() => setShowCreate(true)} className="gap-2">
                            <Plus className="size-4" /> Nueva Respuesta
                        </Button>
                    )}
                </div>

                {replies.length > 0 && (
                    <div className="relative max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder="Buscar por atajo o contenido..."
                            className="flex h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </div>
                )}

                {replies.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <MessageSquareText className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes respuestas rápidas</p>
                        <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                            Crea atajos para los mensajes que envías con más frecuencia y agiliza tus conversaciones.
                        </p>
                        {can('quick_replies.create') && (
                            <Button onClick={() => setShowCreate(true)} className="gap-2 mt-6">
                                <Plus className="size-4" /> Crear mi primera respuesta
                            </Button>
                        )}
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="rounded-xl border border-dashed py-10 text-center text-sm text-muted-foreground">
                        Sin resultados para «{search}».
                    </div>
                ) : (
                    <div className="rounded-xl border bg-card overflow-hidden">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                                <tr>
                                    <th className="text-left font-semibold px-4 py-3 w-48">Atajo</th>
                                    <th className="text-left font-semibold px-4 py-3">Mensaje</th>
                                    <th className="text-right font-semibold px-4 py-3 w-32">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map(reply => (
                                    <tr key={reply.id} className="border-t hover:bg-muted/30 transition-colors">
                                        <td className="px-4 py-3 align-top">
                                            <span className="inline-flex items-center rounded-md bg-primary/10 text-primary px-2 py-1 text-xs font-mono font-bold">
                                                /{reply.shortcut}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 align-top text-foreground whitespace-pre-wrap break-words max-w-xl">
                                            {reply.message}
                                        </td>
                                        <td className="px-4 py-3 align-top">
                                            <div className="flex justify-end gap-1">
                                                {can('quick_replies.update') && (
                                                    <Button variant="ghost" size="icon" onClick={() => setEditing(reply)}>
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                )}
                                                {can('quick_replies.delete') && (
                                                    <Button variant="ghost" size="icon" className="text-destructive hover:bg-destructive/10" onClick={() => handleDelete(reply)}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="flex items-start gap-3 p-4 bg-muted/40 rounded-xl border text-xs text-muted-foreground max-w-3xl">
                    <Info className="size-4 text-primary shrink-0 mt-0.5" />
                    <p>
                        Los atajos sólo pueden contener letras, números, guiones y guiones bajos (sin espacios).
                        Cada atajo es único dentro de tu empresa.
                    </p>
                </div>
            </div>

            {showCreate && (
                <ReplyFormModal
                    title="Nueva Respuesta Rápida"
                    description="Define un atajo y el mensaje que se enviará."
                    submitLabel="Crear Respuesta"
                    onClose={() => setShowCreate(false)}
                    onSaved={(reply) => { upsertLocal(reply); setShowCreate(false); }}
                />
            )}

            {editing && (
                <ReplyFormModal
                    title="Editar Respuesta Rápida"
                    description={`Modificar atajo /${editing.shortcut}`}
                    submitLabel="Guardar Cambios"
                    initial={editing}
                    onClose={() => setEditing(null)}
                    onSaved={(reply) => { upsertLocal(reply); setEditing(null); }}
                />
            )}
        </>
    );
}

function ReplyFormModal({ title, description, submitLabel, initial, onClose, onSaved }) {
    const [shortcut, setShortcut] = useState(initial?.shortcut ?? '');
    const [message, setMessage] = useState(initial?.message ?? '');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    function validate() {
        const next = {};
        if (!shortcut.trim()) next.shortcut = 'El atajo es obligatorio.';
        else if (!SHORTCUT_PATTERN.test(shortcut)) next.shortcut = 'Sólo letras, números, guiones y guiones bajos.';
        else if (shortcut.length > 50) next.shortcut = 'Máximo 50 caracteres.';
        if (!message.trim()) next.message = 'El mensaje es obligatorio.';
        else if (message.length > 4000) next.message = 'Máximo 4000 caracteres.';
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
            const payload = { shortcut: shortcut.trim(), message };
            const res = initial
                ? await axios.put(`/api/quick-replies/${initial.id}`, payload)
                : await axios.post('/api/quick-replies', payload);
            onSaved(res.data);
        } catch (err) {
            if (err?.response?.status === 422) {
                const apiErrors = err.response.data?.errors ?? {};
                setErrors({
                    shortcut: apiErrors.shortcut?.[0],
                    message: apiErrors.message?.[0],
                });
            } else {
                alert(err?.response?.data?.message ?? 'No se pudo guardar la respuesta.');
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
                        <label className="text-sm font-medium text-foreground">Atajo</label>
                        <div className="flex items-stretch rounded-md border border-input overflow-hidden focus-within:ring-2 focus-within:ring-ring/50">
                            <span className="flex items-center px-3 bg-muted text-muted-foreground text-sm font-mono">/</span>
                            <input
                                type="text"
                                value={shortcut}
                                onChange={e => setShortcut(e.target.value)}
                                placeholder="saludo"
                                autoFocus
                                className="flex-1 h-9 bg-transparent px-3 py-1 text-sm font-mono placeholder:text-muted-foreground focus:outline-none"
                            />
                        </div>
                        {errors.shortcut && <p className="text-xs text-destructive font-medium">{errors.shortcut}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Mensaje</label>
                        <textarea
                            value={message}
                            onChange={e => setMessage(e.target.value)}
                            placeholder="¡Hola! Gracias por contactarnos. ¿En qué podemos ayudarte?"
                            rows={5}
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 resize-y"
                        />
                        <div className="flex justify-between text-xs text-muted-foreground">
                            {errors.message ? (
                                <span className="text-destructive font-medium">{errors.message}</span>
                            ) : <span />}
                            <span>{message.length}/4000</span>
                        </div>
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

QuickRepliesIndex.layout = page => <AppLayout breadcrumb={['Respuestas Rápidas']}>{page}</AppLayout>;
