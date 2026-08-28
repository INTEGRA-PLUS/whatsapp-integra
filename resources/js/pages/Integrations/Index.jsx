import { useState, useEffect, useRef } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import ProviderConnectForm, { Field, inputClass } from '@/components/ProviderConnectForm';
import {
    Plus, Pencil, Trash2, Webhook, Info, Send, History, CheckCircle2, XCircle,
    Power, Copy, X, Plug, Wallet, ArrowRight,
    RefreshCw, AlertTriangle, Loader2, Save, CreditCard, Users,
} from 'lucide-react';

// Webhooks es nuestro; lo demás son proveedores. Cuando entre el segundo, esta
// lista se arma con el catálogo que ya manda el backend (IntegrationProvider)
// en vez de escribirse a mano.
const SECTIONS = [
    { id: 'webhooks', label: 'Webhooks', Icon: Webhook },
    { id: 'integra',  label: 'Integra',  Icon: Plug },
];

export default function IntegrationsIndex({ webhooks, eventCatalog }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [section, setSection] = useState('webhooks');

    return (
        <>
            <Head title="Integraciones" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <div className="size-11 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                        <Plug className="size-6" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Integraciones</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            Conecta tu WhatsApp con sistemas externos y úsalos desde el chat.
                        </p>
                    </div>
                </div>

                {/* Sub-nav */}
                <div className="flex gap-1 border-b">
                    {SECTIONS.map(s => {
                        const active = section === s.id;
                        return (
                            <button
                                key={s.id}
                                onClick={() => setSection(s.id)}
                                className={`relative inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium transition-colors ${
                                    active ? 'text-primary' : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <s.Icon className="size-4" />
                                {s.label}
                                {active && <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-t" />}
                            </button>
                        );
                    })}
                </div>

                {section === 'webhooks' && <WebhooksSection webhooks={webhooks} eventCatalog={eventCatalog} can={can} />}
                {section === 'integra' && <ProviderSection can={can} />}
            </div>
        </>
    );
}

/* ───────────────────────── Webhooks ───────────────────────── */

function WebhooksSection({ webhooks: initialWebhooks, eventCatalog, can }) {
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
        <div className="flex flex-col gap-6">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <p className="text-sm text-muted-foreground max-w-2xl">
                    Notifica a sistemas externos (CRM, ERP, Zapier, n8n) cuando ocurren eventos en tus conversaciones.
                </p>
                {can('integrations.create') && (
                    <Button onClick={() => setShowForm(true)} className="gap-2 shrink-0">
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
        </div>
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

/* ───────────────────────── Pagos a facturas (software Integra) ───────────────────────── */

/**
 * La cabecera del proveedor, común a todas sus funciones.
 *
 * Antes cada tarjeta se presentaba sola, con su icono genérico, y nada decía
 * que las dos hablaran con el MISMO Integra. De ahí que se pidiera la URL dos
 * veces y que nadie entendiera por qué conectar una tumbaba la otra. Aquí se
 * ve el proveedor, su logo, y qué funciones habilita esa única conexión.
 */
function ProviderHeader({ integration }) {
    const { providers = [] } = usePage().props;
    // Hoy sólo hay uno; el día que haya varios, esto se resuelve por la clave
    // de la integración y el resto de la pantalla no cambia.
    const provider = providers[0];

    if (!provider) {
        return (
            <div>
                <h2 className="text-lg font-semibold text-foreground">{integration.name}</h2>
                <p className="text-sm text-muted-foreground">{integration.description}</p>
            </div>
        );
    }

    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="flex items-start gap-3">
                <img src={provider.logo} alt={provider.name}
                    className="size-12 rounded-xl object-contain shrink-0 bg-[#0d1b2a]" />
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <h2 className="text-lg font-semibold text-foreground">{provider.name}</h2>
                        {integration.connected && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                                <CheckCircle2 className="size-3" /> Conectado
                            </span>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">{provider.description}</p>
                </div>
            </div>

            <div className="mt-3 border-t pt-3">
                <p className="text-[11px] text-muted-foreground">
                    Una sola conexión —una URL y un token— habilita todo esto:
                </p>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {provider.capabilities.map(c => (
                        <span key={c.id} title={c.does}
                            className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-foreground">
                            {c.label}
                        </span>
                    ))}
                </div>
            </div>
        </div>
    );
}

/* ───────────────────────── Integra (proveedor) ───────────────────────── */

/**
 * Todo lo que habilita un proveedor, en una sola pantalla.
 *
 * Antes eran dos pestañas —"Pagos a facturas" y "Contactos"— que pedían por
 * separado la URL del MISMO entorno de Integra y emitían un token cada una.
 * Nada en pantalla decía que hablaran con el mismo servidor, así que la
 * pregunta obvia era por qué hay que escribirlo dos veces. Ahora se conecta una
 * vez y debajo aparece lo que esa conexión habilita.
 */
function ProviderSection({ can }) {
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const [toast, setToast] = useState(null);

    useEffect(() => { load(); }, []);

    async function load() {
        try {
            const { data } = await axios.get('/api/integrations');
            setRows(data ?? []);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudieron cargar las integraciones.');
        }
    }

    function showToast(text, kind = 'success') {
        setToast({ text, kind });
        setTimeout(() => setToast(null), 4000);
    }

    /** Sustituye la fila que devuelve el backend y deja las demás como están. */
    function upsert(row) {
        setRows(prev => (prev ?? []).map(r => (r.key === row.key ? row : r)));
    }

    if (error) {
        return <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">{error}</div>;
    }

    if (!rows) {
        return (
            <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" /> Cargando…
            </div>
        );
    }

    const payments = rows.find(r => r.key === 'invoice_payments');
    const contacts = rows.find(r => r.key === 'contacts_sync');
    // Cualquiera sirve para saberlo: la conexión es del proveedor, no de la
    // función, así que o están las dos conectadas o no lo está ninguna.
    const connected = !!payments?.connected;
    const canManage = can('integrations.update');

    return (
        <div className="flex max-w-2xl flex-col gap-6">
            <ProviderHeader integration={payments ?? { connected }} />

            {toast && (
                <div className={`flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${
                    toast.kind === 'error'
                        ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                        : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                }`}>
                    {toast.kind === 'error' ? <AlertTriangle className="mt-0.5 size-4 shrink-0" /> : <CheckCircle2 className="mt-0.5 size-4 shrink-0" />}
                    <span>{toast.text}</span>
                </div>
            )}

            {!canManage && (
                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                    No tienes permisos para modificar esta integración. Pide a un administrador que la configure.
                </div>
            )}

            <fieldset disabled={!canManage} className="contents">
                {!connected ? (
                    <div className="rounded-xl border bg-card p-5">
                        <ProviderConnectForm
                            integrationKey="invoice_payments"
                            initialBaseUrl={payments?.base_url ?? ''}
                            onConnected={() => { showToast('Conexión establecida con Integra.'); load(); }}
                            onError={message => showToast(message, 'error')}
                        />
                    </div>
                ) : (
                    <>
                        {payments && (
                            <StepStatus integration={payments} onUpdated={() => load()} showToast={showToast} />
                        )}

                        <Panel title="Pagos a facturas" Icon={Wallet}
                            does="Consultar la deuda de un cliente y registrar su pago sin salir del chat.">
                            {payments && <StepActivate integration={payments} onUpdated={upsert} showToast={showToast} />}
                        </Panel>

                        <Panel title="Contactos" Icon={Users}
                            does="Traer el maestro de clientes de Integra para tener la agenda al día.">
                            {contacts && <StepSync integration={contacts} onUpdated={upsert} showToast={showToast} />}
                        </Panel>

                        <Panel title="Menús de WhatsApp" Icon={CreditCard}
                            does="Que el bot responda facturas, estado del servicio, consumo y reportes.">
                            <p className="text-sm text-muted-foreground">
                                No hay nada que activar aquí: en cuanto Integra está conectado, las opciones de
                                autoservicio del menú empiezan a responder.{' '}
                                <a href="/whatsapp-menus" className="underline underline-offset-2 hover:text-foreground">
                                    Ir a los menús
                                </a>
                            </p>
                        </Panel>
                    </>
                )}
            </fieldset>
        </div>
    );
}

/** Una función del proveedor, con lo que hace escrito arriba. */
function Panel({ title, Icon, does, children }) {
    return (
        <div className="rounded-xl border bg-card p-5">
            <div className="mb-4 flex items-start gap-2.5">
                <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div>
                    <h3 className="text-sm font-semibold text-foreground">{title}</h3>
                    <p className="text-xs text-muted-foreground">{does}</p>
                </div>
            </div>
            {children}
        </div>
    );
}

function StepStatus({ integration, onUpdated, onNext, showToast }) {
    const [checking, setChecking] = useState(false);

    async function verify() {
        setChecking(true);
        try {
            const { data } = await axios.get(`/api/integrations/${integration.key}/status`);
            onUpdated(data);
            showToast(data.connected ? 'Conexión verificada.' : (data.last_error ?? 'La conexión no está activa.'), data.connected ? 'success' : 'error');
        } catch (err) {
            showToast(err?.response?.data?.message ?? 'No se pudo verificar.', 'error');
        } finally {
            setChecking(false);
        }
    }

    async function disconnect() {
        if (!confirm('¿Desconectar la integración? Tendrás que volver a conectar para usarla.')) return;
        try {
            const { data } = await axios.post(`/api/integrations/${integration.key}/disconnect`);
            onUpdated(data);
            showToast('Integración desconectada.');
        } catch (err) {
            showToast(err?.response?.data?.message ?? 'No se pudo desconectar.', 'error');
        }
    }

    const ok = integration.connected;

    return (
        <div className="rounded-xl border bg-card p-5 space-y-5">
            <div className={`flex items-start gap-3 rounded-xl p-4 ${ok ? 'bg-emerald-500/10 border border-emerald-500/30' : 'bg-rose-500/10 border border-rose-500/30'}`}>
                {ok ? <CheckCircle2 className="size-6 text-emerald-600 dark:text-emerald-400 shrink-0" /> : <XCircle className="size-6 text-rose-600 dark:text-rose-400 shrink-0" />}
                <div className="min-w-0">
                    <p className={`font-semibold ${ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300'}`}>
                        {ok ? 'Conectado a Integra' : 'Sin conexión'}
                    </p>
                    {ok ? (
                        <div className="text-xs text-muted-foreground mt-1 space-y-0.5">
                            {integration.base_url && <p className="font-mono">{integration.base_url}</p>}
                            <p>API pública de Integra 2.0 (v1) · token con scopes</p>
                            {integration.connected_at && (
                                <p>Conectada el {new Date(integration.connected_at).toLocaleString('es-CO')}</p>
                            )}
                        </div>
                    ) : (
                        <p className="text-xs text-muted-foreground mt-1">{integration.last_error ?? 'Conecta Integra para empezar.'}</p>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                <Button onClick={verify} disabled={checking} variant="outline" className="gap-2">
                    {checking ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />} Verificar conexión
                </Button>
                {ok && onNext && (
                    <Button onClick={onNext} className="gap-2">Continuar <ArrowRight className="size-4" /></Button>
                )}
                {integration.status !== 'disconnected' && (
                    <Button onClick={disconnect} variant="outline" className="gap-2 text-destructive border-destructive/30 hover:bg-destructive/10">
                        <Power className="size-4" /> Desconectar
                    </Button>
                )}
            </div>
        </div>
    );
}

function StepActivate({ integration, onUpdated, showToast }) {
    const [form, setForm] = useState({
        enabled: integration.enabled ?? false,
        trigger_type: integration.trigger_type ?? 'slash',
        trigger_command: integration.trigger_command ?? 'pagos',
    });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const prefix = form.trigger_type === 'at' ? '@' : '/';

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        setSaving(true);
        try {
            const { data } = await axios.post(`/api/integrations/${integration.key}/activate`, form);
            onUpdated(data);
            showToast(data.enabled ? 'Integración activada en el chat.' : 'Integración desactivada.');
        } catch (err) {
            if (err?.response?.status === 422 && err.response.data?.errors) {
                setErrors(err.response.data.errors);
            } else {
                showToast(err?.response?.data?.message ?? 'No se pudo guardar.', 'error');
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="rounded-xl border bg-card p-5">
            <form onSubmit={submit} className="space-y-6">
                <label className="flex items-center gap-3 rounded-xl border border-border/60 p-4 cursor-pointer hover:bg-muted/30 transition-colors">
                    <input
                        type="checkbox"
                        checked={form.enabled}
                        onChange={e => setForm(f => ({ ...f, enabled: e.target.checked }))}
                        className="size-4 rounded border-input accent-primary"
                    />
                    <div>
                        <p className="text-sm font-medium text-foreground">Habilitar en el chat</p>
                        <p className="text-xs text-muted-foreground">Permite a los agentes registrar pagos desde una conversación.</p>
                    </div>
                </label>

                <div>
                    <p className="text-sm font-medium text-foreground mb-2">¿Cómo se llama la integración en el chat?</p>
                    <div className="grid grid-cols-2 gap-3">
                        {[
                            { value: 'slash', symbol: '/', label: 'Comando', desc: 'Estilo /comando' },
                            { value: 'at',    symbol: '@', label: 'Mención',  desc: 'Estilo @mención' },
                        ].map(opt => {
                            const isActive = form.trigger_type === opt.value;
                            return (
                                <button
                                    type="button"
                                    key={opt.value}
                                    onClick={() => setForm(f => ({ ...f, trigger_type: opt.value }))}
                                    className={`flex items-center gap-3 rounded-xl border-2 p-3.5 text-left transition-all ${
                                        isActive ? 'border-teal-500 bg-teal-500/5' : 'border-border/60 hover:border-border'
                                    }`}
                                >
                                    <span className={`flex items-center justify-center size-9 rounded-lg font-mono text-lg font-bold ${
                                        isActive ? 'bg-teal-500 text-white' : 'bg-muted text-muted-foreground'
                                    }`}>{opt.symbol}</span>
                                    <div>
                                        <p className={`text-sm font-semibold ${isActive ? 'text-teal-600 dark:text-teal-400' : 'text-foreground'}`}>{opt.label}</p>
                                        <p className="text-[11px] text-muted-foreground">{opt.desc}</p>
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>

                <Field label="Palabra del disparador" icon={CreditCard} error={errors.trigger_command?.[0]}>
                    <div className="flex items-center gap-2">
                        <span className="font-mono text-lg font-bold text-muted-foreground">{prefix}</span>
                        <input
                            className={inputClass}
                            value={form.trigger_command}
                            onChange={e => setForm(f => ({ ...f, trigger_command: e.target.value.replace(/[^a-zA-Z0-9_-]/g, '') }))}
                            placeholder="pagos"
                        />
                    </div>
                    <p className="text-[11px] text-muted-foreground mt-1">
                        Los agentes escribirán <code className="font-mono font-semibold text-teal-600 dark:text-teal-400">{prefix}{form.trigger_command || 'pagos'}</code> en el chat para abrir el formulario de pago.
                    </p>
                </Field>

                <Button type="submit" disabled={saving} className="gap-2">
                    {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />} Guardar
                </Button>
            </form>
        </div>
    );
}

/* ───────────────────────── Contactos (software Integra) ───────────────────────── */

function StepSync({ integration, onUpdated, showToast }) {
    const [syncing, setSyncing] = useState(false);
    const pollRef = useRef(null);

    const syncStatus = integration.sync_status;
    const running = syncStatus?.state === 'running';

    useEffect(() => {
        if (running) startPolling();
        return () => clearInterval(pollRef.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function startPolling() {
        setSyncing(true);
        clearInterval(pollRef.current);
        pollRef.current = setInterval(async () => {
            try {
                const { data } = await axios.get(`/api/integrations/${integration.key}/sync-status`);
                onUpdated(prev => ({ ...prev, sync_status: data.sync_status, last_synced_at: data.last_synced_at }));
                if (data.sync_status?.state !== 'running') {
                    clearInterval(pollRef.current);
                    setSyncing(false);
                    if (data.sync_status?.state === 'done') {
                        showToast(`Sincronización completa: ${data.sync_status.created} nuevos, ${data.sync_status.matched} ya existían y se vincularon.`);
                    } else if (data.sync_status?.state === 'error') {
                        showToast(data.sync_status.error ?? 'La sincronización falló.', 'error');
                    }
                }
            } catch {
                clearInterval(pollRef.current);
                setSyncing(false);
            }
        }, 2000);
    }

    async function sync() {
        setSyncing(true);
        try {
            const { data } = await axios.post(`/api/integrations/${integration.key}/sync`);
            onUpdated(data);
            startPolling();
        } catch (err) {
            setSyncing(false);
            showToast(err?.response?.data?.message ?? 'No se pudo iniciar la sincronización.', 'error');
        }
    }

    const busy = syncing || running;
    const progressPct = syncStatus?.total_pages
        ? Math.min(100, Math.round((syncStatus.page / syncStatus.total_pages) * 100))
        : null;

    return (
        <div className="rounded-xl border bg-card p-5 space-y-5">
            <div className="flex items-start gap-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 p-4">
                <CheckCircle2 className="size-6 text-emerald-600 dark:text-emerald-400 shrink-0" />
                <div className="min-w-0">
                    <p className="font-semibold text-emerald-700 dark:text-emerald-300">Conectado a Integra</p>
                    <p className="text-xs text-muted-foreground mt-1">
                        {integration.last_synced_at
                            ? `Última sincronización: ${new Date(integration.last_synced_at).toLocaleString('es-CO')}`
                            : 'Todavía no se ha sincronizado.'}
                    </p>
                </div>
            </div>

            {busy && (
                <div className="space-y-2">
                    <div className="h-2 rounded-full bg-muted overflow-hidden">
                        <div
                            className="h-full bg-teal-500 transition-all"
                            style={{ width: progressPct !== null ? `${progressPct}%` : '30%' }}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Sincronizando… {syncStatus?.processed ?? 0} contactos procesados
                        {syncStatus?.total_pages ? ` (página ${syncStatus.page}/${syncStatus.total_pages})` : ''}.
                    </p>
                </div>
            )}

            {!busy && syncStatus?.state === 'done' && (
                <p className="text-xs text-muted-foreground">
                    Última corrida: {syncStatus.created} contactos nuevos, {syncStatus.matched} ya existían y se vincularon.
                </p>
            )}

            <Button onClick={sync} disabled={busy} className="gap-2">
                {busy ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                {busy ? 'Sincronizando…' : 'Sincronizar contactos'}
            </Button>
            <p className="text-[11px] text-muted-foreground">
                Trae todos los clientes de Integra y los guarda como contactos. Si un contacto ya existe con el
                mismo número de teléfono, no se duplica: se conserva su nombre y solo se etiqueta como
                vinculado a Contactos.
            </p>
        </div>
    );
}

IntegrationsIndex.layout = page => <AppLayout breadcrumb={['Integraciones']}>{page}</AppLayout>;
