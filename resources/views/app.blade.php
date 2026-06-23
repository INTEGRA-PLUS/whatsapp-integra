<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
<body class="bg-gray-50">
    @inertia
</body>
</html>
