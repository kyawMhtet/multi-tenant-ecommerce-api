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
        // General API baseline, applied to the whole 'api' middleware
        // group (see bootstrap/app.php) — Laravel 13's default 'api'
        // group ships without this; it's never been added until now.
        // Keyed by user id when authenticated, IP otherwise, so
        // authenticated and public routes both get a sane default even
        // though this app mixes both under the same group.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Stricter than the general baseline on purpose: unlimited login
        // attempts today means unlimited password guesses. Keyed by
        // email+IP (same pattern Laravel's own Fortify starter kit uses)
        // rather than IP alone, so a legitimate user isn't locked out by
        // someone else's failed attempts against a different account from
        // the same IP (e.g. a shared office network).
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email').$request->ip());
        });

        // The one unauthenticated WRITE path in this app — no user to key
        // by, so IP is the only option. Generous enough for a real
        // shopper placing more than one order, bounded against a script
        // spamming fake orders (which would deduct real stock and now
        // also spam the tenant's notifications).
        RateLimiter::for('public-orders', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Looser than the general api limiter on purpose. This is hit on
        // every storefront page load, and mobile carriers in this market
        // CGNAT very large numbers of users behind very few egress IPs — the
        // 60/min general per-IP limit would 429 real customers browsing a
        // shop, not abusers.
        RateLimiter::for('public-shop', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Stricter than public-orders: this creates a whole new tenant
        // per request, not just an order row — a more expensive action to
        // spam. IP alone is enough here (unlike login, there's no
        // per-account brute-force concern to also guard against, and the
        // owner_email uniqueness check already rejects duplicate signups
        // regardless of rate).
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
