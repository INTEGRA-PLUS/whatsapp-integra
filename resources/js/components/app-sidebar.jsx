import { Link, usePage } from '@inertiajs/react';
import {
    LayoutGrid,
    MessageSquare,
    Settings,
    Briefcase,
    Package,
    PlusCircle,
    Home,
    Layers,
    Users,
    ShieldCheck,
    Zap
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

    let mainNavItems = [];

    if (user?.role === 'master') {
        mainNavItems = [
            { title: 'Panel Master', href: route('master.index'), icon: LayoutGrid },
            { title: 'Empresas', href: route('master.index', { tab: 'companies' }), icon: Briefcase },
            { title: 'Planes', href: route('master.index', { tab: 'plans' }), icon: Package },
        ];
    } else {
        const permissions = usePage().props.auth.user.permissions || [];
        const hasPermission = (perm) => permissions.includes(perm);

        mainNavItems = [
            { title: 'Chat', href: route('chat.index'), icon: MessageSquare, show: hasPermission('chat.view') },
            { title: 'CRM', href: route('chat.kanban'), icon: Layers, show: hasPermission('crm.view') },
            { title: 'Respuestas Rápidas', href: route('quick-replies.index'), icon: Zap, show: hasPermission('quick_replies.view') },
            { title: 'Usuarios', href: route('users.index'), icon: Users, show: hasPermission('users.view') },
            { title: 'Roles', href: route('roles.index'), icon: ShieldCheck, show: hasPermission('roles.view') },
            { title: 'Instancias', href: route('instances.index'), icon: Settings, show: hasPermission('instances.view') },
        ].filter(item => item.show !== false);
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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
