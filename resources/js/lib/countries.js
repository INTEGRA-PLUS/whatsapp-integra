// Indicativos telefónicos para el formulario de contacto.
//
// WhatsApp pide el país aparte del número porque el agente copia el teléfono
// como se lo dictan ("305 258 3254") y sin indicativo Meta no sabe a quién
// mandarlo. Aquí el número se guarda siempre en la forma que usa la API:
// indicativo + número, sólo dígitos ("573052583254").
//
// La lista es corta a propósito —lo que factura Partequipos— y ordenada por
// uso: Colombia primero, el resto alfabético.

export const COUNTRIES = [
    { code: 'CO', dial: '57', name: 'Colombia', flag: '🇨🇴' },
    { code: 'AR', dial: '54', name: 'Argentina', flag: '🇦🇷' },
    { code: 'BO', dial: '591', name: 'Bolivia', flag: '🇧🇴' },
    { code: 'BR', dial: '55', name: 'Brasil', flag: '🇧🇷' },
    { code: 'CL', dial: '56', name: 'Chile', flag: '🇨🇱' },
    { code: 'CR', dial: '506', name: 'Costa Rica', flag: '🇨🇷' },
    { code: 'EC', dial: '593', name: 'Ecuador', flag: '🇪🇨' },
    { code: 'SV', dial: '503', name: 'El Salvador', flag: '🇸🇻' },
    { code: 'ES', dial: '34', name: 'España', flag: '🇪🇸' },
    { code: 'US', dial: '1', name: 'Estados Unidos / Canadá', flag: '🇺🇸' },
    { code: 'GT', dial: '502', name: 'Guatemala', flag: '🇬🇹' },
    { code: 'HN', dial: '504', name: 'Honduras', flag: '🇭🇳' },
    { code: 'MX', dial: '52', name: 'México', flag: '🇲🇽' },
    { code: 'NI', dial: '505', name: 'Nicaragua', flag: '🇳🇮' },
    { code: 'PA', dial: '507', name: 'Panamá', flag: '🇵🇦' },
    { code: 'PY', dial: '595', name: 'Paraguay', flag: '🇵🇾' },
    { code: 'PE', dial: '51', name: 'Perú', flag: '🇵🇪' },
    { code: 'PT', dial: '351', name: 'Portugal', flag: '🇵🇹' },
    { code: 'DO', dial: '1809', name: 'República Dominicana', flag: '🇩🇴' },
    { code: 'UY', dial: '598', name: 'Uruguay', flag: '🇺🇾' },
    { code: 'VE', dial: '58', name: 'Venezuela', flag: '🇻🇪' },
];

export const DEFAULT_COUNTRY = 'CO';

/** Sólo dígitos: es como viaja el número en la API de Meta. */
export function onlyDigits(value) {
    return String(value ?? '').replace(/\D+/g, '');
}

export function countryByCode(code) {
    return COUNTRIES.find(c => c.code === code) ?? COUNTRIES[0];
}

/**
 * Parte un número guardado en (país, número nacional) para poder editarlo.
 *
 * Gana el indicativo más largo que encaje: sin eso "1809..." de República
 * Dominicana se leería como Estados Unidos y el país saldría mal en el
 * desplegable cada vez que se abre la ficha.
 */
export function splitPhoneNumber(value) {
    const digits = onlyDigits(value);
    if (!digits) return { country: DEFAULT_COUNTRY, national: '' };

    const match = [...COUNTRIES]
        .sort((a, b) => b.dial.length - a.dial.length)
        .find(c => digits.startsWith(c.dial) && digits.length > c.dial.length);

    return match
        ? { country: match.code, national: digits.slice(match.dial.length) }
        : { country: DEFAULT_COUNTRY, national: digits };
}

/** Reconstruye el número completo tal y como se guarda. */
export function joinPhoneNumber(countryCode, national) {
    const digits = onlyDigits(national);
    if (!digits) return '';

    const dial = countryByCode(countryCode).dial;

    // El agente pega a veces el número ya con indicativo dentro del campo
    // nacional; duplicarlo daría "5757305...", que Meta rechaza.
    return digits.startsWith(dial) ? digits : dial + digits;
}

/** El nombre de usuario de WhatsApp sin arroba y en minúsculas. */
export function cleanUsername(value) {
    return String(value ?? '').trim().replace(/^@+/, '').replace(/\s+/g, '').toLowerCase();
}
