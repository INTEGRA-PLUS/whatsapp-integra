<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp',
            'master/impersonate/*', // Just in case, though it's internal
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Fix for cPanel: Force public path to the actual document root
if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $app->usePublicPath($_SERVER['DOCUMENT_ROOT']);
} elseif (file_exists('/home/intesoga/whatsapp.integracolombia.com')) {
    $app->usePublicPath('/home/intesoga/whatsapp.integracolombia.com');
}

return $app;
