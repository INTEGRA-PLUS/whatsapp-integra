import './bootstrap';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from '@/hooks/use-appearance';

// Inicializar tema antes del primer render
initializeTheme();

createInertiaApp({
    title: (title) => title ? `${title} - WhatsApp Manager` : 'WhatsApp Manager',
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.jsx`,
            import.meta.glob('./pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#16a34a',
    },
});
