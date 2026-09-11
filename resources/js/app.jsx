import './bootstrap';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from '@/hooks/use-appearance';

// Inicializar tema antes del primer render
initializeTheme();

createInertiaApp({
    title: (title) => title ? `${title} — Integra CRM` : 'Integra CRM',
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.jsx`,
            import.meta.glob('./pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        // El verde del logo. Va escrito a mano porque Inertia pinta esta barra
        // fuera de React, antes de que exista una hoja de estilos donde mirar
        // `--primary`; si se le pasa `var(--primary)` no pinta nada.
        color: '#76c652',
    },
});
