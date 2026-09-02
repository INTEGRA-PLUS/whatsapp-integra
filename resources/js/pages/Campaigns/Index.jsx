import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Megaphone, Send, Trash2, Eye, RefreshCw, X, Search, Loader2, CalendarClock, FileText, Upload, Paperclip } from 'lucide-react';

const STATUS_LABEL = {
    draft: 'Borrador',
    queued: 'En cola',
    sending: 'Enviando',
    completed: 'Completada',
    cancelled: 'Cancelada',
    failed: 'Fallida',
};

const STATUS_CLASS = {
    draft: 'bg-muted text-muted-foreground',
    queued: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    sending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    cancelled: 'bg-muted text-muted-foreground',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
};

const DAY_OPTIONS = [
    { key: 'mon', label: 'Lun' },
    { key: 'tue', label: 'Mar' },
    { key: 'wed', label: 'Mié' },
    { key: 'thu', label: 'Jue' },
    { key: 'fri', label: 'Vie' },
    { key: 'sat', label: 'Sáb' },
    { key: 'sun', label: 'Dom' },
];

const DAY_LABEL = Object.fromEntries(DAY_OPTIONS.map(d => [d.key, d.label]));

// ── Plantillas ───────────────────────────────────────────────────────────────
const MEDIA_HEADERS = ['IMAGE', 'VIDEO', 'DOCUMENT'];
const HEADER_ACCEPT = {
    IMAGE: 'image/jpeg,image/png',
    VIDEO: 'video/mp4,video/3gpp',
    DOCUMENT: 'application/pdf',
};
const HEADER_LABEL = { IMAGE: 'Imagen', VIDEO: 'Video', DOCUMENT: 'Documento', LOCATION: 'Ubicación' };

// Tokens que el backend sustituye por los datos de cada destinatario al enviar.
const TOKENS = [
    { token: '{{nombre}}', label: 'Nombre' },
    { token: '{{telefono}}', label: 'Teléfono' },
];

function templateBody(t) {
    return (t?.components || []).find(c => (c.type || '').toUpperCase() === 'BODY')?.text ?? '';
}

// IMAGE/VIDEO/DOCUMENT/LOCATION, o null si el encabezado es de texto o no existe.
function templateHeaderFormat(t) {
    const h = (t?.components || []).find(c => (c.type || '').toUpperCase() === 'HEADER');
    const f = (h?.format || '').toUpperCase();
    return [...MEDIA_HEADERS, 'LOCATION'].includes(f) ? f : null;
}

function countVars(text) {
    const m = (text || '').match(/{{\s*\d+\s*}}/g);
    return m ? new Set(m.map(x => x.replace(/\D/g, ''))).size : 0;
}

function fillVars(text, vars) {
    return (text || '').replace(/{{\s*(\d+)\s*}}/g, (_, n) => vars[Number(n) - 1] || `{{${n}}}`);
}

const emptyForm = {
    name: '',
    instance_id: '',
    message_type: 'text',
    message: '',
    template: null,       // plantilla completa tal como la devuelve Meta
    template_vars: [],    // valor de cada {{n}} del cuerpo (admite tokens)
    header: null,         // { format, path, filename, mime_type, lat, lng, ... }
    contacts: [],
    launch_now: false,
    schedule_type: 'manual',
    schedule_days: [],
    schedule_time: '09:00',
};

export default function CampaignsIndex({ campaigns, instances }) {
    const [showCreate, setShowCreate] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});

    const isTemplate = form.message_type === 'template';

    function handleCreate(e) {
        e.preventDefault();
        const isRecurring = form.schedule_type === 'recurring';
        const payload = {
            name: form.name,
            instance_id: form.instance_id,
            message_type: form.message_type,
            message: isTemplate ? null : form.message,
            launch_now: !isRecurring && form.launch_now,
            contact_ids: form.contacts.filter(c => c.id != null).map(c => c.id),
            manual_recipients: form.contacts
                .filter(c => c.id == null)
                .map(c => ({ phone: c.phone_number, name: c.name ?? null })),
            schedule_type: form.schedule_type,
            schedule_days: isRecurring ? form.schedule_days : [],
            schedule_time: isRecurring ? form.schedule_time : null,
        };

        if (isTemplate) {
            if (!form.template) { setErrors({ template_name: 'Selecciona una plantilla.' }); return; }
            payload.template_name = form.template.name;
            payload.template_language = form.template.language;
            payload.template_payload = {
                body_vars: form.template_vars,
                header: form.header
                    ? {
                        format: form.header.format,
                        path: form.header.path ?? null,
                        filename: form.header.filename ?? null,
                        mime_type: form.header.mime_type ?? null,
                        lat: form.header.lat || null,
                        lng: form.header.lng || null,
                        name: form.header.name || null,
                        address: form.header.address || null,
                    }
                    : null,
            };
        }

        router.post(route('campaigns.store'), payload, {
            onSuccess: () => { setShowCreate(false); setForm(emptyForm); setErrors({}); },
            onError: (err) => setErrors(err),
        });
    }

    // Al elegir plantilla se reinicia el relleno: el número de variables y el
    // tipo de encabezado son distintos en cada una.
    function pickTemplate(tpl) {
        const format = templateHeaderFormat(tpl);
        setForm(f => ({
            ...f,
            template: tpl,
            template_vars: Array.from({ length: countVars(templateBody(tpl)) }, () => ''),
            header: format
                ? { format, path: '', filename: '', mime_type: '', lat: '', lng: '', name: '', address: '' }
                : null,
        }));
    }

    function toggleDay(day) {
        setForm(f => ({
            ...f,
            schedule_days: f.schedule_days.includes(day)
                ? f.schedule_days.filter(d => d !== day)
                : [...f.schedule_days, day],
        }));
    }

    function handleSend(campaign) {
        if (!confirm(`Iniciar el envío de "${campaign.name}" a ${campaign.total_recipients} destinatarios?`)) return;
        router.post(route('campaigns.send', campaign.id));
    }

    function handleDelete(campaign) {
        if (!confirm(`¿Eliminar la campaña "${campaign.name}"?`)) return;
        router.delete(route('campaigns.destroy', campaign.id));
    }

    return (
        <>
            <Head title="Campañas" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Campañas masivas</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Envía un mismo mensaje a una lista de contactos. El mensaje libre solo llega dentro de la
                            sesión de 24 horas; para escribirle a quien no ha respondido, usa una plantilla aprobada.
                        </p>
                    </div>
                    <Button onClick={() => setShowCreate(true)} className="gap-2" disabled={instances.length === 0}>
                        <Plus className="size-4" /> Nueva campaña
                    </Button>
                </div>

                {instances.length === 0 && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                        Necesitas al menos una instancia activa para crear campañas.
                    </div>
                )}

                {campaigns.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <Megaphone className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">Aún no tienes campañas</p>
                        <p className="text-sm text-muted-foreground mt-1">Crea una para empezar a enviar mensajes a varios contactos a la vez.</p>
                    </div>
                ) : (
                    <div className="rounded-xl border bg-card overflow-hidden">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th className="text-left px-4 py-3 font-medium">Nombre</th>
                                    <th className="text-left px-4 py-3 font-medium">Instancia</th>
                                    <th className="text-left px-4 py-3 font-medium">Estado</th>
                                    <th className="text-left px-4 py-3 font-medium">Progreso</th>
                                    <th className="text-left px-4 py-3 font-medium">Creada</th>
                                    <th className="text-right px-4 py-3 font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {campaigns.map(c => (
                                    <tr key={c.id} className="border-t hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1.5">
                                                <span className="font-medium text-foreground">{c.name}</span>
                                                {c.message_type === 'template' && (
                                                    <span
                                                        title={c.template_name}
                                                        className="inline-flex items-center gap-1 rounded-full bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 px-2 py-0.5 text-[11px] font-medium"
                                                    >
                                                        <FileText className="size-3" /> Plantilla
                                                    </span>
                                                )}
                                            </div>
                                            {c.schedule_type === 'recurring' && (
                                                <div className="mt-0.5 inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
                                                    <CalendarClock className="size-3.5" />
                                                    {(c.schedule_days ?? []).map(d => DAY_LABEL[d] ?? d).join(', ')} · {(c.schedule_time ?? '').slice(0, 5)}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{c.instance?.name ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_CLASS[c.status] ?? 'bg-muted'}`}>
                                                {STATUS_LABEL[c.status] ?? c.status}
                                            </span>
                                            {c.schedule_type === 'recurring' && c.next_run_at && (
                                                <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                    Próximo: {new Date(c.next_run_at).toLocaleString()}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            <div className="flex items-center gap-3">
                                                <span>{c.sent_count}/{c.total_recipients}</span>
                                                {c.failed_count > 0 && <span className="text-red-600 dark:text-red-400">({c.failed_count} fallos)</span>}
                                            </div>
                                            <div className="mt-1 h-1.5 w-32 rounded-full bg-muted overflow-hidden">
                                                <div
                                                    className="h-full bg-green-500"
                                                    style={{ width: `${c.total_recipients > 0 ? (c.sent_count / c.total_recipients) * 100 : 0}%` }}
                                                />
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground text-xs">
                                            {new Date(c.created_at).toLocaleString()}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-1">
                                                <Link href={route('campaigns.show', c.id)}>
                                                    <Button variant="outline" size="sm" className="gap-1.5">
                                                        <Eye className="size-3.5" /> Ver
                                                    </Button>
                                                </Link>
                                                {c.schedule_type !== 'recurring' && (c.status === 'draft' || c.status === 'failed') && (
                                                    <Button variant="outline" size="sm" className="gap-1.5" onClick={() => handleSend(c)}>
                                                        {c.status === 'failed' ? <RefreshCw className="size-3.5" /> : <Send className="size-3.5" />}
                                                        {c.status === 'failed' ? 'Reintentar' : 'Enviar'}
                                                    </Button>
                                                )}
                                                {!['queued', 'sending'].includes(c.status) && (
                                                    <Button variant="outline" size="sm" className="text-destructive hover:bg-destructive/10" onClick={() => handleDelete(c)}>
                                                        <Trash2 className="size-3.5" />
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
            </div>

            {showCreate && (
                <Modal title="Nueva campaña" description="Define el mensaje, los destinatarios y la instancia desde donde se enviará." onClose={() => setShowCreate(false)}>
                    <form onSubmit={handleCreate} className="space-y-4">
                        <Field label="Nombre" value={form.name} onChange={v => setForm(f => ({ ...f, name: v }))} required placeholder="Ej: Promo abril" error={errors.name} />

                        <div className="space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Instancia</label>
                            <select
                                value={form.instance_id}
                                onChange={e => setForm(f => ({ ...f, instance_id: e.target.value, contacts: [], template: null, template_vars: [], header: null }))}
                                required
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                            >
                                <option value="">Selecciona una instancia</option>
                                {instances.map(i => (
                                    <option key={i.id} value={i.id}>
                                        {i.name}{i.display_phone_number ? ` — ${i.display_phone_number}` : ''}
                                    </option>
                                ))}
                            </select>
                            {errors.instance_id && <p className="text-xs text-red-600">{errors.instance_id}</p>}
                        </div>

                        <div className="space-y-2 rounded-md border border-input p-3">
                            <p className="text-sm font-medium text-foreground">Tipo de mensaje</p>
                            <div className="flex gap-4 text-sm">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="message_type"
                                        checked={!isTemplate}
                                        onChange={() => setForm(f => ({ ...f, message_type: 'text' }))}
                                        className="accent-green-600"
                                    />
                                    Mensaje libre
                                </label>
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="message_type"
                                        checked={isTemplate}
                                        onChange={() => setForm(f => ({ ...f, message_type: 'template' }))}
                                        className="accent-green-600"
                                    />
                                    Plantilla aprobada
                                </label>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {isTemplate
                                    ? 'Una plantilla aprobada llega aunque el cliente no haya escrito antes: es la única forma de abrir conversación.'
                                    : 'El mensaje libre solo llega a quien haya escrito en las últimas 24 horas. Al resto WhatsApp lo rechaza.'}
                            </p>
                        </div>

                        {isTemplate ? (
                            <TemplateComposer
                                instanceId={form.instance_id}
                                template={form.template}
                                vars={form.template_vars}
                                header={form.header}
                                onPick={pickTemplate}
                                onVarsChange={vars => setForm(f => ({ ...f, template_vars: vars }))}
                                onHeaderChange={header => setForm(f => ({ ...f, header }))}
                                errors={errors}
                            />
                        ) : (
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium text-foreground">Mensaje</label>
                                <textarea
                                    value={form.message}
                                    onChange={e => setForm(f => ({ ...f, message: e.target.value }))}
                                    required
                                    rows={4}
                                    placeholder="Hola 👋, queríamos contarte..."
                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                />
                                {errors.message && <p className="text-xs text-red-600">{errors.message}</p>}
                            </div>
                        )}

                        <ContactPicker
                            instanceId={form.instance_id}
                            selected={form.contacts}
                            onChange={contacts => setForm(f => ({ ...f, contacts }))}
                            error={errors.contact_ids || errors['contact_ids.0']}
                        />

                        <div className="space-y-2 rounded-md border border-input p-3">
                            <p className="text-sm font-medium text-foreground">Programación</p>
                            <div className="flex gap-4 text-sm">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="schedule_type"
                                        value="manual"
                                        checked={form.schedule_type === 'manual'}
                                        onChange={() => setForm(f => ({ ...f, schedule_type: 'manual' }))}
                                        className="accent-green-600"
                                    />
                                    Envío único
                                </label>
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="schedule_type"
                                        value="recurring"
                                        checked={form.schedule_type === 'recurring'}
                                        onChange={() => setForm(f => ({ ...f, schedule_type: 'recurring' }))}
                                        className="accent-green-600"
                                    />
                                    Recurrente por día
                                </label>
                            </div>

                            {form.schedule_type === 'recurring' && (
                                <div className="space-y-2 pt-2">
                                    <div>
                                        <p className="text-xs font-medium text-muted-foreground mb-1.5">Días de la semana</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {DAY_OPTIONS.map(d => {
                                                const active = form.schedule_days.includes(d.key);
                                                return (
                                                    <button
                                                        key={d.key}
                                                        type="button"
                                                        onClick={() => toggleDay(d.key)}
                                                        className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${active ? 'bg-green-600 text-white border-green-600' : 'bg-transparent text-foreground border-input hover:bg-muted'}`}
                                                    >
                                                        {d.label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        {errors.schedule_days && <p className="text-xs text-red-600 mt-1">{errors.schedule_days}</p>}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <label className="text-xs font-medium text-muted-foreground">Hora</label>
                                        <input
                                            type="time"
                                            value={form.schedule_time}
                                            onChange={e => setForm(f => ({ ...f, schedule_time: e.target.value }))}
                                            className="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                        />
                                        {errors.schedule_time && <p className="text-xs text-red-600">{errors.schedule_time}</p>}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        La campaña se enviará a la lista completa cada uno de los días seleccionados a la hora indicada.
                                    </p>
                                </div>
                            )}
                        </div>

                        {form.schedule_type === 'manual' && (
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={form.launch_now}
                                    onChange={e => setForm(f => ({ ...f, launch_now: e.target.checked }))}
                                    className="rounded border-input size-4 accent-green-600"
                                />
                                <span className="text-sm text-foreground">Iniciar envío inmediatamente</span>
                            </label>
                        )}

                        <div className="flex gap-2 pt-2">
                            <Button type="submit" className="flex-1">Crear campaña</Button>
                            <Button type="button" variant="outline" onClick={() => setShowCreate(false)}>Cancelar</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}

/**
 * Selección y relleno de una plantilla aprobada. Las variables admiten los
 * tokens {{nombre}} y {{telefono}}, que el backend sustituye por los datos de
 * cada destinatario en el momento del envío: son el motivo de que una campaña
 * de plantilla pueda seguir siendo personalizada.
 */
function TemplateComposer({ instanceId, template, vars, header, onPick, onVarsChange, onHeaderChange, errors }) {
    const [templates, setTemplates] = useState([]);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState('');
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState('');
    const varRefs = useRef([]);

    useEffect(() => {
        if (!instanceId) { setTemplates([]); return; }
        let active = true;
        setLoading(true);
        setLoadError('');
        axios.get(route('campaigns.templates'), { params: { instance_id: instanceId } })
            .then(res => {
                if (!active) return;
                const approved = (res.data.data ?? []).filter(t => (t.status || '').toUpperCase() === 'APPROVED');
                setTemplates(approved);
                if (approved.length === 0) {
                    setLoadError(res.data.message || 'Esta instancia no tiene plantillas aprobadas.');
                }
            })
            .catch(() => active && setLoadError('No se pudieron cargar las plantillas.'))
            .finally(() => active && setLoading(false));
        return () => { active = false; };
    }, [instanceId]);

    // Inserta el token en la posición del cursor, para poder combinarlo con
    // texto fijo ("Hola {{nombre}}, tu cita es el lunes").
    function insertToken(index, token) {
        const el = varRefs.current[index];
        const current = vars[index] ?? '';
        const at = el ? el.selectionStart ?? current.length : current.length;
        const next = current.slice(0, at) + token + current.slice(el ? el.selectionEnd ?? at : at);
        onVarsChange(vars.map((v, i) => (i === index ? next : v)));
        requestAnimationFrame(() => {
            if (!el) return;
            el.focus();
            el.setSelectionRange(at + token.length, at + token.length);
        });
    }

    async function uploadHeaderFile(file) {
        if (!file) return;
        setUploading(true);
        setUploadError('');
        try {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('instance_id', instanceId);
            fd.append('format', header.format);
            const res = await axios.post(route('campaigns.template-media'), fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            onHeaderChange({
                ...header,
                path: res.data.path,
                filename: res.data.filename,
                mime_type: res.data.mime_type,
            });
        } catch (err) {
            setUploadError(err?.response?.data?.error || 'No se pudo subir el archivo.');
        } finally {
            setUploading(false);
        }
    }

    const body = templateBody(template);
    // Vista previa con un destinatario de ejemplo, para que se vea qué hacen los tokens.
    const preview = useMemo(() => {
        const sample = vars.map(v => (v || '')
            .replaceAll('{{nombre}}', 'Daniela Galindo')
            .replaceAll('{{telefono}}', '573245637786'));
        return fillVars(body, sample);
    }, [body, vars]);

    if (!instanceId) {
        return (
            <p className="rounded-md border border-dashed px-3 py-4 text-sm text-muted-foreground text-center">
                Selecciona una instancia para ver sus plantillas.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground flex items-center gap-1.5">
                    <FileText className="size-3.5" /> Plantilla
                </label>
                <div className="relative">
                    <select
                        value={template ? `${template.name}::${template.language}` : ''}
                        onChange={e => {
                            const [name, language] = e.target.value.split('::');
                            onPick(templates.find(t => t.name === name && t.language === language) ?? null);
                        }}
                        disabled={loading || templates.length === 0}
                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60"
                    >
                        <option value="">{loading ? 'Cargando plantillas…' : 'Selecciona una plantilla aprobada'}</option>
                        {templates.map(t => (
                            <option key={`${t.name}::${t.language}`} value={`${t.name}::${t.language}`}>
                                {t.name} ({t.language})
                            </option>
                        ))}
                    </select>
                    {loading && <Loader2 className="absolute right-8 top-1/2 -translate-y-1/2 size-4 text-muted-foreground animate-spin" />}
                </div>
                {loadError && <p className="text-xs text-amber-600">{loadError}</p>}
                {errors.template_name && <p className="text-xs text-red-600">{errors.template_name}</p>}
            </div>

            {template && (
                <>
                    {header && (
                        <div className="space-y-1.5 rounded-md border border-input p-3">
                            <p className="text-sm font-medium text-foreground flex items-center gap-1.5">
                                <Paperclip className="size-3.5" /> Encabezado · {HEADER_LABEL[header.format]}
                            </p>

                            {header.format === 'LOCATION' ? (
                                <div className="grid grid-cols-2 gap-2">
                                    <input type="text" inputMode="decimal" placeholder="Latitud" value={header.lat}
                                        onChange={e => onHeaderChange({ ...header, lat: e.target.value })}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm" />
                                    <input type="text" inputMode="decimal" placeholder="Longitud" value={header.lng}
                                        onChange={e => onHeaderChange({ ...header, lng: e.target.value })}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm" />
                                    <input type="text" placeholder="Nombre del lugar (opcional)" value={header.name}
                                        onChange={e => onHeaderChange({ ...header, name: e.target.value })}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm" />
                                    <input type="text" placeholder="Dirección (opcional)" value={header.address}
                                        onChange={e => onHeaderChange({ ...header, address: e.target.value })}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm" />
                                </div>
                            ) : (
                                <div className="flex items-center gap-2">
                                    <label className="inline-flex items-center gap-1.5 rounded-md border border-input px-3 h-9 text-sm cursor-pointer hover:bg-muted">
                                        {uploading ? <Loader2 className="size-3.5 animate-spin" /> : <Upload className="size-3.5" />}
                                        {header.path ? 'Cambiar archivo' : 'Subir archivo'}
                                        <input
                                            type="file"
                                            accept={HEADER_ACCEPT[header.format]}
                                            className="hidden"
                                            disabled={uploading}
                                            onChange={e => uploadHeaderFile(e.target.files?.[0])}
                                        />
                                    </label>
                                    {header.filename && (
                                        <span className="text-xs text-muted-foreground truncate">{header.filename}</span>
                                    )}
                                </div>
                            )}

                            {uploadError && <p className="text-xs text-red-600">{uploadError}</p>}
                            {errors.template_header && <p className="text-xs text-red-600">{errors.template_header}</p>}
                            <p className="text-xs text-muted-foreground">
                                El archivo se guarda y se vuelve a subir a WhatsApp en cada envío, así que las
                                campañas recurrentes no se rompen cuando caduca.
                            </p>
                        </div>
                    )}

                    {vars.length > 0 && (
                        <div className="space-y-2 rounded-md border border-input p-3">
                            <p className="text-sm font-medium text-foreground">Variables del mensaje</p>
                            {vars.map((v, i) => (
                                <div key={i} className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-mono text-muted-foreground w-10 shrink-0">{`{{${i + 1}}}`}</span>
                                        <input
                                            ref={el => { varRefs.current[i] = el; }}
                                            type="text"
                                            value={v}
                                            onChange={e => onVarsChange(vars.map((x, j) => (j === i ? e.target.value : x)))}
                                            placeholder="Texto fijo o token"
                                            className="h-9 flex-1 rounded-md border border-input bg-transparent px-3 text-sm"
                                        />
                                    </div>
                                    <div className="flex gap-1.5 pl-12">
                                        {TOKENS.map(t => (
                                            <button
                                                key={t.token}
                                                type="button"
                                                onClick={() => insertToken(i, t.token)}
                                                className="rounded-full border border-input px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-muted"
                                            >
                                                + {t.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                            <p className="text-xs text-muted-foreground">
                                Los tokens se reemplazan por los datos de cada destinatario. Si un contacto no
                                tiene nombre guardado, {'{{nombre}}'} se envía como «cliente».
                            </p>
                            {errors.template_payload && <p className="text-xs text-red-600">{errors.template_payload}</p>}
                        </div>
                    )}

                    <div className="rounded-md bg-muted/40 border border-input p-3">
                        <p className="text-xs font-medium text-muted-foreground mb-1">Vista previa (ejemplo)</p>
                        <p className="text-sm whitespace-pre-wrap text-foreground">{preview || '—'}</p>
                    </div>
                </>
            )}
        </div>
    );
}

function ContactPicker({ instanceId, selected, onChange, error }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const wrapRef = useRef(null);
    const debounceRef = useRef(null);

    useEffect(() => {
        function onClickOutside(e) {
            if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', onClickOutside);
        return () => document.removeEventListener('mousedown', onClickOutside);
    }, []);

    useEffect(() => {
        if (!instanceId) { setResults([]); return; }
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            setLoading(true);
            const url = route('campaigns.contacts.search', { instance_id: instanceId, q: query });
            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : { contacts: [] })
                .then(data => setResults(data.contacts ?? []))
                .catch(() => setResults([]))
                .finally(() => setLoading(false));
        }, 200);
        return () => debounceRef.current && clearTimeout(debounceRef.current);
    }, [query, instanceId]);

    const selectedIds = new Set(selected.filter(c => c.id != null).map(c => c.id));
    const selectedPhones = new Set(selected.map(c => c.phone_number));
    const filtered = results.filter(r => !selectedIds.has(r.id));
    const disabled = !instanceId;

    const queryDigits = (query.match(/\d/g) ?? []).join('');
    const canAddManual =
        !disabled &&
        queryDigits.length >= 7 &&
        !selectedPhones.has(queryDigits) &&
        !results.some(r => (r.phone_number ?? '').replace(/\D/g, '') === queryDigits);

    function addExisting(contact) {
        onChange([...selected, contact]);
        setQuery('');
    }

    function addManual() {
        onChange([...selected, { id: null, phone_number: queryDigits, name: null, isNew: true }]);
        setQuery('');
    }

    function removeByKey(c) {
        onChange(selected.filter(x => (x.id != null ? x.id !== c.id : x.phone_number !== c.phone_number)));
    }

    return (
        <div className="space-y-1.5" ref={wrapRef}>
            <label className="text-sm font-medium text-foreground">
                Para <span className="text-muted-foreground font-normal">({selected.length} seleccionados)</span>
            </label>

            {selected.length > 0 && (
                <div className="flex flex-wrap gap-1.5 rounded-md border border-input bg-muted/30 p-2">
                    {selected.map(c => {
                        const isNew = c.id == null;
                        const palette = isNew
                            ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300'
                            : 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300';
                        const phoneTone = isNew
                            ? 'text-blue-600/70 dark:text-blue-400/70'
                            : 'text-green-600/70 dark:text-green-400/70';
                        const hover = isNew
                            ? 'hover:bg-blue-200 dark:hover:bg-blue-800'
                            : 'hover:bg-green-200 dark:hover:bg-green-800';
                        return (
                            <span key={c.id ?? `manual:${c.phone_number}`} className={`inline-flex items-center gap-1.5 rounded-full ${palette} pl-2.5 pr-1 py-0.5 text-xs`}>
                                <span className="font-medium">{c.name || c.phone_number}</span>
                                {c.name && <span className={`${phoneTone} font-mono`}>{c.phone_number}</span>}
                                {isNew && <span className="text-[10px] uppercase tracking-wide opacity-70">nuevo</span>}
                                <button type="button" onClick={() => removeByKey(c)} className={`rounded-full ${hover} size-4 flex items-center justify-center`}>
                                    <X className="size-3" />
                                </button>
                            </span>
                        );
                    })}
                </div>
            )}

            <div className="relative">
                <div className="relative">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground pointer-events-none" />
                    <input
                        type="text"
                        value={query}
                        onChange={e => { setQuery(e.target.value); setOpen(true); }}
                        onFocus={() => setOpen(true)}
                        disabled={disabled}
                        placeholder={disabled ? 'Selecciona una instancia primero' : 'Busca por nombre o teléfono…'}
                        className="flex h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 py-1 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60"
                    />
                    {loading && <Loader2 className="absolute right-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground animate-spin" />}
                </div>

                {open && !disabled && (
                    <div className="absolute z-10 mt-1 w-full max-h-64 overflow-y-auto rounded-md border bg-popover shadow-lg">
                        {filtered.length === 0 && !canAddManual ? (
                            <div className="px-3 py-3 text-sm text-muted-foreground text-center">
                                {loading
                                    ? 'Buscando…'
                                    : query
                                        ? 'Sin resultados. Escribe el número completo (mínimo 7 dígitos) para agregarlo.'
                                        : 'Empieza a escribir para buscar contactos'}
                            </div>
                        ) : (
                            <>
                                {filtered.map(c => (
                                    <button
                                        key={c.id}
                                        type="button"
                                        onClick={() => addExisting(c)}
                                        className="w-full flex items-center justify-between px-3 py-2 text-sm hover:bg-muted text-left"
                                    >
                                        <span className="font-medium text-foreground">{c.name || 'Sin nombre'}</span>
                                        <span className="font-mono text-xs text-muted-foreground">{c.phone_number}</span>
                                    </button>
                                ))}
                                {canAddManual && (
                                    <button
                                        type="button"
                                        onClick={addManual}
                                        className={`w-full flex items-center justify-between px-3 py-2 text-sm hover:bg-muted text-left ${filtered.length > 0 ? 'border-t' : ''}`}
                                    >
                                        <span className="text-blue-600 dark:text-blue-400 font-medium">+ Agregar número nuevo</span>
                                        <span className="font-mono text-xs text-muted-foreground">{queryDigits}</span>
                                    </button>
                                )}
                            </>
                        )}
                    </div>
                )}
            </div>

            {error && <p className="text-xs text-red-600">{error}</p>}
            <p className="text-xs text-muted-foreground">
                Selecciona contactos existentes o escribe el número (mínimo 7 dígitos, con código de país) para agregar uno nuevo.
            </p>
        </div>
    );
}

function Modal({ title, description, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-xl border bg-card shadow-2xl p-6 max-h-[92vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
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
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
            />
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}

CampaignsIndex.layout = page => <AppLayout breadcrumb={['Campañas']}>{page}</AppLayout>;
