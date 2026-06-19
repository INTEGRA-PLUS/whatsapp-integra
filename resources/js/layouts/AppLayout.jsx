import { router, usePage } from '@inertiajs/react';
import { AppSidebar } from '@/components/app-sidebar';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import NotificationBell from '@/components/notification-bell';

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
                className="flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400"
            >
                <span className="size-1.5 rounded-full bg-amber-500 animate-pulse" />
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
                            {auth?.isImpersonating && <ImpersonatingBadge />}
                            {auth?.user?.role !== 'master' && <NotificationBell />}
                        </div>
                    </div>
                </header>

                {/* Flash messages */}
                {(flash?.success || flash?.error) && (
                    <div className="px-6 pt-4">
                        {flash.success && (
                            <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800/30 dark:bg-green-950/30 dark:text-green-400">
                                {flash.success}
                            </div>
                        )}
                        {flash.error && (
                            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/30 dark:bg-red-950/30 dark:text-red-400">
                                {flash.error}
                            </div>
                        )}
                    </div>
                )}

                {children}
            </SidebarInset>
        </SidebarProvider>
    );
}
