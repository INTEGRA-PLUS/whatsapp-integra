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
        // Fix for cPanel: Bind public path to the actual document root
        if (isset($_SERVER['DOCUMENT_ROOT']) && !app()->runningInConsole()) {
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
