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
export function NavMain({ groups = [] }) {
    const { url } = usePage();

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
                                        isActive={url.startsWith(item.href)}
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
