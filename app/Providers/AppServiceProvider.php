<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El catálogo de extensiones se resuelve una vez por petición: las
        // clases no tienen estado y el webhook llega a preguntar por ellas en
        // cada mensaje entrante.
        $this->app->singleton(\App\Extensions\ExtensionRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Tag::observe(\App\Observers\TagObserver::class);
        \App\Models\Company::observe(\App\Observers\CompanyObserver::class);

        // Super Admin Gate: Si es master, tiene todos los permisos
        Gate::before(function ($user, $ability) {
            return $user->hasRole('master') ? true : null;
        });
    }
}
