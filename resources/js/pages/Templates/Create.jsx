import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { WhatsAppPreview, formStateToModel } from './preview';
import {
    Plus,
    Trash2,
    Loader2,
    Bold,
    Italic,
    Strikethrough,
    Code,
    Smile,
    Info,
    ChevronLeft,
    CheckCircle2,
    Languages,
} from 'lucide-react';

const LANGUAGES = [
    { code: 'es', label: 'Español' },
    { code: 'es_AR', label: 'Español (Argentina)' },
    { code: 'es_ES', label: 'Español (España)' },
    { code: 'es_MX', label: 'Español (México)' },
    { code: 'en', label: 'Inglés' },
    { code: 'en_US', label: 'Inglés (EE.UU.)' },
    { code: 'en_GB', label: 'Inglés (Reino Unido)' },
    { code: 'pt_BR', label: 'Portugués (Brasil)' },
    { code: 'pt_PT', label: 'Portugués (Portugal)' },
    { code: 'fr', label: 'Francés' },
    { code: 'it', label: 'Italiano' },
    { code: 'de', label: 'Alemán' },
];

const NAME_PATTERN = /^[a-z0-9_]+$/;
const NAMED_VAR_PATTERN = /^[a-z][a-z0-9_]*$/;

const CATEGORIES = [
    { value: 'MARKETING', label: 'Marketing', desc: 'Promociones, ofertas y novedades para tus clientes.' },
    { value: 'UTILITY', label: 'Utilidad', desc: 'Mensajes sobre un pedido o cuenta específica: pagos, cortes, recordatorios.' },
    { value: 'AUTHENTICATION', label: 'Autenticación', desc: 'Códigos de un solo uso para verificar identidad.' },
];

const MEDIA_OPTIONS = [
    { value: 'NONE', label: 'Ninguna' },
    { value: 'IMAGE', label: 'Imagen' },
    { value: 'VIDEO', label: 'Video' },
    { value: 'DOCUMENT', label: 'Documento' },
    { value: 'LOCATION', label: 'Ubicación' },
];
const MEDIA_UPLOAD_TYPES = ['IMAGE', 'VIDEO', 'DOCUMENT'];
const MEDIA_ACCEPT = {
    IMAGE: 'image/jpeg,image/png',
    VIDEO: 'video/mp4,video/3gpp',
    DOCUMENT: 'application/pdf',
};

const MAX_BUTTONS = 10;
const BUTTON_LIMITS = {
    PHONE_NUMBER: 1,
    URL: 2,
    COPY_CODE: 1,
    OTP: 1,
};

const EMOJIS = [
    '😀', '😁', '😂', '🙂', '😉', '😍', '🤗', '🤔', '👍', '👏',
    '🙏', '💪', '🎉', '🎊', '✅', '❌', '⚠️', '⏰', '📅', '📌',
    '📢', '💡', '🔔', '💰', '💳', '🧾', '📄', '🛠️', '🚀', '❤️',
];

function emptyComponents() {
    return {
        header: { media: 'NONE', text: '', handle: '', fileName: '', uploading: false, mediaError: '' },
        body: { text: '' },
        footer: { text: '' },
        buttons: [],
    };
}

function componentsFromTemplate(template) {
    const out = emptyComponents();
    for (const c of template?.components ?? []) {
        if (c.type === 'HEADER' && (c.format ?? 'TEXT') === 'TEXT') {
            out.header = { ...out.header, media: 'NONE', text: c.text ?? '' };
        } else if (c.type === 'HEADER') {
            // Encabezado multimedia: el handle de muestra es de un solo uso, así que
            // en traducciones/duplicados se exige volver a subir el archivo.
            out.header = { ...out.header, media: c.format ?? 'IMAGE' };
        } else if (c.type === 'BODY') {
            out.body = { text: c.text ?? '' };
        } else if (c.type === 'FOOTER') {
            out.footer = { text: c.text ?? '' };
        } else if (c.type === 'BUTTONS') {
            out.buttons = (c.buttons ?? []).map(b => ({
                type: b.type ?? 'QUICK_REPLY',
                text: b.text ?? '',
                url: b.url ?? '',
                url_example: '',
                phone_number: b.phone_number ?? '',
                example: '',
                otp_type: b.otp_type ?? 'COPY_CODE',
                autofill_text: b.autofill_text ?? '',
                package_name: b.package_name ?? '',
                signature_hash: b.signature_hash ?? '',
            }));
        }
    }
    return out;
}

// Detecta variables {{1}}/{{nombre}} en orden de aparición, sin duplicados.
function detectVars(text) {
    const out = [];
    const seen = new Set();
    const re = /\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g;
    let m;
    while ((m = re.exec(text ?? '')) !== null) {
        if (!seen.has(m[1])) {
            seen.add(m[1]);
            out.push(m[1]);
        }
    }
    return out;
}

function isNumeric(token) {
    return /^\d+$/.test(token);
}

function isSequentialFromOne(tokens) {
    const nums = tokens.map(t => parseInt(t, 10)).sort((a, b) => a - b);
    return nums.every((n, i) => n === i + 1);
}

// Siguiente variable a insertar según el formato: {{n+1}} o {{campo_k}} libre.
function nextVarToken(existing, format) {
    if (format === 'NAMED') {
        let k = 1;
        while (existing.includes(`campo_${k}`)) k++;
        return `campo_${k}`;
    }
    const max = existing.filter(isNumeric).reduce((acc, t) => Math.max(acc, parseInt(t, 10)), 0);
    return String(max + 1);
}

export default function TemplatesCreate({ instances = [], prefill = {} }) {
    const isTranslation = prefill.mode === 'translation';
    const familyName = prefill.family ?? '';

    const [instanceId, setInstanceId] = useState(() => {
        const fromQuery = parseInt(prefill.instance_id, 10);
        return Number.isFinite(fromQuery) ? fromQuery : (instances[0]?.id ?? null);
    });

    const [step, setStep] = useState(1);
    const [name, setName] = useState(isTranslation ? familyName : '');
    const [category, setCategory] = useState('UTILITY');
    const [language, setLanguage] = useState('');
    const [allowCategoryChange, setAllowCategoryChange] = useState(false);
    const [parameterFormat, setParameterFormat] = useState('POSITIONAL');
    const [comps, setComps] = useState(emptyComponents);
    const [headerExamples, setHeaderExamples] = useState({});
    const [bodyExamples, setBodyExamples] = useState({});
    const [errors, setErrors] = useState({});
    const [apiError, setApiError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [created, setCreated] = useState(null);
    const [usedLanguages, setUsedLanguages] = useState(() => new Set());
    const [familyVerifiedName, setFamilyVerifiedName] = useState(null);
    const [loadingSource, setLoadingSource] = useState(isTranslation);

    const bodyRef = useRef(null);
    const [emojiOpen, setEmojiOpen] = useState(false);

    // En modo traducción cargamos la familia desde Meta para bloquear idiomas
    // usados y prellenar el contenido con la plantilla de origen.
    useEffect(() => {
        if (!isTranslation || !familyName) return;
        let cancelled = false;
        axios.get(`/api/templates/family/${encodeURIComponent(familyName)}`, {
            params: { instance_id: instanceId },
        })
            .then(({ data }) => {
                if (cancelled) return;
                const variants = data.data || [];
                setUsedLanguages(new Set(variants.map(v => v.language)));
                setFamilyVerifiedName(variants[0]?.verified_name ?? null);
                const source = variants.find(v => String(v.id) === String(prefill.source_id))
                    ?? variants.find(v => v.status === 'APPROVED')
                    ?? variants[0];
                if (source) {
                    setCategory(source.category ?? 'UTILITY');
                    setComps(componentsFromTemplate(source));
                    const bodyText = source.components?.find(c => c.type === 'BODY')?.text ?? '';
                    if (detectVars(bodyText).some(t => !isNumeric(t))) {
                        setParameterFormat('NAMED');
                    }
                }
            })
            .catch(err => {
                if (cancelled) return;
                setApiError(err?.response?.data?.message ?? 'No se pudo cargar la plantilla de origen.');
            })
            .finally(() => !cancelled && setLoadingSource(false));
        return () => { cancelled = true; };
    }, []);

    const headerVars = useMemo(() => detectVars(comps.header.text), [comps.header.text]);
    const bodyVars = useMemo(() => detectVars(comps.body.text), [comps.body.text]);

    useEffect(() => {
        setHeaderExamples(prev => Object.fromEntries(headerVars.map(t => [t, prev[t] ?? ''])));
    }, [headerVars.join(',')]);
    useEffect(() => {
        setBodyExamples(prev => Object.fromEntries(bodyVars.map(t => [t, prev[t] ?? ''])));
    }, [bodyVars.join(',')]);

    const headerType = comps.header.media !== 'NONE'
        ? comps.header.media
        : (comps.header.text.trim() ? 'TEXT' : 'NONE');

    const previewModel = useMemo(() => formStateToModel(
        {
            header: { type: headerType, text: comps.header.text },
            body: comps.body,
            footer: { enabled: !!comps.footer.text.trim(), text: comps.footer.text },
            buttons: comps.buttons,
        },
        headerExamples,
        bodyExamples,
    ), [comps, headerType, headerExamples, bodyExamples]);

    function validateVarKind(tokens, fieldLabel) {
        if (parameterFormat === 'NAMED') {
            const bad = tokens.find(t => !NAMED_VAR_PATTERN.test(t));
            if (bad !== undefined) {
                return `${fieldLabel}: con tipo de variable "Nombre", {{${bad}}} debe ser minúsculas/números/guion bajo empezando por letra.`;
            }
        } else {
            const bad = tokens.find(t => !isNumeric(t));
            if (bad !== undefined) {
                return `${fieldLabel}: con tipo de variable "Número" usa {{1}}, {{2}}... (encontré {{${bad}}}).`;
            }
            if (!isSequentialFromOne(tokens)) {
                return `${fieldLabel}: las variables deben ser {{1}}, {{2}}, {{3}}... sin saltos.`;
            }
        }
        return null;
    }

    function validateStep1() {
        const e = {};
        if (!NAME_PATTERN.test(name)) e.name = 'Solo minúsculas, números y guiones bajos.';
        if (name.length > 512) e.name = 'Máximo 512 caracteres.';
        if (!language) e.language = 'Selecciona un idioma.';
        if (isTranslation && usedLanguages.has(language)) e.language = 'Ya existe una plantilla de esta familia en ese idioma.';
        if (!instanceId) e.instance = 'Selecciona una instancia.';
        return e;
    }

    function validateStep2() {
        const e = {};
        if (!comps.body.text.trim()) e.body = 'El cuerpo es obligatorio.';
        if (comps.body.text.length > 1024) e.body = 'Máximo 1024 caracteres.';
        const bodyVarErr = validateVarKind(bodyVars, 'Cuerpo');
        if (bodyVarErr) e.body = bodyVarErr;

        if (headerType === 'TEXT') {
            if (comps.header.text.length > 60) e.header = 'Máximo 60 caracteres.';
            if (headerVars.length > 1) e.header = 'El título admite máximo una variable.';
            const headerVarErr = validateVarKind(headerVars, 'Título');
            if (headerVarErr) e.header = headerVarErr;
            for (const t of headerVars) {
                if (!headerExamples[t]) {
                    e.header_example = `Falta el ejemplo para {{${t}}}.`;
                    break;
                }
            }
        }
        if (MEDIA_UPLOAD_TYPES.includes(comps.header.media) && !comps.header.handle) {
            e.header = 'Sube el archivo de muestra del encabezado.';
        }
        for (const t of bodyVars) {
            if (!bodyExamples[t]) {
                e.body_example = `Falta el ejemplo para {{${t}}}.`;
                break;
            }
        }

        if (comps.footer.text.length > 60) e.footer = 'Máximo 60 caracteres.';
        if (comps.footer.text.includes('{{')) e.footer = 'El pie de página no admite variables.';

        const counts = { PHONE_NUMBER: 0, URL: 0, COPY_CODE: 0, OTP: 0, QUICK_REPLY: 0 };
        comps.buttons.forEach((b, i) => {
            counts[b.type] = (counts[b.type] ?? 0) + 1;
            if (b.type !== 'OTP' && !b.text.trim()) e[`btn_${i}_text`] = 'Texto requerido.';
            if (b.type === 'URL') {
                if (!b.url.trim()) e[`btn_${i}_url`] = 'URL requerida.';
                const urlVars = detectVars(b.url);
                if (urlVars.length > 1) e[`btn_${i}_url`] = 'La URL admite máximo una variable {{1}} al final.';
                if (urlVars.length === 1 && !b.url_example?.trim()) {
                    e[`btn_${i}_url_example`] = 'Provee un ejemplo de URL completa.';
                }
            }
            if (b.type === 'PHONE_NUMBER' && !b.phone_number.trim()) e[`btn_${i}_phone`] = 'Teléfono requerido.';
            if (b.type === 'COPY_CODE' && !b.example?.trim()) e[`btn_${i}_example`] = 'Provee un código de ejemplo.';
        });
        Object.entries(BUTTON_LIMITS).forEach(([type, max]) => {
            if (counts[type] > max) {
                e._buttons = `Meta solo permite ${max} botón${max > 1 ? 'es' : ''} de tipo ${type}.`;
            }
        });
        if (category === 'AUTHENTICATION' && counts.OTP === 0) {
            e._buttons = 'Las plantillas AUTHENTICATION requieren un botón OTP.';
        }
        return e;
    }

    function goNext() {
        const e = validateStep1();
        setErrors(e);
        if (Object.keys(e).length === 0) setStep(2);
    }

    function buildPayload() {
        const components = [];

        if (headerType === 'TEXT') {
            const h = { type: 'HEADER', format: 'TEXT', text: comps.header.text };
            if (headerVars.length) {
                h.example = parameterFormat === 'NAMED'
                    ? { header_text_named_params: headerVars.map(t => ({ param_name: t, example: headerExamples[t] })) }
                    : { header_text: headerVars.map(t => headerExamples[t]) };
            }
            components.push(h);
        } else if (MEDIA_UPLOAD_TYPES.includes(comps.header.media) && comps.header.handle) {
            components.push({
                type: 'HEADER',
                format: comps.header.media,
                example: { header_handle: [comps.header.handle] },
            });
        } else if (comps.header.media === 'LOCATION') {
            components.push({ type: 'HEADER', format: 'LOCATION' });
        }

        const b = { type: 'BODY', text: comps.body.text };
        if (bodyVars.length) {
            b.example = parameterFormat === 'NAMED'
                ? { body_text_named_params: bodyVars.map(t => ({ param_name: t, example: bodyExamples[t] })) }
                : { body_text: [bodyVars.map(t => bodyExamples[t])] };
        }
        components.push(b);

        if (comps.footer.text.trim()) {
            components.push({ type: 'FOOTER', text: comps.footer.text });
        }

        if (comps.buttons.length) {
            components.push({
                type: 'BUTTONS',
                buttons: comps.buttons.map(btn => {
                    const out = { type: btn.type };
                    if (btn.type !== 'OTP') out.text = btn.text;
                    if (btn.type === 'URL') {
                        out.url = btn.url;
                        if (detectVars(btn.url).length && btn.url_example?.trim()) {
                            out.example = [btn.url_example.trim()];
                        }
                    }
                    if (btn.type === 'PHONE_NUMBER') out.phone_number = btn.phone_number;
                    if (btn.type === 'COPY_CODE' && btn.example?.trim()) {
                        out.example = [btn.example.trim()];
                    }
                    if (btn.type === 'OTP') {
                        out.otp_type = btn.otp_type || 'COPY_CODE';
                        if (btn.text?.trim()) out.text = btn.text;
                        if (btn.autofill_text?.trim()) out.autofill_text = btn.autofill_text;
                        if (btn.package_name?.trim()) out.package_name = btn.package_name;
                        if (btn.signature_hash?.trim()) out.signature_hash = btn.signature_hash;
                    }
                    return out;
                }),
            });
        }

        return {
            instance_id: instanceId,
            name,
            language,
            category,
            allow_category_change: allowCategoryChange,
            parameter_format: parameterFormat,
            components,
        };
    }

    async function handleSubmit() {
        const step1Errors = validateStep1();
        const e = { ...step1Errors, ...validateStep2() };
        if (Object.keys(e).length) {
            setErrors(e);
            if (Object.keys(step1Errors).length) setStep(1);
            return;
        }
        setErrors({});
        setApiError(null);
        setSubmitting(true);
        try {
            const res = await axios.post('/api/templates', buildPayload());
            setCreated({
                template: res.data.data,
                waba_id: res.data.waba_id,
                instance: res.data.instance,
                verified_in_meta: res.data.verified_in_meta,
            });
        } catch (err) {
            const resp = err?.response?.data;
            if (err?.response?.status === 422) {
                const list = resp?.errors;
                if (Array.isArray(list) && list.length) {
                    setApiError(list.join(' · '));
                } else if (list && typeof list === 'object') {
                    setApiError(Object.values(list).flat().join(' · '));
                } else {
                    setApiError(resp?.message || 'Datos inválidos. Revisa los campos.');
                }
            } else {
                const meta = resp?.error?.error ?? resp?.error;
                setApiError(
                    meta?.error_user_msg
                    || meta?.error_user_title
                    || meta?.message
                    || resp?.message
                    || 'No se pudo crear la plantilla.'
                );
            }
        } finally {
            setSubmitting(false);
        }
    }

    function setMedia(media) {
        // Elegir multimedia limpia el título y viceversa: el HEADER es uno solo.
        setComps(p => ({
            ...p,
            header: { media, text: '', handle: '', fileName: '', uploading: false, mediaError: '' },
        }));
        setErrors(prev => ({ ...prev, header: undefined, header_example: undefined }));
    }

    async function handleHeaderFile(file) {
        if (!file) return;
        setComps(p => ({ ...p, header: { ...p.header, uploading: true, mediaError: '', handle: '', fileName: file.name } }));
        try {
            const fd = new FormData();
            if (instanceId) fd.append('instance_id', instanceId);
            fd.append('file', file);
            const res = await axios.post('/api/templates/upload-media', fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setComps(p => ({ ...p, header: { ...p.header, uploading: false, handle: res.data.handle, fileName: res.data.file_name || file.name } }));
        } catch (err) {
            const msg = err?.response?.data?.message
                || err?.response?.data?.error?.error?.error_user_msg
                || 'No se pudo subir el archivo a Meta.';
            setComps(p => ({ ...p, header: { ...p.header, uploading: false, handle: '', mediaError: msg } }));
        }
    }

    function insertInBody(snippet, { wrap = false } = {}) {
        const el = bodyRef.current;
        const value = comps.body.text;
        if (!el) return;
        const start = el.selectionStart ?? value.length;
        const end = el.selectionEnd ?? value.length;
        let next;
        let caret;
        if (wrap) {
            const selected = value.slice(start, end) || 'texto';
            next = value.slice(0, start) + snippet + selected + snippet + value.slice(end);
            caret = start + snippet.length + selected.length + snippet.length;
        } else {
            next = value.slice(0, start) + snippet + value.slice(end);
            caret = start + snippet.length;
        }
        if (next.length > 1024) return;
        setComps(p => ({ ...p, body: { text: next } }));
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(caret, caret);
        });
    }

    function addHeaderVariable() {
        if (headerVars.length >= 1) return;
        const token = nextVarToken(headerVars, parameterFormat);
        setComps(p => ({ ...p, header: { ...p.header, text: `${p.header.text}{{${token}}}`.slice(0, 60) } }));
    }

    function addBodyVariable() {
        insertInBody(`{{${nextVarToken(bodyVars, parameterFormat)}}}`);
    }

    function addButton() {
        if (comps.buttons.length >= MAX_BUTTONS) return;
        setComps(p => ({
            ...p,
            buttons: [...p.buttons, {
                type: 'QUICK_REPLY', text: '', url: '', url_example: '', phone_number: '',
                example: '', otp_type: 'COPY_CODE', autofill_text: '', package_name: '', signature_hash: '',
            }],
        }));
    }
    function updateButton(i, patch) {
        setComps(p => ({ ...p, buttons: p.buttons.map((b, idx) => idx === i ? { ...b, ...patch } : b) }));
    }
    function removeButton(i) {
        setComps(p => ({ ...p, buttons: p.buttons.filter((_, idx) => idx !== i) }));
    }

    const activeInstance = instances.find(i => i.id === instanceId);

    if (created) {
        return (
            <>
                <Head title="Plantilla enviada" />
                <CreatedScreen created={created} />
            </>
        );
    }

    return (
        <>
            <Head title={isTranslation ? `Nueva traducción · ${familyName}` : 'Crear plantilla'} />
            <div className="flex flex-col min-h-[calc(100vh-3rem)]">
                {/* Encabezado de página */}
                <div className="border-b bg-card px-6 py-4">
                    <div className="flex items-center justify-between gap-4 max-w-6xl mx-auto w-full">
                        <div className="flex items-center gap-3 min-w-0">
                            <Link href={route('templates.index')} className="text-muted-foreground hover:text-foreground">
                                <ChevronLeft className="size-5" />
                            </Link>
                            <div className="min-w-0">
                                <h1 className="text-lg font-semibold text-foreground truncate">
                                    {isTranslation ? <>Nueva traducción de <span className="font-mono">{familyName}</span></> : 'Crear plantilla'}
                                </h1>
                                <p className="text-xs text-muted-foreground">
                                    {isTranslation
                                        ? 'La subplantilla mantiene el nombre y la categoría; solo cambia el idioma y el contenido.'
                                        : 'Meta revisará el contenido y las variables de la plantilla antes de aprobarla.'}
                                </p>
                            </div>
                        </div>
                        <Stepper step={step} />
                    </div>
                </div>

                {/* Cuerpo: formulario + vista previa */}
                <div className="flex-1 bg-muted/20">
                    <div className="max-w-6xl mx-auto w-full grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 px-6 py-6 pb-28">
                        <div className="space-y-5">
                            {apiError && (
                                <div className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                    {apiError}
                                </div>
                            )}

                            {loadingSource && (
                                <div className="flex items-center gap-2 rounded-lg border bg-card px-4 py-3 text-sm text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" /> Cargando plantilla de origen…
                                </div>
                            )}

                            {step === 1 && (
                                <StepConfig
                                    isTranslation={isTranslation}
                                    instances={instances}
                                    instanceId={instanceId}
                                    setInstanceId={setInstanceId}
                                    name={name}
                                    setName={setName}
                                    category={category}
                                    setCategory={setCategory}
                                    language={language}
                                    setLanguage={setLanguage}
                                    usedLanguages={usedLanguages}
                                    allowCategoryChange={allowCategoryChange}
                                    setAllowCategoryChange={setAllowCategoryChange}
                                    errors={errors}
                                />
                            )}

                            {step === 2 && (
                                <StepContent
                                    comps={comps}
                                    setComps={setComps}
                                    parameterFormat={parameterFormat}
                                    setParameterFormat={setParameterFormat}
                                    headerVars={headerVars}
                                    bodyVars={bodyVars}
                                    headerExamples={headerExamples}
                                    setHeaderExamples={setHeaderExamples}
                                    bodyExamples={bodyExamples}
                                    setBodyExamples={setBodyExamples}
                                    errors={errors}
                                    bodyRef={bodyRef}
                                    emojiOpen={emojiOpen}
                                    setEmojiOpen={setEmojiOpen}
                                    insertInBody={insertInBody}
                                    addHeaderVariable={addHeaderVariable}
                                    addBodyVariable={addBodyVariable}
                                    handleHeaderFile={handleHeaderFile}
                                    addButton={addButton}
                                    updateButton={updateButton}
                                    removeButton={removeButton}
                                />
                            )}
                        </div>

                        {/* Vista previa fija */}
                        <div className="hidden lg:block">
                            <div className="sticky top-6 space-y-2">
                                <div className="rounded-xl border bg-card overflow-hidden">
                                    <div className="px-4 py-3 border-b">
                                        <h3 className="text-sm font-semibold text-foreground">Vista previa de la plantilla</h3>
                                    </div>
                                    <div className="p-3">
                                        <WhatsAppPreview
                                            model={previewModel}
                                            verifiedName={familyVerifiedName ?? activeInstance?.name}
                                            empty="Escribe el contenido para ver la vista previa en tiempo real."
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Barra inferior estilo Meta */}
                <div className="fixed bottom-0 left-0 right-0 z-30 border-t bg-card/95 backdrop-blur">
                    <div className="max-w-6xl mx-auto w-full flex items-center justify-between gap-3 px-6 py-3">
                        <Link href={route('templates.index')}>
                            <Button type="button" variant="ghost" disabled={submitting}>Cancelar</Button>
                        </Link>
                        <div className="flex items-center gap-2">
                            {step === 2 && (
                                <Button type="button" variant="outline" onClick={() => setStep(1)} disabled={submitting}>
                                    Anterior
                                </Button>
                            )}
                            {step === 1 ? (
                                <Button type="button" onClick={goNext}>Siguiente</Button>
                            ) : (
                                <Button type="button" onClick={handleSubmit} disabled={submitting || !comps.body.text.trim()} className="gap-2">
                                    {submitting && <Loader2 className="size-4 animate-spin" />}
                                    Enviar para revisión
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

function Stepper({ step }) {
    const steps = ['Configuración', 'Contenido'];
    return (
        <div className="hidden sm:flex items-center gap-3">
            {steps.map((label, i) => {
                const n = i + 1;
                const active = step === n;
                const done = step > n;
                return (
                    <div key={label} className="flex items-center gap-2">
                        {i > 0 && <span className="w-8 h-px bg-border" />}
                        <span className={`flex items-center justify-center size-6 rounded-full text-xs font-semibold ${
                            done ? 'bg-primary text-primary-foreground'
                                : active ? 'bg-primary/15 text-primary ring-1 ring-primary/40'
                                : 'bg-muted text-muted-foreground'
                        }`}>
                            {done ? '✓' : n}
                        </span>
                        <span className={`text-xs ${active ? 'text-foreground font-medium' : 'text-muted-foreground'}`}>{label}</span>
                    </div>
                );
            })}
        </div>
    );
}

function FieldLabel({ children, optional, hint }) {
    return (
        <div className="flex items-center gap-1.5">
            <label className="text-sm font-medium text-foreground">
                {children}
                {optional && <span className="text-xs font-normal text-muted-foreground"> · Opcional</span>}
            </label>
            {hint && (
                <span className="group relative inline-flex">
                    <Info className="size-3.5 text-muted-foreground cursor-help" />
                    <span className="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 hidden group-hover:block w-64 rounded-md border bg-popover px-2.5 py-1.5 text-[11px] text-popover-foreground shadow-md z-20">
                        {hint}
                    </span>
                </span>
            )}
        </div>
    );
}

function CounterInput({ value, onChange, maxLength, placeholder, disabled }) {
    return (
        <div className="relative">
            <input
                type="text"
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                maxLength={maxLength}
                disabled={disabled}
                className="flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 pr-16 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60"
            />
            <span className="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] text-muted-foreground tabular-nums">
                {value.length}/{maxLength}
            </span>
        </div>
    );
}

function StepConfig({
    isTranslation, instances, instanceId, setInstanceId,
    name, setName, category, setCategory, language, setLanguage,
    usedLanguages, allowCategoryChange, setAllowCategoryChange, errors,
}) {
    return (
        <div className="rounded-xl border bg-card p-5 space-y-5">
            <div>
                <h2 className="text-base font-semibold text-foreground">Configura tu plantilla</h2>
                <p className="text-xs text-muted-foreground mt-1">
                    Elige la categoría, el nombre y el idioma. {isTranslation ? 'El nombre y la categoría vienen de la plantilla principal.' : 'Después podrás añadir traducciones (subplantillas) a otros idiomas.'}
                </p>
            </div>

            {instances.length > 1 && !isTranslation && (
                <div className="space-y-1.5">
                    <FieldLabel>Instancia (WABA)</FieldLabel>
                    <select
                        value={instanceId ?? ''}
                        onChange={e => setInstanceId(Number(e.target.value) || null)}
                        className="h-9 w-full rounded-md border border-input bg-card px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                    >
                        {instances.map(i => (
                            <option key={i.id} value={i.id}>{i.name} ({i.display_phone_number})</option>
                        ))}
                    </select>
                    {errors.instance && <p className="text-xs text-destructive">{errors.instance}</p>}
                </div>
            )}

            <div className="space-y-1.5">
                <FieldLabel hint="Cambia cómo se cobra y revisa Meta el mensaje. UTILITY para avisos de cuenta, MARKETING para promociones.">
                    Categoría {isTranslation && <span className="text-xs font-normal text-muted-foreground">(bloqueada)</span>}
                </FieldLabel>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    {CATEGORIES.map(c => {
                        const active = category === c.value;
                        return (
                            <button
                                key={c.value}
                                type="button"
                                disabled={isTranslation}
                                onClick={() => setCategory(c.value)}
                                className={`rounded-lg border p-3 text-left transition-colors disabled:opacity-60 ${
                                    active ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'hover:border-primary/40'
                                }`}
                            >
                                <div className="text-sm font-medium text-foreground">{c.label}</div>
                                <div className="text-[11px] text-muted-foreground mt-0.5 leading-snug">{c.desc}</div>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <FieldLabel hint="Identificador interno de la plantilla en Meta. Solo minúsculas, números y guiones bajos.">
                        Nombre {isTranslation && <span className="text-xs font-normal text-muted-foreground">(bloqueado)</span>}
                    </FieldLabel>
                    <input
                        type="text"
                        value={name}
                        onChange={e => setName(e.target.value.toLowerCase())}
                        disabled={isTranslation}
                        placeholder="cortes_servicio"
                        className="flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm font-mono shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60"
                    />
                    {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                </div>
                <div className="space-y-1.5">
                    <FieldLabel>Idioma</FieldLabel>
                    <select
                        value={language}
                        onChange={e => setLanguage(e.target.value)}
                        className="h-9 w-full rounded-md border border-input bg-card px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                    >
                        <option value="">Selecciona...</option>
                        {LANGUAGES.map(l => (
                            <option key={l.code} value={l.code} disabled={isTranslation && usedLanguages.has(l.code)}>
                                {l.code} — {l.label}{isTranslation && usedLanguages.has(l.code) ? ' (ya existe)' : ''}
                            </option>
                        ))}
                    </select>
                    {errors.language && <p className="text-xs text-destructive">{errors.language}</p>}
                </div>
            </div>

            {isTranslation && usedLanguages.size > 0 && (
                <div className="flex items-start gap-2 rounded-md border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                    <Languages className="size-3.5 mt-0.5 shrink-0" />
                    <span>Idiomas ya existentes en esta familia: {Array.from(usedLanguages).join(', ')}.</span>
                </div>
            )}

            {!isTranslation && (
                <label className="flex items-center gap-2 text-sm text-foreground">
                    <input
                        type="checkbox"
                        checked={allowCategoryChange}
                        onChange={e => setAllowCategoryChange(e.target.checked)}
                        className="size-4 rounded border-input"
                    />
                    Permitir que Meta reclasifique la categoría si no coincide con el contenido
                </label>
            )}
        </div>
    );
}

function StepContent({
    comps, setComps, parameterFormat, setParameterFormat,
    headerVars, bodyVars, headerExamples, setHeaderExamples, bodyExamples, setBodyExamples,
    errors, bodyRef, emojiOpen, setEmojiOpen, insertInBody,
    addHeaderVariable, addBodyVariable, handleHeaderFile,
    addButton, updateButton, removeButton,
}) {
    const mediaSelected = comps.header.media !== 'NONE';

    return (
        <div className="space-y-5">
            <div className="rounded-xl border bg-card p-5 space-y-5">
                <div>
                    <h2 className="text-base font-semibold text-foreground">Contenido</h2>
                    <p className="text-xs text-muted-foreground mt-1">
                        Agrega un encabezado, cuerpo y pie de página a tu plantilla. La API de la nube, alojada por Meta,
                        revisará el contenido y las variables de la plantilla.
                    </p>
                </div>

                {/* Tipo de variable */}
                <div className="space-y-1.5 max-w-xs">
                    <FieldLabel hint='Con "Número" las variables son {{1}}, {{2}}... Con "Nombre" usas nombres descriptivos como {{cliente}} o {{fecha_corte}}.'>
                        Tipo de variable
                    </FieldLabel>
                    <select
                        value={parameterFormat}
                        onChange={e => setParameterFormat(e.target.value)}
                        className="h-9 w-full rounded-md border border-input bg-card px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                    >
                        <option value="POSITIONAL">Número</option>
                        <option value="NAMED">Nombre</option>
                    </select>
                </div>

                {/* Muestra de contenido multimedia */}
                <div className="space-y-1.5 max-w-xs">
                    <FieldLabel optional hint="Si el encabezado es imagen, video o documento, sube una muestra. Meta la usa solo para revisar y aprobar la plantilla.">
                        Muestra de contenido multimedia
                    </FieldLabel>
                    <select
                        value={comps.header.media}
                        onChange={e => {
                            const media = e.target.value;
                            setComps(p => ({
                                ...p,
                                header: { media, text: '', handle: '', fileName: '', uploading: false, mediaError: '' },
                            }));
                        }}
                        className="h-9 w-full rounded-md border border-input bg-card px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                    >
                        {MEDIA_OPTIONS.map(m => (
                            <option key={m.value} value={m.value}>{m.label}</option>
                        ))}
                    </select>
                </div>

                {MEDIA_UPLOAD_TYPES.includes(comps.header.media) && (
                    <div className="space-y-1.5 rounded-md border bg-muted/20 p-3">
                        <p className="text-xs text-muted-foreground">
                            Sube un archivo de muestra ({comps.header.media === 'IMAGE' ? 'JPG/PNG' : comps.header.media === 'VIDEO' ? 'MP4' : 'PDF'}).
                        </p>
                        <input
                            type="file"
                            accept={MEDIA_ACCEPT[comps.header.media]}
                            disabled={comps.header.uploading}
                            onChange={e => handleHeaderFile(e.target.files?.[0])}
                            className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary hover:file:bg-primary/20 disabled:opacity-60"
                        />
                        {comps.header.uploading && (
                            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Loader2 className="size-3.5 animate-spin" /> Subiendo a Meta…
                            </p>
                        )}
                        {!comps.header.uploading && comps.header.handle && (
                            <p className="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                                <span className="inline-block size-1.5 rounded-full bg-emerald-500" />
                                Archivo listo{comps.header.fileName ? `: ${comps.header.fileName}` : ''}
                            </p>
                        )}
                        {comps.header.mediaError && <p className="text-xs text-destructive">{comps.header.mediaError}</p>}
                    </div>
                )}

                {comps.header.media === 'LOCATION' && (
                    <p className="rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground">
                        La ubicación (latitud, longitud y nombre) se define al enviar el mensaje, no en la plantilla.
                    </p>
                )}

                {/* Título */}
                <div className="space-y-1.5">
                    <FieldLabel optional hint="Encabezado de texto del mensaje. Máximo 60 caracteres y una variable. No disponible si elegiste contenido multimedia.">
                        Título
                    </FieldLabel>
                    <CounterInput
                        value={comps.header.text}
                        onChange={e => setComps(p => ({ ...p, header: { ...p.header, text: e.target.value } }))}
                        maxLength={60}
                        placeholder="Agrega una breve línea de texto en el encabezado del mensaje"
                        disabled={mediaSelected}
                    />
                    {!mediaSelected && (
                        <div className="flex justify-end">
                            <button
                                type="button"
                                onClick={addHeaderVariable}
                                disabled={headerVars.length >= 1}
                                className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline disabled:opacity-50 disabled:no-underline"
                            >
                                <Plus className="size-3" /> Agregar variable
                            </button>
                        </div>
                    )}
                    {errors.header && <p className="text-xs text-destructive">{errors.header}</p>}
                    {headerVars.map(t => (
                        <input
                            key={t}
                            type="text"
                            value={headerExamples[t] ?? ''}
                            onChange={e => setHeaderExamples(p => ({ ...p, [t]: e.target.value }))}
                            placeholder={`Ejemplo para {{${t}}}`}
                            className="flex h-8 w-full rounded-md border border-input bg-card px-3 py-1 text-xs"
                        />
                    ))}
                    {errors.header_example && <p className="text-xs text-destructive">{errors.header_example}</p>}
                </div>

                {/* Cuerpo */}
                <div className="space-y-1.5">
                    <FieldLabel>Cuerpo</FieldLabel>
                    <div className="rounded-md border border-input bg-card shadow-xs focus-within:ring-2 focus-within:ring-ring/50">
                        <div className="relative">
                            <textarea
                                ref={bodyRef}
                                value={comps.body.text}
                                onChange={e => setComps(p => ({ ...p, body: { text: e.target.value } }))}
                                placeholder="Hola {{1}}, te recordamos que tu servicio será suspendido el {{2}} si no realizas el pago."
                                rows={6}
                                maxLength={1024}
                                className="w-full bg-transparent px-3 py-2 text-sm resize-y focus:outline-none"
                            />
                            <span className="absolute right-3 bottom-2 text-[11px] text-muted-foreground tabular-nums pointer-events-none">
                                {comps.body.text.length}/1024
                            </span>
                        </div>
                        {/* Barra de formato estilo Meta */}
                        <div className="flex items-center gap-0.5 border-t px-2 py-1.5">
                            <div className="relative">
                                <ToolbarButton title="Emoji" onClick={() => setEmojiOpen(o => !o)}>
                                    <Smile className="size-4" />
                                </ToolbarButton>
                                {emojiOpen && (
                                    <div className="absolute bottom-full mb-1 left-0 z-20 w-64 rounded-lg border bg-popover p-2 shadow-lg grid grid-cols-10 gap-0.5">
                                        {EMOJIS.map(em => (
                                            <button
                                                key={em}
                                                type="button"
                                                className="size-6 flex items-center justify-center rounded hover:bg-muted text-base"
                                                onMouseDown={e => e.preventDefault()}
                                                onClick={() => { insertInBody(em); setEmojiOpen(false); }}
                                            >
                                                {em}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <ToolbarButton title="Negrita" onClick={() => insertInBody('*', { wrap: true })}>
                                <Bold className="size-4" />
                            </ToolbarButton>
                            <ToolbarButton title="Cursiva" onClick={() => insertInBody('_', { wrap: true })}>
                                <Italic className="size-4" />
                            </ToolbarButton>
                            <ToolbarButton title="Tachado" onClick={() => insertInBody('~', { wrap: true })}>
                                <Strikethrough className="size-4" />
                            </ToolbarButton>
                            <ToolbarButton title="Monoespaciado" onClick={() => insertInBody('```', { wrap: true })}>
                                <Code className="size-4" />
                            </ToolbarButton>
                            <div className="ml-auto">
                                <button
                                    type="button"
                                    onClick={addBodyVariable}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    <Plus className="size-3" /> Agregar variable
                                </button>
                            </div>
                        </div>
                    </div>
                    <p className="text-[11px] text-muted-foreground">
                        {parameterFormat === 'NAMED'
                            ? 'Variables con nombre: {{cliente}}, {{fecha_corte}}... Puedes renombrarlas directamente en el texto.'
                            : 'Variables por número: {{1}}, {{2}}... en orden y sin saltos.'}
                    </p>
                    {errors.body && <p className="text-xs text-destructive">{errors.body}</p>}
                    {bodyVars.length > 0 && (
                        <div className="space-y-1.5 rounded-md border bg-muted/20 p-3">
                            <p className="text-xs font-medium text-foreground">Ejemplos para las variables del cuerpo</p>
                            <p className="text-[11px] text-muted-foreground">Meta los usa para revisar la plantilla; no se envían al cliente.</p>
                            {bodyVars.map(t => (
                                <div key={t} className="flex items-center gap-2">
                                    <code className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[11px] font-mono text-foreground">{`{{${t}}}`}</code>
                                    <input
                                        type="text"
                                        value={bodyExamples[t] ?? ''}
                                        onChange={e => setBodyExamples(p => ({ ...p, [t]: e.target.value }))}
                                        placeholder={`Ejemplo para {{${t}}}`}
                                        className="flex h-8 w-full rounded-md border border-input bg-card px-3 py-1 text-xs"
                                    />
                                </div>
                            ))}
                            {errors.body_example && <p className="text-xs text-destructive">{errors.body_example}</p>}
                        </div>
                    )}
                </div>

                {/* Pie de página */}
                <div className="space-y-1.5">
                    <FieldLabel optional hint="Línea corta al final del mensaje, en texto atenuado. No admite variables.">
                        Pie de página
                    </FieldLabel>
                    <CounterInput
                        value={comps.footer.text}
                        onChange={e => setComps(p => ({ ...p, footer: { text: e.target.value } }))}
                        maxLength={60}
                        placeholder="Agrega una breve línea de texto en la parte inferior del mensaje"
                    />
                    {errors.footer && <p className="text-xs text-destructive">{errors.footer}</p>}
                </div>
            </div>

            {/* Botones */}
            <div className="rounded-xl border bg-card p-5 space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-base font-semibold text-foreground">Botones <span className="text-xs font-normal text-muted-foreground">· Opcional</span></h2>
                        <p className="text-xs text-muted-foreground mt-0.5">
                            Máx. 1 teléfono · 2 URL · 1 copiar código · 1 OTP. AUTHENTICATION requiere OTP.
                        </p>
                    </div>
                    <Button type="button" variant="outline" size="sm" onClick={addButton} disabled={comps.buttons.length >= MAX_BUTTONS} className="gap-1">
                        <Plus className="size-3.5" /> Agregar botón
                    </Button>
                </div>
                {errors._buttons && <p className="text-xs text-destructive">{errors._buttons}</p>}

                {comps.buttons.length === 0 && (
                    <p className="rounded-md border border-dashed px-3 py-4 text-center text-xs text-muted-foreground">
                        Sin botones. Agrega respuestas rápidas o llamadas a la acción.
                    </p>
                )}

                {comps.buttons.map((btn, i) => {
                    const urlVarCount = btn.type === 'URL' ? detectVars(btn.url).length : 0;
                    return (
                        <div key={i} className="rounded-md border bg-muted/20 p-2.5 space-y-2">
                            <div className="flex gap-2">
                                <select
                                    value={btn.type}
                                    onChange={e => updateButton(i, { type: e.target.value })}
                                    className="h-8 rounded-md border border-input bg-card px-2 text-xs"
                                >
                                    <option value="QUICK_REPLY">Respuesta rápida</option>
                                    <option value="URL">Ir al sitio web</option>
                                    <option value="PHONE_NUMBER">Llamar</option>
                                    <option value="COPY_CODE">Copiar código</option>
                                    <option value="OTP">OTP (autenticación)</option>
                                </select>
                                {btn.type !== 'OTP' && (
                                    <input
                                        type="text"
                                        value={btn.text}
                                        onChange={e => updateButton(i, { text: e.target.value })}
                                        placeholder="Texto del botón"
                                        maxLength={25}
                                        className="flex-1 h-8 rounded-md border border-input bg-card px-2 text-xs"
                                    />
                                )}
                                {btn.type === 'OTP' && (
                                    <select
                                        value={btn.otp_type || 'COPY_CODE'}
                                        onChange={e => updateButton(i, { otp_type: e.target.value })}
                                        className="flex-1 h-8 rounded-md border border-input bg-card px-2 text-xs"
                                    >
                                        <option value="COPY_CODE">COPY_CODE</option>
                                        <option value="ONE_TAP">ONE_TAP</option>
                                        <option value="ZERO_TAP">ZERO_TAP</option>
                                    </select>
                                )}
                                <Button type="button" variant="ghost" size="icon" onClick={() => removeButton(i)} className="text-destructive hover:bg-destructive/10">
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </div>
                            {errors[`btn_${i}_text`] && <p className="text-xs text-destructive">{errors[`btn_${i}_text`]}</p>}

                            {btn.type === 'URL' && (
                                <>
                                    <input
                                        type="url"
                                        value={btn.url}
                                        onChange={e => updateButton(i, { url: e.target.value })}
                                        placeholder="https://midominio.com/ruta/{{1}}"
                                        className="flex h-8 w-full rounded-md border border-input bg-card px-2 text-xs"
                                    />
                                    {errors[`btn_${i}_url`] && <p className="text-xs text-destructive">{errors[`btn_${i}_url`]}</p>}
                                    {urlVarCount > 0 && (
                                        <>
                                            <input
                                                type="url"
                                                value={btn.url_example || ''}
                                                onChange={e => updateButton(i, { url_example: e.target.value })}
                                                placeholder="Ejemplo de URL completa: https://midominio.com/ruta/abc123"
                                                className="flex h-8 w-full rounded-md border border-input bg-card px-2 text-xs"
                                            />
                                            {errors[`btn_${i}_url_example`] && <p className="text-xs text-destructive">{errors[`btn_${i}_url_example`]}</p>}
                                        </>
                                    )}
                                </>
                            )}

                            {btn.type === 'PHONE_NUMBER' && (
                                <>
                                    <input
                                        type="tel"
                                        value={btn.phone_number}
                                        onChange={e => updateButton(i, { phone_number: e.target.value })}
                                        placeholder="+573001234567"
                                        className="flex h-8 w-full rounded-md border border-input bg-card px-2 text-xs"
                                    />
                                    {errors[`btn_${i}_phone`] && <p className="text-xs text-destructive">{errors[`btn_${i}_phone`]}</p>}
                                </>
                            )}

                            {btn.type === 'COPY_CODE' && (
                                <>
                                    <input
                                        type="text"
                                        value={btn.example || ''}
                                        onChange={e => updateButton(i, { example: e.target.value })}
                                        placeholder="Código de ejemplo (ej. 250FF)"
                                        maxLength={15}
                                        className="flex h-8 w-full rounded-md border border-input bg-card px-2 text-xs"
                                    />
                                    {errors[`btn_${i}_example`] && <p className="text-xs text-destructive">{errors[`btn_${i}_example`]}</p>}
                                </>
                            )}

                            {btn.type === 'OTP' && (btn.otp_type === 'ONE_TAP' || btn.otp_type === 'ZERO_TAP') && (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <input
                                        type="text"
                                        value={btn.package_name || ''}
                                        onChange={e => updateButton(i, { package_name: e.target.value })}
                                        placeholder="package_name (com.tu.app)"
                                        className="h-8 rounded-md border border-input bg-card px-2 text-xs"
                                    />
                                    <input
                                        type="text"
                                        value={btn.signature_hash || ''}
                                        onChange={e => updateButton(i, { signature_hash: e.target.value })}
                                        placeholder="signature_hash"
                                        className="h-8 rounded-md border border-input bg-card px-2 text-xs"
                                    />
                                    <input
                                        type="text"
                                        value={btn.autofill_text || ''}
                                        onChange={e => updateButton(i, { autofill_text: e.target.value })}
                                        placeholder="autofill_text (opcional)"
                                        maxLength={25}
                                        className="h-8 rounded-md border border-input bg-card px-2 text-xs sm:col-span-2"
                                    />
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function ToolbarButton({ title, onClick, children }) {
    return (
        <button
            type="button"
            title={title}
            onMouseDown={e => e.preventDefault()}
            onClick={onClick}
            className="size-7 flex items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
        >
            {children}
        </button>
    );
}

function CreatedScreen({ created }) {
    const tpl = created.template ?? {};
    const wabaId = created.waba_id;
    const metaUrl = wabaId
        ? `https://business.facebook.com/wa/manage/message-templates/?waba_id=${wabaId}`
        : null;
    return (
        <div className="flex items-center justify-center px-6 py-16">
            <div className="w-full max-w-md rounded-xl border bg-card shadow-sm">
                <div className="px-6 py-5 space-y-4">
                    <div className="flex items-start gap-3">
                        <div className="size-10 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                            <CheckCircle2 className="size-5" />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold text-foreground">Plantilla enviada a Meta</h2>
                            <p className="text-xs text-muted-foreground mt-1">
                                Meta la recibió en estado <span className="font-medium text-foreground">{tpl.status ?? 'PENDING'}</span>. La aprobación suele tardar unos minutos.
                            </p>
                        </div>
                    </div>

                    <div className="rounded-md border bg-muted/30 p-3 space-y-2 text-xs">
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">Template ID</span>
                            <code className="font-mono text-foreground break-all text-right">{tpl.id ?? '—'}</code>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">WABA ID</span>
                            <code className="font-mono text-foreground break-all text-right">{wabaId ?? '—'}</code>
                        </div>
                        {created.instance?.name && (
                            <div className="flex justify-between gap-3">
                                <span className="text-muted-foreground">Instancia</span>
                                <span className="text-foreground text-right">{created.instance.name}{created.instance.display_phone_number ? ` · ${created.instance.display_phone_number}` : ''}</span>
                            </div>
                        )}
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">Verificada en Meta</span>
                            <span className={created.verified_in_meta ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-amber-600 dark:text-amber-400 font-medium'}>
                                {created.verified_in_meta ? 'Sí' : 'No respondió aún'}
                            </span>
                        </div>
                    </div>

                    <div className="flex gap-2 pt-1">
                        {metaUrl && (
                            <a href={metaUrl} target="_blank" rel="noopener noreferrer" className="flex-1">
                                <Button type="button" variant="outline" className="w-full">
                                    Abrir en Meta
                                </Button>
                            </a>
                        )}
                        <Button type="button" onClick={() => router.visit(route('templates.index'))} className="flex-1">
                            Volver a plantillas
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}

TemplatesCreate.layout = page => <AppLayout breadcrumb={['Plantillas', 'Crear plantilla']}>{page}</AppLayout>;
