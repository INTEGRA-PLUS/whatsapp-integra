# WhatsApp Integra — Notas de arquitectura

## Stack actual
- Laravel 12 + Inertia.js (inertiajs/inertia-laravel) + React 19 + Vite 7
- Tailwind CSS 4 con variables oklch para dark/light mode
- Radix UI (slot, tooltip, dropdown-menu, separator, dialog/sheet, avatar)
- Lucide React para iconos
- Ziggy (tightenco/ziggy) para rutas en frontend
- @vitejs/plugin-react v5.2.0 (compatible con vite 7; v6 requiere vite 8)

## Estructura frontend
```
resources/js/
├── app.jsx                    # Bootstrap Inertia + initializeTheme()
├── lib/utils.js               # cn() helper (clsx + tailwind-merge)
├── hooks/
│   ├── use-appearance.js      # Dark/light/system theme hook
│   └── use-mobile.js          # isMobile breakpoint hook
├── components/
│   ├── ui/                    # Componentes Radix (sidebar, button, tooltip, etc.)
│   ├── app-logo.jsx           # Logo WhatsApp verde
│   ├── app-sidebar.jsx        # Sidebar con nav dinámica según rol
│   ├── nav-main.jsx           # Navegación principal con active state
│   └── nav-user.jsx           # Dropdown usuario + theme toggle
├── layouts/
│   └── AppLayout.jsx          # SidebarProvider + SidebarInset + flash messages
└── pages/
    ├── Auth/Login.jsx
    ├── Chat/Index.jsx         # Vue→React migrado, usa API JSON /api/chat/*
    ├── Instances/Index.jsx
    └── Master/Index.jsx
```

## Middleware Inertia
- `app/Http/Middleware/HandleInertiaRequests.php` — comparte auth.user, auth.isImpersonating, flash
- Registrado en `bootstrap/app.php` bajo `->withMiddleware(web: append)`

## Dark mode
- Hook `use-appearance.js` → guarda en localStorage + cookie
- `initializeTheme()` llamado en app.jsx antes del primer render
- CSS usa `.dark` class en `<html>` con oklch custom properties
- Toggle en dropdown del NavUser: light → dark → system (ciclo)

## Roles de usuario
- master: ve Panel Master en sidebar + puede impersonar
- admin / agent: ven Chat + Instancias

## Auth
- Sin Fortify — auth custom con roles multi-tenant
- Impersonación via session('impersonated_by')
- Badge naranja en topbar cuando se está suplantando

## Rutas importantes
- Login: GET/POST /login (Inertia::render('Auth/Login'))
- Chat: GET /chat → Inertia::render('Chat/Index', ['instances'])
- Instancias: GET /instances → Inertia::render('Instances/Index')
- Master: GET /master → Inertia::render('Master/Index', ['companies', 'filters'])
- API chat (JSON): /api/chat/conversations, /api/chat/updates, etc.

## Notas de compatibilidad
- NO usar @vitejs/plugin-react v6 (requiere vite 8; laravel-vite-plugin 2.x usa vite 7)
- CSS: `tw-animate-css` para animaciones Radix + `@custom-variant dark`
- Ziggy: usa @routes blade directive en app.blade.php (no via shared props)
