<?php

namespace App\Http\Middleware;

use App\Exceptions\ShopSuspendedException;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Derived from the token owner, never the header. X-Tenant-Slug isn't
        // read at all here — not "trust then verify", since a bug in the verify
        // step would bind an attacker-chosen tenant. Deriving makes the header
        // irrelevant rather than merely rejected when wrong.
        if ($user = $request->user()) {
            // The tenant-side door, and the counterpart to
            // EnsurePlatformAdmin. Sanctum tokens are polymorphic and its
            // guard authenticates whatever model a token points at, ignoring
            // the configured provider — so a PLATFORM ADMIN's token satisfies
            // auth:sanctum on these routes too.
            //
            // Letting one through would be worse than a 500 on the missing
            // ->tenant relation: TenantScope::currentTenantId() would read a
            // null tenant_id, the global scope would add no WHERE clause at
            // all, and the request would run unscoped across every tenant.
            // Rejecting by TYPE closes that off structurally rather than
            // relying on the tenant lookup below to fail.
            abort_unless($user instanceof User, 403, 'This account cannot access shop data.');

            $tenant = $user->tenant;

            abort_if($tenant === null || ! $tenant->is_active, 404, 'Tenant not found.');

            // Checked HERE and nowhere else, and that asymmetry is the whole
            // point of suspension existing separately from is_active: the shop
            // owner is locked out of their admin while the storefront branch
            // below keeps serving customers, who did nothing wrong and are
            // holding links that must not break. Moving this check above the
            // branch, or reusing is_active, would silently turn a support
            // action into taking a shop's business offline.
            if ($tenant->isSuspended()) {
                throw ShopSuspendedException::for($tenant);
            }

            app()->instance('tenant', $tenant);

            return $next($request);
        }

        // Unauthenticated storefront routes: no identity to derive from, so the
        // header is the only source. The one case where it's read.
        //
        // Deliberately does NOT consider suspension — see above.
        $slug = $request->header('X-Tenant-Slug');

        $tenant = $slug
            ? Tenant::where('slug', $slug)->where('is_active', true)->first()
            : null;

        abort_if($tenant === null, 404, 'Tenant not found.');

        app()->instance('tenant', $tenant);

        return $next($request);
    }
}
