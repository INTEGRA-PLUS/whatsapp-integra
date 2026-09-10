import { router, usePage } from '@inertiajs/react';
import { AppSidebar } from '@/components/app-sidebar';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import NotificationBell from '@/components/notification-bell';
import AvisoNuevaVersion from '@/components/aviso-nueva-version';

function getDefaultOpen() {
    if (typeof document === 'undefined') return true;
    const match = document.cookie.match(/sidebar_state=([^;]+)/);
    return match ? match[1] === 'true' : true;
}

function ImpersonatingBadge() {
    return (
        <form onSubmit={(e) => { e.preventDefault(); router.post(route('stop-impersonating')); }}>
            <button
                type="submit"
                className="flex items-center gap-1.5 rounded-full bg-warning/15 px-3 py-1 text-xs font-medium text-warning hover:bg-warning/15 dark:text-warning"
            >
                <span className="size-1.5 rounded-full bg-warning animate-pulse" />
                Suplantando — click para salir
            </button>
        </form>
    );
}

export default function AppLayout({ children, breadcrumb }) {
    const { flash, auth } = usePage().props;

    return (
        <SidebarProvider defaultOpen={getDefaultOpen()}>
            <AppSidebar />
            <SidebarInset>
                {/* Top bar */}
                <header className="flex h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear">
                    <div className="flex flex-1 items-center gap-2">
                        <SidebarTrigger className="-ml-1" />
                        <Separator orientation="vertical" className="mr-2 h-4" />
                        {breadcrumb && (
                            <nav className="flex items-center gap-1 text-sm text-muted-foreground">
                                {breadcrumb.map((item, i) => (
                                    <span key={i} className="flex items-center gap-1">
                                        {i > 0 && <span className="opacity-40">/</span>}
                                        <span className={i === breadcrumb.length - 1 ? 'text-foreground font-medium' : ''}>
                                            {item}
                                        </span>
                                    </span>
                                ))}
                            </nav>
                        )}
                        <div className="ml-auto flex items-center gap-2">
                            {/* Slot para que la página monte sus controles acá (vía portal)
                                en lugar de agregar una segunda barra propia. */}
                            <div id="app-topbar-actions" className="flex items-center gap-2" />
                            {auth?.isImpersonating && <ImpersonatingBadge />}
                            {auth?.user?.role !== 'master' && <NotificationBell />}
                        </div>
                    </div>
                </header>

                {/* Flash messages */}
                {(flash?.success || flash?.error) && (
                    <div className="px-6 pt-4">
                        {flash.success && (
                            <div className="rounded-lg border border-success/30 bg-success/15 px-4 py-3 text-sm text-success dark:border-success/30 dark:bg-success/30 dark:text-success">
                                {flash.success}
                            </div>
                        )}
                        {flash.error && (
                            <div className="rounded-lg border border-destructive/30 bg-destructive/15 px-4 py-3 text-sm text-destructive dark:border-destructive/30 dark:bg-destructive/30 dark:text-destructive">
                                {flash.error}
                            </div>
                        )}
                    </div>
                )}

                {children}
                <AvisoNuevaVersion />
            </SidebarInset>
        </SidebarProvider>
    );
}
