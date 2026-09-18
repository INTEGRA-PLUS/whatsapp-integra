import { useEffect, useRef } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AppSidebar } from '@/components/app-sidebar';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import NotificationBell from '@/components/notification-bell';
import AvisoNuevaVersion from '@/components/aviso-nueva-version';
import { AvisosProvider, useAviso } from '@/components/ui/toast';

function getDefaultOpen() {
    if (typeof document === 'undefined') return true;
    const match = document.cookie.match(/sidebar_state=([^;]+)/);
    return match ? match[1] === 'true' : true;
}

/**
 * El aviso de que estás dentro de la cuenta de otro.
 *
 * En el móvil la frase entera —«Suplantando — click para salir»— no cabe en una
 * barra de 48 píxeles: se partía en tres líneas y se salía por el borde derecho,
 * tapando la campana. Ahí queda sólo «Suplantando», que es lo que hay que ver de
 * un vistazo; cómo se sale lo dice la pantalla en cuanto hay sitio.
 */
function ImpersonatingBadge() {
    return (
        <form className="shrink-0" onSubmit={(e) => { e.preventDefault(); router.post(route('stop-impersonating')); }}>
            <button
                type="submit"
                title="Estás dentro de la cuenta de otro usuario. Pulsa para volver a la tuya."
                className="flex items-center gap-1.5 whitespace-nowrap rounded-full bg-warning/15 px-2.5 sm:px-3 py-1 text-xs font-medium text-warning hover:bg-warning/15 dark:text-warning"
            >
                <span className="size-1.5 shrink-0 rounded-full bg-warning animate-pulse" />
                Suplantando<span className="hidden sm:inline"> — click para salir</span>
            </button>
        </form>
    );
}

/**
 * Los mensajes del servidor, como aviso flotante.
 *
 * Eran una banda a todo el ancho entre la barra superior y la pantalla: empujaba
 * el contenido hacia abajo al aparecer, se quedaba puesta hasta la siguiente
 * visita y no se parecía a nada del resto del producto —que ya tiene sus avisos
 * flotantes, con los tokens de la marca, desde el tablero—.
 *
 * Aquí sólo se traducen: el `flash` de Laravel entra por `useAviso()` y sale
 * como los demás avisos, arriba a la derecha y sin mover nada de sitio.
 */
function FlashComoAviso({ flash }) {
    const aviso = useAviso();
    const ultimo = useRef(null);

    useEffect(() => {
        const texto = flash?.success ?? flash?.error;

        // Cuando no hay mensaje se olvida el anterior: así dos acciones seguidas
        // con el mismo texto —«Usuario actualizado», «Usuario actualizado»— sí
        // avisan las dos veces.
        if (! texto) {
            ultimo.current = null;

            return;
        }

        // Inertia conserva las props compartidas en las recargas parciales, así
        // que el mismo `flash` vuelve a llegar en cada una. Sin esta guarda, un
        // aviso se repetiría cada vez que la pantalla pide sólo una parte.
        const huella = (flash.success ? 'ok:' : 'err:') + texto;

        if (ultimo.current === huella) return;

        ultimo.current = huella;

        if (flash.success) {
            aviso.exito(texto);
        } else {
            aviso.error(texto);
        }
    }, [flash?.success, flash?.error]); // eslint-disable-line react-hooks/exhaustive-deps

    return null;
}

export default function AppLayout({ children, breadcrumb }) {
    const { flash, auth } = usePage().props;

    return (
        // Los avisos flotantes envuelven todo el layout para que cualquier
        // pantalla pueda pedirlos con `useAviso()` sin montar nada propio.
        <AvisosProvider>
        <SidebarProvider defaultOpen={getDefaultOpen()}>
            <AppSidebar />
            <SidebarInset>
                {/* Top bar */}
                <header className="flex h-12 shrink-0 items-center gap-2 overflow-hidden border-b border-sidebar-border/50 px-3 sm:px-4 transition-[width,height] ease-linear">
                    <div className="flex flex-1 items-center gap-2 min-w-0">
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
                        <div className="ml-auto flex items-center gap-2 min-w-0">
                            {/* Slot para que la página monte sus controles acá (vía portal)
                                en lugar de agregar una segunda barra propia. */}
                            <div id="app-topbar-actions" className="flex items-center gap-2" />
                            {auth?.isImpersonating && <ImpersonatingBadge />}
                            {auth?.user?.role !== 'master' && <NotificationBell />}
                        </div>
                    </div>
                </header>

                <FlashComoAviso flash={flash} />

                {children}
                <AvisoNuevaVersion />
            </SidebarInset>
        </SidebarProvider>
        </AvisosProvider>
    );
}
