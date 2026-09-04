<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            // Lapsed-subscription lockout. Applied to catalogue and config
            // WRITES only — never to orders, the storefront or billing. See
            // RequireWriteAccess for why each exclusion is deliberate.
            'subscription' => \App\Http\Middleware\RequireWriteAccess::class,
            // Route-level plan gate: 'plan:profit_reports'.
            'plan' => \App\Http\Middleware\RequirePlanFeature::class,
            // Shop-side role floor: 'role:manager' means manager or above.
            'role' => \App\Http\Middleware\EnsureShopRole::class,
            // Platform staff, not shop staff. Asserts the Sanctum token
            // belongs to a PlatformAdmin — auth:sanctum alone does not,
            // because tokens are polymorphic.
            'platform' => \App\Http\Middleware\EnsurePlatformAdmin::class,
        ]);

        // Laravel 13's default 'api' group ships without throttle:api —
        // it's opt-in now, not a leftover to restore. The 'api' rate
        // limiter itself is defined in AppServiceProvider::boot().
        $middleware->api(prepend: [
            'throttle:api',
        ]);

        // The 'api' middleware group bundles SubstituteBindings itself, so
        // without this it runs before any route-specific middleware —
        // including 'tenant' — no matter how routes/api.php nests things.
        // That means implicit route-model binding (e.g. {product}) would
        // resolve before the tenant scope for this request exists. Harmless
        // today only because every bound route sits behind auth:sanctum,
        // whose guard resolves lazily regardless of middleware order; it
        // would silently skip tenant scoping entirely on any future
        // unauthenticated route (e.g. storefront) that uses implicit
        // binding. Forcing this order closes that gap at the root instead
        // of relying on that coincidence.
        // Both billing gates read app('tenant'), so both MUST run after
        // ResolveTenant has bound it. Listing them here states that
        // dependency explicitly rather than relying on the order they happen
        // to appear in a route group — a non-prioritised middleware keeps
        // only its position relative to other non-prioritised ones, which is
        // a guarantee about the wrong thing.
        $middleware->priority([
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\ResolveTenant::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureShopRole::class,
            \App\Http\Middleware\RequireWriteAccess::class,
            \App\Http\Middleware\RequirePlanFeature::class,
            \App\Http\Middleware\EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
