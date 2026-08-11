import { useEffect } from 'react';

const REFRESH_EVENT = 'notifications:refresh';

/**
 * Pide a la campana que recargue ahora mismo.
 *
 * La campana ya consulta cada 25 segundos, pero cuando la notificación la
 * provoca una acción del propio usuario —cerrar un chat, por ejemplo— esperar
 * ese cuarto de minuto se siente como que no funcionó. Se llama después de que
 * el servidor confirme la acción.
 *
 * Solo sirve para la pestaña que hizo la acción: las notificaciones que genera
 * otra persona siguen llegando por el poll.
 */
export function refreshNotifications() {
    window.dispatchEvent(new CustomEvent(REFRESH_EVENT));
}

/**
 * Suscribe la campana a esas peticiones de recarga.
 *
 * `onRefresh` debe ser estable (useCallback) o el efecto se re-suscribe en
 * cada render.
 */
export function useNotificationRefresh(onRefresh) {
    useEffect(() => {
        window.addEventListener(REFRESH_EVENT, onRefresh);
        return () => window.removeEventListener(REFRESH_EVENT, onRefresh);
    }, [onRefresh]);
}
