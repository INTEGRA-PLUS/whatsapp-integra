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
    const matches = (text || '').match(/{{\s*[A-Za-z0-9_]+\s*}}/g);
    if (!matches) return 0;
    return new Set(matches.map(x => x.replace(/[{}\s]/g, ''))).size;
}

/** Reemplaza {{n}} por los valores dados (array posicional, {{1}} = índice 0). */
export function fillTemplate(text, vars) {
    return (text || '').replace(/{{\s*(\d+)\s*}}/g, (_, n) => vars[Number(n) - 1] || `{{${n}}}`);
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
