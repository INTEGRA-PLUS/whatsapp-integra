import { Link, usePage } from '@inertiajs/react';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';

/**
 * La navegación, por grupos.
 *
 * Antes era una lista plana de dieciséis elementos bajo una sola etiqueta:
 * "Chat", que se usa cada día, pesaba lo mismo que "Roles", que se toca una vez
 * al año. Y las cuatro formas de que el sistema conteste solo —menús,
 * respuestas automáticas, respuestas rápidas y macros— estaban repartidas entre
 * Contactos y Campañas, así que alguien podía montar una respuesta automática
 * sin enterarse de que existían los menús.
 *
 * Los grupos van de lo diario a lo que se configura una vez. Un grupo cuyos
 * elementos se hayan filtrado por permisos no se pinta: la etiqueta sola sería
 * ruido.
 */
/**
 * Cuánto encaja un elemento con la página abierta. -1 si no encaja.
 *
 * Dos cosas que había que resolver a la vez:
 *
 * `route()` devuelve la URL entera —"http://host/chat"— mientras que Inertia da
 * la ruta a secas —"/chat"—, así que compararlas era comparar cosas distintas:
 * no coincidía ninguna y **la barra no resaltaba nunca dónde estabas**.
 *
 * Y el panel maestro tiene tres elementos que apuntan al mismo sitio y sólo se
 * distinguen por el parámetro: `/master`, `/master?tab=companies` y
 * `/master?tab=plans`. Comparando sólo la ruta, los tres se encendían a la vez.
 *
 * Por eso esto puntúa en vez de responder sí o no: encaja el que comparte ruta
 * y **todos** sus parámetros, y de los que encajan se queda el más específico
 * —primero por número de parámetros, luego por longitud de la ruta—. Así
 * "Empresas" gana a "Panel Master" cuando hay `tab=companies`, y "Panel Master"
 * gana cuando no hay ninguno.
 */
function loQueEncaja(href, actual, exacto) {
    let destino;
    try {
        destino = new URL(href, window.location.origin);
    } catch {
        return -1;
    }

    const ruta = destino.pathname.replace(/\/$/, '') || '/';
    const aqui = actual.pathname.replace(/\/$/, '') || '/';

    const mismaRuta = exacto
        ? aqui === ruta
        : aqui === ruta || (ruta !== '/' && aqui.startsWith(ruta + '/'));

    if (! mismaRuta) {
        return -1;
    }

    const parametros = [...destino.searchParams];
    for (const [clave, valor] of parametros) {
        if (actual.searchParams.get(clave) !== valor) {
            return -1;
        }
    }

    return parametros.length * 1000 + ruta.length;
}

export function NavMain({ groups = [] }) {
    const { url } = usePage();

    const visibles = groups
        .map(group => ({ ...group, items: (group.items ?? []).filter(item => item.show !== false) }))
        .filter(group => group.items.length > 0);

    // Se resuelve una sola vez para toda la barra, no por grupo: si no, el
    // ganador de cada grupo se encendería por su cuenta y volveríamos a tener
    // varios a la vez.
    const actual = new URL(url, window.location.origin);
    const puntos = new Map();
    let mejor = -1;

    for (const grupo of visibles) {
        for (const item of grupo.items) {
            const p = loQueEncaja(item.href, actual, item.exact);
            puntos.set(item, p);
            if (p > mejor) mejor = p;
        }
    }

    return (
        <>
            {visibles
                .map(group => (
                    <SidebarGroup key={group.label} className="px-2 py-0">
                        <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                        <SidebarMenu>
                            {group.items.map(item => (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        // Sólo el mejor, y sólo si de verdad encaja:
                                        // con `mejor` a -1 no se enciende ninguno.
                                        isActive={mejor >= 0 && puntos.get(item) === mejor}
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link href={item.href}>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
        </>
    );
}
