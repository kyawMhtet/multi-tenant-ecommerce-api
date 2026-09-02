<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformAdminRequest;
use App\Http\Resources\PlatformAdminResource;
use App\Services\Platform\PlatformAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Platform staff accounts.
 *
 * `php artisan platform:create-admin` is deliberately kept alongside this: it
 * is how the first account exists, and the way back if every account here is
 * deactivated.
 */
class PlatformAdminController extends Controller
{
    public function __construct(private readonly PlatformAdminService $admins) {}

    public function index(): JsonResponse
    {
        return PlatformAdminResource::collection($this->admins->list())->response();
    }

    public function store(StorePlatformAdminRequest $request): JsonResponse
    {
        return (new PlatformAdminResource($this->admins->create($request->validated())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Takes the acting admin so the service can refuse self-deactivation —
     * one click that could otherwise lock every human out of the payment
     * queue.
     */
    public function deactivate(Request $request, int $admin): JsonResponse
    {
        return (new PlatformAdminResource(
            $this->admins->deactivate($admin, $request->user())
        ))->response();
    }

    public function reactivate(int $admin): JsonResponse
    {
        return (new PlatformAdminResource($this->admins->reactivate($admin)))->response();
    }
}
