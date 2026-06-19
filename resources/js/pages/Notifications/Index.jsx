import { useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { BellRing, Send, Users, User as UserIcon, Info, Megaphone, CheckCircle2 } from 'lucide-react';

export default function NotificationsIndex({ users: initialUsers, announcements: initialAnnouncements }) {
    const users = initialUsers ?? [];
    const [announcements, setAnnouncements] = useState(initialAnnouncements ?? []);

    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [target, setTarget] = useState('all'); // 'all' | 'user'
    const [userId, setUserId] = useState('');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [flash, setFlash] = useState(null);

    async function handleSubmit(e) {
        e.preventDefault();
        const next = {};
        if (!title.trim()) next.title = 'El título es obligatorio.';
        if (!body.trim()) next.body = 'El mensaje es obligatorio.';
        if (target === 'user' && !userId) next.user_id = 'Selecciona un usuario.';
        if (Object.keys(next).length) { setErrors(next); return; }

        setErrors({});
        setSubmitting(true);
        setFlash(null);
        try {
            const payload = { title: title.trim(), body, target, user_id: target === 'user' ? Number(userId) : null };
            const res = await axios.post('/api/announcements', payload);
            if (res.data.success) {
                setAnnouncements(prev => [res.data.announcement, ...prev]);
                setTitle('');
                setBody('');
                setTarget('all');
                setUserId('');
                setFlash(res.data.message);
            }
        } catch (err) {
            if (err?.response?.status === 422) {
                const apiErrors = err.response.data?.errors ?? {};
                setErrors({
                    title: apiErrors.title?.[0],
                    body: apiErrors.body?.[0],
                    user_id: apiErrors.user_id?.[0],
                });
                if (err.response.data?.message && !Object.keys(apiErrors).length) {
                    alert(err.response.data.message);
                }
            } else {
                alert(err?.response?.data?.message ?? 'No se pudo enviar el anuncio.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="Notificaciones" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                <div className="flex items-center gap-3">
                    <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                        <BellRing className="size-6" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Notificaciones del sistema</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            Envía un anuncio a un usuario o a todo el equipo. Aparecerá en su campanita.
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-5">
                    {/* Compose */}
                    <form onSubmit={handleSubmit} className="lg:col-span-2 rounded-xl border bg-card p-5 space-y-4 h-fit">
                        {flash && (
                            <div className="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800 dark:border-green-800/30 dark:bg-green-950/30 dark:text-green-400">
                                <CheckCircle2 className="size-4 shrink-0" /> {flash}
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Destinatario</label>
                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() => setTarget('all')}
                                    className={`flex items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors ${target === 'all' ? 'border-primary bg-primary/10 text-primary' : 'border-input text-muted-foreground hover:bg-muted'}`}
                                >
                                    <Users className="size-4" /> Todos
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setTarget('user')}
                                    className={`flex items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors ${target === 'user' ? 'border-primary bg-primary/10 text-primary' : 'border-input text-muted-foreground hover:bg-muted'}`}
                                >
                                    <UserIcon className="size-4" /> Un usuario
                                </button>
                            </div>
                        </div>

                        {target === 'user' && (
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-foreground">Usuario</label>
                                <select
                                    value={userId}
                                    onChange={e => setUserId(e.target.value)}
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                >
                                    <option value="">Selecciona…</option>
                                    {users.map(u => (
                                        <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
                                    ))}
                                </select>
                                {errors.user_id && <p className="text-xs text-destructive font-medium">{errors.user_id}</p>}
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Título</label>
                            <input
                                type="text"
                                value={title}
                                onChange={e => setTitle(e.target.value)}
                                placeholder="Ej: Mantenimiento programado"
                                maxLength={120}
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                            />
                            {errors.title && <p className="text-xs text-destructive font-medium">{errors.title}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Mensaje</label>
                            <textarea
                                value={body}
                                onChange={e => setBody(e.target.value)}
                                placeholder="Escribe el anuncio que verá el equipo…"
                                rows={5}
                                maxLength={2000}
                                className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 resize-y"
                            />
                            <div className="flex justify-between text-xs text-muted-foreground">
                                {errors.body ? <span className="text-destructive font-medium">{errors.body}</span> : <span />}
                                <span>{body.length}/2000</span>
                            </div>
                        </div>

                        <Button type="submit" className="w-full gap-2" disabled={submitting}>
                            <Send className="size-4" /> {submitting ? 'Enviando…' : 'Enviar anuncio'}
                        </Button>
                    </form>

                    {/* History */}
                    <div className="lg:col-span-3">
                        <h2 className="text-sm font-semibold text-muted-foreground uppercase tracking-wider mb-3">Enviados recientemente</h2>
                        {announcements.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                                <Megaphone className="size-10 text-muted-foreground/40 mb-3" />
                                <p className="text-sm text-muted-foreground">Todavía no has enviado anuncios.</p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {announcements.map(a => (
                                    <div key={a.id} className="rounded-xl border bg-card p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <h3 className="font-semibold text-foreground">{a.title}</h3>
                                                <p className="text-sm text-muted-foreground mt-1 whitespace-pre-wrap break-words">{a.body}</p>
                                            </div>
                                            <span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full shrink-0 ${a.target === 'all' ? 'bg-primary/10 text-primary' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'}`}>
                                                {a.target === 'all' ? 'Todos' : (a.target_user?.name ?? 'Usuario')}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-3 mt-3 text-[11px] text-muted-foreground">
                                            <span>{a.recipients_count} destinatario(s)</span>
                                            <span>·</span>
                                            <span>{a.sender?.name ?? 'Sistema'}</span>
                                            <span>·</span>
                                            <span>{a.created_at ? new Date(a.created_at).toLocaleString('es-CO') : ''}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex items-start gap-3 p-4 bg-muted/40 rounded-xl border text-xs text-muted-foreground max-w-3xl">
                    <Info className="size-4 text-primary shrink-0 mt-0.5" />
                    <p>
                        Los anuncios se entregan en la campanita de cada destinatario dentro del sistema.
                        Solo los usuarios con el permiso <code className="font-mono">notifications.send</code> pueden enviarlos.
                    </p>
                </div>
            </div>
        </>
    );
}

NotificationsIndex.layout = page => <AppLayout breadcrumb={['Notificaciones']}>{page}</AppLayout>;
