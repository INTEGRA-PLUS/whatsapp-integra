<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // En cPanel, forzar la ruta pública correcta si estamos en el entorno de producción
        $productionPublicPath = '/home/intesoga/whatsapp.integracolombia.com';
        
        if (file_exists($productionPublicPath)) {
            $this->app->bind('path.public', function() use ($productionPublicPath) {
                return $productionPublicPath;
            });
        } elseif (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
             // Fallback genérico para cPanel
            $this->app->bind('path.public', function() {
                return $_SERVER['DOCUMENT_ROOT'];
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
