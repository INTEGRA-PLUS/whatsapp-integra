import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import CabeceraModulo from '@/components/cabecera-modulo';
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
    CheckCircle2,
    Languages,
    FileType,
    Pencil,
    TriangleAlert,
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

const CATEGORY_LABEL = { MARKETING: 'Marketing', UTILITY: 'Utilidad', AUTHENTICATION: 'Autenticación' };

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
// Lo que acepta la subida reanudable de Meta, que es por donde sale la muestra
// del encabezado: jpeg, png, mp4 y pdf, nada más. Se ofrecía también
// `video/3gpp`, que el selector dejaba elegir y Meta rechazaba después de
// subirlo, con un error que no decía por qué.
const MEDIA_ACCEPT = {
    IMAGE: 'image/jpeg,image/png',
    VIDEO: 'video/mp4',
    DOCUMENT: 'application/pdf',
};
const MEDIA_MIME = {
    IMAGE: ['image/jpeg', 'image/jpg', 'image/png'],
    VIDEO: ['video/mp4'],
    DOCUMENT: ['application/pdf'],
};
// Los límites de tamaño de Meta para cada tipo de archivo.
const MEDIA_MAX_MB = { IMAGE: 5, VIDEO: 16, DOCUMENT: 100 };
const MEDIA_LABEL = { IMAGE: 'JPG o PNG', VIDEO: 'MP4', DOCUMENT: 'PDF' };

const MAX_BUTTONS = 10;
const BUTTON_LIMITS = {
    PHONE_NUMBER: 1,
    URL: 2,
    COPY_CODE: 1,
};

/**
 * Una plantilla de autenticación no tiene texto propio.
 *
 * Meta escribe el cuerpo («*123456* es tu código de verificación»), el aviso
 * de seguridad y el pie con la caducidad, cada uno traducido al idioma de la
 * plantilla. Lo único que se elige es si llevan aviso y caducidad, y cómo se
 * entrega el código: un botón para copiarlo o el autocompletado en una app
 * Android. El editor dejaba escribir un cuerpo libre y Meta lo rechazaba.
 */
function emptyAuth() {
    return {
        add_security_recommendation: true,
        code_expiration_minutes: '10',
        otp_type: 'COPY_CODE',
        text: '',
        autofill_text: '',
        supported_apps: [{ package_name: '', signature_hash: '' }],
        zero_tap_terms_accepted: false,
    };
}

function authFromTemplate(template) {
    const out = emptyAuth();
    out.code_expiration_minutes = '';
    out.add_security_recommendation = false;
    for (const c of template?.components ?? []) {
        if (c.type === 'BODY') {
            out.add_security_recommendation = !!c.add_security_recommendation;
        } else if (c.type === 'FOOTER' && c.code_expiration_minutes) {
            out.code_expiration_minutes = String(c.code_expiration_minutes);
        } else if (c.type === 'BUTTONS') {
            const otp = (c.buttons ?? []).find(b => b.type === 'OTP');
            if (otp) {
                out.otp_type = otp.otp_type ?? 'COPY_CODE';
                out.text = otp.text ?? '';
                out.autofill_text = otp.autofill_text ?? '';
                // Las plantillas viejas traían una sola app suelta en el botón.
                const apps = otp.supported_apps?.length
                    ? otp.supported_apps
                    : (otp.package_name ? [{ package_name: otp.package_name, signature_hash: otp.signature_hash ?? '' }] : []);
                out.supported_apps = apps.length
                    ? apps.map(a => ({ package_name: a.package_name ?? '', signature_hash: a.signature_hash ?? '' }))
                    : [{ package_name: '', signature_hash: '' }];
                out.zero_tap_terms_accepted = !!otp.zero_tap_terms_accepted;
            }
        }
    }
    return out;
}

const PACKAGE_NAME_PATTERN = /^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/;
const SIGNATURE_HASH_PATTERN = /^[a-zA-Z0-9+/=]{11}$/;
const MAX_SUPPORTED_APPS = 5;

// Lo que Meta no admite en el encabezado de texto: saltos de línea, emojis y
// los caracteres de formato de WhatsApp.
const EMOJI_PATTERN = /\p{Extended_Pictographic}/u;
const FORMAT_CHARS_PATTERN = /[*_~`]/;

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

/**
 * El estado del formulario a partir de una plantilla de Meta.
 *
 * Con `conservarMuestras` (al editar) se quedan también los ejemplos de los
 * botones y la URL del archivo de muestra del encabezado: la plantilla es la
 * misma y se espera poder guardarla sin volver a subir nada. El servidor
 * convierte esa URL en un handle nuevo antes de mandarla a Meta.
 */
function componentsFromTemplate(template, { conservarMuestras = false } = {}) {
    const out = emptyComponents();
    for (const c of template?.components ?? []) {
        if (c.type === 'HEADER' && (c.format ?? 'TEXT') === 'TEXT') {
            out.header = { ...out.header, media: 'NONE', text: c.text ?? '' };
        } else if (c.type === 'HEADER') {
            // Encabezado multimedia: el handle de muestra es de un solo uso, así que
            // en traducciones/duplicados se exige volver a subir el archivo.
            const muestra = conservarMuestras ? (c.example?.header_handle?.[0] ?? '') : '';
            out.header = {
                ...out.header,
                media: c.format ?? 'IMAGE',
                handle: muestra,
                fileName: muestra ? 'el archivo actual de la plantilla' : '',
            };
        } else if (c.type === 'BODY') {
            out.body = { text: c.text ?? '' };
        } else if (c.type === 'FOOTER') {
            out.footer = { text: c.text ?? '' };
        } else if (c.type === 'BUTTONS') {
            out.buttons = (c.buttons ?? []).map(b => ({
                type: b.type ?? 'QUICK_REPLY',
                text: b.text ?? '',
                url: b.url ?? '',
                url_example: conservarMuestras && b.type === 'URL' ? (b.example?.[0] ?? '') : '',
                phone_number: b.phone_number ?? '',
                example: conservarMuestras && b.type === 'COPY_CODE' ? (b.example?.[0] ?? '') : '',
                otp_type: b.otp_type ?? 'COPY_CODE',
                autofill_text: b.autofill_text ?? '',
                package_name: b.package_name ?? '',
                signature_hash: b.signature_hash ?? '',
            }));
        }
    }
    return out;
}

/**
 * Los valores de ejemplo de las variables, por token, tal como Meta los guarda.
 * Sin ellos, editar una plantilla con variables obligaba a reescribir todos
 * los ejemplos aunque sólo se quisiera corregir una palabra.
 */
function examplesFromTemplate(template) {
    const header = {};
    const body = {};
    for (const c of template?.components ?? []) {
        if (c.type === 'HEADER') {
            for (const p of c.example?.header_text_named_params ?? []) header[p.param_name] = p.example ?? '';
            detectVars(c.text).forEach((t, i) => {
                if (header[t] === undefined && c.example?.header_text?.[i] !== undefined) header[t] = c.example.header_text[i];
            });
        } else if (c.type === 'BODY') {
            for (const p of c.example?.body_text_named_params ?? []) body[p.param_name] = p.example ?? '';
            const valores = c.example?.body_text?.[0] ?? [];
            detectVars(c.text).forEach((t, i) => {
                if (body[t] === undefined && valores[i] !== undefined) body[t] = valores[i];
            });
        }
    }
    return { header, body };
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
    const isEdit = prefill.mode === 'edit';
    const familyName = prefill.family ?? '';

    // La instancia sale de la URL. Si no viene, al editar o traducir no se
    // toma la primera en silencio: una empresa con dos líneas tiene dos
    // catálogos en Meta, y el id de la plantilla sólo existe en uno. Antes se
    // caía a `instances[0]` y la plantilla «no cargaba» —o, peor, la traducción
    // se creaba en la otra línea—. Con una sola instancia no hay duda posible;
    // al crear, el selector está a la vista y la primera es sólo el punto de
    // partida.
    const [instanceId, setInstanceId] = useState(() => {
        const fromQuery = parseInt(prefill.instance_id, 10);
        if (Number.isFinite(fromQuery) && instances.some(i => i.id === fromQuery)) return fromQuery;
        if (instances.length === 1) return instances[0].id;
        if (isEdit || isTranslation) return null;
        return instances[0]?.id ?? null;
    });
    const faltaInstancia = (isEdit || isTranslation) && !instanceId;

    const [step, setStep] = useState(1);
    const [name, setName] = useState(isTranslation ? familyName : '');
    const [category, setCategory] = useState('UTILITY');
    const [language, setLanguage] = useState('');
    const [parameterFormat, setParameterFormat] = useState('POSITIONAL');
    const [comps, setComps] = useState(emptyComponents);
    const [auth, setAuth] = useState(emptyAuth);
    const [headerExamples, setHeaderExamples] = useState({});
    const [bodyExamples, setBodyExamples] = useState({});
    const [errors, setErrors] = useState({});
    const [apiError, setApiError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [created, setCreated] = useState(null);
    const [usedLanguages, setUsedLanguages] = useState(() => new Set());
    const [familyVerifiedName, setFamilyVerifiedName] = useState(null);
    const [loadingSource, setLoadingSource] = useState(false);
    // La plantilla tal como está en Meta, al editar. Su estado decide qué se
    // puede tocar: la categoría de una aprobada, por ejemplo, no.
    const [original, setOriginal] = useState(null);

    const bodyRef = useRef(null);
    const [emojiOpen, setEmojiOpen] = useState(false);

    // En modo traducción cargamos la familia desde Meta para bloquear idiomas
    // usados y prellenar el contenido con la plantilla de origen.
    useEffect(() => {
        if (!isTranslation || !familyName || !instanceId) return;
        let cancelled = false;
        setLoadingSource(true);
        axios.get(`/api/templates/family/${encodeURIComponent(familyName)}`, {
            params: { instance_id: instanceId },
        })
            .then(({ data }) => {
                if (cancelled) return;
                // Meta busca por `name` como «contiene»: pedir `pago` trae
                // también `pago_recibido`, y sus idiomas se daban por usados.
                const variants = (data.data || []).filter(v => v.name === familyName);
                setUsedLanguages(new Set(variants.map(v => v.language)));
                setFamilyVerifiedName(variants[0]?.verified_name ?? null);
                const source = variants.find(v => String(v.id) === String(prefill.source_id))
                    ?? variants.find(v => v.status === 'APPROVED')
                    ?? variants[0];
                if (source) {
                    setCategory(source.category ?? 'UTILITY');
                    setComps(componentsFromTemplate(source));
                    if (source.category === 'AUTHENTICATION') setAuth(authFromTemplate(source));
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
    }, [instanceId]);

    // Al editar se carga la plantilla entera, con sus ejemplos y su muestra.
    useEffect(() => {
        if (!isEdit || !prefill.template_id || !instanceId) return;
        let cancelled = false;
        setLoadingSource(true);
        axios.get(`/api/templates/${prefill.template_id}`, { params: { instance_id: instanceId } })
            .then(({ data }) => {
                if (cancelled) return;
                const tpl = data.data ?? {};
                setOriginal(tpl);
                setName(tpl.name ?? '');
                setLanguage(tpl.language ?? '');
                setCategory(tpl.category ?? 'UTILITY');
                setComps(componentsFromTemplate(tpl, { conservarMuestras: true }));
                if (tpl.category === 'AUTHENTICATION') setAuth(authFromTemplate(tpl));
                const textos = (tpl.components ?? []).map(c => c.text ?? '').join(' ');
                setParameterFormat(
                    tpl.parameter_format
                        ?? (detectVars(textos).some(t => !isNumeric(t)) ? 'NAMED' : 'POSITIONAL')
                );
                const ejemplos = examplesFromTemplate(tpl);
                setHeaderExamples(ejemplos.header);
                setBodyExamples(ejemplos.body);
            })
            .catch(err => {
                if (cancelled) return;
                setApiError(err?.response?.data?.message ?? 'No se pudo cargar la plantilla.');
            })
            .finally(() => !cancelled && setLoadingSource(false));
        return () => { cancelled = true; };
    }, [instanceId]);

    const isAuth = category === 'AUTHENTICATION';
    const editable = !isEdit || ['APPROVED', 'REJECTED', 'PAUSED'].includes(original?.status);
    const categoriaBloqueada = isTranslation || (isEdit && original?.status === 'APPROVED');

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

    const previewModel = useMemo(() => isAuth ? authPreviewModel(auth) : formStateToModel(
        {
            header: { type: headerType, text: comps.header.text },
            body: comps.body,
            footer: { enabled: !!comps.footer.text.trim(), text: comps.footer.text },
            buttons: comps.buttons,
        },
        headerExamples,
        bodyExamples,
    ), [isAuth, auth, comps, headerType, headerExamples, bodyExamples]);

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
        if (isEdit && !original) e.instance = 'La plantilla todavía no ha cargado.';
        if (!instanceId) e.instance = 'Selecciona una instancia.';
        return e;
    }

    function validateAuth() {
        const e = {};
        const min = auth.code_expiration_minutes;
        if (min !== '' && min !== null) {
            const n = Number(min);
            if (!Number.isInteger(n) || n < 1 || n > 90) {
                e.auth_expiration = 'La caducidad va de 1 a 90 minutos.';
            }
        }
        if (auth.text.length > 25) e.auth_text = 'Máximo 25 caracteres.';
        if (auth.otp_type !== 'COPY_CODE') {
            if (auth.autofill_text.length > 25) e.auth_autofill = 'Máximo 25 caracteres.';
            const apps = auth.supported_apps;
            if (!apps.length) e.auth_apps = 'Añade al menos una app Android.';
            if (apps.length > MAX_SUPPORTED_APPS) e.auth_apps = `Meta admite como mucho ${MAX_SUPPORTED_APPS} apps.`;
            apps.forEach((app, i) => {
                if (!PACKAGE_NAME_PATTERN.test(app.package_name.trim()) || app.package_name.trim().length > 224) {
                    e[`auth_app_${i}_package`] = 'Nombre de paquete inválido: al menos dos partes separadas por punto, cada una empezando por letra (com.tuempresa.app).';
                }
                if (!SIGNATURE_HASH_PATTERN.test(app.signature_hash.trim())) {
                    e[`auth_app_${i}_hash`] = 'El hash de firma tiene exactamente 11 caracteres (letras, números, +, / o =).';
                }
            });
            if (auth.otp_type === 'ZERO_TAP' && !auth.zero_tap_terms_accepted) {
                e.auth_terms = 'Para el autocompletado sin toque hay que aceptar los términos de Meta.';
            }
        }
        return e;
    }

    /**
     * Lo que Meta rechaza al revisar la plantilla, comprobado antes de mandarla.
     *
     * Cada uno de estos llegaba como un (#100) o un rechazo horas después, con
     * un mensaje en inglés que no decía qué tocar.
     */
    function validateStep2() {
        if (isAuth) return validateAuth();

        const e = {};
        const body = comps.body.text;
        if (!body.trim()) e.body = 'El cuerpo es obligatorio.';
        if (body.length > 1024) e.body = 'Máximo 1024 caracteres.';
        if (/^\s*\{\{[^}]*\}\}/.test(body)) e.body = 'El cuerpo no puede empezar con una variable: pon texto antes.';
        else if (/\{\{[^}]*\}\}\s*$/.test(body)) e.body = 'El cuerpo no puede terminar con una variable: pon texto después (basta un punto).';
        else if (/\}\}\s*\{\{/.test(body)) e.body = 'Dos variables no pueden ir seguidas: pon al menos una palabra entre ellas.';
        const bodyVarErr = validateVarKind(bodyVars, 'Cuerpo');
        if (bodyVarErr) e.body = bodyVarErr;

        if (headerType === 'TEXT') {
            const ht = comps.header.text;
            if (ht.length > 60) e.header = 'Máximo 60 caracteres.';
            if (/[\r\n]/.test(ht)) e.header = 'El título no admite saltos de línea.';
            else if (EMOJI_PATTERN.test(ht)) e.header = 'El título no admite emojis.';
            else if (FORMAT_CHARS_PATTERN.test(ht)) e.header = 'El título no admite formato (*, _, ~ ni `).';
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

        const counts = { PHONE_NUMBER: 0, URL: 0, COPY_CODE: 0, QUICK_REPLY: 0 };
        comps.buttons.forEach((b, i) => {
            counts[b.type] = (counts[b.type] ?? 0) + 1;
            if (!b.text.trim()) e[`btn_${i}_text`] = 'Texto requerido.';
            if (b.type === 'URL') {
                const url = b.url.trim();
                if (!url) e[`btn_${i}_url`] = 'URL requerida.';
                const urlVars = detectVars(url);
                if (urlVars.length > 1) {
                    e[`btn_${i}_url`] = 'La URL admite una sola variable, {{1}}, al final.';
                } else if (urlVars.length === 1 && !/\{\{\s*1\s*\}\}$/.test(url)) {
                    // Meta sólo deja variable el final de la URL: el dominio y
                    // la ruta los revisa al aprobar, y no los puede revisar si
                    // cambian en cada envío.
                    e[`btn_${i}_url`] = 'La variable de la URL tiene que ser {{1}} y estar al final.';
                }
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
        // Las respuestas rápidas van juntas: Meta rechaza una lista que las
        // mezcla con los de llamada a la acción (rápida, enlace, rápida).
        const tipos = comps.buttons.map(b => b.type === 'QUICK_REPLY');
        const primera = tipos.indexOf(true);
        const ultima = tipos.lastIndexOf(true);
        if (primera !== -1 && tipos.slice(primera, ultima + 1).some(v => !v)) {
            e._buttons = 'Las respuestas rápidas tienen que ir seguidas, todas antes o todas después de los demás botones.';
        }
        return e;
    }

    function goNext() {
        const e = validateStep1();
        setErrors(e);
        if (Object.keys(e).length === 0) setStep(2);
    }

    function buildAuthComponents() {
        const components = [
            { type: 'BODY', add_security_recommendation: !!auth.add_security_recommendation },
        ];
        if (auth.code_expiration_minutes !== '' && auth.code_expiration_minutes !== null) {
            components.push({ type: 'FOOTER', code_expiration_minutes: Number(auth.code_expiration_minutes) });
        }
        const otp = { type: 'OTP', otp_type: auth.otp_type };
        if (auth.text.trim()) otp.text = auth.text.trim();
        if (auth.otp_type !== 'COPY_CODE') {
            if (auth.autofill_text.trim()) otp.autofill_text = auth.autofill_text.trim();
            otp.supported_apps = auth.supported_apps.map(a => ({
                package_name: a.package_name.trim(),
                signature_hash: a.signature_hash.trim(),
            }));
        }
        if (auth.otp_type === 'ZERO_TAP') otp.zero_tap_terms_accepted = !!auth.zero_tap_terms_accepted;
        components.push({ type: 'BUTTONS', buttons: [otp] });
        return components;
    }

    function buildPayload() {
        if (isAuth) {
            return { instance_id: instanceId, name, language, category, components: buildAuthComponents() };
        }
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
                    const out = { type: btn.type, text: btn.text };
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
                    return out;
                }),
            });
        }

        return {
            instance_id: instanceId,
            name,
            language,
            category,
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
            // Al editar sólo viajan categoría y contenido: nombre e idioma no
            // se pueden cambiar en Meta, y el servidor no los acepta.
            const payload = buildPayload();
            const res = isEdit
                ? await axios.post(`/api/templates/${prefill.template_id}`, {
                    instance_id: payload.instance_id,
                    category: payload.category,
                    ...(payload.parameter_format ? { parameter_format: payload.parameter_format } : {}),
                    components: payload.components,
                })
                : await axios.post('/api/templates', payload);
            setCreated({
                template: res.data.data,
                waba_id: res.data.waba_id,
                instance: res.data.instance,
                verified_in_meta: res.data.verified_in_meta,
                editada: !!res.data.editada,
                categoria_cambiada: res.data.categoria_cambiada ?? null,
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
                    || (isEdit ? 'No se pudieron guardar los cambios.' : 'No se pudo crear la plantilla.')
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
        // Se comprueba aquí y no después de subirlo: un video de 40 MB tardaba
        // en subir para que Meta lo rechazara al final.
        const media = comps.header.media;
        const tipoMal = !(MEDIA_MIME[media] ?? []).includes((file.type || '').toLowerCase());
        const maxMb = MEDIA_MAX_MB[media];
        const grande = maxMb && file.size > maxMb * 1024 * 1024;
        if (tipoMal || grande) {
            setComps(p => ({
                ...p,
                header: {
                    ...p.header, handle: '', fileName: '', uploading: false,
                    mediaError: tipoMal
                        ? `Meta solo acepta ${MEDIA_LABEL[media]} para este encabezado.`
                        : `El archivo pesa ${(file.size / 1024 / 1024).toFixed(1)} MB y Meta admite hasta ${maxMb} MB.`,
                },
            }));
            return;
        }
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
                example: '',
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
                <Head title={created.editada ? 'Cambios enviados' : 'Plantilla enviada'} />
                <CreatedScreen created={created} />
            </>
        );
    }

    return (
        <>
            <Head title={isEdit ? `Editar · ${name || 'plantilla'}` : isTranslation ? `Nueva traducción · ${familyName}` : 'Crear plantilla'} />
            <div className="flex flex-col min-h-[calc(100vh-3rem)]">
                {/* Encabezado de página: sobre el mismo fondo que el cuerpo,
                    para que no quede una franja de otro color encima. */}
                <div className="bg-muted/20 px-6 pt-6">
                    <div className="max-w-6xl mx-auto w-full">
                        <CabeceraModulo
                            icono={isEdit ? Pencil : isTranslation ? Languages : FileType}
                            volver={route('templates.index')}
                            accionesClassName="hidden sm:flex"
                            titulo={isEdit
                                ? <>Editar <span className="font-mono">{name || 'plantilla'}</span>{language ? <span className="font-mono text-muted-foreground"> · {language}</span> : null}</>
                                : isTranslation ? <>Nueva traducción de <span className="font-mono">{familyName}</span></> : 'Crear plantilla'}
                            descripcion={isEdit
                                ? 'El nombre y el idioma no se pueden cambiar. Al guardar, Meta vuelve a revisar la plantilla.'
                                : isTranslation
                                ? 'La subplantilla mantiene el nombre y la categoría; solo cambia el idioma y el contenido.'
                                : 'Meta revisará el contenido y las variables de la plantilla antes de aprobarla.'}
                        >
                            <Stepper step={step} />
                        </CabeceraModulo>
                    </div>
                </div>

                {/* Cuerpo: formulario + vista previa */}
                <div className="flex-1 bg-muted/20">
                    <div className="max-w-6xl mx-auto w-full grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 px-6 py-6">
                        <div className="space-y-5">
                            {apiError && (
                                <div className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                    {apiError}
                                </div>
                            )}

                            {faltaInstancia && (
                                <ElegirInstancia instances={instances} onElegir={setInstanceId} isEdit={isEdit} />
                            )}

                            {loadingSource && (
                                <div className="flex items-center gap-2 rounded-lg border bg-card px-4 py-3 text-sm text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" /> {isEdit ? 'Cargando la plantilla…' : 'Cargando plantilla de origen…'}
                                </div>
                            )}

                            {/* Lo que Meta no cuenta hasta que ya pasó: mientras
                                revisa la edición, la plantilla no se puede enviar.
                                Aquí hay plantillas —las facturas y tirillas del
                                ERP— que salen solas todo el día, y editarlas a
                                media mañana es dejar de mandarlas hasta que Meta
                                conteste. */}
                            {isEdit && original && editable && (
                                <div className="flex gap-2.5 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                                    <div className="space-y-1 text-foreground">
                                        <p className="font-semibold">Al guardar, Meta vuelve a revisar la plantilla.</p>
                                        <p className="text-muted-foreground">
                                            Mientras la revisa <strong className="text-foreground">no se puede enviar</strong>: suele
                                            tardar minutos, pero puede llegar a 24 horas. Si la usas para facturas, campañas o
                                            envíos automáticos, esos mensajes fallarán hasta que Meta la apruebe.
                                        </p>
                                        {original.status === 'APPROVED' && (
                                            <p className="text-muted-foreground">
                                                Una plantilla aprobada solo se puede editar <strong className="text-foreground">una vez
                                                cada 24 horas</strong> y 10 veces al mes, y su categoría no se puede cambiar.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {isEdit && original && !editable && (
                                <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                                    Meta solo deja editar plantillas aprobadas, rechazadas o pausadas. Esta está
                                    en estado <strong>{original.status}</strong>: espera a que Meta termine de revisarla.
                                </div>
                            )}

                            {!faltaInstancia && step === 1 && (
                                <StepConfig
                                    isTranslation={isTranslation}
                                    isEdit={isEdit}
                                    categoriaBloqueada={categoriaBloqueada}
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
                                    errors={errors}
                                />
                            )}

                            {!faltaInstancia && step === 2 && isAuth && (
                                <AuthContent auth={auth} setAuth={setAuth} errors={errors} />
                            )}

                            {!faltaInstancia && step === 2 && !isAuth && (
                                <StepContent
                                    comps={comps}
                                    setComps={setComps}
                                    parameterFormat={parameterFormat}
                                    setParameterFormat={setParameterFormat}
                                    formatoBloqueado={isEdit}
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

                {/* La barra de abajo, pegada al pie del formulario y no a la
                    ventana. Con `fixed left-0 right-0` se estiraba por debajo
                    del menú lateral, así que «Cancelar» quedaba flotando sobre
                    la navegación y parecía pertenecerle. `sticky` la mantiene
                    a la vista igual, pero dentro del ancho del contenido. */}
                <div className="sticky bottom-0 z-30 border-t bg-card/95 backdrop-blur">
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
                                <Button type="button" onClick={goNext} disabled={faltaInstancia}>Siguiente</Button>
                            ) : (
                                <Button type="button" onClick={handleSubmit} disabled={submitting || (!isAuth && !comps.body.text.trim()) || !editable} className="gap-2">
                                    {submitting && <Loader2 className="size-4 animate-spin" />}
                                    {isEdit ? 'Guardar y enviar a revisión' : 'Enviar para revisión'}
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
    isTranslation, isEdit = false, categoriaBloqueada = false, instances, instanceId, setInstanceId,
    name, setName, category, setCategory, language, setLanguage,
    usedLanguages, errors,
}) {
    return (
        <div className="rounded-xl border bg-card p-5 space-y-5">
            <div>
                <h2 className="text-base font-semibold text-foreground">Configura tu plantilla</h2>
                <p className="text-xs text-muted-foreground mt-1">
                    {isEdit
                        ? 'El nombre y el idioma son fijos en Meta. La categoría solo se puede cambiar si la plantilla no está aprobada.'
                        : <>Elige la categoría, el nombre y el idioma. {isTranslation ? 'El nombre y la categoría vienen de la plantilla principal.' : 'Después podrás añadir traducciones (subplantillas) a otros idiomas.'}</>}
                </p>
            </div>

            {instances.length > 1 && !isTranslation && !isEdit && (
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
                    Categoría {categoriaBloqueada && <span className="text-xs font-normal text-muted-foreground">(bloqueada)</span>}
                </FieldLabel>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    {CATEGORIES.map(c => {
                        const active = category === c.value;
                        return (
                            <button
                                key={c.value}
                                type="button"
                                disabled={categoriaBloqueada}
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
                        Nombre {(isTranslation || isEdit) && <span className="text-xs font-normal text-muted-foreground">(bloqueado)</span>}
                    </FieldLabel>
                    <input
                        type="text"
                        value={name}
                        onChange={e => setName(e.target.value.toLowerCase())}
                        disabled={isTranslation || isEdit}
                        placeholder="cortes_servicio"
                        className="flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm font-mono shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60"
                    />
                    {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                </div>
                <div className="space-y-1.5">
                    <FieldLabel>Idioma {isEdit && <span className="text-xs font-normal text-muted-foreground">(bloqueado)</span>}</FieldLabel>
                    <select
                        value={language}
                        onChange={e => setLanguage(e.target.value)}
                        disabled={isEdit}
                        className="h-9 w-full rounded-md border border-input bg-card px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50 disabled:opacity-60"
                    >
                        <option value="">Selecciona...</option>
                        {/* Un idioma que no está en la lista corta —la plantilla se
                            creó en Meta— tiene que poder verse al editarla. */}
                        {isEdit && language && !LANGUAGES.some(l => l.code === language) && (
                            <option value={language}>{language}</option>
                        )}
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

            {/* Antes había aquí una casilla «Permitir que Meta reclasifique la
                categoría». Desde abril de 2025 Meta ya no la mira: revisa la
                categoría de todas y la cambia cuando no cuadra con el texto,
                se marque o no. La casilla prometía un control que no existe. */}
            {!isTranslation && !isEdit && (
                <div className="flex items-start gap-2 rounded-md border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                    <Info className="size-3.5 mt-0.5 shrink-0" />
                    <span>
                        Meta revisa la categoría y puede cambiarla si no coincide con el contenido; por ejemplo, un
                        aviso con tono de promoción pasa a <strong className="text-foreground">Marketing</strong>, que
                        cuesta más por mensaje. Si lo hace, te lo diremos al enviarla.
                    </span>
                </div>
            )}
        </div>
    );
}

function StepContent({
    comps, setComps, parameterFormat, setParameterFormat, formatoBloqueado = false,
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
                    {/* Meta no deja cambiar el tipo de variable al editar: sólo
                        categoría y contenido. */}
                    <select
                        value={parameterFormat}
                        onChange={e => setParameterFormat(e.target.value)}
                        disabled={formatoBloqueado}
                        className="h-9 w-full rounded-md border border-input bg-card px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50 disabled:opacity-60"
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
                            Sube un archivo de muestra ({MEDIA_LABEL[comps.header.media]}, hasta {MEDIA_MAX_MB[comps.header.media]} MB).
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
                            <p className="flex items-center gap-1.5 text-xs text-success">
                                <span className="inline-block size-1.5 rounded-full bg-success" />
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
                    <FieldLabel optional hint="Encabezado de texto del mensaje. Máximo 60 caracteres y una variable, sin emojis, saltos de línea ni formato. No disponible si elegiste contenido multimedia.">
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
                            Máx. 1 teléfono · 2 URL · 1 copiar código. Las respuestas rápidas van seguidas.
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
                                </select>
                                <input
                                    type="text"
                                    value={btn.text}
                                    onChange={e => updateButton(i, { text: e.target.value })}
                                    placeholder="Texto del botón"
                                    maxLength={25}
                                    className="flex-1 h-8 rounded-md border border-input bg-card px-2 text-xs"
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

                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Lo que verá el cliente, aproximado: Meta pone el texto en el idioma de la
 * plantilla, así que esto es la versión en español.
 */
function authPreviewModel(auth) {
    let body = '*123456* es tu código de verificación.';
    if (auth.add_security_recommendation) body += ' Por tu seguridad, no lo compartas.';
    const min = Number(auth.code_expiration_minutes);
    const label = auth.otp_type === 'COPY_CODE'
        ? (auth.text.trim() || 'Copiar código')
        : (auth.autofill_text.trim() || 'Autocompletar');
    return {
        header: null,
        body: { text: body },
        footer: auth.code_expiration_minutes !== '' && Number.isInteger(min) && min > 0
            ? { text: `Este código caduca en ${min} ${min === 1 ? 'minuto' : 'minutos'}.` }
            : null,
        buttons: [{ type: 'COPY_CODE', text: label }],
    };
}

/**
 * La instancia, cuando la URL no la dice y hay más de una.
 *
 * El id de una plantilla sólo existe en el catálogo de su línea: buscarlo en
 * otra devuelve «no existe» y parecía que la plantilla se había perdido.
 */
function ElegirInstancia({ instances, onElegir, isEdit }) {
    const [elegida, setElegida] = useState(instances[0]?.id ?? null);
    return (
        <div className="rounded-xl border bg-card p-5 space-y-3">
            <div>
                <h2 className="text-base font-semibold text-foreground">¿En qué línea está la plantilla?</h2>
                <p className="text-xs text-muted-foreground mt-1">
                    {isEdit
                        ? 'Cada línea tiene su propio catálogo en Meta. Elige la línea donde está la plantilla que quieres editar.'
                        : 'Cada línea tiene su propio catálogo en Meta. Elige la línea donde está la plantilla que quieres traducir.'}
                </p>
            </div>
            <select
                value={elegida ?? ''}
                onChange={e => setElegida(Number(e.target.value) || null)}
                className="h-9 w-full rounded-md border border-input bg-card px-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-ring/50"
            >
                {instances.map(i => (
                    <option key={i.id} value={i.id}>{i.name} ({i.display_phone_number})</option>
                ))}
            </select>
            <div className="flex justify-end">
                <Button type="button" onClick={() => elegida && onElegir(elegida)} disabled={!elegida}>
                    Continuar
                </Button>
            </div>
        </div>
    );
}

const OTP_TYPES = [
    {
        value: 'COPY_CODE',
        label: 'Copiar código',
        desc: 'El mensaje trae un botón que copia el código; el cliente lo pega en tu web o app.',
    },
    {
        value: 'ONE_TAP',
        label: 'Autocompletar con un toque',
        desc: 'En Android, el botón rellena el código en tu app. Necesita el paquete y el hash de firma de la app.',
    },
    {
        value: 'ZERO_TAP',
        label: 'Autocompletar sin toque',
        desc: 'En Android, la app recibe el código sola, sin que el cliente toque nada. Si no puede, cae en un toque o en copiar.',
    },
];

function AuthContent({ auth, setAuth, errors }) {
    const set = patch => setAuth(p => ({ ...p, ...patch }));
    const setApp = (i, patch) => setAuth(p => ({
        ...p,
        supported_apps: p.supported_apps.map((a, idx) => idx === i ? { ...a, ...patch } : a),
    }));
    const conApp = auth.otp_type !== 'COPY_CODE';

    return (
        <div className="space-y-5">
            <div className="rounded-xl border bg-card p-5 space-y-5">
                <div>
                    <h2 className="text-base font-semibold text-foreground">Contenido del código</h2>
                    <p className="text-xs text-muted-foreground mt-1">
                        En las plantillas de autenticación el texto lo pone Meta, traducido al idioma de la plantilla:
                        «<span className="text-foreground">*123456* es tu código de verificación.</span>» No lleva
                        encabezado ni otros botones. Aquí se elige lo que se le añade y cómo se entrega el código.
                    </p>
                </div>

                <label className="flex items-start gap-2 text-sm text-foreground">
                    <input
                        type="checkbox"
                        checked={auth.add_security_recommendation}
                        onChange={e => set({ add_security_recommendation: e.target.checked })}
                        className="mt-0.5 size-4 rounded border-input"
                    />
                    <span>
                        Añadir el aviso de seguridad
                        <span className="block text-xs text-muted-foreground">«Por tu seguridad, no lo compartas.»</span>
                    </span>
                </label>

                <div className="space-y-1.5 max-w-xs">
                    <FieldLabel optional hint="Meta añade un pie con «Este código caduca en N minutos». Entre 1 y 90. Vacío, sin pie.">
                        Caducidad del código (minutos)
                    </FieldLabel>
                    <input
                        type="number"
                        min={1}
                        max={90}
                        value={auth.code_expiration_minutes}
                        onChange={e => set({ code_expiration_minutes: e.target.value })}
                        placeholder="10"
                        className="flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    />
                    {errors.auth_expiration && <p className="text-xs text-destructive">{errors.auth_expiration}</p>}
                </div>
            </div>

            <div className="rounded-xl border bg-card p-5 space-y-4">
                <div>
                    <h2 className="text-base font-semibold text-foreground">Botón del código</h2>
                    <p className="text-xs text-muted-foreground mt-0.5">Meta admite un único botón de código.</p>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    {OTP_TYPES.map(t => {
                        const active = auth.otp_type === t.value;
                        return (
                            <button
                                key={t.value}
                                type="button"
                                onClick={() => set({ otp_type: t.value })}
                                className={`rounded-lg border p-3 text-left transition-colors ${
                                    active ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'hover:border-primary/40'
                                }`}
                            >
                                <div className="text-sm font-medium text-foreground">{t.label}</div>
                                <div className="text-[11px] text-muted-foreground mt-0.5 leading-snug">{t.desc}</div>
                            </button>
                        );
                    })}
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                        <FieldLabel optional hint="Si lo dejas vacío, Meta pone «Copiar código» en el idioma de la plantilla.">
                            Texto del botón de copiar
                        </FieldLabel>
                        <CounterInput value={auth.text} onChange={e => set({ text: e.target.value })} maxLength={25} placeholder="Copiar código" />
                        {errors.auth_text && <p className="text-xs text-destructive">{errors.auth_text}</p>}
                    </div>
                    {conApp && (
                        <div className="space-y-1.5">
                            <FieldLabel optional hint="Si lo dejas vacío, Meta pone «Autocompletar» en el idioma de la plantilla.">
                                Texto del botón de autocompletar
                            </FieldLabel>
                            <CounterInput value={auth.autofill_text} onChange={e => set({ autofill_text: e.target.value })} maxLength={25} placeholder="Autocompletar" />
                            {errors.auth_autofill && <p className="text-xs text-destructive">{errors.auth_autofill}</p>}
                        </div>
                    )}
                </div>

                {conApp && (
                    <div className="space-y-2 rounded-md border bg-muted/20 p-3">
                        <div className="flex items-center justify-between gap-2">
                            <div>
                                <p className="text-xs font-medium text-foreground">Apps Android que reciben el código</p>
                                <p className="text-[11px] text-muted-foreground">
                                    El paquete (com.tuempresa.app) y el hash de firma de 11 caracteres. Hasta {MAX_SUPPORTED_APPS} apps.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="gap-1 shrink-0"
                                disabled={auth.supported_apps.length >= MAX_SUPPORTED_APPS}
                                onClick={() => set({ supported_apps: [...auth.supported_apps, { package_name: '', signature_hash: '' }] })}
                            >
                                <Plus className="size-3.5" /> App
                            </Button>
                        </div>
                        {auth.supported_apps.map((app, i) => (
                            <div key={i} className="space-y-1">
                                <div className="flex gap-2">
                                    <input
                                        type="text"
                                        value={app.package_name}
                                        onChange={e => setApp(i, { package_name: e.target.value })}
                                        placeholder="com.tuempresa.app"
                                        maxLength={224}
                                        className="flex-1 h-8 rounded-md border border-input bg-card px-2 text-xs font-mono"
                                    />
                                    <input
                                        type="text"
                                        value={app.signature_hash}
                                        onChange={e => setApp(i, { signature_hash: e.target.value })}
                                        placeholder="K8a/AINcGX7"
                                        maxLength={11}
                                        className="w-36 h-8 rounded-md border border-input bg-card px-2 text-xs font-mono"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={auth.supported_apps.length <= 1}
                                        onClick={() => set({ supported_apps: auth.supported_apps.filter((_, idx) => idx !== i) })}
                                        className="text-destructive hover:bg-destructive/10"
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                                {errors[`auth_app_${i}_package`] && <p className="text-xs text-destructive">{errors[`auth_app_${i}_package`]}</p>}
                                {errors[`auth_app_${i}_hash`] && <p className="text-xs text-destructive">{errors[`auth_app_${i}_hash`]}</p>}
                            </div>
                        ))}
                        {errors.auth_apps && <p className="text-xs text-destructive">{errors.auth_apps}</p>}
                    </div>
                )}

                {auth.otp_type === 'ZERO_TAP' && (
                    <div className="space-y-1">
                        <label className="flex items-start gap-2 text-sm text-foreground">
                            <input
                                type="checkbox"
                                checked={auth.zero_tap_terms_accepted}
                                onChange={e => set({ zero_tap_terms_accepted: e.target.checked })}
                                className="mt-0.5 size-4 rounded border-input"
                            />
                            <span>
                                Acepto los términos de Meta para el autocompletado sin toque
                                <span className="block text-xs text-muted-foreground">
                                    El cliente no ve el mensaje antes de que la app use el código: la empresa se hace
                                    responsable de que sepa que lo va a recibir así.
                                </span>
                            </span>
                        </label>
                        {errors.auth_terms && <p className="text-xs text-destructive">{errors.auth_terms}</p>}
                    </div>
                )}
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
                        <div className="size-10 rounded-xl bg-success/15 text-success flex items-center justify-center shrink-0">
                            <CheckCircle2 className="size-5" />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold text-foreground">
                                {created.editada ? 'Cambios enviados a Meta' : 'Plantilla enviada a Meta'}
                            </h2>
                            <p className="text-xs text-muted-foreground mt-1">
                                {created.editada
                                    ? 'Meta está revisando la plantilla editada. Hasta que la apruebe no se puede enviar; suele tardar unos minutos.'
                                    : <>Meta la recibió en estado <span className="font-medium text-foreground">{tpl.status ?? 'PENDING'}</span>. La aprobación suele tardar unos minutos.</>}
                            </p>
                        </div>
                    </div>

                    {/* Meta decide la categoría por su cuenta y la cambia sin
                        preguntar. Si la sube a marketing, cada envío cuesta más
                        —y en una plantilla de facturas, que sale a diario, se
                        nota en la factura de Meta antes que en ningún otro
                        sitio—. Hay que decirlo aquí, que es el único momento en
                        que alguien está mirando. */}
                    {created.categoria_cambiada && (
                        <div className="flex gap-2.5 rounded-md border border-warning/40 bg-warning/10 px-3 py-2.5 text-xs">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                            <div className="space-y-1 text-muted-foreground">
                                <p className="font-semibold text-foreground">
                                    Meta la puso en otra categoría: pediste {CATEGORY_LABEL[created.categoria_cambiada.pedida] ?? created.categoria_cambiada.pedida} y
                                    la dejó como {CATEGORY_LABEL[created.categoria_cambiada.asignada] ?? created.categoria_cambiada.asignada}.
                                </p>
                                {created.categoria_cambiada.asignada === 'MARKETING' ? (
                                    <p>
                                        Los mensajes de marketing <strong className="text-foreground">cuestan más</strong> que los de
                                        utilidad. Si es un aviso de cuenta, quita del texto lo que suene a promoción y créala de nuevo.
                                    </p>
                                ) : (
                                    <p>Se cobrará y revisará con la categoría que puso Meta.</p>
                                )}
                            </div>
                        </div>
                    )}

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
                        {!created.editada && <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">Verificada en Meta</span>
                            <span className={created.verified_in_meta ? 'text-success font-medium' : 'text-warning font-medium'}>
                                {created.verified_in_meta ? 'Sí' : 'No respondió aún'}
                            </span>
                        </div>}
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
