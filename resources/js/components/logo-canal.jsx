/**
 * El canal de una línea, con su logo de marca.
 *
 * Los glifos van dibujados a mano y no importados: lucide-react quitó los iconos
 * de marca, y traer una dependencia entera para tres formas no se sostiene.
 *
 * Importa que se reconozcan de un vistazo. Antes todas las líneas llevaban el
 * mismo icono de wifi, así que en una pantalla con WhatsApp e Instagram juntos
 * no se distinguía cuál era cuál hasta leer la letra pequeña.
 */

function IconoWhatsApp(props) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {...props}>
            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24a8.2 8.2 0 0 1 5.82 2.42 8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.23 8.23Zm4.52-6.17c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.22.25-.85.83-.85 2.03s.87 2.35.99 2.51c.12.16 1.71 2.62 4.15 3.67.58.25 1.03.4 1.39.51.58.19 1.11.16 1.53.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.22-.17-.47-.29Z" />
        </svg>
    );
}

function IconoInstagram(props) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
            strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
            <rect x="2" y="2" width="20" height="20" rx="5.5" />
            <circle cx="12" cy="12" r="4" />
            <circle cx="17.6" cy="6.4" r="1.2" fill="currentColor" stroke="none" />
        </svg>
    );
}

function IconoMessenger(props) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {...props}>
            <path d="M12 2C6.3 2 2 6.16 2 11.77c0 2.94 1.2 5.48 3.15 7.23.16.15.26.35.27.57l.06 1.78c.02.57.6.94 1.12.71l1.99-.88c.17-.07.36-.09.54-.04 1.22.34 2.51.5 3.87.44 5.7 0 10-4.16 10-9.77S17.7 2 12 2Zm5.9 7.6-2.9 4.6a1.5 1.5 0 0 1-2.17.4L10.5 12.9a.6.6 0 0 0-.72 0l-3.1 2.35c-.41.32-.95-.18-.67-.62l2.9-4.6a1.5 1.5 0 0 1 2.17-.4l2.33 1.75a.6.6 0 0 0 .72 0l3.1-2.35c.41-.32.95.18.67.62Z" />
        </svg>
    );
}

/**
 * Un canal desconocido cuenta como WhatsApp: las líneas creadas antes de que
 * existiera la columna lo son, y dejarlas sin icono las haría parecer rotas.
 */
const CANALES = {
    whatsapp: {
        nombre: 'WhatsApp',
        Icono: IconoWhatsApp,
        // Los verdes y azules oficiales de cada marca. Van en literales y no en
        // tokens del tema a propósito: son colores de terceros, no nuestros, y
        // no deben moverse cuando cambie la paleta de Integra.
        color: '#25D366',
        fondo: 'rgba(37, 211, 102, 0.14)',
    },
    instagram: {
        nombre: 'Instagram',
        Icono: IconoInstagram,
        color: '#E1306C',
        fondo: 'rgba(225, 48, 108, 0.12)',
    },
    messenger: {
        nombre: 'Messenger',
        Icono: IconoMessenger,
        color: '#0084FF',
        fondo: 'rgba(0, 132, 255, 0.12)',
    },
};

export function canalDe(instancia) {
    return CANALES[instancia?.channel] ?? CANALES.whatsapp;
}

/**
 * El cuadro con el logo, al principio de la tarjeta.
 *
 * Se apaga a gris cuando la línea no está activa o Meta no responde: el color de
 * marca a todo volumen en una línea caída decía «todo bien» de un vistazo, que
 * es justo lo contrario de lo que pasa.
 */
export function LogoCanal({ instancia, apagado = false, className = 'size-10' }) {
    const { Icono, color, fondo, nombre } = canalDe(instancia);

    return (
        <div
            className={`flex ${className} shrink-0 items-center justify-center rounded-xl`}
            style={apagado ? undefined : { backgroundColor: fondo }}
            title={nombre}
        >
            <Icono
                className={`size-5 ${apagado ? 'text-muted-foreground' : ''}`}
                style={apagado ? undefined : { color }}
            />
        </div>
    );
}

/**
 * La pastilla con el nombre del canal, para que no haya que reconocer el logo.
 */
export function EtiquetaCanal({ instancia }) {
    const { Icono, color, fondo, nombre } = canalDe(instancia);

    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
            style={{ backgroundColor: fondo, color }}
        >
            <Icono className="size-3.5" />
            {nombre}
        </span>
    );
}
