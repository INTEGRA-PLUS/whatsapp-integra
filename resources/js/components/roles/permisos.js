import {
    Bell,
    Bot,
    Clock,
    Contact,
    HelpCircle,
    Layers,
    ListTree,
    Megaphone,
    MessageSquare,
    Settings,
    Shield,
    ShieldCheck,
    Users,
    Wand2,
    Webhook,
    Zap,
    BarChart3,
    FileType,
} from 'lucide-react';

/**
 * Cada módulo viaja desde PHP con una clave de icono, no con el componente.
 * Así el catálogo se mantiene en un solo archivo del lado del servidor.
 */
export const ICONOS = {
    chat: MessageSquare,
    crm: Layers,
    contacts: Contact,
    menus: ListTree,
    bot: Bot,
    rayo: Zap,
    varita: Wand2,
    reloj: Clock,
    megafono: Megaphone,
    plantilla: FileType,
    grafico: BarChart3,
    engranaje: Settings,
    webhook: Webhook,
    usuarios: Users,
    escudo: ShieldCheck,
    campana: Bell,
    interrogacion: HelpCircle,
};

export const iconoDe = (clave) => ICONOS[clave] || Shield;

/** Acciones que componen cada nivel, de menor a mayor. */
const ACCIONES_POR_NIVEL = {
    ninguno: [],
    ver: ['view'],
    operar: ['view', 'create', 'update', 'run', 'send'],
    total: ['view', 'create', 'update', 'delete', 'run', 'send'],
};

export const ORDEN_NIVELES = ['ninguno', 'ver', 'operar', 'total'];

/**
 * Niveles que tienen sentido para un módulo concreto.
 *
 * Reportes solo tiene `view` y Notificaciones solo `send`: ofrecerles cuatro
 * niveles sería pedirle a alguien que elija entre tres opciones idénticas.
 */
export function nivelesDe(modulo) {
    const disponibles = ORDEN_NIVELES.filter(
        (nivel) => nivel === 'ninguno' || idsDeNivel(modulo, nivel).length > 0,
    );

    // Dejamos un solo nivel por cada combinación distinta de permisos: si
    // «Operar» y «Control total» conceden exactamente lo mismo, sobra uno.
    const vistos = new Set();
    return disponibles.filter((nivel) => {
        const firma = idsDeNivel(modulo, nivel).slice().sort().join(',');
        if (vistos.has(firma)) return false;
        vistos.add(firma);
        return true;
    });
}

export function idsDeNivel(modulo, nivel) {
    const acciones = ACCIONES_POR_NIVEL[nivel] || [];
    return modulo.permisos.filter((p) => acciones.includes(p.accion)).map((p) => p.id);
}

/**
 * Qué nivel representa la selección actual de un módulo. Devuelve null cuando
 * la combinación no coincide con ninguno (por ejemplo: eliminar sin ver), que
 * es justo lo que la pantalla debe rotular como «personalizado».
 */
export function nivelDe(modulo, seleccionados) {
    const propios = modulo.permisos.filter((p) => seleccionados.includes(p.id)).map((p) => p.id);

    if (propios.length === 0) return 'ninguno';

    for (const nivel of nivelesDe(modulo)) {
        const ids = idsDeNivel(modulo, nivel);
        if (ids.length === propios.length && ids.every((id) => propios.includes(id))) {
            return nivel;
        }
    }

    return null;
}

/** Cuántos permisos de este módulo están marcados. */
export function contarModulo(modulo, seleccionados) {
    return modulo.permisos.filter((p) => seleccionados.includes(p.id)).length;
}

/**
 * Plantillas de rol. Se describen por módulo y nivel, no por id de permiso,
 * porque los ids cambian de una instalación a otra.
 */
export const PLANTILLAS = [
    {
        clave: 'agente',
        nombre: 'Agente de chat',
        ayuda: 'Atiende conversaciones y lleva la ficha del cliente. No configura nada ni hace envíos masivos.',
        niveles: {
            chat: 'operar',
            crm: 'operar',
            contacts: 'operar',
            quick_replies: 'ver',
            macros: 'operar',
        },
    },
    {
        clave: 'supervisor',
        nombre: 'Supervisor',
        ayuda: 'Lo del agente, más las respuestas automáticas, las campañas y los reportes.',
        niveles: {
            chat: 'total',
            crm: 'total',
            contacts: 'total',
            whatsapp_menus: 'operar',
            auto_responses: 'operar',
            quick_replies: 'operar',
            macros: 'total',
            business_hours: 'operar',
            campaigns: 'operar',
            templates: 'ver',
            reports: 'ver',
        },
    },
    {
        clave: 'lectura',
        nombre: 'Solo lectura',
        ayuda: 'Puede mirar todo el sistema y no modificar nada. Útil para auditoría o para alguien que está entrando.',
        niveles: '__todo_ver__',
    },
    {
        clave: 'vacio',
        nombre: 'Empezar en blanco',
        ayuda: 'Sin ningún acceso. Vas marcando módulo por módulo.',
        niveles: {},
    },
];

/** Aplica una plantilla y devuelve la lista de ids seleccionados. */
export function idsDePlantilla(plantilla, grupos) {
    const ids = [];

    grupos.forEach((grupo) => {
        if (grupo.sobrante) return; // los permisos huérfanos nunca se conceden solos

        grupo.modulos.forEach((modulo) => {
            const nivel =
                plantilla.niveles === '__todo_ver__'
                    ? 'ver'
                    : plantilla.niveles[modulo.clave];

            if (!nivel) return;
            ids.push(...idsDeNivel(modulo, nivel));
        });
    });

    return [...new Set(ids)];
}

/** Resumen legible: los módulos con acceso y con qué alcance. */
export function resumen(grupos, seleccionados, niveles) {
    const lineas = [];

    grupos.forEach((grupo) => {
        grupo.modulos.forEach((modulo) => {
            const marcados = modulo.permisos.filter((p) => seleccionados.includes(p.id));
            if (marcados.length === 0) return;

            const nivel = nivelDe(modulo, seleccionados);

            lineas.push({
                grupo: grupo.titulo,
                modulo: modulo.nombre,
                delicado: modulo.delicado,
                nivel: nivel && nivel !== 'ninguno' ? niveles[nivel].etiqueta : 'Personalizado',
                detalle: marcados.map((p) => p.etiqueta).join(' · '),
            });
        });
    });

    return lineas;
}
