<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexPlatformShopRequest;
use App\Http\Requests\Platform\SuspendShopRequest;
use App\Http\Resources\PlatformShopResource;
use App\Services\Platform\PlatformShopService;
use Illuminate\Http\JsonResponse;

/**
 * The shop directory. {shop} is an id rather than a route-model binding, like
 * everything else on the platform surface: implicit binding resolves through a
 * global scope that merely happens to no-op for an admin, and this codebase
 * consistently refuses to rely on that accident.
 */
class PlatformShopController extends Controller
{
    public function __construct(private readonly PlatformShopService $shops) {}

    public function index(IndexPlatformShopRequest $request): JsonResponse
    {
        return PlatformShopResource::collection(
            $this->shops->directory($request->filters(), $request->integer('per_page', 25))
        )->response();
    }

    public function show(int $shop): JsonResponse
    {
        return (new PlatformShopResource($this->shops->detail($shop)))->response();
    }

    /**
     * Locks the OWNER out of their admin. The shop's storefront keeps serving
     * customers — that separation is the entire reason this exists apart from
     * the is_active kill switch, which is not exposed here.
     */
    public function suspend(SuspendShopRequest $request, int $shop): JsonResponse
    {
        return (new PlatformShopResource(
            $this->shops->suspend($shop, $request->validated('reason'))
        ))->response();
    }

    public function restore(int $shop): JsonResponse
    {
        return (new PlatformShopResource($this->shops->restore($shop)))->response();
    }
}
