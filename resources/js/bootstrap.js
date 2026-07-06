import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Laravel Echo + Reverb (websockets) para eventos en tiempo real (llamadas).
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Config de Reverb horneada por Vite en build. Si el host o la key no están
// presentes, NO inicializamos Echo: de lo contrario pusher-js cae en su host
// por defecto (wss://ws-<cluster>.pusher.com) y reintenta sin parar, generando
// errores en consola y lentitud. Reverb NO es Pusher cloud.
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const reverbHost = import.meta.env.VITE_REVERB_HOST;
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT ?? (reverbScheme === 'https' ? 443 : 80));

if (reverbKey && reverbHost) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: reverbPort,
        wssPort: reverbPort,
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else {
    console.warn(
        '[Echo] Reverb no configurado (faltan VITE_REVERB_APP_KEY o VITE_REVERB_HOST). ' +
        'El tiempo real queda deshabilitado. Revisa el .env y recompila con `npm run build`.',
    );
}

function applyCsrfToken(token) {
    if (!token) return;
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
}

applyCsrfToken(document.querySelector('meta[name="csrf-token"]')?.content);

let csrfRefreshPromise = null;
function refreshCsrfToken() {
    if (!csrfRefreshPromise) {
        csrfRefreshPromise = fetch('/csrf-token', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((data) => {
                applyCsrfToken(data?.token);
                return data?.token;
            })
            .finally(() => { csrfRefreshPromise = null; });
    }
    return csrfRefreshPromise;
}

window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const original = error.config;
        if (error.response?.status === 419 && original && !original.__csrfRetried) {
            original.__csrfRetried = true;
            try {
                const token = await refreshCsrfToken();
                if (token) {
                    original.headers = original.headers ?? {};
                    original.headers['X-CSRF-TOKEN'] = token;
                }
                return window.axios(original);
            } catch (e) {
                return Promise.reject(error);
            }
        }
        return Promise.reject(error);
    },
);
