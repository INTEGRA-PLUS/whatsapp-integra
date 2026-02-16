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
        // Fix for cPanel: Check for sibling directory "whatsapp.integracolombia.com"
        // This is necessary because the code is in "whatsapp-integra" and public is in "whatsapp.integracolombia.com"
        $customPublicPath = base_path('../whatsapp.integracolombia.com');
        
        if (file_exists($customPublicPath)) {
            $this->app->bind('path.public', function () use ($customPublicPath) {
                return $customPublicPath;
            });
        } elseif (isset($_SERVER['DOCUMENT_ROOT']) && !app()->runningInConsole()) {
            // Fallback to DOCUMENT_ROOT if the specific folder isn't found
            $this->app->bind('path.public', function () {
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
