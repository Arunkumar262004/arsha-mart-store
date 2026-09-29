<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Every assignable permission becomes a Gate ability, so routes can
        // use `can:products.manage` and policies stay out of the way.
        foreach (array_keys(config('permissions')) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        Gate::define('settings.manage', fn (User $user) => $user->isAdmin());

        // Cashiers need the catalog for the billing dropdown even without
        // access to the Inventory screens.
        Gate::define('products.view-or-bill', fn (User $user) => $user->hasPermission('products.view') || $user->hasPermission('billing.create'));

        // Slow down password guessing: 5 attempts per minute per email + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
