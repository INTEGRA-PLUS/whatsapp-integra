import {
    MessageSquare, CornerDownRight, UserRound, FileText, CreditCard,
    Wifi, Wrench, CircleSlash, Activity, Construction, Image,
} from 'lucide-react';

/**
 * Lo que el formulario y la ayuda comparten.
 *
 * Vive aparte porque son las dos caras de lo mismo: el formulario deja elegir
 * una acción y la ayuda explica qué hace. Con las listas duplicadas en cada
 * archivo, añadir un tipo nuevo dejaba la ayuda hablando de un módulo que ya no
 * existe, que es la forma más rápida de que nadie vuelva a abrirla.
 *
 * La lista de tipos en sí NO está aquí: la manda el backend
 * (WhatsAppMenuOption::catalog). Aquí sólo vive lo visual y lo explicativo.
 */

export const MATCH_LABELS = {
    exact: 'Coincidencia exacta',
    contains: 'Contiene',
    starts_with: 'Empieza con',
    welcome: 'Mensaje de bienvenida',
};

export const MATCH_OPTIONS = [
    { value: 'welcome', label: 'Primer mensaje del cliente (bienvenida)' },
    { value: 'contains', label: 'Contiene la palabra' },
    { value: 'exact', label: 'Coincidencia exacta' },
    { value: 'starts_with', label: 'Empieza con' },
];

/** Cuándo se dispara cada tipo, con un ejemplo de mensaje que lo activa. */
export const MATCH_HELP = {
    welcome: {
        when: 'El cliente escribe por primera vez, diga lo que diga.',
        example: 'Buenas tardes',
    },
    contains: {
        when: 'El mensaje incluye alguna de tus palabras clave en cualquier parte.',
        example: 'necesito el menu por favor',
    },
    exact: {
        when: 'El mensaje es exactamente la palabra clave, sin nada más.',
        example: 'menu',
    },
    starts_with: {
        when: 'El mensaje arranca con la palabra clave.',
        example: 'menu principal',
    },
};

export const KEYWORD_TYPES = ['exact', 'contains', 'starts_with'];

export const ACTION_ICONS = {
    reply_text: MessageSquare,
    reply_image: Image,
    submenu: CornerDownRight,
    handoff: UserRound,
    consultar_factura: FileText,
    pagar_en_linea: CreditCard,
    cambiar_clave: Wifi,
    reportar_falla: Wrench,
    estado_servicio: Activity,
    none: CircleSlash,
};

export const iconFor = value => ACTION_ICONS[value] ?? Construction;

export const GROUP_LABELS = {
    core: 'Acciones',
    integra: 'Autoservicio (consulta Integra)',
    pending: 'Opciones de negocio (integración pendiente)',
    none: 'Otros',
};

export const GROUP_ORDER = ['core', 'integra', 'pending', 'none'];

/**
 * Qué hace cada acción, en una frase.
 *
 * `needs` es lo que el admin tiene que rellenar para que funcione: si se queda
 * vacío, la opción no hace lo que promete. La ayuda lo saca aparte porque es la
 * causa número uno de "configuré la opción y no pasa nada".
 */
export const ACTION_HELP = {
    reply_text: {
        does: 'Responde con el texto que escribas. Para horarios, direcciones o los pasos para reiniciar el router.',
        needs: 'El mensaje que recibirá el cliente.',
    },
    reply_image: {
        does: 'Responde con una imagen y un pie de foto. Para el cartel de puntos de pago, la cobertura o la tabla de planes: lo que ya tienes diseñado y en texto no se lee.',
        needs: 'La imagen (JPG o PNG, máx. 5 MB). El pie es opcional, pero es lo único que le queda al cliente si la imagen no le carga.',
    },
    submenu: {
        does: 'Abre otro menú. Sirve para agrupar: una opción "Soporte" que despliega los tipos de falla.',
        needs: 'El submenú de destino, que debe existir y estar marcado como submenú.',
    },
    handoff: {
        does: 'Pasa el chat a una persona. Además calla al bot: con asesor asignado ya no se envían más menús ni respuestas automáticas.',
        needs: 'Cómo repartir: al asesor con menos chats, siempre al mismo, o dejarlo en la bandeja.',
    },
    consultar_factura: {
        does: 'Identifica al cliente por su número de WhatsApp y le responde sus facturas pendientes con el total.',
        needs: null,
    },
    pagar_en_linea: {
        does: 'Le dice cuánto debe, le entrega tu enlace de pago y avisa a tus sistemas de que quiere pagar.',
        needs: 'El enlace de pago. Sin él, el cliente ve su total pero no tiene dónde pagar.',
    },
    estado_servicio: {
        does: 'Consulta el contrato y responde la parte que elijas: por qué está suspendido y cuánto cuesta reactivarlo, facturas y saldo a favor, últimos pagos, reportes abiertos, consumo, permanencia, clave WiFi…',
        needs: 'Qué parte del servicio muestra. Sin elegir, manda el resumen completo.',
    },
    reportar_falla: {
        does: 'Revisa el contrato antes de abrir nada: si ya tiene un reporte en curso le muestra ese en vez de duplicarlo, y si está cortado por mora se lo dice y no crea radicado. Si no, le pide que describa la falla y lo registra.',
        needs: 'Bajo qué servicio de Integra entra el radicado. Ojo: la falla la describe el cliente con sus palabras; esto es sólo la categoría con la que se archiva. Sin ella no se puede crear y el cliente acaba con un asesor.',
    },
    cambiar_clave: {
        does: 'Todavía no cambia nada: responde un aviso de que la función está en camino.',
        needs: 'Tu propio texto, para que el cliente no quede sin salida.',
    },
    none: {
        does: 'El cliente toca y no recibe nada. Sólo para armar el menú antes de decidir qué hará cada opción.',
        needs: null,
    },
};

/**
 * Qué pasa con cada acción de autoservicio cuando el software no está conectado.
 *
 * Se documenta siempre, esté conectado o no: es la pregunta que llega cuando ya
 * está pasando, y para entonces nadie quiere descubrir el comportamiento
 * probándolo con un cliente de verdad.
 */
export const OFFLINE_BEHAVIOUR = [
    {
        title: 'El cliente nunca se queda sin respuesta',
        text: 'Recibe un mensaje y el chat pasa al asesor con menos conversaciones abiertas. Callar sería lo peor: el silencio se lee como un sistema roto.',
    },
    {
        title: 'Queda una nota en el chat',
        text: 'Quien abra la conversación ve «El bot no pudo resolver la solicitud del cliente y derivó el chat», así sabe por qué le llegó.',
    },
    {
        title: 'Puedes escribir tú el mensaje',
        text: 'Si rellenas el texto adicional de la opción, es ese el que recibe el cliente en lugar del genérico. El chat pasa a un asesor igualmente.',
    },
    {
        title: 'Lo demás sigue funcionando',
        text: 'Responder con un mensaje, abrir otro menú y pasar a un asesor no dependen del software: funcionan siempre.',
    },
];

/** Por qué un menú no se envía, en orden de frecuencia real. */
export const SILENCE_REASONS = [
    'El menú está apagado. Es lo primero que hay que mirar.',
    'El chat ya tiene un asesor asignado. El bot no interrumpe una conversación que alguien atiende.',
    'El chat está cerrado.',
    'Sigue dentro de la espera entre envíos: ese mismo menú se mandó hace poco.',
    'Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp no deja escribir fuera de esa ventana.',
    'La línea de WhatsApp está inactiva.',
];

/** Variables que se reemplazan al enviar, en cualquier texto del menú. */
export const TEMPLATE_VARS = [
    { token: '{name}', is: 'El nombre que el cliente puso en su perfil de WhatsApp' },
    { token: '{phone}', is: 'Su número de teléfono' },
    { token: '{wa_id}', is: 'Su identificador de WhatsApp, con indicativo' },
];

/**
 * Un ejemplo de lo que recibe el cliente con cada acción.
 *
 * No basta con explicar qué hace una opción: hasta que no ves el mensaje que le
 * llega al cliente no sabes si es lo que querías. Los textos siguen de cerca a
 * los que arma el backend, con datos de muestra.
 *
 * `null` significa que el mensaje lo escribe el admin y ya se ve en su campo.
 */
export const ACTION_SAMPLES = {
    reply_text: null,
    reply_image: null,
    submenu: null,
    none: null,
    consultar_factura:
        '📄 *Tus facturas pendientes*\n\n' +
        '• FV-1001 — *$70.000* (venció el 30/07/2026 ⚠️)\n\n' +
        '*Total por pagar: $70.000*\n\n' +
        '💰 Tienes *$12.000* a favor.\nSe descuenta de tu próxima factura.',
    pagar_en_linea:
        '💳 *Pagar en línea*\n\n' +
        'Tienes *$70.000* por pagar.\n\n' +
        'Paga aquí 👉 https://pagos.tuempresa.com/?nit=40389154&valor=70000',
    reportar_falla:
        '🛠️ Cuéntame en un mensaje qué está pasando con tu servicio ' +
        '(por ejemplo: *sin internet desde anoche*, *se corta a ratos*, ' +
        '*el televisor no da señal*).',
    cambiar_clave:
        'Por ahora no puedo cambiar la clave desde aquí. Estamos habilitando ' +
        'esta opción muy pronto.',
};

/** Y lo mismo para cada parte del servicio que puede mostrar "Estado del contrato". */
export const SEGMENT_SAMPLES = {
    resumen:
        '📡 *Estado de tu servicio*\n\nContrato: 15\nPlan: ZAFIRO GOLD 3.0\n' +
        'Internet: ⛔ suspendido (por facturas pendientes)\n\n' +
        'Facturas pendientes: *$70.000*\nTu servicio se reactiva automáticamente al registrar el pago.',
    internet:
        '🌐 *Estado de tu internet*\n\nContrato 15 — ⛔ *Suspendido*\n\n' +
        'Está suspendido *por facturas pendientes*.\n\n' +
        'Con *$45.000* se reactiva, y se hace solo en cuanto registremos el pago.\n' +
        'Tu deuda total es de $70.000, pero para volver a navegar basta con lo de arriba.',
    facturas:
        '📄 *Facturas pendientes de este contrato*\n\n' +
        '• FV-1001 — *$70.000* (venció el 30/07/2026 ⚠️)\n\n*Total por pagar: $70.000*',
    pagos:
        '💳 *Tus últimos pagos*\n\n' +
        '• 05/07/2026 — *$60.000* · Efecty (recibo RC-8891)\n' +
        '• 04/06/2026 — *$60.000* · PSE (recibo RC-8420)\n\n' +
        '¿Falta alguno? Mándanos el comprobante por este chat y lo revisamos.',
    soportes:
        '🔧 *Tus reportes de falla*\n\nTienes *1 reporte abierto*:\n\n' +
        '• #5601 del 26/08/2026 · Sin internet — 🔧 en proceso\n\n' +
        'Ya está en cola: no hace falta que lo reportes otra vez.',
    consumo:
        '📊 *Tu consumo de este mes*\n\nTotal: *141 GB*\n' +
        'Descarga: 128 GB · Subida: 12,6 GB\nContado desde el 01/08/2026.\n\n' +
        'Tu plan no tiene límite de datos: esto es informativo.',
    corte:
        '📅 *Fechas de tu servicio*\n\nPeriodo de facturación: PERIODO 1 AL 30\n' +
        'Te facturamos el día 1 de cada mes.\nFecha límite de pago: día 15.\n' +
        'Corte por falta de pago: *día 20*.\n\n' +
        'Tienes *$70.000* por pagar. Paga antes del día 20 para no perder el servicio.',
    plan:
        '⚡ *Tu plan contratado*\n\nPlan: *ZAFIRO GOLD 3.0*\n' +
        'Velocidad: 300 Mbps de bajada · 150 Mbps de subida\nValor mensual: $60.000\n\n' +
        'La velocidad se mide por cable y con un solo equipo conectado; por WiFi siempre llega menos.',
    contrato:
        '📋 *Tu contrato*\n\nPermanencia: *12 meses*\nContrato firmado el 01/03/2024.\n' +
        'Costo de reconexión si te cortan: $15.000\n\n📄 Tu contrato firmado 👉 https://…/contratos/15.pdf',
    wifi:
        '🔑 *Tu red WiFi*\n\nRed: *INTERSOLAR_5G*\nClave: *casa1234*\n\n' +
        'Es la clave que quedó registrada en la instalación.',
    television:
        '📺 *Tu servicio de televisión*\n\nEstado: ✅ activo',
    datos:
        '📍 *Datos de tu contrato*\n\nNúmero de contrato: *15*\n' +
        'Dirección de instalación: CRA 5 # 12-34, El Prado\n\n' +
        'Con el número de contrato puedes pagar en cualquiera de nuestros puntos autorizados.',
};
