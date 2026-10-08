// Plantillas de WhatsApp: lo que hay que saber para leer una definición de Meta
// y para construir el payload de envío.
//
// Vivía duplicado dentro de Chat/Index.jsx —dos veces, en el modal de nuevo chat
// y en el selector de plantillas— y ahora lo necesita también el asistente de
// campañas. Una sola definición del formato de `components`: si Meta cambia algo,
// se cambia aquí y no en tres sitios que se van separando con el tiempo.

/** El componente BODY de una plantilla de Meta. */
export function templateBodyComponent(t) {
    return (t?.components || []).find(c => c.type === 'BODY');
}

/** El componente HEADER, sea del formato que sea. */
export function templateHeaderComponent(t) {
    return (t?.components || []).find(c => c.type === 'HEADER');
}

/**
 * Formato del encabezado multimedia (IMAGE/VIDEO/DOCUMENT/LOCATION), o null si
 * la plantilla no tiene encabezado o lo tiene de texto.
 */
export function templateHeaderFormat(t) {
    const h = templateHeaderComponent(t);
    const f = (h?.format || '').toUpperCase();
    return ['IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'].includes(f) ? f : null;
}

export const HEADER_MEDIA_ACCEPT = {
    IMAGE: 'image/jpeg,image/png',
    VIDEO: 'video/mp4,video/3gpp',
    DOCUMENT: 'application/pdf',
};

export const HEADER_MEDIA_LABEL = {
    IMAGE: 'Imagen',
    VIDEO: 'Video',
    DOCUMENT: 'Documento',
    LOCATION: 'Ubicación',
};

/** Número de variables distintas {{n}} en un texto de plantilla. */
export function countTemplateVars(text) {
    return templateVarNames(text).length;
}

/**
 * Las variables distintas de un texto, por orden de primera aparición:
 * ['1', '2'] en una plantilla posicional, ['nombre', 'factura'] en una con
 * nombre. El hueco i-ésimo del formulario es la variable i-ésima de esta lista.
 */
export function templateVarNames(text) {
    const matches = (text || '').match(/{{\s*[A-Za-z0-9_]+\s*}}/g);
    if (!matches) return [];
    return [...new Set(matches.map(x => x.replace(/[{}\s]/g, '')))];
}

/**
 * ¿Usa parámetros con nombre? Lo dice `parameter_format` si viene; si no, se
 * nota en las variables: Meta sólo admite números en las posicionales y
 * minúsculas con guion bajo en las nombradas. Con nombre, cada parámetro del
 * envío tiene que llevar su `parameter_name` o Meta no sabe dónde va.
 */
export function isNamedTemplate(t) {
    const format = (t?.parameter_format || '').toUpperCase();
    if (format) return format === 'NAMED';
    const names = [
        ...templateVarNames(templateBodyComponent(t)?.text),
        ...templateHeaderVarNames(t),
    ];
    return names.some(n => !/^\d+$/.test(n));
}

/** La variable del encabezado de texto (Meta admite una como mucho), o []. */
export function templateHeaderVarNames(t) {
    const h = templateHeaderComponent(t);
    if (!h || (h.format && h.format.toUpperCase() !== 'TEXT')) return [];
    return templateVarNames(h.text);
}

/**
 * Botones que cambian en cada envío: la URL que acaba en {{1}}, el código para
 * copiar, y el código de una plantilla de autenticación (botón OTP), que Meta
 * recibe como si fuera el sufijo de una URL. `index` es la posición del botón
 * dentro de la plantilla, que es lo que Meta usa para casarlos.
 */
export function templateDynamicButtons(t) {
    const buttons = (t?.components || []).find(c => c.type === 'BUTTONS')?.buttons || [];
    const out = [];
    buttons.forEach((b, index) => {
        const type = (b.type || '').toUpperCase();
        if (type === 'URL' && (b.url || '').includes('{{')) {
            out.push({ index, subType: 'url', otp: false, text: b.text || '', url: b.url });
        } else if (type === 'COPY_CODE') {
            out.push({ index, subType: 'copy_code', otp: false, text: b.text || 'Copiar código', url: '' });
        } else if (type === 'OTP') {
            out.push({ index, subType: 'url', otp: true, text: b.text || 'Copiar código', url: '' });
        }
    });
    return out;
}

/** ¿Hay que pedirle algún dato al agente antes de mandarla? */
export function templateNeedsFill(t) {
    return countTemplateVars(templateBodyComponent(t)?.text) > 0
        || !!templateHeaderFormat(t)
        || templateHeaderVarNames(t).length > 0
        || templateDynamicButtons(t).length > 0;
}

/**
 * La misma limpieza que hace el guardarraíl en PHP: Meta rechaza (132018) un
 * dato con saltos de línea, tabuladores o más de cuatro espacios seguidos. Se
 * repite aquí para que la vista previa enseñe lo que de verdad saldrá.
 */
export function cleanTemplateParam(value) {
    return String(value ?? '')
        .replace(/[\r\n\t\u2028\u2029]+/g, ' ')
        .replace(/ {4,}/g, ' ')
        .trim();
}

/**
 * Reemplaza las variables por los valores dados. `vars` es posicional: el
 * valor i-ésimo es el de la variable i-ésima distinta del texto, que en una
 * plantilla con nombre es la que diga `templateVarNames()`.
 */
export function fillTemplate(text, vars) {
    const names = templateVarNames(text);
    return (text || '').replace(/{{\s*([A-Za-z0-9_]+)\s*}}/g, (match, key) => {
        const i = /^\d+$/.test(key) ? Number(key) - 1 : names.indexOf(key);
        return vars[i] || match;
    });
}

function textParam(value, name, named) {
    const p = { type: 'text', text: cleanTemplateParam(value) };
    return named && name ? { type: 'text', parameter_name: name, text: p.text } : p;
}

/**
 * Todos los componentes del envío a partir de lo que rellenó el agente.
 *
 * - `header`: el estado del encabezado multimedia o de ubicación (ver
 *   `buildTemplateHeaderComponent`).
 * - `headerVars` / `bodyVars`: un valor por variable distinta, en orden.
 * - `buttonVars`: un valor por botón de `templateDynamicButtons()`, en orden.
 *   El botón OTP de una plantilla de autenticación, si se deja vacío, toma el
 *   código del cuerpo ({{1}}): es el mismo código y pedirlo dos veces sólo
 *   sirve para que no coincidan.
 */
export function buildTemplateComponents(t, { header = null, headerVars = [], bodyVars = [], buttonVars = [] } = {}) {
    const named = isNamedTemplate(t);
    const components = [];

    const headerComp = buildTemplateHeaderComponent(header);
    if (headerComp) {
        components.push(headerComp);
    } else {
        const headerNames = templateHeaderVarNames(t);
        if (headerNames.length > 0) {
            components.push({
                type: 'header',
                parameters: headerNames.map((name, i) => textParam(headerVars[i], name, named)),
            });
        }
    }

    const bodyNames = templateVarNames(templateBodyComponent(t)?.text);
    if (bodyNames.length > 0) {
        components.push({
            type: 'body',
            parameters: bodyNames.map((name, i) => textParam(bodyVars[i], name, named)),
        });
    }

    templateDynamicButtons(t).forEach((b, i) => {
        const raw = buttonVars[i] || (b.otp ? bodyVars[0] : '');
        const value = cleanTemplateParam(raw);
        components.push({
            type: 'button',
            sub_type: b.subType,
            index: String(b.index),
            parameters: [b.subType === 'copy_code'
                ? { type: 'coupon_code', coupon_code: value }
                : { type: 'text', text: value }],
        });
    });

    return components;
}

/**
 * Qué falta por rellenar, en una frase para el agente; `null` si nada.
 * El guardarraíl del servidor dice lo mismo, pero aquí se ve antes de pulsar.
 */
export function missingTemplateValues(t, { headerVars = [], bodyVars = [], buttonVars = [] } = {}) {
    const bodyN = countTemplateVars(templateBodyComponent(t)?.text);
    if (bodyVars.slice(0, bodyN).some(v => !cleanTemplateParam(v)) || bodyVars.length < bodyN) {
        return 'Completa todas las variables de la plantilla.';
    }
    if (templateHeaderVarNames(t).some((_, i) => !cleanTemplateParam(headerVars[i]))) {
        return 'Completa el dato del encabezado de la plantilla.';
    }
    const falta = templateDynamicButtons(t).find((b, i) => !b.otp && !cleanTemplateParam(buttonVars[i]));
    if (falta) {
        return `Completa el dato del botón «${falta.text}».`;
    }
    return null;
}

/**
 * Componente header listo para enviar. Para IMAGE/VIDEO/DOCUMENT se usa el
 * media_id que devuelve Meta al subir el archivo a /{phone_number_id}/media.
 */
export function buildTemplateHeaderComponent(h) {
    if (!h) return null;
    if (h.format === 'LOCATION') {
        const location = { latitude: String(h.lat ?? ''), longitude: String(h.lng ?? '') };
        if (h.name?.trim()) location.name = h.name.trim();
        if (h.address?.trim()) location.address = h.address.trim();
        return { type: 'header', parameters: [{ type: 'location', location }] };
    }
    if (!h.mediaId) return null;
    const kind = h.format.toLowerCase(); // image | video | document
    const media = { id: h.mediaId };
    if (h.format === 'DOCUMENT') media.filename = h.filename || 'documento.pdf';
    return { type: 'header', parameters: [{ type: kind, [kind]: media }] };
}

/** Etiqueta legible de la categoría de Meta. */
export const CATEGORY_LABEL = {
    MARKETING: 'Marketing',
    UTILITY: 'Utilidad',
    AUTHENTICATION: 'Autenticación',
};
