<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- El .ico se queda de último: es el que piden los navegadores viejos, pero
         el logo tiene degradados y bordes suaves que en 16 colores se ensucian. --}}
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <script>
        // Esta app NO usa PWA / service worker. Si quedó uno registrado en este
        // origen (de una versión previa u otra app en el mismo localhost), puede
        // interceptar peticiones y devolver respuestas obsoletas que rompen la
        // carga de Inertia. Lo desregistramos y limpiamos sus cachés.
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations()
                .then(function (regs) { regs.forEach(function (reg) { reg.unregister(); }); })
                .catch(function () {});
            if (window.caches && caches.keys) {
                caches.keys()
                    .then(function (keys) { keys.forEach(function (k) { caches.delete(k); }); })
                    .catch(function () {});
            }
        }
    </script>
    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
{{-- Sin clase de color: el fondo lo pone el token del tema (`bg-background`
     en @layer base de app.css). Con `bg-gray-50` fija aquí, el body se quedaba
     gris claro en tema oscuro y ninguna variable de marca llegaba a verse. --}}
<body>
    @inertia
</body>
</html>
