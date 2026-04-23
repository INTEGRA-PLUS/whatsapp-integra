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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Tag::observe(\App\Observers\TagObserver::class);

        // Super Admin Gate: Si es master, tiene todos los permisos
        Gate::before(function ($user, $ability) {
            return $user->hasRole('master') ? true : null;
        });
    }
}
