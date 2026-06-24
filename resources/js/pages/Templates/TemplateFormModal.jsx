import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { X, Plus, Trash2, Loader2, Pencil, Smartphone } from 'lucide-react';
import { TabButton, WhatsAppPreview, formStateToModel } from './preview';

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

// Tipos de encabezado (estilo Meta). NONE = sin encabezado.
const HEADER_TYPES = [
    { value: 'NONE', label: 'Ninguno' },
    { value: 'TEXT', label: 'Texto' },
    { value: 'IMAGE', label: 'Imagen' },
    { value: 'VIDEO', label: 'Video' },
    { value: 'DOCUMENT', label: 'Documento' },
    { value: 'LOCATION', label: 'Ubicación' },
];
const MEDIA_HEADER_TYPES = ['IMAGE', 'VIDEO', 'DOCUMENT'];
const MEDIA_ACCEPT = {
    IMAGE: 'image/jpeg,image/png',
    VIDEO: 'video/mp4,video/3gpp',
    DOCUMENT: 'application/pdf',
};

function emptyComponents() {
    return {
        header: { type: 'NONE', text: '', handle: '', fileName: '', uploading: false, mediaError: '' },
        body: { text: '' },
        footer: { enabled: false, text: '' },
        buttons: [],
    };
}

function componentsFromTemplate(template) {
    const out = emptyComponents();
    for (const c of template?.components ?? []) {
        if (c.type === 'HEADER' && (c.format ?? 'TEXT') === 'TEXT') {
            out.header = { ...out.header, type: 'TEXT', text: c.text ?? '' };
        } else if (c.type === 'HEADER') {
            // Encabezado multimedia: el handle de muestra es de un solo uso, así que
            // en traducciones/duplicados se exige volver a subir el archivo.
            out.header = { ...out.header, type: c.format ?? 'IMAGE' };
        } else if (c.type === 'BODY') {
            out.body = { text: c.text ?? '' };
        } else if (c.type === 'FOOTER') {
            out.footer = { enabled: true, text: c.text ?? '' };
        } else if (c.type === 'BUTTONS') {
            out.buttons = (c.buttons ?? []).map(b => ({
                type: b.type ?? 'QUICK_REPLY',
                text: b.text ?? '',
                url: b.url ?? '',
                phone_number: b.phone_number ?? '',
            }));
        }
    }
    return out;
}

function detectVariables(text) {
    const matches = (text ?? '').match(/\{\{\s*(\d+)\s*\}\}/g) ?? [];
    const nums = new Set(matches.map(m => parseInt(m.replace(/[^0-9]/g, ''), 10)));
    return Array.from(nums).sort((a, b) => a - b);
}

function isSequentialFromOne(nums) {
    if (!nums.length) return true;
    return nums.every((n, i) => n === i + 1);
}

const MAX_BUTTONS = 10;
const BUTTON_LIMITS = {
    PHONE_NUMBER: 1,
    URL: 2,
    COPY_CODE: 1,
    OTP: 1,
};

export default function TemplateFormModal({ mode, instanceId, family, sourceTemplate, onClose, onCreated }) {
    const isTranslation = mode === 'translation';

    const [name, setName] = useState(isTranslation ? family.name : '');
    const [category, setCategory] = useState(isTranslation ? family.category : 'UTILITY');
    const [language, setLanguage] = useState('');
    const [allowCategoryChange, setAllowCategoryChange] = useState(false);
    const [comps, setComps] = useState(() =>
        sourceTemplate ? componentsFromTemplate(sourceTemplate) : emptyComponents()
    );
    const [headerExamples, setHeaderExamples] = useState({});
    const [bodyExamples, setBodyExamples] = useState({});
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [apiError, setApiError] = useState(null);
    const [tab, setTab] = useState('edit');
    const [created, setCreated] = useState(null);

    const usedLanguages = useMemo(
        () => new Set((family?.variants ?? []).map(v => v.language)),
        [family]
    );

    const headerVars = useMemo(() => detectVariables(comps.header.text), [comps.header.text]);
    const bodyVars = useMemo(() => detectVariables(comps.body.text), [comps.body.text]);

    useEffect(() => {
        // reset examples when variable list changes
        setHeaderExamples(prev => Object.fromEntries(headerVars.map(n => [n, prev[n] ?? ''])));
    }, [headerVars.join(',')]);
    useEffect(() => {
        setBodyExamples(prev => Object.fromEntries(bodyVars.map(n => [n, prev[n] ?? ''])));
    }, [bodyVars.join(',')]);

    function validate() {
        const e = {};
        if (!NAME_PATTERN.test(name)) e.name = 'Solo minúsculas, números y guiones bajos.';
        if (name.length > 512) e.name = 'Máximo 512 caracteres.';
        if (!language) e.language = 'Selecciona un idioma.';
        if (isTranslation && usedLanguages.has(language)) e.language = 'Esa traducción ya existe.';
        if (!comps.body.text.trim()) e.body = 'El cuerpo es obligatorio.';
        if (comps.body.text.length > 1024) e.body = 'Máximo 1024 caracteres.';
        if (!isSequentialFromOne(bodyVars)) e.body = 'Las variables deben ser {{1}}, {{2}}, {{3}}... sin saltos.';
        if (comps.header.type === 'TEXT') {
            if (!comps.header.text.trim()) e.header = 'Escribe el texto del encabezado.';
            if (comps.header.text.length > 60) e.header = 'Máximo 60 caracteres.';
            if (headerVars.length > 1) e.header = 'El encabezado admite máximo una variable {{1}}.';
            if (!isSequentialFromOne(headerVars)) e.header = 'La variable del encabezado debe ser {{1}}.';
        }
        if (MEDIA_HEADER_TYPES.includes(comps.header.type) && !comps.header.handle) {
            e.header = 'Sube el archivo de muestra del encabezado.';
        }
        if (comps.footer.enabled) {
            if (comps.footer.text.length > 60) e.footer = 'Máximo 60 caracteres.';
            if (comps.footer.text.includes('{{')) e.footer = 'El pie no admite variables.';
        }
        for (const v of bodyVars) {
            if (!bodyExamples[v]) {
                e.body_example = `Falta el ejemplo para {{${v}}}.`;
                break;
            }
        }
        if (comps.header.type === 'TEXT') {
            for (const v of headerVars) {
                if (!headerExamples[v]) {
                    e.header_example = `Falta el ejemplo para {{${v}}}.`;
                    break;
                }
            }
        }

        const counts = { PHONE_NUMBER: 0, URL: 0, COPY_CODE: 0, OTP: 0, QUICK_REPLY: 0 };
        comps.buttons.forEach((b, i) => {
            counts[b.type] = (counts[b.type] ?? 0) + 1;
            if (b.type !== 'OTP' && !b.text.trim()) e[`btn_${i}_text`] = 'Texto requerido.';
            if (b.type === 'URL') {
                if (!b.url.trim()) e[`btn_${i}_url`] = 'URL requerida.';
                const urlVars = detectVariables(b.url);
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

    function buildPayload() {
        const components = [];

        if (comps.header.type === 'TEXT' && comps.header.text.trim()) {
            const h = { type: 'HEADER', format: 'TEXT', text: comps.header.text };
            if (headerVars.length) {
                h.example = { header_text: headerVars.map(n => headerExamples[n]) };
            }
            components.push(h);
        } else if (MEDIA_HEADER_TYPES.includes(comps.header.type) && comps.header.handle) {
            components.push({
                type: 'HEADER',
                format: comps.header.type,
                example: { header_handle: [comps.header.handle] },
            });
        } else if (comps.header.type === 'LOCATION') {
            components.push({ type: 'HEADER', format: 'LOCATION' });
        }

        const b = { type: 'BODY', text: comps.body.text };
        if (bodyVars.length) {
            b.example = { body_text: [bodyVars.map(n => bodyExamples[n])] };
        }
        components.push(b);

        if (comps.footer.enabled && comps.footer.text.trim()) {
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
                        const urlVars = detectVariables(btn.url);
                        if (urlVars.length && btn.url_example?.trim()) {
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
            components,
        };
    }

    async function handleSubmit(e) {
        e.preventDefault();
        const localErrors = validate();
        if (Object.keys(localErrors).length) {
            setErrors(localErrors);
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

    function setHeaderType(type) {
        // Al cambiar de tipo limpiamos texto/archivo para no enviar datos cruzados.
        setComps(p => ({
            ...p,
            header: { type, text: '', handle: '', fileName: '', uploading: false, mediaError: '' },
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

    function addButton() {
        if (comps.buttons.length >= MAX_BUTTONS) return;
        setComps(p => ({
            ...p,
            buttons: [...p.buttons, {
                type: 'QUICK_REPLY',
                text: '',
                url: '',
                url_example: '',
                phone_number: '',
                example: '',
                otp_type: 'COPY_CODE',
                autofill_text: '',
                package_name: '',
                signature_hash: '',
            }],
        }));
    }
    function updateButton(i, patch) {
        setComps(p => ({
            ...p,
            buttons: p.buttons.map((b, idx) => idx === i ? { ...b, ...patch } : b),
        }));
    }
    function removeButton(i) {
        setComps(p => ({ ...p, buttons: p.buttons.filter((_, idx) => idx !== i) }));
    }

    if (created) {
        const tpl = created.template ?? {};
        const wabaId = created.waba_id;
        const metaUrl = wabaId
            ? `https://business.facebook.com/wa/manage/message-templates/?waba_id=${wabaId}`
            : null;
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={() => onCreated(tpl)}>
                <div className="w-full max-w-md rounded-xl border bg-card shadow-2xl" onClick={e => e.stopPropagation()}>
                    <div className="px-6 py-5 space-y-4">
                        <div className="flex items-start gap-3">
                            <div className="size-10 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="size-5"><polyline points="20 6 9 17 4 12"/></svg>
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

                        <div className="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                            Si no la ves en tu Meta Business Manager, abre el botón de abajo y verifica que el WABA ID coincide con el que estás viendo en Meta. Si no coincide, la instancia conectada apunta a otra WABA.
                        </div>

                        <div className="flex gap-2 pt-1">
                            {metaUrl && (
                                <a href={metaUrl} target="_blank" rel="noopener noreferrer" className="flex-1">
                                    <Button type="button" variant="outline" className="w-full">
                                        Abrir en Meta
                                    </Button>
                                </a>
                            )}
                            <Button type="button" onClick={() => onCreated(tpl)} className="flex-1">
                                Cerrar
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-2xl max-h-[92vh] overflow-y-auto rounded-xl border bg-card shadow-2xl" onClick={e => e.stopPropagation()}>
                <div className="sticky top-0 bg-card border-b px-6 py-4 flex items-start justify-between gap-4 z-10">
                    <div>
                        <h2 className="text-lg font-semibold text-foreground">
                            {isTranslation ? `Nueva traducción de "${family.name}"` : 'Nueva plantilla'}
                        </h2>
                        <p className="text-xs text-muted-foreground mt-0.5">
                            {isTranslation
                                ? 'Manten el mismo nombre y categoría, cambia el idioma y traduce el contenido.'
                                : 'Define una plantilla padre. Después podrás añadir traducciones a otros idiomas.'}
                        </p>
                    </div>
                    <Button variant="ghost" size="icon" onClick={onClose}>
                        <X className="size-4" />
                    </Button>
                </div>

                {/* Tab strip */}
                <div className="sticky top-[73px] z-10 bg-card border-b px-6 flex gap-1">
                    <TabButton active={tab === 'edit'} onClick={() => setTab('edit')} icon={Pencil}>
                        Editar
                    </TabButton>
                    <TabButton active={tab === 'preview'} onClick={() => setTab('preview')} icon={Smartphone}>
                        Vista previa
                    </TabButton>
                </div>

                <form onSubmit={handleSubmit} className="px-6 py-5 space-y-5">
                    {apiError && (
                        <div className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            {apiError}
                        </div>
                    )}

                    {tab === 'preview' && (
                        <WhatsAppPreview
                            model={formStateToModel(comps, headerExamples, bodyExamples)}
                            verifiedName={family?.variants?.[0]?.verified_name}
                            empty="Completa al menos el cuerpo del mensaje en la pestaña Editar para ver la vista previa."
                        />
                    )}

                    <div className={tab === 'edit' ? 'space-y-5' : 'hidden'}>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div className="sm:col-span-2 space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Nombre {isTranslation && <span className="text-xs text-muted-foreground">(bloqueado)</span>}</label>
                            <input
                                type="text"
                                value={name}
                                onChange={e => setName(e.target.value.toLowerCase())}
                                disabled={isTranslation}
                                placeholder="cortes_servicio"
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm font-mono shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60"
                            />
                            {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Idioma</label>
                            <select
                                value={language}
                                onChange={e => setLanguage(e.target.value)}
                                className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
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

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium text-foreground">Categoría {isTranslation && <span className="text-xs text-muted-foreground">(bloqueado)</span>}</label>
                            <select
                                value={category}
                                onChange={e => setCategory(e.target.value)}
                                disabled={isTranslation}
                                className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50 disabled:opacity-60"
                            >
                                <option value="UTILITY">UTILITY (Utilidad)</option>
                                <option value="MARKETING">MARKETING</option>
                                <option value="AUTHENTICATION">AUTHENTICATION (Autenticación)</option>
                            </select>
                        </div>
                        {!isTranslation && (
                            <label className="flex items-center gap-2 text-sm text-foreground mt-6">
                                <input
                                    type="checkbox"
                                    checked={allowCategoryChange}
                                    onChange={e => setAllowCategoryChange(e.target.checked)}
                                    className="size-4 rounded border-input"
                                />
                                Permitir que Meta reclasifique la categoría
                            </label>
                        )}
                    </div>

                    {/* HEADER */}
                    <div className="rounded-md border bg-background p-3 space-y-2">
                        <div className="flex items-center justify-between gap-3">
                            <label className="text-sm font-medium text-foreground">Encabezado <span className="text-xs text-muted-foreground">· Opcional</span></label>
                            <select
                                value={comps.header.type}
                                onChange={e => setHeaderType(e.target.value)}
                                className="h-8 rounded-md border border-input bg-transparent px-2 text-xs shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
                            >
                                {HEADER_TYPES.map(h => (
                                    <option key={h.value} value={h.value}>{h.label}</option>
                                ))}
                            </select>
                        </div>

                        {comps.header.type === 'TEXT' && (
                            <>
                                <input
                                    type="text"
                                    value={comps.header.text}
                                    onChange={e => setComps(p => ({ ...p, header: { ...p.header, text: e.target.value } }))}
                                    placeholder="Hola {{1}}"
                                    maxLength={60}
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                />
                                {errors.header && <p className="text-xs text-destructive">{errors.header}</p>}
                                {headerVars.map(v => (
                                    <input
                                        key={v}
                                        type="text"
                                        value={headerExamples[v] ?? ''}
                                        onChange={e => setHeaderExamples(p => ({ ...p, [v]: e.target.value }))}
                                        placeholder={`Ejemplo para {{${v}}}`}
                                        className="flex h-8 w-full rounded-md border border-input bg-transparent px-3 py-1 text-xs"
                                    />
                                ))}
                                {errors.header_example && <p className="text-xs text-destructive">{errors.header_example}</p>}
                            </>
                        )}

                        {MEDIA_HEADER_TYPES.includes(comps.header.type) && (
                            <div className="space-y-1.5">
                                <p className="text-xs text-muted-foreground">
                                    Sube un archivo de muestra ({comps.header.type === 'IMAGE' ? 'JPG/PNG' : comps.header.type === 'VIDEO' ? 'MP4' : 'PDF'}). Meta lo usa solo como ejemplo para aprobar la plantilla.
                                </p>
                                <input
                                    type="file"
                                    accept={MEDIA_ACCEPT[comps.header.type]}
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
                                {errors.header && <p className="text-xs text-destructive">{errors.header}</p>}
                            </div>
                        )}

                        {comps.header.type === 'LOCATION' && (
                            <p className="text-xs text-muted-foreground">
                                La ubicación (latitud, longitud y nombre) se define al enviar el mensaje, no en la plantilla.
                            </p>
                        )}
                    </div>

                    {/* BODY */}
                    <div className="rounded-md border bg-background p-3 space-y-2">
                        <label className="text-sm font-medium text-foreground">Cuerpo *</label>
                        <textarea
                            value={comps.body.text}
                            onChange={e => setComps(p => ({ ...p, body: { text: e.target.value } }))}
                            placeholder="Hola {{1}}, te recordamos que tu servicio será cortado el {{2}} si no realizas el pago."
                            rows={5}
                            maxLength={1024}
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs resize-y"
                        />
                        <div className="flex justify-between text-xs text-muted-foreground">
                            <span>Usa {'{{1}}, {{2}}'} para variables.</span>
                            <span>{comps.body.text.length}/1024</span>
                        </div>
                        {errors.body && <p className="text-xs text-destructive">{errors.body}</p>}
                        {bodyVars.length > 0 && (
                            <div className="space-y-1.5 pt-1">
                                <p className="text-xs text-muted-foreground">Ejemplos para variables:</p>
                                {bodyVars.map(v => (
                                    <input
                                        key={v}
                                        type="text"
                                        value={bodyExamples[v] ?? ''}
                                        onChange={e => setBodyExamples(p => ({ ...p, [v]: e.target.value }))}
                                        placeholder={`Ejemplo para {{${v}}}`}
                                        className="flex h-8 w-full rounded-md border border-input bg-transparent px-3 py-1 text-xs"
                                    />
                                ))}
                                {errors.body_example && <p className="text-xs text-destructive">{errors.body_example}</p>}
                            </div>
                        )}
                    </div>

                    {/* FOOTER */}
                    <div className="rounded-md border bg-background p-3 space-y-2">
                        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
                            <input
                                type="checkbox"
                                checked={comps.footer.enabled}
                                onChange={e => setComps(p => ({ ...p, footer: { ...p.footer, enabled: e.target.checked } }))}
                                className="size-4 rounded border-input"
                            />
                            Pie de página (opcional)
                        </label>
                        {comps.footer.enabled && (
                            <>
                                <input
                                    type="text"
                                    value={comps.footer.text}
                                    onChange={e => setComps(p => ({ ...p, footer: { ...p.footer, text: e.target.value } }))}
                                    placeholder="Equipo de soporte"
                                    maxLength={60}
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                />
                                {errors.footer && <p className="text-xs text-destructive">{errors.footer}</p>}
                            </>
                        )}
                    </div>

                    {/* BUTTONS */}
                    <div className="rounded-md border bg-background p-3 space-y-3">
                        <div className="flex items-center justify-between">
                            <label className="text-sm font-medium text-foreground">Botones (hasta {MAX_BUTTONS})</label>
                            <Button type="button" variant="outline" size="sm" onClick={addButton} disabled={comps.buttons.length >= MAX_BUTTONS} className="gap-1">
                                <Plus className="size-3.5" /> Añadir
                            </Button>
                        </div>
                        {errors._buttons && <p className="text-xs text-destructive">{errors._buttons}</p>}
                        <p className="text-[11px] text-muted-foreground">
                            Meta: máx. 1 teléfono · 2 URL · 1 COPY_CODE · 1 OTP. AUTHENTICATION requiere OTP.
                        </p>
                        {comps.buttons.map((btn, i) => {
                            const urlVarCount = btn.type === 'URL' ? detectVariables(btn.url).length : 0;
                            return (
                            <div key={i} className="rounded-md border bg-muted/30 p-2 space-y-2">
                                <div className="flex gap-2">
                                    <select
                                        value={btn.type}
                                        onChange={e => updateButton(i, { type: e.target.value })}
                                        className="h-8 rounded-md border border-input bg-transparent px-2 text-xs"
                                    >
                                        <option value="QUICK_REPLY">Respuesta rápida</option>
                                        <option value="URL">URL</option>
                                        <option value="PHONE_NUMBER">Teléfono</option>
                                        <option value="COPY_CODE">Copiar código</option>
                                        <option value="OTP">OTP (AUTHENTICATION)</option>
                                    </select>
                                    {btn.type !== 'OTP' && (
                                        <input
                                            type="text"
                                            value={btn.text}
                                            onChange={e => updateButton(i, { text: e.target.value })}
                                            placeholder="Texto del botón"
                                            maxLength={25}
                                            className="flex-1 h-8 rounded-md border border-input bg-transparent px-2 text-xs"
                                        />
                                    )}
                                    {btn.type === 'OTP' && (
                                        <select
                                            value={btn.otp_type || 'COPY_CODE'}
                                            onChange={e => updateButton(i, { otp_type: e.target.value })}
                                            className="flex-1 h-8 rounded-md border border-input bg-transparent px-2 text-xs"
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
                                            className="flex h-8 w-full rounded-md border border-input bg-transparent px-2 text-xs"
                                        />
                                        {errors[`btn_${i}_url`] && <p className="text-xs text-destructive">{errors[`btn_${i}_url`]}</p>}
                                        {urlVarCount > 0 && (
                                            <>
                                                <input
                                                    type="url"
                                                    value={btn.url_example || ''}
                                                    onChange={e => updateButton(i, { url_example: e.target.value })}
                                                    placeholder="Ejemplo de URL completa: https://midominio.com/ruta/abc123"
                                                    className="flex h-8 w-full rounded-md border border-input bg-transparent px-2 text-xs"
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
                                            className="flex h-8 w-full rounded-md border border-input bg-transparent px-2 text-xs"
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
                                            className="flex h-8 w-full rounded-md border border-input bg-transparent px-2 text-xs"
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
                                            className="h-8 rounded-md border border-input bg-transparent px-2 text-xs"
                                        />
                                        <input
                                            type="text"
                                            value={btn.signature_hash || ''}
                                            onChange={e => updateButton(i, { signature_hash: e.target.value })}
                                            placeholder="signature_hash"
                                            className="h-8 rounded-md border border-input bg-transparent px-2 text-xs"
                                        />
                                        <input
                                            type="text"
                                            value={btn.autofill_text || ''}
                                            onChange={e => updateButton(i, { autofill_text: e.target.value })}
                                            placeholder="autofill_text (opcional)"
                                            maxLength={25}
                                            className="h-8 rounded-md border border-input bg-transparent px-2 text-xs sm:col-span-2"
                                        />
                                    </div>
                                )}
                            </div>
                            );
                        })}
                    </div>

                    </div>

                    <div className="flex gap-2 pt-2">
                        <Button type="submit" className="flex-1 gap-2" disabled={submitting}>
                            {submitting && <Loader2 className="size-4 animate-spin" />}
                            {isTranslation ? 'Crear traducción' : 'Crear plantilla'}
                        </Button>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

