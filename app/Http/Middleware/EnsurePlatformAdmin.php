<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform-side door. Runs after auth:sanctum and asserts that the token
 * actually belongs to a PlatformAdmin.
 *
 * This check is not belt-and-braces, it is the mechanism. Sanctum's personal
 * access tokens are polymorphic and its guard authenticates whatever model a
 * token points at, without consulting the guard's configured provider — so
 * auth:sanctum alone would happily let a SHOP OWNER's token through to these
 * routes, where every query deliberately runs across all tenants.
 *
 * is_active is checked here rather than only at login, so revoking a staff
 * account takes effect on their next request instead of whenever their token
 * happens to expire.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        abort_unless($admin instanceof PlatformAdmin, 403, 'Platform access required.');
        abort_unless($admin->is_active, 403, 'This platform account is no longer active.');

        return $next($request);
    }
}
