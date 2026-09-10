import { useState, useEffect, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import ProviderConnectForm, { Field, inputClass } from '@/components/ProviderConnectForm';
import IntegrationsHelp from './IntegrationsHelp';
import { WhatsAppPreview } from '../Templates/preview';
import { cn } from '@/lib/utils';
import {
    Plus, Pencil, Trash2, Webhook, Info, Send, History, CheckCircle2, XCircle,
    Power, Copy, X, Plug, Wallet, ArrowRight, Blocks, ArrowLeft, HelpCircle,
    RefreshCw, AlertTriangle, Loader2, Save, CreditCard, Users, FileCheck2,
} from 'lucide-react';

// Los complementos son los proveedores del catálogo; los webhooks son cosa
// nuestra y por eso van aparte.
const SECTIONS = [
    { id: 'apps',     label: 'Complementos', Icon: Blocks },
    { id: 'webhooks', label: 'Webhooks',     Icon: Webhook },
];

export default function IntegrationsIndex({ webhooks, eventCatalog }) {
    const { auth } = usePage().props;
    const can = (perm) => (auth?.user?.permissions ?? []).includes(perm);

    const [section, setSection] = useState('apps');
    // Qué complemento se está viendo por dentro. Sin ninguno, se ve la galería.
    const [openProvider, setOpenProvider] = useState(null);
    const [showHelp, setShowHelp] = useState(false);

    return (
        <>
            <Head title="Integraciones" />
            <div className="flex flex-col gap-6 p-6 lg:p-8">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
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
                    <Button variant="outline" onClick={() => setShowHelp(true)} className="gap-2">
                        <HelpCircle className="size-4" /> ¿Cómo funciona?
                    </Button>
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

                {section === 'apps' && (openProvider
                    ? <ProviderSection can={can} onBack={() => setOpenProvider(null)} />
                    : <ProviderGallery onOpen={setOpenProvider} />)}
            </div>

            {showHelp && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                    onClick={() => setShowHelp(false)}>
                    <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl border bg-card p-6 shadow-2xl"
                        onClick={e => e.stopPropagation()}>
                        <div className="mb-5 flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold text-foreground">Complementos y webhooks</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Qué es cada uno, cuál necesitas y cómo saber si está funcionando
                                </p>
                            </div>
                            <button type="button" onClick={() => setShowHelp(false)}
                                className="text-muted-foreground hover:text-foreground">
                                <X className="size-5" />
                            </button>
                        </div>
                        <IntegrationsHelp onClose={() => setShowHelp(false)} />
                    </div>
                </div>
            )}
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
                                        <span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${wh.active ? 'bg-success/15 text-success dark:bg-success/30 dark:text-success' : 'bg-muted text-muted-foreground'}`}>
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

                                    <WebhookHealth health={wh.health} />
                                </div>
                                <div className="flex items-center gap-1 shrink-0">
                                    {can('integrations.update') && (
                                        <Button variant="ghost" size="icon" title={wh.active ? 'Desactivar' : 'Activar'} onClick={() => toggleActive(wh)}>
                                            <Power className={`size-4 ${wh.active ? 'text-success' : 'text-muted-foreground'}`} />
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


/**
 * Si el webhook está entregando, no si está encendido.
 *
 * "ACTIVO" en verde sólo dice que el interruptor está puesto. Uno de los que
 * había en producción llevaba 111 avisos mandados a una puerta que no abría
 * —apuntaba a una web, no a una ruta que recibiera POSTs— y la pantalla lo
 * mostraba en verde todo el tiempo. El estado que importa es este.
 */
function WebhookHealth({ health }) {
    if (!health) return null;

    const { state, total, ok, failed, says, fix, last_at: lastAt } = health;

    const tone = state === 'ok'
        ? 'text-success'
        : state === 'failing'
            ? 'text-destructive'
            : 'text-muted-foreground';

    const Icon = state === 'ok' ? CheckCircle2 : state === 'failing' ? AlertTriangle : History;

    return (
        <div className="mt-3 border-t pt-3">
            <div className={`flex items-center gap-1.5 text-xs font-medium ${tone}`}>
                <Icon className="size-3.5 shrink-0" />
                {says}
                {lastAt && (
                    <span className="font-normal text-muted-foreground">
                        · {new Date(lastAt).toLocaleString('es-CO')}
                    </span>
                )}
            </div>

            {total > 0 && (
                <p className="mt-1 text-[11px] text-muted-foreground">
                    {total} {total === 1 ? 'entrega' : 'entregas'} · {ok} correctas · {failed} fallidas
                </p>
            )}

            {fix && <p className="mt-1 text-[11px] text-muted-foreground">{fix}</p>}
        </div>
    );
}


/**
 * Prueba la dirección antes de guardarla.
 *
 * El fallo típico no se ve al escribir: la URL parece correcta, el webhook se
 * guarda, sale "activo" en verde, y meses después alguien descubre que todas
 * las entregas devolvían 405 porque apuntaba a una página web. Un evento de
 * prueba en el momento convierte eso en una frase antes de guardar nada.
 */
function UrlProbe({ url, probe, setProbe }) {
    const [testing, setTesting] = useState(false);

    async function run() {
        setTesting(true);
        try {
            const { data } = await axios.post('/api/webhooks/probe', { url: url.trim() });
            setProbe(data);
        } catch (err) {
            setProbe({
                ok: false,
                says: err?.response?.data?.message ?? 'No se pudo probar la dirección.',
                fix: null,
            });
        } finally {
            setTesting(false);
        }
    }

    const usable = /^https?:\/\/.+\..+/i.test(url.trim());

    return (
        <div className="space-y-1.5 pt-1">
            <button
                type="button"
                onClick={run}
                disabled={!usable || testing}
                className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2.5 py-1 text-xs font-medium text-foreground hover:bg-accent disabled:opacity-50"
            >
                {testing ? <Loader2 className="size-3.5 animate-spin" /> : <Send className="size-3.5" />}
                {testing ? 'Probando…' : 'Probar esta dirección'}
            </button>

            {probe && (
                <div className={`rounded-md px-2.5 py-2 text-xs ${probe.ok ? 'bg-success/10 text-success' : 'bg-destructive/10 text-destructive'}`}>
                    <p className="font-medium">{probe.says}</p>
                    {probe.fix && <p className="mt-1 opacity-90">{probe.fix}</p>}
                </div>
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
    const [probe, setProbe] = useState(null);

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
                            onChange={e => { setUrl(e.target.value); setProbe(null); }}
                            placeholder="https://tu-sistema.com/webhooks/whatsapp"
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm font-mono focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                        <p className="text-[11px] text-muted-foreground">
                            La ruta de tu servidor que recibe los avisos, no la página de tu software.
                            Tiene que aceptar peticiones <code className="font-mono">POST</code>.
                        </p>
                        {errors.url && <p className="text-xs font-medium text-destructive">{errors.url}</p>}

                        <UrlProbe url={url} probe={probe} setProbe={setProbe} />
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
                                                <span className="inline-flex items-center gap-1 text-success"><CheckCircle2 className="size-4" /> OK</span>
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
                            <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success">
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

/* ───────────────────────── Galería de complementos ───────────────────────── */

/**
 * Los complementos que puede tener el CRM, uno por tarjeta.
 *
 * Con una sola pestaña por proveedor no se veía que esto fuera un ecosistema:
 * parecía que la plataforma era "lo de Integra". En galería, Integra es una
 * tarjeta y el hueco para las que vengan está a la vista — que es lo que de
 * verdad es, porque la plataforma es nuestra y ellos son un cliente más.
 *
 * El catálogo llega del backend (IntegrationProvider), así que sumar un
 * complemento no obliga a tocar esta pantalla.
 */
function ProviderGallery({ onOpen }) {
    const { providers = [] } = usePage().props;
    const [rows, setRows] = useState(null);

    useEffect(() => {
        axios.get('/api/integrations')
            .then(({ data }) => setRows(data ?? []))
            .catch(() => setRows([]));
    }, []);

    // La conexión es del proveedor, así que basta con mirar una de sus filas.
    const connected = id => !!(rows ?? []).find(r => r.key === 'invoice_payments')?.connected && id === 'integra';

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-2xl text-sm text-muted-foreground">
                Conecta el software que ya usa tu empresa y sus datos empiezan a responder desde WhatsApp:
                facturas, contratos, soporte y clientes, sin que nadie los copie a mano.
            </p>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {providers.map(p => {
                    const on = connected(p.id);

                    return (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() => onOpen(p.id)}
                            className="group flex flex-col rounded-xl border bg-card p-5 text-left transition-colors hover:border-primary/40 hover:bg-accent/30"
                        >
                            <div className="flex items-start justify-between">
                                <img src={p.logo} alt={p.name}
                                    className="size-12 rounded-xl bg-[#0d1b2a] object-contain" />
                                <ArrowRight className="size-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                            </div>

                            <h3 className="mt-3 font-semibold text-foreground">{p.name}</h3>
                            <p className="text-xs text-muted-foreground">{p.tagline}</p>

                            <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">{p.description}</p>

                            <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                {rows === null ? (
                                    <span className="text-[11px] text-muted-foreground">Comprobando…</span>
                                ) : on ? (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success">
                                        <CheckCircle2 className="size-3" /> Conectado
                                    </span>
                                ) : (
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                        Sin conectar
                                    </span>
                                )}
                                <span className="text-[11px] text-muted-foreground">
                                    · {p.capabilities.length} funciones
                                </span>
                            </div>
                        </button>
                    );
                })}

                {/* El hueco de los que vengan: sin él, la galería de una sola
                    tarjeta vuelve a parecer una pantalla dedicada a Integra. */}
                <div className="flex flex-col items-start justify-center rounded-xl border border-dashed p-5">
                    <Blocks className="size-8 text-muted-foreground/40" />
                    <h3 className="mt-3 font-semibold text-muted-foreground">Más complementos en camino</h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        ¿Usas otro software de gestión? Escríbenos y lo integramos.
                    </p>
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
function ProviderSection({ can, onBack }) {
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
            {onBack && (
                <button type="button" onClick={onBack}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> Complementos
                </button>
            )}

            <ProviderHeader integration={payments ?? { connected }} />

            {toast && (
                <div className={`flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${
                    toast.kind === 'error'
                        ? 'border-destructive/30 bg-destructive/10 text-destructive'
                        : 'border-success/30 bg-success/10 text-success'
                }`}>
                    {toast.kind === 'error' ? <AlertTriangle className="mt-0.5 size-4 shrink-0" /> : <CheckCircle2 className="mt-0.5 size-4 shrink-0" />}
                    <span>{toast.text}</span>
                </div>
            )}

            {!canManage && (
                <div className="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">
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

                        <LineasDelErp showToast={showToast} canManage={canManage} />

                        <AjustesDeEnvio showToast={showToast} canManage={canManage} />

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

/**
 * Los envíos automáticos del ERP y sus plantillas por defecto.
 *
 * Estaban en la configuración de Integra 2.0, mientras el WhatsApp lo lleva el
 * CRM: el cliente tenía que saber que una cosa se toca en un sitio y la otra en
 * el otro. Ahora se ven y se cambian aquí.
 *
 * **No se guardan aquí.** Se leen y se escriben en Integra en cada visita, para
 * que no existan dos copias de la misma configuración: el problema con el que
 * empezó todo este trabajo.
 */
function AjustesDeEnvio({ showToast, canManage }) {
    const [estado, setEstado] = useState({ cargando: true });
    const [guardando, setGuardando] = useState(null);
    // Qué plantilla se está parametrizando, si alguna.
    const [parametrizando, setParametrizando] = useState(null);

    const cargar = async () => {
        try {
            const { data } = await axios.get('/integrations/ajustes-envio');
            setEstado({ cargando: false, ...data });
        } catch {
            setEstado({ cargando: false, error: 'No se pudieron leer los ajustes de Integra.' });
        }
    };

    useEffect(() => { cargar(); }, []);

    const guardar = async (cambios, etiqueta) => {
        setGuardando(etiqueta);
        try {
            const { data } = await axios.put('/integrations/ajustes-envio', cambios);
            setEstado(e => ({ ...e, ...data }));
            showToast('Guardado en Integra.');
        } catch (e) {
            showToast(e.response?.data?.message ?? 'No se pudo guardar en Integra.', 'error');
            cargar();
        } finally {
            setGuardando(null);
        }
    };

    if (estado.cargando) {
        return (
            <Panel title="Envíos automáticos" Icon={Send} does="Qué manda Integra por WhatsApp y con qué plantilla.">
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" /> Leyendo los ajustes de Integra…
                </p>
            </Panel>
        );
    }

    if (estado.conectado === false) return null;

    if (estado.error) {
        return (
            <Panel title="Envíos automáticos" Icon={Send} does="Qué manda Integra por WhatsApp y con qué plantilla.">
                <p className="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">
                    <AlertTriangle className="mt-px size-3.5 shrink-0" /> {estado.error}
                </p>
            </Panel>
        );
    }

    const conDocumento = (estado.disponibles ?? []).filter(p => p.con_documento);

    return (
        <Panel title="Envíos automáticos" Icon={Send} does="Qué manda Integra por WhatsApp y con qué plantilla.">
            <div className="space-y-4">
                <Interruptor
                    titulo="Factura del mes"
                    descripcion="Al generarse la factura en cada ciclo, se le manda al cliente por WhatsApp."
                    activo={estado.envio_automatico_facturas}
                    ocupado={guardando === 'facturas'}
                    disabled={!canManage}
                    onCambiar={v => guardar({ envio_automatico_facturas: v }, 'facturas')}
                />

                <Interruptor
                    titulo="Recibo de pago (tirilla)"
                    descripcion="Al registrar un pago —manual o de una pasarela— se le manda la tirilla confirmando."
                    activo={estado.envio_automatico_recibos}
                    ocupado={guardando === 'recibos'}
                    disabled={!canManage}
                    onCambiar={v => guardar({ envio_automatico_recibos: v }, 'recibos')}
                />

                <div className="space-y-3 border-t border-border pt-4">
                    <p className="text-xs font-semibold text-foreground">Plantilla que se usa en cada caso</p>

                    {/* Sólo las que llevan encabezado de documento pueden
                        adjuntar la factura o la tirilla: ofrecer las demás es
                        invitar a un envío que llega sin el PDF. */}
                    {conDocumento.length === 0 && (
                        <p className="flex items-start gap-2 rounded-lg bg-warning/10 px-2.5 py-2 text-[11px] text-warning">
                            <AlertTriangle className="mt-px size-3.5 shrink-0" />
                            Ninguna de tus plantillas lleva encabezado de documento, así que no pueden adjuntar
                            el PDF. Crea una en Plantillas con encabezado de tipo DOCUMENTO.
                        </p>
                    )}

                    {[
                        ['factura', 'Facturas', 'plantilla_factura_id'],
                        ['tirilla', 'Recibos de pago', 'plantilla_tirilla_id'],
                        ['contrato', 'Contratos', 'plantilla_contrato_id'],
                    ].map(([clave, etiqueta, campo]) => {
                        const actual = estado.plantillas?.[clave];

                        if (actual?.disponible === false) {
                            return (
                                <div key={clave} className="flex items-center justify-between gap-2 text-xs">
                                    <span className="text-muted-foreground">{etiqueta}</span>
                                    <span className="text-muted-foreground/70">No disponible en tu versión de Integra</span>
                                </div>
                            );
                        }

                        return (
                            <div key={clave} className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs text-foreground">{etiqueta}</span>

                                <div className="flex items-center gap-1.5">
                                    <select
                                        value={actual?.id ?? ''}
                                        disabled={!canManage || guardando === clave}
                                        onChange={e => guardar({ [campo]: e.target.value ? Number(e.target.value) : null }, clave)}
                                        className="h-8 min-w-[200px] rounded-lg border border-input bg-card px-2 text-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                                    >
                                        <option value="">Sin elegir</option>
                                        {(estado.disponibles ?? []).map(p => (
                                            <option key={p.id} value={p.id}>
                                                {p.title} ({p.language}){p.con_documento ? '' : ' · sin adjunto'}
                                            </option>
                                        ))}
                                    </select>

                                    {/* Elegir la plantilla y decir qué lleva
                                        dentro son la misma decisión: el botón
                                        va al lado del selector, no en otra
                                        pantalla y menos en otro sistema. */}
                                    <button
                                        type="button"
                                        disabled={!actual?.id}
                                        onClick={() => setParametrizando({ id: actual.id, uso: etiqueta.toLowerCase() })}
                                        title={actual?.id ? 'Decir qué dato va en cada variable' : 'Elige primero una plantilla'}
                                        className={cn(
                                            'flex h-8 items-center gap-1 rounded-lg border border-input px-2 text-[11px] transition-colors',
                                            actual?.id
                                                ? 'cursor-pointer text-foreground hover:bg-muted'
                                                : 'cursor-not-allowed text-muted-foreground/50',
                                        )}
                                    >
                                        <Pencil className="size-3" /> Variables
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <p className="text-[11px] text-muted-foreground">
                    Estos ajustes viven en Integra: se leen y se guardan allí, así que no hay dos copias
                    que puedan quedar distintas.
                </p>
            </div>

            {parametrizando && (
                <ParametrizarPlantilla
                    plantillaId={parametrizando.id}
                    uso={parametrizando.uso}
                    canManage={canManage}
                    showToast={showToast}
                    onClose={() => setParametrizando(null)}
                />
            )}
        </Panel>
    );
}

/**
 * Qué dato del ERP va en cada `{{n}}` de la plantilla.
 *
 * Elegir la plantilla y decir qué lleva dentro son la misma decisión, y estaban
 * en dos sistemas: la plantilla se elige aquí y sus variables se editaban en
 * Integra. Ahora se resuelven las dos en el mismo sitio; la parametrización se
 * sigue guardando allí, que es de donde el cron la lee.
 *
 * El texto que manda es el de Meta, no el que Integra tenga guardado: si la
 * plantilla se editó en Meta y ahora pide una variable más, el envío se cae
 * entero con «number of parameters does not match» y no hay forma de verlo
 * hasta que las facturas empiezan a rebotar.
 */
function ParametrizarPlantilla({ plantillaId, uso, onClose, showToast, canManage }) {
    const [datos, setDatos] = useState(null);
    const [error, setError] = useState(null);
    const [variables, setVariables] = useState([]);
    const [guardando, setGuardando] = useState(false);
    // El insertador abierto, si hay alguno: uno solo a la vez.
    const [insertando, setInsertando] = useState(null);
    const campos = useRef([]);

    useEffect(() => {
        let vivo = true;

        axios.get(`/integrations/plantillas/${plantillaId}/campos`)
            .then(({ data }) => {
                if (!vivo) return;
                setDatos(data);
                // Los huecos de Meta mandan: es lo que se envía de verdad.
                const cuantos = data.meta?.encontrada ? data.meta.huecos : data.huecos;
                setVariables(Array.from({ length: cuantos }, (_, i) => data.variables?.[i] ?? ''));
            })
            .catch(e => vivo && setError(e.response?.data?.message ?? 'No se pudo leer la plantilla en Integra.'));

        return () => { vivo = false; };
    }, [plantillaId]);

    const guardar = async () => {
        setGuardando(true);
        try {
            const { data } = await axios.put(`/integrations/plantillas/${plantillaId}/campos`, { variables });
            setDatos(data);
            showToast('Parametrización guardada en Integra.');
            onClose();
        } catch (e) {
            showToast(e.response?.data?.message ?? 'No se pudo guardar en Integra.', 'error');
        } finally {
            setGuardando(false);
        }
    };

    const texto = datos?.meta?.encontrada ? datos.meta.texto : (datos?.contenido ?? '');
    const catalogo = datos?.catalogo ?? [];
    const ejemplos = datos?.ejemplos ?? {};

    /**
     * Un valor con los datos cambiados por su ejemplo.
     *
     * Una variable no es «un campo o un texto»: es un texto que puede llevar
     * dentro los campos que haga falta, y así se usan de verdad —el `{{1}}` de
     * la plantilla de facturación de Transinternet es
     * `[contacto.nombre] [contacto.apellido1] [contacto.apellido2]`—. El helper
     * del ERP hace exactamente esto: reemplaza cada `[x.y]` allí donde esté.
     */
    const conEjemplos = (valor) =>
        String(valor ?? '').replace(/\[[a-z]+\.[a-z0-9_]+\]/gi, m => ejemplos[m] ?? m);

    // Inserta el campo donde esté el cursor, no al final: si estás componiendo
    // «Sr(a) [contacto.nombre]» quieres el dato donde lo estabas escribiendo.
    const insertar = (i, clave) => {
        const campo = campos.current[i];
        const valor = variables[i] ?? '';
        const desde = campo?.selectionStart ?? valor.length;
        const hasta = campo?.selectionEnd ?? valor.length;
        const nuevo = valor.slice(0, desde) + clave + valor.slice(hasta);

        setVariables(prev => prev.map((x, j) => (j === i ? nuevo : x)));
        setInsertando(null);

        requestAnimationFrame(() => {
            campo?.focus();
            campo?.setSelectionRange(desde + clave.length, desde + clave.length);
        });
    };

    const muestra = texto.replace(/\{\{\s*(\d+)\s*\}\}/g, (_, n) => {
        const valor = variables[Number(n) - 1] ?? '';
        return valor.trim() ? conEjemplos(valor) : '⟨sin llenar⟩';
    });

    const faltan = variables.some(v => !String(v).trim());

    /**
     * El mensaje tal y como sale en el teléfono del cliente.
     *
     * El cuerpo es el de arriba —con las variables ya resueltas—, y el resto
     * viene de Meta tal cual: el encabezado, el pie y los botones también son
     * parte de lo que llega, y hasta ahora no se veían por ningún lado. Con
     * encabezado de documento se pinta el PDF adjunto con el nombre que le pone
     * el ERP de verdad: `Factura_FV-10482.pdf` sale de `CronController`.
     */
    const componentes = datos?.meta?.componentes ?? [];
    const encabezado = componentes.find(c => (c.type ?? '').toUpperCase() === 'HEADER');
    const pie = componentes.find(c => (c.type ?? '').toUpperCase() === 'FOOTER');
    const botones = componentes.find(c => (c.type ?? '').toUpperCase() === 'BUTTONS');

    // Sin catálogo de Meta se cae al `body_header` que guarda el ERP: dice si
    // la plantilla adjunta documento, que es lo que más se pregunta.
    const formatoEncabezado = encabezado
        ? (encabezado.format ?? 'TEXT').toUpperCase()
        : (datos?.con_documento ? 'DOCUMENT' : null);

    const adjunto = uso.includes('recibo') ? 'Recibo_RC-3391.pdf' : 'Factura_FV-10482.pdf';

    const modelo = {
        header: formatoEncabezado === 'TEXT'
            ? { text: conEjemplos(encabezado?.text ?? '') }
            : formatoEncabezado
                ? { text: '', mediaFormat: formatoEncabezado, filename: formatoEncabezado === 'DOCUMENT' ? adjunto : null }
                : null,
        body: { text: muestra },
        footer: pie?.text ? { text: pie.text } : null,
        buttons: (botones?.buttons ?? []).map(b => ({
            type: b.type ?? 'QUICK_REPLY',
            text: b.text ?? '',
            url: b.url ?? '',
            phone_number: b.phone_number ?? '',
        })),
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" onClick={onClose}>
            <div
                className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-xl border bg-card p-6 shadow-2xl"
                onClick={e => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-foreground">Qué va en cada variable</h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {datos ? <>Plantilla <span className="font-medium text-foreground">{datos.title}</span> ({datos.language}) · se usa para {uso}.</> : 'Leyendo la plantilla…'}
                        </p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1 text-muted-foreground hover:bg-muted hover:text-foreground">
                        <X className="size-4" />
                    </button>
                </div>

                {error && (
                    <p className="flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">
                        <AlertTriangle className="mt-px size-3.5 shrink-0" /> {error}
                    </p>
                )}

                {!datos && !error && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Loader2 className="size-4 animate-spin" /> Leyendo la plantilla…
                    </p>
                )}

                {datos && (
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                      <div className="space-y-4">
                        {/* Una plantilla que no está en la línea por la que
                            envía el ERP no se puede enviar: Meta contesta
                            «(#100) Invalid parameter» y la factura no sale. */}
                        {datos.meta?.encontrada === false && (
                            <p className="flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">
                                <AlertTriangle className="mt-px size-3.5 shrink-0" />
                                Esta plantilla no existe en {datos.meta.linea}, que es la línea por la que envía
                                Integra. Cópiala a esa línea desde Plantillas, o el envío fallará.
                            </p>
                        )}

                        {datos.meta?.encontrada && datos.meta.huecos !== datos.huecos && (
                            <p className="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">
                                <AlertTriangle className="mt-px size-3.5 shrink-0" />
                                En Meta esta plantilla pide {datos.meta.huecos} variable{datos.meta.huecos === 1 ? '' : 's'} y
                                en Integra hay {datos.huecos} guardada{datos.huecos === 1 ? '' : 's'}. Manda Meta: llena las de abajo.
                            </p>
                        )}

                        {datos.meta?.encontrada && datos.meta.estado !== 'APPROVED' && (
                            <p className="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">
                                <AlertTriangle className="mt-px size-3.5 shrink-0" />
                                Meta todavía no la aprueba (está en {datos.meta.estado}). Hasta que lo haga no se puede enviar.
                            </p>
                        )}

                        {/* El texto con sus huecos resaltados: es el mapa de lo
                            que se está llenando abajo. */}
                        <div className="rounded-lg border border-border bg-muted/40 p-3">
                            <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                Texto de la plantilla
                            </p>
                            <p className="whitespace-pre-wrap text-xs leading-relaxed text-foreground">
                                {texto
                                    ? texto.split(/(\{\{\s*\d+\s*\}\})/g).map((trozo, i) =>
                                        /^\{\{\s*\d+\s*\}\}$/.test(trozo)
                                            ? <span key={i} className="rounded bg-primary/15 px-1 font-mono text-[11px] text-primary">{trozo}</span>
                                            : <span key={i}>{trozo}</span>,
                                    )
                                    : '—'}
                            </p>
                        </div>

                        {variables.length === 0 ? (
                            <p className="rounded-lg bg-success/10 px-3 py-2 text-xs text-success">
                                Esta plantilla no tiene variables: se envía tal cual, no hay nada que parametrizar.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                <p className="text-[11px] text-muted-foreground">
                                    Cada variable puede llevar datos del cliente, texto tuyo, o las dos cosas mezcladas
                                    —«Sr(a) [contacto.nombre]» es una sola variable—.
                                </p>

                                {variables.map((valor, i) => (
                                    <div key={i} className="rounded-lg border border-border p-2.5">
                                        <div className="flex items-center gap-2">
                                            <span className="shrink-0 rounded bg-primary/10 px-1.5 py-1 font-mono text-[11px] text-primary">
                                                {`{{${i + 1}}}`}
                                            </span>

                                            <input
                                                ref={el => { campos.current[i] = el; }}
                                                type="text"
                                                value={valor}
                                                disabled={!canManage}
                                                placeholder="Escribe, o inserta un dato del cliente →"
                                                onChange={e => setVariables(prev => prev.map((x, j) => (j === i ? e.target.value : x)))}
                                                className="h-8 min-w-0 flex-1 rounded-lg border border-input bg-card px-2 font-mono text-[11px] focus:outline-none focus:ring-2 focus:ring-ring/50"
                                            />

                                            <InsertarDato
                                                abierto={insertando === i}
                                                catalogo={catalogo}
                                                disabled={!canManage}
                                                onAbrir={() => setInsertando(insertando === i ? null : i)}
                                                onElegir={clave => insertar(i, clave)}
                                            />
                                        </div>

                                        {/* Cómo queda esa variable sola. Es lo
                                            que convierte «[factura.porpagar]»
                                            en «75.000» sin tener que buscarlo
                                            en el párrafo de abajo. */}
                                        <p className="mt-1.5 pl-1 text-[11px] text-muted-foreground">
                                            {valor.trim()
                                                ? <>Queda: <span className="text-foreground">{conEjemplos(valor)}</span></>
                                                : <span className="text-warning">Sin llenar: el mensaje saldría con ese hueco vacío.</span>}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
                            <Button variant="ghost" onClick={onClose}>Cerrar</Button>
                            <Button onClick={guardar} disabled={!canManage || guardando || faltan || variables.length === 0}>
                                {guardando ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <Save className="mr-1.5 size-4" />}
                                Guardar en Integra
                            </Button>
                        </div>

                        {faltan && variables.length > 0 && (
                            <p className="text-right text-[11px] text-muted-foreground">
                                Falta llenar {variables.filter(v => !String(v).trim()).length} variable(s).
                            </p>
                        )}
                      </div>

                      {/* El teléfono, al lado y siempre a la vista: lo que se
                          está llenando a la izquierda se ve llegar aquí. */}
                      <div className="lg:sticky lg:top-0 lg:self-start">
                          <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                              Así le llega al cliente
                          </p>

                          <Celular>
                              <WhatsAppPreview
                                  model={modelo}
                                  verifiedName={datos.meta?.nombre_visible ?? 'Tu empresa'}
                                  bare
                                  minHeight={360}
                              />
                          </Celular>

                          <p className="mt-2 text-center text-[10px] text-muted-foreground">
                              Con datos de ejemplo. En el envío real van los de cada cliente.
                          </p>

                          {formatoEncabezado === 'DOCUMENT' && (
                              <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-success/10 px-2.5 py-2 text-[11px] text-success">
                                  <FileCheck2 className="mt-px size-3.5 shrink-0" />
                                  Esta plantilla lleva el PDF adjunto: el cliente recibe el documento junto al mensaje.
                              </p>
                          )}

                          {formatoEncabezado !== 'DOCUMENT' && (
                              <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-warning/10 px-2.5 py-2 text-[11px] text-warning">
                                  <AlertTriangle className="mt-px size-3.5 shrink-0" />
                                  Sin encabezado de documento: el mensaje llega solo, sin el PDF adjunto.
                              </p>
                          )}
                      </div>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * La maqueta del teléfono.
 *
 * Un párrafo de texto no dice cómo viaja el mensaje: el cliente pregunta si el
 * PDF va adjunto, si su nombre sale arriba o dentro, si el pie se ve. Puesto en
 * un teléfono se responde solo, y es la misma vista que ya se usa al crear
 * plantillas, para que no haya dos ideas distintas de cómo se ve un WhatsApp.
 */
function Celular({ children }) {
    return (
        <div className="mx-auto w-full max-w-[300px] rounded-[2.2rem] border-[7px] border-neutral-800 bg-neutral-800 p-0 shadow-2xl dark:border-neutral-700 dark:bg-neutral-700">
            <div className="relative overflow-hidden rounded-[1.7rem] bg-card">
                {/* La muesca de arriba, que es lo que lo hace leerse como un
                    teléfono y no como una tarjeta más. */}
                <div className="pointer-events-none absolute left-1/2 top-0 z-20 h-[18px] w-24 -translate-x-1/2 rounded-b-2xl bg-neutral-800 dark:bg-neutral-700" />
                {children}
            </div>
        </div>
    );
}

/**
 * El insertador de datos del cliente.
 *
 * Era un `<select>` con veintidós opciones agrupadas: se desplegaba por encima
 * del modal, tapaba lo que estabas llenando, y obligaba a elegir *o* un dato
 * *o* texto tuyo cuando lo que se necesita casi siempre es mezclarlos. Aquí el
 * campo es texto normal y esto sólo pega el dato donde tengas el cursor, con un
 * buscador porque veintidós nombres no se recorren con la vista.
 */
function InsertarDato({ abierto, catalogo, disabled, onAbrir, onElegir }) {
    const [busca, setBusca] = useState('');

    const filtrados = catalogo.filter(c =>
        !busca.trim() || (c.etiqueta + ' ' + c.clave + ' ' + c.grupo).toLowerCase().includes(busca.toLowerCase()),
    );
    const grupos = [...new Set(filtrados.map(c => c.grupo))];

    return (
        <div className="relative shrink-0">
            <button
                type="button"
                disabled={disabled}
                onClick={onAbrir}
                title="Insertar un dato del cliente o de la factura"
                className={cn(
                    'flex h-8 items-center gap-1 rounded-lg border border-input px-2 text-[11px] transition-colors',
                    disabled ? 'cursor-not-allowed text-muted-foreground/50' : 'text-foreground hover:bg-muted',
                    abierto && 'bg-muted',
                )}
            >
                <Plus className="size-3" /> Dato
            </button>

            {abierto && (
                <>
                    {/* Un clic fuera lo cierra sin tocar nada. */}
                    <div className="fixed inset-0 z-10" onClick={onAbrir} />

                    <div className="absolute right-0 z-20 mt-1 w-72 rounded-lg border border-border bg-card p-2 shadow-2xl">
                        <input
                            type="text"
                            value={busca}
                            autoFocus
                            placeholder="Buscar: saldo, vencimiento, cédula…"
                            onChange={e => setBusca(e.target.value)}
                            className="mb-1.5 h-8 w-full rounded-lg border border-input bg-card px-2 text-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                        />

                        <div className="max-h-56 overflow-y-auto">
                            {grupos.length === 0 && (
                                <p className="px-2 py-3 text-center text-[11px] text-muted-foreground">
                                    Ningún dato se llama así.
                                </p>
                            )}

                            {grupos.map(g => (
                                <div key={g} className="mb-1">
                                    <p className="px-1.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                        {g}
                                    </p>
                                    {filtrados.filter(c => c.grupo === g).map(c => (
                                        <button
                                            key={c.clave}
                                            type="button"
                                            onClick={() => onElegir(c.clave)}
                                            className="block w-full rounded px-1.5 py-1 text-left text-xs text-foreground hover:bg-muted"
                                        >
                                            {c.etiqueta}
                                            <span className="ml-1.5 font-mono text-[10px] text-muted-foreground">{c.clave}</span>
                                        </button>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * Un interruptor de encender y apagar, con lo que hace escrito al lado.
 *
 * Una casilla de verificación se lee como «marca esto y luego guarda»; aquí no
 * hay guardar: se enciende y ya está enviándose, o se apaga y deja de enviarse.
 * El interruptor dice eso, la casilla no.
 */
function Interruptor({ titulo, descripcion, activo, ocupado, disabled, onCambiar }) {
    const bloqueado = disabled || ocupado;

    return (
        <div className={cn('flex items-start justify-between gap-4', bloqueado && 'opacity-60')}>
            <div className="min-w-0">
                <p className="flex items-center gap-2 text-sm font-medium text-foreground">
                    {titulo}
                    {ocupado && <Loader2 className="size-3 animate-spin text-muted-foreground" />}
                </p>
                <p className="text-[11px] text-muted-foreground">{descripcion}</p>
            </div>

            <button
                type="button"
                role="switch"
                aria-checked={!!activo}
                aria-label={titulo}
                disabled={bloqueado}
                onClick={() => onCambiar(!activo)}
                className={cn(
                    'relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
                    bloqueado ? 'cursor-not-allowed' : 'cursor-pointer',
                    activo ? 'bg-primary' : 'bg-muted-foreground/30',
                )}
            >
                {/* La bolita va en el flujo, no en `absolute`.
                    Estaba posicionada de forma absoluta sin `left`, así que
                    partía de su posición estática —que un botón centra— en vez
                    del borde izquierdo: las dos posiciones salían unos 12px
                    corridas a la derecha y, encendido, se salía del carril.
                    Con `inline-flex` en el carril, el desplazamiento se cuenta
                    desde la izquierda y los 22px dejan el mismo margen de 2px a
                    cada lado. Es el mismo patrón que ya usan los de Ajustes. */}
                <span
                    className={cn(
                        'inline-block size-5 rounded-full bg-white shadow transition-transform',
                        activo ? 'translate-x-[22px]' : 'translate-x-0.5',
                    )}
                />
            </button>
        </div>
    );
}

/**
 * Por qué línea está enviando Integra, y desde cuándo no lo hace.
 *
 * El CRM e Integra 2.0 son dos sistemas con dos bases de datos, unidos por una
 * sola cadena de texto: el `phone_number_id` de la línea. Hasta hoy no había
 * ninguna pantalla donde comprobar si esa unión estaba viva —para saberlo había
 * que contar mensajes con `incoming_invoice_id` en la base de datos—, así que
 * la pregunta «¿está conectado de verdad?» no tenía respuesta que enseñarle a
 * un cliente (9-sep-2026).
 */
function LineasDelErp({ showToast, canManage }) {
    const { lineasDelErp = [], lineaElegida = false } = usePage().props;
    const [guardando, setGuardando] = useState(null);
    // Qué línea se pidió cambiar y por qué no se pudo. Va por línea y no suelto
    // arriba: el motivo se lee al lado del botón que lo provocó.
    const [rechazo, setRechazo] = useState(null);
    // La confirmación, también en la fila. Cambiar de línea mueve la
    // facturación de toda la empresa a otro número; no es un clic de ida.
    const [confirmando, setConfirmando] = useState(null);

    if (lineasDelErp.length === 0) return null;

    const activas = lineasDelErp.filter(l => l.ultima_vez);
    const actual = lineasDelErp.find(l => l.es_la_del_erp);

    /**
     * El cambio se pide con axios, no con `router.post`.
     *
     * Con Inertia el rechazo sólo llegaba recargando la página entera, y esa
     * recarga devolvía al cliente a la galería de complementos: pulsabas «Usar
     * esta», salías de la pantalla, y tenías que volver a entrar para leer por
     * qué no había pasado nada. Así el motivo aparece en la misma fila y sin
     * moverse; al salir bien se refrescan sólo las dos props que cambian, con
     * el estado de la pantalla intacto.
     */
    const elegir = async (id) => {
        setGuardando(id);
        setRechazo(null);

        try {
            const { data } = await axios.post('/integrations/linea-erp', { instance_id: id });
            setConfirmando(null);
            showToast?.(data.message ?? 'Listo: el ERP enviará por esa línea.');
            router.reload({
                only: ['lineasDelErp', 'lineaElegida'],
                preserveScroll: true,
                preserveState: true,
            });
        } catch (e) {
            setRechazo({
                id,
                motivo: e.response?.data?.message ?? 'No se pudo cambiar la línea.',
                faltan: e.response?.data?.faltan ?? [],
            });
        } finally {
            setGuardando(null);
        }
    };

    return (
        <Panel
            title="Por dónde envía Integra"
            Icon={Plug}
            does="La línea por la que tu software administrativo manda facturas y recibos."
        >
            {lineasDelErp.length > 1 && !lineaElegida && (
                <p className="mb-3 flex items-start gap-1.5 rounded-lg bg-warning/10 px-2.5 py-2 text-[11px] text-warning">
                    <AlertTriangle className="mt-px size-3.5 shrink-0" />
                    Tienes más de una línea y ninguna está elegida, así que Integra usa la primera
                    que encuentra. Elige cuál debe usar.
                </p>
            )}

            <ul className="divide-y divide-border">
                {lineasDelErp.map(linea => (
                    <li key={linea.id} className="py-2.5 first:pt-0 last:pb-0">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-foreground">
                                    {linea.nombre}
                                    {linea.numero && <span className="ml-2 text-xs font-normal text-muted-foreground">{linea.numero}</span>}
                                </p>
                                <p className="truncate font-mono text-[11px] text-muted-foreground">{linea.phone_number_id}</p>
                            </div>

                            <div className="flex items-center gap-2">
                                {linea.ultima_vez ? (
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-semibold text-success">
                                        <CheckCircle2 className="size-3" />
                                        {formatearUltimaVez(linea.ultima_vez)}
                                    </span>
                                ) : (
                                    <span className="rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">
                                        Sin envíos
                                    </span>
                                )}

                                {linea.es_la_del_erp ? (
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-primary/15 px-2.5 py-1 text-[11px] font-black text-accent-foreground">
                                        <CheckCircle2 className="size-3" />
                                        {lineaElegida ? 'Envía por aquí' : 'Por defecto'}
                                    </span>
                                ) : confirmando === linea.id ? (
                                    <>
                                        <Button
                                            size="sm"
                                            disabled={guardando !== null}
                                            onClick={() => elegir(linea.id)}
                                            className="h-7 px-2.5 text-[11px]"
                                        >
                                            {guardando === linea.id
                                                ? <><Loader2 className="mr-1 size-3 animate-spin" /> Cambiando…</>
                                                : 'Sí, cambiar'}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={guardando !== null}
                                            onClick={() => { setConfirmando(null); setRechazo(null); }}
                                            className="h-7 px-2.5 text-[11px]"
                                        >
                                            Cancelar
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={!canManage || guardando !== null}
                                        onClick={() => { setConfirmando(linea.id); setRechazo(null); }}
                                        className="h-7 px-2.5 text-[11px]"
                                    >
                                        Usar esta
                                    </Button>
                                )}
                            </div>
                        </div>

                        {/* Qué va a pasar, antes de que pase. Integra pregunta
                            la línea antes de cada tanda, así que el cambio se
                            nota en la siguiente factura de toda la empresa. */}
                        {confirmando === linea.id && !rechazo && (
                            <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-warning/10 px-2.5 py-2 text-[11px] text-warning">
                                <AlertTriangle className="mt-px size-3.5 shrink-0" />
                                <span>
                                    Integra dejará de enviar por {actual?.numero || actual?.nombre || 'la línea de ahora'} y
                                    pasará a {linea.numero || linea.nombre}. Vale desde la próxima factura, para toda la empresa.
                                </span>
                            </p>
                        )}

                        {rechazo?.id === linea.id && (
                            <div className="mt-2 rounded-lg bg-destructive/10 px-2.5 py-2 text-[11px] text-destructive">
                                <p className="flex items-start gap-1.5">
                                    <AlertTriangle className="mt-px size-3.5 shrink-0" />
                                    <span>
                                        No se cambió nada: Integra sigue enviando por
                                        {' '}{actual?.numero || actual?.nombre || 'la línea de ahora'}.
                                    </span>
                                </p>

                                {rechazo.faltan.length > 0 ? (
                                    <>
                                        <p className="mt-1.5 pl-5">
                                            A esa línea le faltan {rechazo.faltan.length} plantilla{rechazo.faltan.length === 1 ? '' : 's'} de
                                            las que se están usando hoy. Los catálogos de Meta son por número, así que no se heredan:
                                            si cambias sin copiarlas, las facturas dejan de salir.
                                        </p>
                                        <ul className="mt-1.5 flex flex-wrap gap-1 pl-5">
                                            {rechazo.faltan.map(nombre => (
                                                <li key={nombre} className="rounded bg-destructive/15 px-1.5 py-0.5 font-mono text-[10px]">
                                                    {nombre}
                                                </li>
                                            ))}
                                        </ul>
                                        <a
                                            href="/templates"
                                            className="mt-2 ml-5 inline-flex items-center gap-1 rounded-lg border border-destructive/30 px-2 py-1 text-[11px] font-medium hover:bg-destructive/10"
                                        >
                                            <Copy className="size-3" /> Copiarlas a esta línea
                                        </a>
                                    </>
                                ) : (
                                    <p className="mt-1.5 pl-5">{rechazo.motivo}</p>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            <p className="mt-3 text-[11px] text-muted-foreground">
                Integra pregunta cuál usar antes de cada tanda de envíos, así que el cambio vale
                desde la siguiente factura. No hay que tocar nada del otro lado.
            </p>

            {/* Con qué credencial entra dice si ese cliente ya se puede migrar
                al token de verdad: el phone_number_id no es un secreto, se
                enseña en la pantalla de Instancias y en el panel de Meta. */}
            {activas.some(l => l.credencial === 'phone_number_id') && (
                <p className="mt-3 flex items-start gap-1.5 rounded-lg bg-warning/10 px-2.5 py-2 text-[11px] text-warning">
                    <AlertTriangle className="mt-px size-3.5 shrink-0" />
                    Integra entra con el identificador del número, que no es un secreto: se ve en la
                    pantalla de Instancias y en el panel de Meta. Cuando puedas, cámbialo por un token.
                </p>
            )}
        </Panel>
    );
}

/** «hace 5 minutos» dice más que una fecha con hora y segundos. */
function formatearUltimaVez(iso) {
    const minutos = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutos < 2) return 'ahora mismo';
    if (minutos < 60) return `hace ${minutos} min`;

    const horas = Math.floor(minutos / 60);
    if (horas < 24) return `hace ${horas} h`;

    const dias = Math.floor(horas / 24);
    if (dias === 1) return 'ayer';
    if (dias < 30) return `hace ${dias} días`;

    return new Date(iso).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' });
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
            <div className={`flex items-start gap-3 rounded-xl p-4 ${ok ? 'bg-success/10 border border-success/30' : 'bg-destructive/10 border border-destructive/30'}`}>
                {ok ? <CheckCircle2 className="size-6 text-success shrink-0" /> : <XCircle className="size-6 text-destructive shrink-0" />}
                <div className="min-w-0">
                    <p className={`font-semibold ${ok ? 'text-success' : 'text-destructive'}`}>
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
        // Si el token no lo autoriza nace apagado, y no marcado-pero-deshabilitado:
        // así no queda una casilla que el admin no puede desmarcar bloqueándole
        // el guardado del resto del formulario.
        emit_electronic_invoice: integration.can_emit_electronic === false
            ? false
            : (integration.emit_electronic_invoice ?? false),
    });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const prefix = form.trigger_type === 'at' ? '@' : '/';
    // true = el token lo autoriza · false = no · null = no lo sabemos.
    const canEmit = integration.can_emit_electronic;

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
                                        isActive ? 'border-primary/30 bg-primary/5' : 'border-border/60 hover:border-border'
                                    }`}
                                >
                                    <span className={`flex items-center justify-center size-9 rounded-lg font-mono text-lg font-bold ${
                                        isActive ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                                    }`}>{opt.symbol}</span>
                                    <div>
                                        <p className={`text-sm font-semibold ${isActive ? 'text-accent-foreground' : 'text-foreground'}`}>{opt.label}</p>
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
                        Los agentes escribirán <code className="font-mono font-semibold text-accent-foreground">{prefix}{form.trigger_command || 'pagos'}</code> en el chat para abrir el formulario de pago.
                    </p>
                </Field>

                <div>
                    <p className="text-sm font-medium text-foreground mb-2">Facturación electrónica</p>
                    <label className={`flex items-start gap-3 rounded-xl border border-border/60 p-4 transition-colors ${
                        canEmit === false ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer hover:bg-muted/30'
                    }`}>
                        <input
                            type="checkbox"
                            disabled={canEmit === false}
                            checked={form.emit_electronic_invoice}
                            onChange={e => setForm(f => ({ ...f, emit_electronic_invoice: e.target.checked }))}
                            className="size-4 mt-0.5 rounded border-input accent-primary disabled:cursor-not-allowed"
                        />
                        <div>
                            <p className="text-sm font-medium text-foreground">Emitir a la DIAN al registrar el pago</p>
                            <p className="text-xs text-muted-foreground">
                                Integra convierte la factura estándar en electrónica y la emite a la DIAN
                                en el mismo momento en que se registra el pago.
                            </p>
                        </div>
                    </label>

                    {/* El token no trae el permiso: encender esto no haría nada. */}
                    {canEmit === false && (
                        <p className="flex items-start gap-1.5 text-[11px] text-muted-foreground mt-2">
                            <AlertTriangle className="size-3.5 shrink-0 mt-px" />
                            El token de esta empresa no autoriza emitir a la DIAN. Reconecta la
                            integración arriba para pedir uno nuevo con ese permiso.
                        </p>
                    )}

                    {/* Token pegado a mano o conexión anterior: no sabemos qué autoriza. */}
                    {canEmit == null && form.emit_electronic_invoice && (
                        <p className="flex items-start gap-1.5 text-[11px] text-muted-foreground mt-2">
                            <AlertTriangle className="size-3.5 shrink-0 mt-px" />
                            No sabemos si este token autoriza emitir a la DIAN. Si conectaste esta
                            integración antes de que existiera ese permiso, reconéctala para pedirlo.
                        </p>
                    )}

                    {canEmit !== false && form.emit_electronic_invoice && (
                        <p className="flex items-start gap-1.5 text-[11px] text-warning mt-2">
                            <FileCheck2 className="size-3.5 shrink-0 mt-px" />
                            Cada pago desde el chat emitirá un documento fiscal real. Una factura ya
                            emitida no se puede deshacer desde aquí: se corrige con nota crédito en Integra.
                        </p>
                    )}
                </div>

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
            <div className="flex items-start gap-3 rounded-xl bg-success/10 border border-success/30 p-4">
                <CheckCircle2 className="size-6 text-success shrink-0" />
                <div className="min-w-0">
                    <p className="font-semibold text-success">Conectado a Integra</p>
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
                            className="h-full bg-primary transition-all"
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
