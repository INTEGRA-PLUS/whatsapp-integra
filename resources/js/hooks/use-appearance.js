import { useSyncExternalStore } from 'react';

const listeners = new Set();
let currentAppearance = 'system';

const prefersDark = () =>
    typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;

const setCookie = (name, value, days = 365) => {
    if (typeof document === 'undefined') return;
    document.cookie = `${name}=${value};path=/;max-age=${days * 24 * 60 * 60};SameSite=Lax`;
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') return 'system';
    return localStorage.getItem('appearance') || 'system';
};

const isDarkMode = (appearance) =>
    appearance === 'dark' || (appearance === 'system' && prefersDark());

const applyTheme = (appearance) => {
    if (typeof document === 'undefined') return;
    const dark = isDarkMode(appearance);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
};

const subscribe = (callback) => {
    listeners.add(callback);
    return () => listeners.delete(callback);
};

const notify = () => listeners.forEach((l) => l());

const handleSystemChange = () => applyTheme(currentAppearance);

export function initializeTheme() {
    if (typeof window === 'undefined') return;
    if (!localStorage.getItem('appearance')) {
        localStorage.setItem('appearance', 'system');
        setCookie('appearance', 'system');
    }
    currentAppearance = getStoredAppearance();
    applyTheme(currentAppearance);
    window.matchMedia('(prefers-color-scheme: dark)')?.addEventListener('change', handleSystemChange);
}

export function useAppearance() {
    const appearance = useSyncExternalStore(subscribe, () => currentAppearance, () => 'system');
    const resolvedAppearance = isDarkMode(appearance) ? 'dark' : 'light';

    const updateAppearance = (mode) => {
        currentAppearance = mode;
        localStorage.setItem('appearance', mode);
        setCookie('appearance', mode);
        applyTheme(mode);
        notify();
    };

    return { appearance, resolvedAppearance, updateAppearance };
}
