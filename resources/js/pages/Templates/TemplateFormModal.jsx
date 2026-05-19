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

function emptyComponents() {
    return {
        header: { enabled: false, text: '' },
        body: { text: '' },
        footer: { enabled: false, text: '' },
        buttons: [],
    };
}

function componentsFromTemplate(template) {
    const out = emptyComponents();
    for (const c of template?.components ?? []) {
        if (c.type === 'HEADER' && (c.format ?? 'TEXT') === 'TEXT') {
            out.header = { enabled: true, text: c.text ?? '' };
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
        if (!language) e.language = 'Selecciona un idioma.';
        if (isTranslation && usedLanguages.has(language)) e.language = 'Esa traducción ya existe.';
        if (!comps.body.text.trim()) e.body = 'El cuerpo es obligatorio.';
        if (comps.body.text.length > 1024) e.body = 'Máximo 1024 caracteres.';
        if (comps.header.enabled && comps.header.text.length > 60) e.header = 'Máximo 60 caracteres.';
        if (comps.footer.enabled && comps.footer.text.length > 60) e.footer = 'Máximo 60 caracteres.';
        for (const v of bodyVars) {
            if (!bodyExamples[v]) {
                e.body_example = `Falta el ejemplo para {{${v}}}.`;
                break;
            }
        }
        if (comps.header.enabled) {
            for (const v of headerVars) {
                if (!headerExamples[v]) {
                    e.header_example = `Falta el ejemplo para {{${v}}}.`;
                    break;
                }
            }
        }
        comps.buttons.forEach((b, i) => {
            if (!b.text.trim()) e[`btn_${i}_text`] = 'Texto requerido.';
            if (b.type === 'URL' && !b.url.trim()) e[`btn_${i}_url`] = 'URL requerida.';
            if (b.type === 'PHONE_NUMBER' && !b.phone_number.trim()) e[`btn_${i}_phone`] = 'Teléfono requerido.';
        });
        return e;
    }

    function buildPayload() {
        const components = [];

        if (comps.header.enabled && comps.header.text.trim()) {
            const h = { type: 'HEADER', format: 'TEXT', text: comps.header.text };
            if (headerVars.length) {
                h.example = { header_text: headerVars.map(n => headerExamples[n]) };
            }
            components.push(h);
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
                    const out = { type: btn.type, text: btn.text };
                    if (btn.type === 'URL') out.url = btn.url;
                    if (btn.type === 'PHONE_NUMBER') out.phone_number = btn.phone_number;
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
            onCreated(res.data.data);
        } catch (err) {
            if (err?.response?.status === 422) {
                setApiError('Datos inválidos. Revisa los campos.');
            } else {
                const meta = err?.response?.data?.error?.error;
                setApiError(meta?.error_user_msg || meta?.message || err?.response?.data?.message || 'No se pudo crear la plantilla.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    function addButton() {
        if (comps.buttons.length >= 3) return;
        setComps(p => ({
            ...p,
            buttons: [...p.buttons, { type: 'QUICK_REPLY', text: '', url: '', phone_number: '' }],
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
                        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
                            <input
                                type="checkbox"
                                checked={comps.header.enabled}
                                onChange={e => setComps(p => ({ ...p, header: { ...p.header, enabled: e.target.checked } }))}
                                className="size-4 rounded border-input"
                            />
                            Encabezado (texto, opcional)
                        </label>
                        {comps.header.enabled && (
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
                            <label className="text-sm font-medium text-foreground">Botones (hasta 3)</label>
                            <Button type="button" variant="outline" size="sm" onClick={addButton} disabled={comps.buttons.length >= 3} className="gap-1">
                                <Plus className="size-3.5" /> Añadir
                            </Button>
                        </div>
                        {comps.buttons.map((btn, i) => (
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
                                    </select>
                                    <input
                                        type="text"
                                        value={btn.text}
                                        onChange={e => updateButton(i, { text: e.target.value })}
                                        placeholder="Texto del botón"
                                        maxLength={25}
                                        className="flex-1 h-8 rounded-md border border-input bg-transparent px-2 text-xs"
                                    />
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
                                            placeholder="https://..."
                                            className="flex h-8 w-full rounded-md border border-input bg-transparent px-2 text-xs"
                                        />
                                        {errors[`btn_${i}_url`] && <p className="text-xs text-destructive">{errors[`btn_${i}_url`]}</p>}
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
                            </div>
                        ))}
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

