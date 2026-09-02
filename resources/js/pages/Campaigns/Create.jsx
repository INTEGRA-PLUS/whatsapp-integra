import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    AlertTriangle, Check, ChevronLeft, ChevronRight, FileText, Loader2,
    MessageSquareText, Search, Send, Upload, Users, X,
} from 'lucide-react';
import { WhatsAppPreview } from '@/pages/Templates/preview';
import {
    CATEGORY_LABEL, HEADER_MEDIA_ACCEPT, HEADER_MEDIA_LABEL,
    templateBodyComponent, templateHeaderComponent, templateHeaderFormat,
} from '@/lib/templates';
import { DAY_OPTIONS } from './shared';

const STEPS = [
    { n: 1, label: 'Línea y nombre' },
    { n: 2, label: 'Plantilla' },
    { n: 3, label: 'Destinatarios' },
    { n: 4, label: 'Revisar y enviar' },
];

/** Un hueco de variable: o un texto fijo para todos, o un campo del destinatario. */
const EMPTY_SLOT = { source: 'fixed', value: '', field: '' };

export default function CampaignsCreate({ instances = [], defaultInstanceId = null, segments = [], tags = [], fields = [] }) {
    // La línea llega elegida. Quien crea una campaña casi siempre tiene una sola,
    // y elegirla no decidía nada: era un paso de más antes del trabajo de verdad.
    const [form, setForm] = useState({
        name: sugerirNombre(),
        instance_id: defaultInstanceId ?? instances[0]?.id ?? '',
        template: null,
        headerMedia: null,
        headerVars: [],
        bodyVars: [],
        recipients: [],
        csvColumns: [],
        schedule_type: 'manual',
        schedule_days: [],
        schedule_time: '09:00',
        launch_now: true,
        rate_per_minute: 60,
    });

    const [step, setStep] = useState(1);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const update = useCallback((patch) => setForm(f => ({ ...f, ...(typeof patch === 'function' ? patch(f) : patch) })), []);

    const instance = instances.find(i => String(i.id) === String(form.instance_id));

    // Campos que se pueden insertar en una variable: los del contacto más las
    // columnas que traiga el CSV pegado en el paso 3.
    const availableFields = useMemo(() => ([
        ...fields,
        ...form.csvColumns.map(c => ({ key: c, label: `Columna «${c}» del CSV` })),
    ]), [fields, form.csvColumns]);

    const primerDestinatario = form.recipients[0] ?? null;

    const model = useMemo(
        () => buildPreviewModel(form, primerDestinatario),
        [form.template, form.bodyVars, form.headerVars, form.headerMedia, primerDestinatario]
    );

    function validar(n) {
        const e = {};
        if (n === 1) {
            if (!form.name.trim()) e.name = 'Ponle un nombre para reconocerla después.';
            if (!form.instance_id) e.instance_id = 'Elige la línea desde la que se enviará.';
            if (instance && !instance.ready) e.instance_id = 'Esta línea todavía no tiene WhatsApp Business conectado.';
        }
        if (n === 2) {
            if (!form.template) e.template = 'Elige la plantilla que se enviará.';
            else {
                const fmt = templateHeaderFormat(form.template);
                if (fmt && fmt !== 'LOCATION' && !form.headerMedia?.mediaId) {
                    e.header = `Esta plantilla lleva ${HEADER_MEDIA_LABEL[fmt].toLowerCase()} en el encabezado: sube el archivo.`;
                }
                if (form.bodyVars.some(v => !slotCompleto(v))) {
                    e.vars = 'Completa todos los datos variables de la plantilla.';
                }
                if (form.headerVars.some(v => !slotCompleto(v))) {
                    e.vars = 'Completa los datos variables del encabezado.';
                }
            }
        }
        if (n === 3 && form.recipients.length === 0) {
            e.recipients = 'Selecciona al menos un destinatario.';
        }
        if (n === 4 && form.schedule_type === 'recurring' && form.schedule_days.length === 0) {
            e.schedule_days = 'Elige al menos un día de la semana.';
        }
        setErrors(e);
        return Object.keys(e).length === 0;
    }

    function siguiente() {
        if (validar(step)) setStep(s => Math.min(4, s + 1));
    }

    function irA(n) {
        // Hacia atrás siempre; hacia delante solo si lo anterior está resuelto.
        if (n <= step) return setStep(n);
        for (let i = step; i < n; i++) if (!validar(i)) return setStep(i);
        setStep(n);
    }

    function enviar(launchNow) {
        if (![1, 2, 3, 4].every(validar)) return;

        setSaving(true);
        const esRecurrente = form.schedule_type === 'recurring';

        router.post(route('campaigns.store'), {
            name: form.name,
            instance_id: form.instance_id,
            template_name: form.template.name,
            template_language: form.template.language,
            template_components: form.template.components,
            variable_map: {
                header: form.headerVars,
                body: form.bodyVars,
            },
            header_media_id: form.headerMedia?.mediaId ?? null,
            header_media_url: form.headerMedia?.url ?? null,
            header_filename: form.headerMedia?.filename ?? null,
            rate_per_minute: form.rate_per_minute,
            conversation_ids: form.recipients.filter(r => r.conversation_id).map(r => r.conversation_id),
            contact_ids: form.recipients.filter(r => r.contact_id).map(r => r.contact_id),
            manual_recipients: form.recipients
                .filter(r => !r.conversation_id && !r.contact_id)
                .map(r => ({ phone: r.phone_number, name: r.name, variables: r.variables ?? {} })),
            launch_now: !esRecurrente && launchNow,
            schedule_type: form.schedule_type,
            schedule_days: esRecurrente ? form.schedule_days : [],
            schedule_time: esRecurrente ? form.schedule_time : null,
        }, {
            onError: (err) => { setErrors(err); setSaving(false); },
            onFinish: () => setSaving(false),
        });
    }

    return (
        <>
            <Head title="Nueva campaña" />

            <div className="flex flex-col min-h-[calc(100vh-3rem)]">
                <header className="border-b bg-card px-6 py-4 flex items-center gap-4">
                    <Link href={route('campaigns.index')} className="text-muted-foreground hover:text-foreground">
                        <ChevronLeft className="size-5" />
                    </Link>
                    <div className="min-w-0 flex-1">
                        <h1 className="text-lg font-semibold text-foreground truncate">Nueva campaña</h1>
                        <p className="text-xs text-muted-foreground">
                            Un mismo aviso a mucha gente, con una plantilla aprobada por WhatsApp.
                        </p>
                    </div>
                    <Stepper step={step} onGo={irA} />
                </header>

                <div className="flex-1 w-full max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6 px-6 py-6 pb-28">
                    <div className="min-w-0 space-y-6">
                        {step === 1 && (
                            <PasoLinea form={form} update={update} instances={instances} errors={errors} />
                        )}
                        {step === 2 && (
                            <PasoPlantilla
                                form={form}
                                update={update}
                                errors={errors}
                                fields={availableFields}
                            />
                        )}
                        {step === 3 && (
                            <PasoDestinatarios
                                form={form}
                                update={update}
                                errors={errors}
                                tags={tags}
                                segments={segments}
                            />
                        )}
                        {step === 4 && (
                            <PasoRevision form={form} update={update} errors={errors} instance={instance} />
                        )}
                    </div>

                    <aside className="hidden lg:block">
                        <div className="sticky top-6 space-y-3">
                            <div className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                Así lo verá {primerDestinatario?.name || 'el cliente'}
                            </div>
                            <WhatsAppPreview
                                model={model}
                                verifiedName={instance?.name}
                                empty="Elige una plantilla para ver cómo llegará el mensaje."
                            />
                        </div>
                    </aside>
                </div>

                <div className="fixed bottom-0 left-0 right-0 z-30 border-t bg-card/95 backdrop-blur px-6 py-3">
                    <div className="max-w-6xl mx-auto flex items-center gap-3">
                        <Link href={route('campaigns.index')} className="text-sm text-muted-foreground hover:text-foreground">
                            Cancelar
                        </Link>
                        <div className="flex-1" />
                        {step > 1 && (
                            <Button variant="outline" onClick={() => setStep(s => s - 1)} className="gap-1">
                                <ChevronLeft className="size-4" /> Anterior
                            </Button>
                        )}
                        {step < 4 ? (
                            <Button onClick={siguiente} className="gap-1">
                                Siguiente <ChevronRight className="size-4" />
                            </Button>
                        ) : (
                            <>
                                <Button variant="outline" onClick={() => enviar(false)} disabled={saving}>
                                    Guardar borrador
                                </Button>
                                <Button onClick={() => enviar(true)} disabled={saving} className="gap-2">
                                    {saving ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                                    {form.schedule_type === 'recurring' ? 'Programar' : `Enviar a ${form.recipients.length}`}
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

CampaignsCreate.layout = page => <AppLayout breadcrumb={['Campañas', 'Nueva']}>{page}</AppLayout>;

/* ───────────────────────────── Paso 1 · Línea ───────────────────────────── */

function PasoLinea({ form, update, instances, errors }) {
    const unica = instances.length === 1;

    return (
        <Card title="¿Desde dónde y con qué nombre?" description="El nombre es solo para ti: te sirve para encontrar la campaña después.">
            <Campo label="Nombre de la campaña" error={errors.name}>
                <input
                    className="w-full rounded-xl border bg-background px-3 py-2 text-sm"
                    value={form.name}
                    onChange={e => update({ name: e.target.value })}
                    placeholder="Ej: Facturación septiembre"
                />
            </Campo>

            {unica ? (
                <p className="text-sm text-muted-foreground">
                    Se enviará desde <span className="font-medium text-foreground">{instances[0].name}</span>
                    {instances[0].display_phone_number ? ` · ${instances[0].display_phone_number}` : ''}.
                </p>
            ) : (
                <Campo label="Línea de WhatsApp" error={errors.instance_id}>
                    <select
                        className="w-full rounded-xl border bg-background px-3 py-2 text-sm"
                        value={form.instance_id}
                        onChange={e => update({ instance_id: e.target.value, template: null, recipients: [] })}
                    >
                        {instances.map(i => (
                            <option key={i.id} value={i.id}>
                                {i.name}{i.display_phone_number ? ` — ${i.display_phone_number}` : ''}
                            </option>
                        ))}
                    </select>
                </Campo>
            )}

            <Nota>
                WhatsApp solo entrega mensajes libres durante las 24 horas siguientes al último mensaje del cliente.
                Por eso una campaña se envía siempre con una <strong>plantilla aprobada</strong>: es lo único que llega
                a quien no te ha escrito hoy.
            </Nota>
        </Card>
    );
}

/* ─────────────────────────── Paso 2 · Plantilla ─────────────────────────── */

function PasoPlantilla({ form, update, errors, fields }) {
    const [templates, setTemplates] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [busqueda, setBusqueda] = useState('');

    useEffect(() => {
        let vivo = true;
        setLoading(true);
        axios.get(route('campaigns.templates'), { params: { instance_id: form.instance_id } })
            .then(res => {
                if (!vivo) return;
                setTemplates(res.data.templates ?? []);
                setError(res.data.error ?? '');
            })
            .catch(() => vivo && setError('No se pudieron cargar las plantillas.'))
            .finally(() => vivo && setLoading(false));
        return () => { vivo = false; };
    }, [form.instance_id]);

    const filtradas = useMemo(() => {
        const q = busqueda.trim().toLowerCase();
        if (!q) return templates;
        return templates.filter(t =>
            t.name.toLowerCase().includes(q) ||
            (templateBodyComponent(t)?.text || '').toLowerCase().includes(q));
    }, [templates, busqueda]);

    function elegir(t) {
        const cuerpo = templateBodyComponent(t)?.text || '';
        const header = templateHeaderComponent(t);
        const fmt = templateHeaderFormat(t);

        update({
            template: t,
            bodyVars: Array.from({ length: contarVars(cuerpo) }, () => ({ ...EMPTY_SLOT })),
            headerVars: header && !fmt
                ? Array.from({ length: contarVars(header.text || '') }, () => ({ ...EMPTY_SLOT }))
                : [],
            headerMedia: fmt && fmt !== 'LOCATION' ? { format: fmt, mediaId: '', filename: '', url: '' } : null,
        });
    }

    return (
        <div className="space-y-6">
            <Card
                title="¿Qué plantilla se enviará?"
                description="Solo aparecen las plantillas que Meta ya aprobó para esta línea. Si falta alguna, créala en Plantillas y espera su aprobación."
            >
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                    <input
                        className="w-full rounded-xl border bg-background pl-9 pr-3 py-2 text-sm"
                        placeholder="Buscar por nombre o texto…"
                        value={busqueda}
                        onChange={e => setBusqueda(e.target.value)}
                    />
                </div>

                {loading && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground py-6 justify-center">
                        <Loader2 className="size-4 animate-spin" /> Cargando plantillas de WhatsApp…
                    </div>
                )}

                {!loading && error && <Aviso tono="amber">{error}</Aviso>}

                {!loading && !error && filtradas.length === 0 && (
                    <Aviso tono="amber">
                        Esta línea no tiene plantillas aprobadas. Crea una en <Link href="/templates" className="underline">Plantillas</Link> y
                        vuelve cuando Meta la apruebe (suele tardar minutos).
                    </Aviso>
                )}

                <div className="grid sm:grid-cols-2 gap-3 max-h-[420px] overflow-y-auto pr-1">
                    {filtradas.map(t => {
                        const activa = form.template?.id === t.id;
                        return (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => elegir(t)}
                                className={`text-left rounded-xl border p-3 transition-colors ${
                                    activa ? 'border-primary ring-1 ring-primary bg-primary/5' : 'hover:bg-muted/40'
                                }`}
                            >
                                <div className="flex items-center gap-2 mb-1">
                                    <span className="font-medium text-sm text-foreground truncate">{t.name}</span>
                                    {activa && <Check className="size-4 text-primary shrink-0" />}
                                </div>
                                <div className="flex items-center gap-2 mb-2">
                                    <Etiqueta>{CATEGORY_LABEL[t.category] ?? t.category}</Etiqueta>
                                    <Etiqueta>{t.language}</Etiqueta>
                                    {templateHeaderFormat(t) && (
                                        <Etiqueta>{HEADER_MEDIA_LABEL[templateHeaderFormat(t)]}</Etiqueta>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground line-clamp-3">
                                    {templateBodyComponent(t)?.text || 'Sin cuerpo de texto'}
                                </p>
                            </button>
                        );
                    })}
                </div>

                {errors.template && <p className="text-sm text-rose-600">{errors.template}</p>}
            </Card>

            {form.template && form.headerMedia && (
                <SubidaEncabezado form={form} update={update} error={errors.header} />
            )}

            {form.template && (form.bodyVars.length > 0 || form.headerVars.length > 0) && (
                <Card
                    title="Los datos que cambian en cada mensaje"
                    description="Cada dato puede ser el mismo para todos, o salir del propio destinatario."
                >
                    {form.headerVars.map((slot, i) => (
                        <SlotVariable
                            key={`h${i}`}
                            etiqueta={`Encabezado · dato {{${i + 1}}}`}
                            slot={slot}
                            fields={fields}
                            onChange={s => update(f => ({ headerVars: f.headerVars.map((x, j) => j === i ? s : x) }))}
                        />
                    ))}
                    {form.bodyVars.map((slot, i) => (
                        <SlotVariable
                            key={`b${i}`}
                            etiqueta={`Dato {{${i + 1}}}`}
                            slot={slot}
                            fields={fields}
                            onChange={s => update(f => ({ bodyVars: f.bodyVars.map((x, j) => j === i ? s : x) }))}
                        />
                    ))}
                    {errors.vars && <p className="text-sm text-rose-600">{errors.vars}</p>}
                </Card>
            )}
        </div>
    );
}

function SlotVariable({ etiqueta, slot, fields, onChange }) {
    return (
        <div className="rounded-xl border p-3 space-y-2">
            <div className="text-xs font-medium text-muted-foreground">{etiqueta}</div>
            <div className="flex flex-wrap gap-2">
                <label className="flex items-center gap-1.5 text-sm">
                    <input
                        type="radio"
                        checked={slot.source === 'fixed'}
                        onChange={() => onChange({ ...slot, source: 'fixed' })}
                    />
                    Texto fijo
                </label>
                <label className="flex items-center gap-1.5 text-sm">
                    <input
                        type="radio"
                        checked={slot.source === 'field'}
                        onChange={() => onChange({ ...slot, source: 'field', field: slot.field || fields[0]?.key || '' })}
                    />
                    Dato del destinatario
                </label>
            </div>

            {slot.source === 'fixed' ? (
                <input
                    className="w-full rounded-lg border bg-background px-3 py-1.5 text-sm"
                    value={slot.value}
                    onChange={e => onChange({ ...slot, value: e.target.value })}
                    placeholder="Lo mismo para todos"
                />
            ) : (
                <select
                    className="w-full rounded-lg border bg-background px-3 py-1.5 text-sm"
                    value={slot.field}
                    onChange={e => onChange({ ...slot, field: e.target.value })}
                >
                    <option value="">Elige el dato…</option>
                    {fields.map(f => <option key={f.key} value={f.key}>{f.label}</option>)}
                </select>
            )}
        </div>
    );
}

function SubidaEncabezado({ form, update, error }) {
    const [subiendo, setSubiendo] = useState(false);
    const [fallo, setFallo] = useState('');
    const fmt = form.headerMedia.format;

    async function subir(file) {
        if (!file) return;
        setSubiendo(true);
        setFallo('');
        const fd = new FormData();
        fd.append('instance_id', form.instance_id);
        fd.append('file', file);

        try {
            const res = await axios.post(route('campaigns.template-media'), fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            update({
                headerMedia: {
                    ...form.headerMedia,
                    mediaId: res.data.media_id,
                    filename: res.data.filename,
                    // La URL local es solo para la vista previa; a Meta va el media_id.
                    url: URL.createObjectURL(file),
                },
            });
        } catch (e) {
            setFallo(e.response?.data?.error || 'No se pudo subir el archivo.');
        } finally {
            setSubiendo(false);
        }
    }

    return (
        <Card
            title={`Encabezado · ${HEADER_MEDIA_LABEL[fmt]}`}
            description="Esta plantilla lleva un archivo arriba del texto. Se sube una vez y se usa para todos los envíos."
        >
            <label className="flex items-center gap-3 rounded-xl border border-dashed px-4 py-4 cursor-pointer hover:bg-muted/40">
                {subiendo ? <Loader2 className="size-5 animate-spin text-muted-foreground" /> : <Upload className="size-5 text-muted-foreground" />}
                <div className="text-sm">
                    <div className="font-medium text-foreground">
                        {form.headerMedia.mediaId ? 'Cambiar archivo' : `Elegir ${HEADER_MEDIA_LABEL[fmt].toLowerCase()}`}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {fmt === 'IMAGE' && 'JPG o PNG, hasta 5 MB'}
                        {fmt === 'VIDEO' && 'MP4, hasta 16 MB'}
                        {fmt === 'DOCUMENT' && 'PDF, hasta 100 MB'}
                    </div>
                </div>
                <input
                    type="file"
                    className="hidden"
                    accept={HEADER_MEDIA_ACCEPT[fmt]}
                    disabled={subiendo}
                    onChange={e => subir(e.target.files?.[0])}
                />
            </label>

            {form.headerMedia.mediaId && (
                <p className="text-sm text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                    <Check className="size-4" /> {form.headerMedia.filename} · listo
                </p>
            )}
            {(fallo || error) && <p className="text-sm text-rose-600">{fallo || error}</p>}
        </Card>
    );
}

/* ──────────────────────── Paso 3 · Destinatarios ─────────────────────────── */

const FUENTES = [
    { key: 'conversations', label: 'Conversaciones' },
    { key: 'contacts', label: 'Contactos del CRM' },
    { key: 'paste', label: 'Pegar o CSV' },
    { key: 'segments', label: 'Segmentos' },
];

function PasoDestinatarios({ form, update, errors, tags, segments }) {
    const [fuente, setFuente] = useState('conversations');
    const [q, setQ] = useState('');
    const [tagIds, setTagIds] = useState([]);
    const [resultados, setResultados] = useState([]);
    const [total, setTotal] = useState(0);
    const [pagina, setPagina] = useState(1);
    const [cargando, setCargando] = useState(false);
    const [misSegmentos, setMisSegmentos] = useState(segments);
    const debounce = useRef(null);

    const seleccionados = form.recipients;
    const clavesSeleccionadas = useMemo(() => new Set(seleccionados.map(r => r.key)), [seleccionados]);

    const buscar = useCallback((page = 1) => {
        if (fuente === 'paste' || fuente === 'segments') return;
        setCargando(true);
        axios.get(route('campaigns.contacts.search'), {
            params: { instance_id: form.instance_id, source: fuente, q, tag_ids: tagIds, page },
        })
            .then(res => {
                setResultados(page === 1 ? res.data.contacts : [...resultados, ...res.data.contacts]);
                setTotal(res.data.total);
                setPagina(page);
            })
            .finally(() => setCargando(false));
    }, [form.instance_id, fuente, q, tagIds]);

    useEffect(() => {
        clearTimeout(debounce.current);
        debounce.current = setTimeout(() => buscar(1), 250);
        return () => clearTimeout(debounce.current);
    }, [buscar]);

    function alternar(row) {
        update(f => clavesSeleccionadas.has(row.key)
            ? { recipients: f.recipients.filter(r => r.key !== row.key) }
            : { recipients: [...f.recipients, row] });
    }

    async function seleccionarTodos() {
        const res = await axios.get(route('campaigns.contacts.resolve'), {
            params: { instance_id: form.instance_id, source: fuente, q, tag_ids: tagIds },
        });
        update(f => {
            const vistos = new Set(f.recipients.map(r => r.key));
            return { recipients: [...f.recipients, ...res.data.contacts.filter(c => !vistos.has(c.key))] };
        });
    }

    async function guardarSegmento() {
        const nombre = window.prompt('¿Con qué nombre guardas este criterio?');
        if (!nombre) return;
        const res = await axios.post(route('campaigns.segments.store'), {
            name: nombre,
            source: fuente,
            filters: { q, tag_ids: tagIds },
        });
        setMisSegmentos(s => [...s, res.data.segment]);
    }

    async function aplicarSegmento(segmento) {
        const res = await axios.get(route('campaigns.contacts.resolve'), {
            params: {
                instance_id: form.instance_id,
                source: segmento.source,
                q: segmento.filters?.q ?? '',
                tag_ids: segmento.filters?.tag_ids ?? [],
            },
        });
        update(f => {
            const vistos = new Set(f.recipients.map(r => r.key));
            return { recipients: [...f.recipients, ...res.data.contacts.filter(c => !vistos.has(c.key))] };
        });
    }

    return (
        <div className="space-y-6">
            <Card
                title="¿A quién se le envía?"
                description="Puedes mezclar fuentes: lo repetido se envía una sola vez."
            >
                <div className="flex flex-wrap gap-1 border-b -mb-px">
                    {FUENTES.map(f => (
                        <button
                            key={f.key}
                            type="button"
                            onClick={() => { setFuente(f.key); setPagina(1); }}
                            className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                                fuente === f.key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {f.label}
                        </button>
                    ))}
                </div>

                {(fuente === 'conversations' || fuente === 'contacts') && (
                    <div className="space-y-3 pt-3">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <input
                                className="w-full rounded-xl border bg-background pl-9 pr-3 py-2 text-sm"
                                placeholder={fuente === 'contacts' ? 'Nombre, teléfono o identificación…' : 'Nombre o teléfono…'}
                                value={q}
                                onChange={e => setQ(e.target.value)}
                            />
                        </div>

                        {fuente === 'conversations' && tags.length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {tags.map(t => (
                                    <button
                                        key={t.id}
                                        type="button"
                                        onClick={() => setTagIds(ids => ids.includes(t.id) ? ids.filter(x => x !== t.id) : [...ids, t.id])}
                                        className={`px-2.5 py-1 rounded-full text-xs border transition-colors ${
                                            tagIds.includes(t.id) ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted'
                                        }`}
                                    >
                                        {t.name}
                                    </button>
                                ))}
                            </div>
                        )}

                        <div className="flex items-center justify-between text-xs text-muted-foreground">
                            <span>{total} {total === 1 ? 'resultado' : 'resultados'}</span>
                            <div className="flex gap-3">
                                {total > 0 && (
                                    <button type="button" onClick={seleccionarTodos} className="underline hover:text-foreground">
                                        Seleccionar los {total}
                                    </button>
                                )}
                                <button type="button" onClick={guardarSegmento} className="underline hover:text-foreground">
                                    Guardar como segmento
                                </button>
                            </div>
                        </div>

                        <div className="rounded-xl border divide-y max-h-72 overflow-y-auto">
                            {resultados.map(row => (
                                <button
                                    key={row.key}
                                    type="button"
                                    onClick={() => alternar(row)}
                                    className="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-muted/40"
                                >
                                    <span className={`size-4 rounded border flex items-center justify-center ${
                                        clavesSeleccionadas.has(row.key) ? 'bg-primary border-primary text-primary-foreground' : ''
                                    }`}>
                                        {clavesSeleccionadas.has(row.key) && <Check className="size-3" />}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm text-foreground truncate">{row.name || row.phone_number}</span>
                                        <span className="block text-xs text-muted-foreground">{row.phone_number}{row.detail ? ` · ${row.detail}` : ''}</span>
                                    </span>
                                </button>
                            ))}
                            {cargando && (
                                <div className="px-3 py-3 text-sm text-muted-foreground flex items-center gap-2">
                                    <Loader2 className="size-4 animate-spin" /> Buscando…
                                </div>
                            )}
                            {!cargando && resultados.length === 0 && (
                                <div className="px-3 py-6 text-sm text-muted-foreground text-center">Sin resultados.</div>
                            )}
                        </div>

                        {resultados.length < total && (
                            <button type="button" onClick={() => buscar(pagina + 1)} className="text-sm underline text-muted-foreground hover:text-foreground">
                                Ver más
                            </button>
                        )}
                    </div>
                )}

                {fuente === 'paste' && <PegarNumeros form={form} update={update} />}

                {fuente === 'segments' && (
                    <div className="pt-3 space-y-2">
                        {misSegmentos.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Todavía no has guardado ningún segmento. Busca en «Conversaciones» o «Contactos del CRM»
                                y pulsa «Guardar como segmento» para reutilizar ese criterio en las próximas campañas.
                            </p>
                        )}
                        {misSegmentos.map(s => (
                            <div key={s.id} className="flex items-center gap-3 rounded-xl border px-3 py-2">
                                <Users className="size-4 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    <div className="text-sm text-foreground truncate">{s.name}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {s.source === 'contacts' ? 'Contactos del CRM' : 'Conversaciones'}
                                        {s.filters?.q ? ` · «${s.filters.q}»` : ''}
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" onClick={() => aplicarSegmento(s)}>Añadir</Button>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <Card title={`Seleccionados (${seleccionados.length})`}>
                {seleccionados.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Aún no hay destinatarios.</p>
                ) : (
                    <>
                        <div className="flex flex-wrap gap-1.5 max-h-48 overflow-y-auto">
                            {seleccionados.slice(0, 300).map(r => (
                                <span key={r.key} className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs">
                                    {r.name || r.phone_number}
                                    <button type="button" onClick={() => update(f => ({ recipients: f.recipients.filter(x => x.key !== r.key) }))}>
                                        <X className="size-3" />
                                    </button>
                                </span>
                            ))}
                            {seleccionados.length > 300 && (
                                <span className="text-xs text-muted-foreground self-center">y {seleccionados.length - 300} más…</span>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => update({ recipients: [] })}
                            className="text-xs text-muted-foreground underline hover:text-foreground"
                        >
                            Quitar todos
                        </button>
                    </>
                )}
                {errors.recipients && <p className="text-sm text-rose-600">{errors.recipients}</p>}
            </Card>
        </div>
    );
}

function PegarNumeros({ form, update }) {
    const [texto, setTexto] = useState('');
    const [resumen, setResumen] = useState(null);

    function procesar() {
        const lineas = texto.split('\n').map(l => l.trim()).filter(Boolean);
        if (lineas.length === 0) return;

        // Cabecera opcional: si la primera línea nombra las columnas, esas columnas
        // quedan disponibles como datos variables en el paso de la plantilla.
        const primera = lineas[0].toLowerCase();
        const tieneCabecera = /tel[eé]fono|telefono|phone|celular/.test(primera);
        const columnas = tieneCabecera ? separar(lineas[0]).map(c => c.trim()) : [];
        const filas = tieneCabecera ? lineas.slice(1) : lineas;

        const nuevos = [];
        const invalidos = [];
        const vistos = new Set(form.recipients.map(r => r.phone_number));

        for (const fila of filas) {
            const partes = separar(fila).map(p => p.trim());
            // Se acepta "nombre,teléfono" y "teléfono" a secas.
            const posTelefono = columnas.length
                ? columnas.findIndex(c => /tel[eé]fono|telefono|phone|celular/.test(c.toLowerCase()))
                : (partes.length > 1 ? 1 : 0);
            const telefono = (partes[posTelefono] || '').replace(/[^0-9A-Za-z.]/g, '');
            const nombre = columnas.length
                ? partes[columnas.findIndex(c => /nombre|name/.test(c.toLowerCase()))] || ''
                : (partes.length > 1 ? partes[0] : '');

            if (telefono.replace(/\D/g, '').length < 7) {
                invalidos.push(fila);
                continue;
            }
            if (vistos.has(telefono)) continue;
            vistos.add(telefono);

            const variables = {};
            columnas.forEach((c, i) => {
                if (!/tel[eé]fono|telefono|phone|celular|nombre|name/.test(c.toLowerCase())) {
                    variables[c] = partes[i] ?? '';
                }
            });

            nuevos.push({
                key: `manual:${telefono}`,
                contact_id: null,
                conversation_id: null,
                name: nombre || null,
                phone_number: telefono,
                detail: null,
                variables,
            });
        }

        const columnasExtra = columnas.filter(c => !/tel[eé]fono|telefono|phone|celular|nombre|name/.test(c.toLowerCase()));

        update(f => ({
            recipients: [...f.recipients, ...nuevos],
            csvColumns: Array.from(new Set([...f.csvColumns, ...columnasExtra])),
        }));

        setResumen({ agregados: nuevos.length, invalidos });
        setTexto('');
    }

    return (
        <div className="pt-3 space-y-3">
            <p className="text-sm text-muted-foreground">
                Una línea por destinatario: <code className="text-xs bg-muted px-1 py-0.5 rounded">nombre,teléfono</code> o
                solo el teléfono con código de país. Si pegas una cabecera (por ejemplo
                <code className="text-xs bg-muted px-1 py-0.5 rounded"> nombre,telefono,plan</code>), las columnas de más
                quedan disponibles como datos variables de la plantilla.
            </p>
            <textarea
                className="w-full rounded-xl border bg-background px-3 py-2 text-sm font-mono h-40"
                placeholder={'nombre,telefono,plan\nDaniela Galindo,573245637786,Oro'}
                value={texto}
                onChange={e => setTexto(e.target.value)}
            />
            <Button onClick={procesar} variant="outline" className="gap-2">
                <FileText className="size-4" /> Añadir a la lista
            </Button>

            {resumen && (
                <div className="text-sm space-y-1">
                    <p className="text-emerald-600 dark:text-emerald-400">Se añadieron {resumen.agregados} destinatarios.</p>
                    {resumen.invalidos.length > 0 && (
                        <div className="text-amber-600 dark:text-amber-400">
                            <p>{resumen.invalidos.length} líneas no tenían un teléfono válido y se descartaron:</p>
                            <ul className="list-disc list-inside text-xs opacity-80 max-h-24 overflow-y-auto">
                                {resumen.invalidos.slice(0, 20).map((l, i) => <li key={i}>{l}</li>)}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function separar(linea) {
    return linea.includes('\t') ? linea.split('\t') : linea.split(/[,;]/);
}

/* ───────────────────────── Paso 4 · Revisión ────────────────────────────── */

function PasoRevision({ form, update, errors, instance }) {
    const esRecurrente = form.schedule_type === 'recurring';
    const minutos = Math.ceil(form.recipients.length / Math.max(1, form.rate_per_minute));

    return (
        <div className="space-y-6">
            <Card title="Resumen">
                <dl className="grid sm:grid-cols-2 gap-3 text-sm">
                    <Dato termino="Campaña" valor={form.name} />
                    <Dato termino="Línea" valor={`${instance?.name ?? ''}${instance?.display_phone_number ? ' · ' + instance.display_phone_number : ''}`} />
                    <Dato termino="Plantilla" valor={`${form.template?.name} (${form.template?.language})`} />
                    <Dato termino="Categoría" valor={CATEGORY_LABEL[form.template?.category] ?? form.template?.category} />
                    <Dato termino="Destinatarios" valor={String(form.recipients.length)} />
                    <Dato termino="Duración estimada" valor={minutos <= 1 ? 'menos de un minuto' : `unos ${minutos} minutos`} />
                </dl>

                {form.template?.category === 'MARKETING' && (
                    <Aviso tono="amber">
                        Es una plantilla de <strong>marketing</strong>: WhatsApp la cobra como tal y algunos clientes pueden
                        no recibirla si tienen limitados los mensajes promocionales. Para avisos operativos (facturas, cortes,
                        soportes) usa una plantilla de utilidad.
                    </Aviso>
                )}
            </Card>

            <Card title="Cuándo se envía">
                <div className="flex flex-wrap gap-4">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="radio"
                            checked={!esRecurrente}
                            onChange={() => update({ schedule_type: 'manual' })}
                        />
                        Envío único
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="radio"
                            checked={esRecurrente}
                            onChange={() => update({ schedule_type: 'recurring' })}
                        />
                        Recurrente por día
                    </label>
                </div>

                {esRecurrente && (
                    <div className="space-y-3">
                        <div className="flex flex-wrap gap-1.5">
                            {DAY_OPTIONS.map(d => (
                                <button
                                    key={d.key}
                                    type="button"
                                    onClick={() => update(f => ({
                                        schedule_days: f.schedule_days.includes(d.key)
                                            ? f.schedule_days.filter(x => x !== d.key)
                                            : [...f.schedule_days, d.key],
                                    }))}
                                    className={`px-3 py-1.5 rounded-lg text-xs border ${
                                        form.schedule_days.includes(d.key) ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted'
                                    }`}
                                >
                                    {d.label}
                                </button>
                            ))}
                        </div>
                        <input
                            type="time"
                            className="rounded-xl border bg-background px-3 py-2 text-sm"
                            value={form.schedule_time}
                            onChange={e => update({ schedule_time: e.target.value })}
                        />
                        {errors.schedule_days && <p className="text-sm text-rose-600">{errors.schedule_days}</p>}
                    </div>
                )}

                <Campo label="Velocidad de envío" hint="Mensajes por minuto. Bajarla reduce el riesgo de que WhatsApp limite la línea.">
                    <input
                        type="number"
                        min="1"
                        max="600"
                        className="w-32 rounded-xl border bg-background px-3 py-2 text-sm"
                        value={form.rate_per_minute}
                        onChange={e => update({ rate_per_minute: Number(e.target.value) })}
                    />
                </Campo>
            </Card>

            {Object.keys(errors).length > 0 && (
                <Aviso tono="rose">
                    {Object.values(errors)[0]}
                </Aviso>
            )}

            <div className="lg:hidden">
                <WhatsAppPreview
                    model={buildPreviewModel(form, form.recipients[0] ?? null)}
                    verifiedName={instance?.name}
                    empty="Elige una plantilla."
                />
            </div>
        </div>
    );
}

/* ─────────────────────────────── Piezas ─────────────────────────────────── */

function Stepper({ step, onGo }) {
    return (
        <nav className="hidden sm:flex items-center gap-2">
            {STEPS.map((s, i) => (
                <div key={s.n} className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => onGo(s.n)}
                        className={`flex items-center gap-2 text-xs ${
                            s.n === step ? 'text-foreground font-medium' : 'text-muted-foreground'
                        }`}
                    >
                        <span className={`size-6 rounded-full flex items-center justify-center border text-[11px] ${
                            s.n < step ? 'bg-primary text-primary-foreground border-primary'
                                : s.n === step ? 'border-primary text-primary' : ''
                        }`}>
                            {s.n < step ? <Check className="size-3" /> : s.n}
                        </span>
                        <span className="hidden md:inline">{s.label}</span>
                    </button>
                    {i < STEPS.length - 1 && <span className="w-6 h-px bg-border" />}
                </div>
            ))}
        </nav>
    );
}

function Card({ title, description, children }) {
    return (
        <section className="rounded-2xl border bg-card p-5 space-y-4">
            {(title || description) && (
                <div className="space-y-1">
                    {title && <h2 className="text-base font-semibold text-foreground">{title}</h2>}
                    {description && <p className="text-sm text-muted-foreground">{description}</p>}
                </div>
            )}
            {children}
        </section>
    );
}

function Campo({ label, hint, error, children }) {
    return (
        <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">{label}</label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-sm text-rose-600">{error}</p>}
        </div>
    );
}

function Dato({ termino, valor }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">{termino}</dt>
            <dd className="text-foreground">{valor || '—'}</dd>
        </div>
    );
}

function Etiqueta({ children }) {
    return <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] uppercase tracking-wide text-muted-foreground">{children}</span>;
}

function Nota({ children }) {
    return (
        <div className="rounded-xl bg-muted/50 px-4 py-3 text-sm text-muted-foreground flex gap-3">
            <MessageSquareText className="size-4 shrink-0 mt-0.5" />
            <p>{children}</p>
        </div>
    );
}

function Aviso({ tono = 'amber', children }) {
    const clases = {
        amber: 'border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200',
        rose: 'border-rose-300 bg-rose-50 text-rose-800 dark:bg-rose-900/20 dark:border-rose-800 dark:text-rose-200',
    }[tono];

    return (
        <div className={`rounded-xl border px-4 py-3 text-sm flex gap-3 ${clases}`}>
            <AlertTriangle className="size-4 shrink-0 mt-0.5" />
            <div>{children}</div>
        </div>
    );
}

/* ─────────────────────────────── Utilidades ─────────────────────────────── */

function sugerirNombre() {
    const hoy = new Date().toLocaleDateString('es', { day: 'numeric', month: 'long' });
    return `Campaña ${hoy}`;
}

function contarVars(texto) {
    const m = (texto || '').match(/{{\s*[A-Za-z0-9_]+\s*}}/g);
    return m ? new Set(m.map(x => x.replace(/[{}\s]/g, ''))).size : 0;
}

function slotCompleto(slot) {
    return slot.source === 'fixed' ? slot.value.trim() !== '' : !!slot.field;
}

/** Lo que vale una variable para un destinatario concreto (o el texto guía). */
function resolverSlot(slot, destinatario) {
    if (!slot) return '';
    if (slot.source === 'fixed') return slot.value;

    if (!destinatario) {
        return `«${slot.field || 'dato'}»`;
    }

    const directo = destinatario.variables?.[slot.field];
    if (directo != null && directo !== '') return directo;

    return {
        name: destinatario.name,
        phone: destinatario.phone_number,
        identificacion: destinatario.detail,
    }[slot.field] || `«${slot.field}»`;
}

/**
 * La burbuja de la derecha: la plantilla con los datos ya puestos del primer
 * destinatario real. Ver el mensaje terminado, con un nombre de verdad dentro,
 * es lo que evita descubrir el error en el teléfono de mil clientes.
 */
function buildPreviewModel(form, destinatario) {
    const t = form.template;
    if (!t) return { header: null, body: { text: '' }, footer: null, buttons: [] };

    const headerComp = templateHeaderComponent(t);
    const fmt = templateHeaderFormat(t);
    const cuerpo = templateBodyComponent(t)?.text || '';

    let header = null;
    if (headerComp && fmt) {
        header = {
            text: '',
            mediaFormat: fmt,
            mediaUrl: form.headerMedia?.url || '',
            filename: form.headerMedia?.filename || '',
        };
    } else if (headerComp) {
        header = { text: sustituir(headerComp.text || '', form.headerVars, destinatario) };
    }

    const footer = (t.components || []).find(c => c.type === 'FOOTER');
    const botones = (t.components || []).find(c => c.type === 'BUTTONS');

    return {
        header,
        body: { text: sustituir(cuerpo, form.bodyVars, destinatario) },
        footer: footer ? { text: footer.text } : null,
        buttons: (botones?.buttons ?? []).map(b => ({
            type: b.type ?? 'QUICK_REPLY',
            text: b.text ?? '',
            url: b.url ?? '',
            phone_number: b.phone_number ?? '',
        })),
    };
}

function sustituir(texto, slots, destinatario) {
    let i = 0;
    return (texto || '').replace(/{{\s*([A-Za-z0-9_]+)\s*}}/g, (coincidencia, clave) => {
        const slot = /^\d+$/.test(clave) ? slots[Number(clave) - 1] : slots[i];
        i++;
        const valor = resolverSlot(slot, destinatario);
        return valor === '' ? coincidencia : valor;
    });
}
