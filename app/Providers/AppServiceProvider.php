<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One client on the PLATFORM's key; shops are named per-request via
        // 'stripe_account' (see StripeGateway), which is why no per-tenant
        // secret exists anywhere. Built keyless when unconfigured so the app
        // still boots — an optional gateway should fail at use, not at boot.
        $this->app->singleton(StripeClient::class, function () {
            $secret = (string) config('payments.stripe.secret');

            return new StripeClient($secret !== '' ? $secret : []);
        });
    }

    public function boot(): void
    {
        $this->forgetTenantBetweenJobs();

        // Keyed by user id when authenticated, IP otherwise — this app mixes
        // authenticated and public routes under the same group.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Keyed by email+IP, not IP alone, so one person's failed attempts
        // can't lock out everyone else on a shared office network.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email').$request->ip());
        });

        // The one unauthenticated WRITE path — no user to key by. Bounded
        // against scripted fake orders, which deduct real stock.
        RateLimiter::for('public-orders', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Looser on purpose: hit on every storefront page load, and mobile
        // carriers here CGNAT huge numbers of users behind few egress IPs, so
        // 60/min would 429 real customers rather than abusers.
        RateLimiter::for('public-shop', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // A crude abuse brake only — the real gate is the signature check in
        // each gateway. Providers legitimately burst when redelivering a
        // backlog, and 429ing those just makes them retry later.
        RateLimiter::for('payment-webhooks', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        // Tighter than the shop login: there are a handful of these accounts
        // ever, and each one can read and settle money across every tenant on
        // the platform, so a slow brute force is worth making slower still.
        RateLimiter::for('platform-login', function (Request $request) {
            return Limit::perMinute(3)->by($request->input('email').$request->ip());
        });

        // Stricter than public-orders: this creates a whole tenant per
        // request. IP alone is enough — there's no per-account brute-force
        // concern here the way there is on login.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }

    /**
     * Clears the 'tenant' container binding around every queued job.
     *
     * A BLOCKING PREREQUISITE of running a worker at all, and the reason this
     * exists rather than being added when something breaks. ResolveTenant and
     * StorefrontProductService both bind the tenant with app()->instance() and
     * never forget it. Under PHP-FPM that is harmless — the container dies
     * with the request — but a worker process is long-lived and does NOT get a
     * fresh container between jobs. Without this, a job that binds a tenant
     * leaves it bound for whatever runs next on that worker, and the next job
     * silently reads and writes another shop's data through TenantScope.
     *
     * Cleared BEFORE as well as after: `after` alone would still leave the
     * first job of a worker's life exposed to whatever the boot sequence
     * happened to bind, and a job that dies in a way neither `after` nor
     * `failing` catches would poison every job behind it.
     *
     * Jobs that legitimately need a tenant must bind it themselves and treat
     * it as request-scoped, exactly as the HTTP middleware does. Nothing may
     * assume a binding it did not make.
     */
    private function forgetTenantBetweenJobs(): void
    {
        $forget = fn () => app()->forgetInstance('tenant');

        Queue::before($forget);
        Queue::after($forget);
        Queue::failing($forget);
    }
}
