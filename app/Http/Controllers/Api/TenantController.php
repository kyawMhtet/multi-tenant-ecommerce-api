<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Services\TenantService;

class TenantController extends Controller
{
    public function __construct(private readonly TenantService $tenantService) {}

    public function show()
    {
        // No parameter, no lookup: 'tenant' is already the one resolved
        // for this request by the 'tenant' middleware — this endpoint
        // just hands it back, the same way ProductController::index()
        // doesn't route a plain read through a Service.
        return new TenantResource(app('tenant'));
    }

    /**
     * Takes no tenant identifier — not in the URL, not in the body. The
     * tenant it writes to is always app('tenant'), which ResolveTenant
     * derives from the authenticated token's owner and never from the
     * X-Tenant-Slug header on an authenticated request. That's the whole
     * tenant-isolation guarantee for this endpoint: there is simply no
     * input a caller could supply to target someone else's shop.
     */
    public function update(UpdateTenantRequest $request)
    {
        $tenant = $this->tenantService->update(app('tenant'), $request->validated());

        return new TenantResource($tenant);
    }
}
