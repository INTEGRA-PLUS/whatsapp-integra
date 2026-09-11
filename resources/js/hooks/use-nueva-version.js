import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Avisa cuando el servidor ya sirve una versión distinta a la que tiene abierta
 * este navegador.
 *
 * Inertia compara versiones en cada petición y, si no coinciden, responde 409 y
 * el navegador recarga en el acto. Es una protección buena —el JavaScript viejo
 * no debe hablar con el backend nuevo— pero se cobra la página de quien estaba
 * escribiendo, sin preguntar.
 *
 * Este hook llega antes: pregunta la versión por su cuenta, con una petición
 * normal que nunca provoca ese 409, y así la pantalla puede ofrecer recargar
 * cuando al usuario le venga bien. Si aun así navega antes de hacer caso, el
 * 409 de Inertia sigue ahí como última red.
 *
 * @param {number} cadaMs Cada cuánto preguntar. Un despliegue no es algo que
 *   pase cada minuto, así que no hace falta apretar.
 */
export function useNuevaVersion(cadaMs = 60000) {
    const versionAlAbrir = usePage().version;
    const [hayNueva, setHayNueva] = useState(false);

    useEffect(() => {
        // Sin versión no hay nada que comparar: pasa si Inertia no la reporta.
        if (!versionAlAbrir || hayNueva) return;

        let vivo = true;

        async function mirar() {
            // La pestaña en segundo plano no necesita enterarse: se comprueba
            // al volver a ella, que es cuando el aviso sirve de algo.
            if (document.hidden) return;

            try {
                const res = await fetch(route('version'), {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                });

                if (!res.ok) return;

                const { version } = await res.json();

                if (vivo && version && version !== versionAlAbrir) {
                    setHayNueva(true);
                }
            } catch {
                // Sin red, o el servidor reiniciándose durante el despliegue.
                // Callar es lo correcto: ya se preguntará en la siguiente vuelta.
            }
        }

        const reloj = setInterval(mirar, cadaMs);
        document.addEventListener('visibilitychange', mirar);

        return () => {
            vivo = false;
            clearInterval(reloj);
            document.removeEventListener('visibilitychange', mirar);
        };
    }, [versionAlAbrir, hayNueva, cadaMs]);

    return hayNueva;
}
