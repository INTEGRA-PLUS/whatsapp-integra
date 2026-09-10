import { Button } from '@/components/ui/button';

/**
 * El glifo de Instagram, dibujado a mano.
 *
 * lucide-react quitó los iconos de marca, así que importarlo revienta el build
 * con «"Instagram" is not exported». Va aquí en vez de buscar otra dependencia:
 * son doce líneas y el App Review pide que el botón se reconozca.
 */
function IconoInstagram(props) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            <rect x="2" y="2" width="20" height="20" rx="5" />
            <circle cx="12" cy="12" r="4" />
            <circle cx="17.5" cy="6.5" r="1.2" fill="currentColor" stroke="none" />
        </svg>
    );
}

/**
 * El botón que arranca Business Login for Instagram.
 *
 * Es una navegación de verdad y no un `router.visit` de Inertia: el destino está
 * en instagram.com y una petición XHR de Inertia contra otro dominio no lleva a
 * ninguna parte. Por eso `window.location`.
 *
 * Y tiene que estar **visible**: el App Review lo comprueba de forma explícita
 * —«verify that the login button or link is visible in your app and
 * screencast»—, así que no se esconde detrás de un menú.
 */
export default function ConectarInstagramButton({ disponible = true }) {
    if (!disponible) return null;

    return (
        <Button
            variant="outline"
            className="gap-2"
            onClick={() => { window.location.href = route('instagram.conectar'); }}
        >
            <IconoInstagram className="size-4" />
            Conectar Instagram
        </Button>
    );
}
