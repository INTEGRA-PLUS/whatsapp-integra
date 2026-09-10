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
 * La ruta de un enlace, sin dominio ni parámetros.
 *
 * `route()` devuelve la URL entera —"http://host/chat"— mientras que Inertia
 * da la ruta a secas —"/chat"—, así que compararlas era comparar cosas
 * distintas: ninguna coincidía nunca y **la barra no resaltaba jamás dónde
 * estabas**. Se veía poco mientras el fondo era casi blanco; con la barra en
 * navy, un menú sin marcar la página actual es difícil de leer de un vistazo.
 */
function soloLaRuta(href) {
    try {
        return new URL(href, window.location.origin).pathname;
    } catch {
        return String(href).split('?')[0];
    }
}

export function NavMain({ groups = [] }) {
    const { url } = usePage();
    const rutaActual = url.split('?')[0];

    return (
        <>
            {groups
                .map(group => ({ ...group, items: (group.items ?? []).filter(item => item.show !== false) }))
                .filter(group => group.items.length > 0)
                .map(group => (
                    <SidebarGroup key={group.label} className="px-2 py-0">
                        <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                        <SidebarMenu>
                            {group.items.map(item => (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        // `exact` existe por la portada: su enlace es "/",
                                        // y con `startsWith` toda ruta empieza por "/", así
                                        // que aparecería resaltada estuvieras donde estuvieras.
                                        isActive={item.exact
                                            ? rutaActual === soloLaRuta(item.href)
                                            : rutaActual.startsWith(soloLaRuta(item.href))}
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
