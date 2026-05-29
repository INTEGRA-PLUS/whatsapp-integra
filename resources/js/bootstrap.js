import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

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
