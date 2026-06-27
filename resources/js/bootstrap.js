import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Laravel Echo + Reverb (websockets) para eventos en tiempo real (llamadas).
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

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
