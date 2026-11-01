<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        // Auth endpoints are throttled per route, not per "any auth action".
        // The default `throttle:5,1` signs its key on (domain|ip) only, so
        // register, login and logout would share one 5/min bucket: a user
        // who registers and then logs in twice could be locked out, and any
        // two users behind one NAT certainly would be. Named limiters give
        // each endpoint its own budget while keeping the same strictness.
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinute(5)->by('auth-register|'.$request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by('auth-login|'.$request->ip());
        });
    }
}
