<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Authenticated: the tenant is derived from the token owner
        // directly, never from the header. X-Tenant-Slug is not read at
        // all on this path — not "trust it, then verify it matches,"
        // since that still means a bug in the verification step (or a
        // future refactor that forgets it) would bind an attacker-chosen
        // tenant. Deriving it makes the header simply irrelevant for
        // every authenticated request, not merely rejected when wrong.
        if ($request->user()) {
            $tenant = $request->user()->tenant;

            abort_if($tenant === null || ! $tenant->is_active, 404, 'Tenant not found.');

            app()->instance('tenant', $tenant);

            return $next($request);
        }

        // Unauthenticated (public storefront routes): there is no
        // authoritative identity to derive a tenant from, so the header
        // is the only source — this is the one case where it's read.
        $slug = $request->header('X-Tenant-Slug');

        $tenant = $slug
            ? Tenant::where('slug', $slug)->where('is_active', true)->first()
            : null;

        abort_if($tenant === null, 404, 'Tenant not found.');

        app()->instance('tenant', $tenant);

        return $next($request);
    }
}
