<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicShopResource;

class PublicShopController extends Controller
{
    /**
     * Same "no parameter, no lookup" shape as TenantController::show() —
     * 'tenant' is already resolved, here from the X-Tenant-Slug header by
     * the 'tenant' middleware (ResolveTenant 404s on an unknown or inactive
     * slug), since this route has no auth in front of it.
     */
    public function show()
    {
        return new PublicShopResource(app('tenant'));
    }
}
