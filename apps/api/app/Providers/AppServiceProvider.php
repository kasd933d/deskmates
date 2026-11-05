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
        // PRD 6 rate limits. Auth endpoints get per-route buckets: Laravel's
        // default `throttle:5,1` signs its key on (domain|ip) only, so
        // register, login and logout would share one 5/min bucket — a user
        // who registers and then logs in twice could be locked out, and any
        // two users behind one NAT certainly would be.
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinute(5)->by('auth-register|'.$request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by('auth-login|'.$request->ip());
        });

        // PRD 6: 60 requests per minute per user for the authenticated API
        // (heartbeat and auth routes have their own limiters).
        RateLimiter::for('api-user', function (Request $request) {
            return Limit::perMinute(60)->by('api-user|'.($request->user()?->id ?? $request->ip()));
        });

        // PRD 6: "heartbeats exempt (1 per 15 s minimum enforced)" — four
        // per minute comfortably covers the 20 s client cadence (fixed
        // window: sustained traffic is 3/min) while still bounding abuse.
        RateLimiter::for('heartbeat', function (Request $request) {
            return Limit::perMinute(4)->by('heartbeat|'.($request->user()?->id ?? $request->ip()));
        });

        // PRD 4.6: chat — 1 message per 2 seconds and 20 per minute, per
        // user. The two limits MUST sign distinct keys: the throttle
        // middleware hashes (limiter name + by()) into one counter, so a
        // shared by() would make the burst window and the minute window
        // fight over the same bucket.
        RateLimiter::for('chat-send', function (Request $request) {
            $by = $request->user()?->id ?? $request->ip();

            return [
                Limit::perSecond(1, 2)->by('chat-burst|'.$by),
                Limit::perMinute(20)->by('chat-minute|'.$by),
            ];
        });
    }
}
