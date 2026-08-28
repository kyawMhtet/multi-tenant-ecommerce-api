<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPaymentMethodResource;
use App\Models\TenantPaymentMethod;

class PublicPaymentMethodController extends Controller
{
    /**
     * What this shop accepts, in the order the shop chose.
     *
     * No tenant lookup and no parameter: 'tenant' is already resolved from
     * X-Tenant-Slug by the 'tenant' middleware, and TenantPaymentMethod's
     * own BelongsToTenant scope does the filtering — same shape as
     * CategoryController::index().
     *
     * Unpaginated on purpose: a shop's payment methods are a handful of
     * admin-curated rows rendered as buttons, not a browsable list.
     */
    public function index()
    {
        return PublicPaymentMethodResource::collection(
            TenantPaymentMethod::enabled()->get()
        );
    }
}
