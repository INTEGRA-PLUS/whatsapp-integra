import { Button } from '@/components/ui/button';

/**
 * El glifo de Messenger, dibujado a mano.
 *
 * Por lo mismo que el de Instagram: lucide-react quitó los iconos de marca y
 * traer otra dependencia por una forma no se sostiene. El App Review pide que
 * el botón se reconozca, así que el logo tiene que ser el de verdad.
 */
function IconoMessenger(props) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {...props}>
            <path d="M12 2C6.3 2 2 6.16 2 11.77c0 2.94 1.2 5.48 3.15 7.23.16.15.26.35.27.57l.06 1.78c.02.57.6.94 1.12.71l1.99-.88c.17-.07.36-.09.54-.04 1.22.34 2.51.5 3.87.44 5.7 0 10-4.16 10-9.77S17.7 2 12 2Zm5.9 7.6-2.9 4.6a1.5 1.5 0 0 1-2.17.4L10.5 12.9a.6.6 0 0 0-.72 0l-3.1 2.35c-.41.32-.95-.18-.67-.62l2.9-4.6a1.5 1.5 0 0 1 2.17-.4l2.33 1.75a.6.6 0 0 0 .72 0l3.1-2.35c.41-.32.95.18.67.62Z" />
        </svg>
    );
}

/**
 * El botón que arranca el inicio de sesión de Facebook para empresas.
 *
 * Navegación de verdad y no `router.visit`: el destino está en facebook.com y
 * una petición XHR de Inertia contra otro dominio no llega a ninguna parte.
 *
 * Y visible, sin esconderlo tras un menú: el App Review lo comprueba de forma
 * explícita, igual que con el de Instagram.
 */
export default function ConectarMessengerButton({ disponible = true }) {
    if (!disponible) return null;

    return (
        <Button
            variant="outline"
            className="gap-2"
            onClick={() => { window.location.href = route('messenger.conectar'); }}
        >
            <IconoMessenger className="size-4" />
            Conectar Messenger
        </Button>
    );
}
