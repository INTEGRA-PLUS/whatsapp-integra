import { Link, usePage } from '@inertiajs/react';
import {
    LayoutGrid,
    MessageSquare,
    MessageSquareX,
    Settings,
    Briefcase,
    Package,
    PlusCircle,
    Home,
    Layers,
    Users,
    ShieldCheck,
    Zap,
    Bot,
    Megaphone,
    BarChart3,
    FileText,
    FileType,
    Webhook,
    BellRing,
    Contact,
    Wand2,
    ListTree
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

export function AppSidebar() {
    const { auth } = usePage().props;
    const user = auth?.user;

    let navGroups = [];

    if (user?.role === 'master') {
        navGroups = [
            {
                label: 'Master',
                items: [
                    { title: 'Panel Master', href: route('master.index'), icon: LayoutGrid },
                    { title: 'Empresas', href: route('master.index', { tab: 'companies' }), icon: Briefcase },
                    { title: 'Planes', href: route('master.index', { tab: 'plans' }), icon: Package },
                ],
            },
            {
                label: 'Auditoría',
                items: [
                    { title: 'Mensajes no entregados', href: route('master.messages.index'), icon: MessageSquareX },
                    { title: 'Logs', href: route('master.logs.index'), icon: FileText },
                ],
            },
        ];
    } else {
        const permissions = usePage().props.auth.user.permissions || [];
        const hasPermission = (perm) => permissions.includes(perm);

        // De lo que se usa cada día a lo que se configura una vez.
        navGroups = [
            {
                label: 'Conversaciones',
                items: [
                    { title: 'Chat', href: route('chat.index'), icon: MessageSquare, show: hasPermission('chat.view') },
                    { title: 'CRM', href: route('chat.kanban'), icon: Layers, show: hasPermission('crm.view') },
                    { title: 'Contactos', href: route('contacts.index'), icon: Contact, show: hasPermission('contacts.view') },
                ],
            },
            {
                // Las cuatro maneras de que el sistema conteste solo, juntas:
                // son alternativas entre sí y separadas nadie las compara.
                label: 'Respuestas automáticas',
                items: [
                    { title: 'Menús de WhatsApp', href: route('whatsapp-menus.index'), icon: ListTree, show: hasPermission('whatsapp_menus.view') },
                    { title: 'Respuestas Automáticas', href: route('auto-responses.index'), icon: Bot, show: hasPermission('auto_responses.view') },
                    { title: 'Respuestas Rápidas', href: route('quick-replies.index'), icon: Zap, show: hasPermission('quick_replies.view') },
                    { title: 'Macros', href: route('macros.index'), icon: Wand2, show: hasPermission('macros.view') },
                ],
            },
            {
                label: 'Envíos',
                items: [
                    { title: 'Campañas', href: route('campaigns.index'), icon: Megaphone, show: hasPermission('campaigns.view') },
                    { title: 'Plantillas', href: route('templates.index'), icon: FileType, show: hasPermission('templates.view') },
                ],
            },
            {
                label: 'Análisis',
                items: [
                    { title: 'Reportes', href: route('reports.index'), icon: BarChart3, show: hasPermission('reports.view') },
                    // Cuelga de las rutas master por herencia, pero el listado
                    // va filtrado a la empresa de quien mira: es auditoría suya.
                    { title: 'Mensajes no entregados', href: route('master.messages.index'), icon: MessageSquareX },
                ],
            },
            {
                label: 'Configuración',
                items: [
                    { title: 'Instancias', href: route('instances.index'), icon: Settings, show: hasPermission('instances.view') },
                    { title: 'Integraciones', href: route('integrations.index'), icon: Webhook, show: hasPermission('integrations.view') },
                    { title: 'Usuarios', href: route('users.index'), icon: Users, show: hasPermission('users.view') },
                    { title: 'Roles', href: route('roles.index'), icon: ShieldCheck, show: hasPermission('roles.view') },
                    { title: 'Notificaciones', href: route('announcements.index'), icon: BellRing, show: hasPermission('notifications.send') },
                ],
            },
        ];
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={user?.role === 'master' ? route('master.index') : route('chat.index')}>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
