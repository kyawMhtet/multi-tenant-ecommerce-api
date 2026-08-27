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
        $middleware->priority([
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\ResolveTenant::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
