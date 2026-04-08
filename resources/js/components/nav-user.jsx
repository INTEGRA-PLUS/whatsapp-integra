import { router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, LogOut, Moon, Settings, ShieldCheck, Sun, SunMoon } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useAppearance } from '@/hooks/use-appearance';

function getInitials(name) {
    if (!name) return '??';
    return name
        .split(' ')
        .slice(0, 2)
        .map((n) => n[0])
        .join('')
        .toUpperCase();
}

const ROLE_LABELS = { master: 'Master', admin: 'Admin', agent: 'Agente' };

export function NavUser() {
    const { auth } = usePage().props;
    const { isMobile } = useSidebar();
    const { appearance, updateAppearance } = useAppearance();

    const user = auth?.user;
    if (!user) return null;

    function handleLogout() {
        router.post(route('logout'));
    }

    function handleReturnToMaster() {
        router.post(route('stop-impersonating'));
    }

    function cycleAppearance() {
        const next = appearance === 'light' ? 'dark' : appearance === 'dark' ? 'system' : 'light';
        updateAppearance(next);
    }

    const AppearanceIcon = appearance === 'light' ? Sun : appearance === 'dark' ? Moon : SunMoon;
    const appearanceLabel = appearance === 'light' ? 'Claro' : appearance === 'dark' ? 'Oscuro' : 'Sistema';

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        >
                            <Avatar className="h-8 w-8 rounded-lg">
                                <AvatarFallback className="rounded-lg bg-green-600 text-white text-xs font-bold">
                                    {getInitials(user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">{user.name}</span>
                                <span className="text-muted-foreground truncate text-xs">{ROLE_LABELS[user.role] ?? user.role}</span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-[--radix-dropdown-menu-trigger-width] min-w-56 rounded-lg"
                        side={isMobile ? 'bottom' : 'right'}
                        align="end"
                        sideOffset={4}
                    >
                        <DropdownMenuLabel className="p-0 font-normal">
                            <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <Avatar className="h-8 w-8 rounded-lg">
                                    <AvatarFallback className="rounded-lg bg-green-600 text-white text-xs font-bold">
                                        {getInitials(user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold">{user.name}</span>
                                    <span className="text-muted-foreground truncate text-xs">{user.email}</span>
                                </div>
                            </div>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuItem onClick={() => router.get(route('settings.index'))} className="cursor-pointer">
                                <Settings className="mr-2 size-4" />
                                Configuración
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={cycleAppearance} className="cursor-pointer">
                                <AppearanceIcon className="mr-2 size-4" />
                                Tema: {appearanceLabel}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                        {auth?.fromMaster && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onClick={handleReturnToMaster} className="cursor-pointer text-indigo-600 focus:text-indigo-600 focus:bg-indigo-50 dark:focus:bg-indigo-950/40">
                                    <ShieldCheck className="mr-2 size-4" />
                                    Volver al Master
                                </DropdownMenuItem>
                            </>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={handleLogout} className="cursor-pointer" variant="destructive">
                            <LogOut className="mr-2 size-4" />
                            Cerrar sesión
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
