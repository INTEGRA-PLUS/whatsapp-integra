import { useState, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Webhook, Info, Send, History, CheckCircle2, XCircle, Power, Copy, X } from 'lucide-react';

export default function IntegrationsIndex({ webhooks: initialWebhooks, eventCatalog }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [webhooks, setWebhooks] = useState(initialWebhooks ?? []);
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deliveriesFor, setDeliveriesFor] = useState(null);

    function upsertLocal(wh) {
        setWebhooks(prev => {
            const idx = prev.findIndex(w => w.id === wh.id);
            if (idx === -1) return [wh, ...prev];
            const next = [...prev];
            next[idx] = wh;
            return next;
        });
    }

    async function handleDelete(wh) {
        if (!confirm(`¿Eliminar el webhook "${wh.name}"?`)) return;
        try {
            await axios.delete(`/api/webhooks/${wh.id}`);
            setWebhooks(prev => prev.filter(w => w.id !== wh.id));
        } catch (err) {
            alert(err?.response?.data?.message ?? 'No se pudo eliminar.');
        }
    }

    async function handleTest(wh) {
        try {
            await axios.post(`/api/webhooks/${wh.id}/test`);
            alert('Evento de prueba encolado. Revísalo en el historial de entregas.');
        } catch (err) {
            alert(err?.response?.data?.message ?? 'No se pudo enviar la prueba.');
        }
    }

    async function toggleActive(wh) {
        try {
            const res = await axios.put(`/api/webhooks/${wh.id}`, { active: !wh.active });
            upsertLocal(res.data);
        } catch (err) {
            alert('No se pudo actualizar el estado.');
        }
    }

    return (
        <>
            <Head title="Integraciones" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <Webhook className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-foreground">Integraciones · Webhooks</h1>
                            <p className="text-sm text-muted-foreground mt-0.5">
                                Notifica a sistemas externos (CRM, ERP, Zapier, n8n) cuando ocurren eventos en tus conversaciones.
                            </p>
                        </div>
                    </div>
                    {can('integrations.create') && (
                        <Button onClick={() => setShowForm(true)} className="gap-2">
                            <Plus className="size-4" /> Nuevo Webhook
                        </Button>
                    )}
                </div>

                {webhooks.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <Webhook className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes webhooks configurados</p>
                        <p className="text-sm text-muted-foreground mt-1 max-w-md">
                            Crea un endpoint, elige a qué eventos suscribirlo y empezaremos a notificar a tu sistema en tiempo real.
                        </p>
                        {can('integrations.create') && (
                            <Button onClick={() => setShowForm(true)} className="gap-2 mt-6">
                                <Plus className="size-4" /> Crear mi primer webhook
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {webhooks.map(wh => (
                            <div key={wh.id} className="rounded-xl border bg-card p-5">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <h3 className="font-semibold text-foreground truncate">{wh.name}</h3>
                                            <span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${wh.active ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-muted text-muted-foreground'}`}>
                                                {wh.active ? 'Activo' : 'Inactivo'}
                                            </span>
                                        </div>
                                        <p className="text-xs font-mono text-muted-foreground mt-1 truncate">{wh.url}</p>
                                        <div className="flex flex-wrap gap-1.5 mt-3">
                                            {(wh.events ?? []).map(ev => (
                                                <span key={ev} className="text-[10px] font-medium px-2 py-0.5 rounded bg-primary/10 text-primary">
                                                    {eventCatalog?.[ev] ?? ev}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1 shrink-0">
                                        {can('integrations.update') && (
                                            <Button variant="ghost" size="icon" title={wh.active ? 'Desactivar' : 'Activar'} onClick={() => toggleActive(wh)}>
                                                <Power className={`size-4 ${wh.active ? 'text-green-600' : 'text-muted-foreground'}`} />
                                            </Button>
                                        )}
                                        {can('integrations.update') && (
                                            <Button variant="ghost" size="icon" title="Enviar prueba" onClick={() => handleTest(wh)}>
                                                <Send className="size-4" />
                                            </Button>
                                        )}
                                        <Button variant="ghost" size="icon" title="Historial de entregas" onClick={() => setDeliveriesFor(wh)}>
                                            <History className="size-4" />
                                        </Button>
                                        {can('integrations.update') && (
                                            <Button variant="ghost" size="icon" title="Editar" onClick={() => setEditing(wh)}>
                                                <Pencil className="size-4" />
                                            </Button>
                                        )}
                                        {can('integrations.delete') && (
                                            <Button variant="ghost" size="icon" className="text-destructive hover:bg-destructive/10" onClick={() => handleDelete(wh)}>
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <div className="flex items-start gap-3 p-4 bg-muted/40 rounded-xl border text-xs text-muted-foreground max-w-3xl">
                    <Info className="size-4 text-primary shrink-0 mt-0.5" />
                    <p>
                        Cada solicitud se envía por <code className="font-mono">POST</code> en formato JSON e incluye la cabecera
                        {' '}<code className="font-mono">X-Webhook-Signature</code> (HMAC-SHA256 del cuerpo usando el secreto del webhook),
                        para que tu sistema pueda verificar la autenticidad. Las entregas fallidas se reintentan automáticamente.
                    </p>
                </div>
            </div>

            {(showForm || editing) && (
                <WebhookFormModal
                    eventCatalog={eventCatalog ?? {}}
                    initial={editing}
                    onClose={() => { setShowForm(false); setEditing(null); }}
                    onSaved={(wh) => { upsertLocal(wh); setShowForm(false); setEditing(null); }}
                />
            )}

            {deliveriesFor && (
                <DeliveriesModal
                    webhook={deliveriesFor}
                    eventCatalog={eventCatalog ?? {}}
                    onClose={() => setDeliveriesFor(null)}
                />
            )}
        </>
    );
}

function WebhookFormModal({ eventCatalog, initial, onClose, onSaved }) {
    const [name, setName] = useState(initial?.name ?? '');
    const [url, setUrl] = useState(initial?.url ?? '');
    const [events, setEvents] = useState(initial?.events ?? []);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    function toggleEvent(ev) {
        setEvents(prev => prev.includes(ev) ? prev.filter(e => e !== ev) : [...prev, ev]);
    }

    async function handleSubmit(e) {
        e.preventDefault();
        const next = {};
        if (!name.trim()) next.name = 'El nombre es obligatorio.';
        if (!url.trim()) next.url = 'La URL es obligatoria.';
        if (events.length === 0) next.events = 'Selecciona al menos un evento.';
        if (Object.keys(next).length) { setErrors(next); return; }

        setErrors({});
        setSubmitting(true);
        try {
            const payload = { name: name.trim(), url: url.trim(), events };
            const res = initial
                ? await axios.put(`/api/webhooks/${initial.id}`, payload)
                : await axios.post('/api/webhooks', payload);
            onSaved(res.data);
        } catch (err) {
            if (err?.response?.status === 422) {
                const apiErrors = err.response.data?.errors ?? {};
                setErrors({
                    name: apiErrors.name?.[0],
                    url: apiErrors.url?.[0],
                    events: apiErrors.events?.[0],
                });
            } else {
                alert(err?.response?.data?.message ?? 'No se pudo guardar el webhook.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    function copySecret() {
        if (initial?.secret) {
            navigator.clipboard?.writeText(initial.secret);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-xl border bg-card shadow-2xl p-6" onClick={e => e.stopPropagation()}>
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-foreground">{initial ? 'Editar Webhook' : 'Nuevo Webhook'}</h2>
                    <p className="text-sm text-muted-foreground mt-1">Define el endpoint y los eventos a notificar.</p>
                </div>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Nombre</label>
                        <input
                            type="text"
                            value={name}
                            onChange={e => setName(e.target.value)}
                            placeholder="CRM istinge"
                            autoFocus
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                        {errors.name && <p className="text-xs text-destructive font-medium">{errors.name}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">URL del endpoint</label>
                        <input
                            type="url"
                            value={url}
                            onChange={e => setUrl(e.target.value)}
                            placeholder="https://tu-sistema.com/webhooks/whatsapp"
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm font-mono focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                        {errors.url && <p className="text-xs text-destructive font-medium">{errors.url}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <label className="text-sm font-medium text-foreground">Eventos</label>
                        <div className="grid gap-2 rounded-md border border-input p-3">
                            {Object.entries(eventCatalog).map(([key, label]) => (
                                <label key={key} className="flex items-center gap-2.5 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={events.includes(key)}
                                        onChange={() => toggleEvent(key)}
                                        className="size-4 rounded border-input accent-primary"
                                    />
                                    <span className="text-sm text-foreground">{label}</span>
                                    <code className="ml-auto text-[10px] font-mono text-muted-foreground">{key}</code>
                                </label>
                            ))}
                        </div>
                        {errors.events && <p className="text-xs text-destructive font-medium">{errors.events}</p>}
                    </div>

                    {initial?.secret && (
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Secreto de firma</label>
                            <div className="flex items-stretch rounded-md border border-input overflow-hidden">
                                <code className="flex-1 px-3 py-2 text-xs font-mono bg-muted/50 truncate">{initial.secret}</code>
                                <button type="button" onClick={copySecret} className="px-3 bg-muted hover:bg-muted/70 transition-colors" title="Copiar">
                                    <Copy className="size-4" />
                                </button>
                            </div>
                            <p className="text-[11px] text-muted-foreground">Úsalo para verificar la cabecera <code className="font-mono">X-Webhook-Signature</code>.</p>
                        </div>
                    )}

                    <div className="flex gap-2 pt-2">
                        <Button type="submit" className="flex-1" disabled={submitting}>{initial ? 'Guardar Cambios' : 'Crear Webhook'}</Button>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancelar</Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function DeliveriesModal({ webhook, eventCatalog, onClose }) {
    const [deliveries, setDeliveries] = useState(null);

    useEffect(() => {
        axios.get(`/api/webhooks/${webhook.id}/deliveries`)
            .then(res => setDeliveries(res.data))
            .catch(() => setDeliveries([]));
    }, [webhook.id]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl border bg-card shadow-2xl" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-card">
                    <div>
                        <h2 className="text-lg font-semibold text-foreground">Entregas · {webhook.name}</h2>
                        <p className="text-xs text-muted-foreground mt-0.5">Últimas 50 entregas</p>
                    </div>
                    <Button variant="ghost" size="icon" onClick={onClose}><X className="size-4" /></Button>
                </div>
                <div className="p-4">
                    {deliveries === null ? (
                        <p className="text-sm text-muted-foreground text-center py-8">Cargando…</p>
                    ) : deliveries.length === 0 ? (
                        <p className="text-sm text-muted-foreground text-center py-8">Sin entregas todavía.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                                <tr>
                                    <th className="text-left font-semibold px-3 py-2">Evento</th>
                                    <th className="text-left font-semibold px-3 py-2 w-20">Estado</th>
                                    <th className="text-left font-semibold px-3 py-2 w-20">HTTP</th>
                                    <th className="text-left font-semibold px-3 py-2 w-44">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deliveries.map(d => (
                                    <tr key={d.id} className="border-t">
                                        <td className="px-3 py-2">{eventCatalog?.[d.event] ?? d.event}</td>
                                        <td className="px-3 py-2">
                                            {d.success ? (
                                                <span className="inline-flex items-center gap-1 text-green-600"><CheckCircle2 className="size-4" /> OK</span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 text-destructive" title={d.error ?? ''}><XCircle className="size-4" /> Falló</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">{d.status_code ?? '—'}</td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">
                                            {d.delivered_at ? new Date(d.delivered_at).toLocaleString('es-CO') : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </div>
    );
}

IntegrationsIndex.layout = page => <AppLayout breadcrumb={['Integraciones']}>{page}</AppLayout>;
