<?php

namespace App\Http\Middleware;

use App\Exceptions\InsufficientRoleException;
use App\Services\Tenants\ShopRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * `role:manager` means manager OR ABOVE — the argument is a floor.
 */
class EnsureShopRole
{
    public function handle(Request $request, Closure $next, string $minimum): Response
    {
        $required = ShopRole::tryFrom($minimum)
            ?? throw new ValueError("Unknown shop role [{$minimum}] in route middleware.");

        $role = $request->user()?->role;

        if (! ShopRole::atLeast($role, $required)) {
            throw new InsufficientRoleException($required, $role);
        }

        return $next($request);
    }
}
