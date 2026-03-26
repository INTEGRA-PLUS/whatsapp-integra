import { Link, usePage } from '@inertiajs/react';
import { 
    LayoutGrid, 
    MessageSquare, 
    Settings, 
    Briefcase,
    Package,
    PlusCircle,
    Home,
    Layers
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
        mainNavItems = [
            { title: 'Chat', href: route('chat.index'), icon: MessageSquare },
            { title: 'Instancias', href: route('instances.index'), icon: Settings },
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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
