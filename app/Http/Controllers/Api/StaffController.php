<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\PlanGate;
use App\Services\Tenants\ShopRole;
use App\Services\Tenants\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly PlanGate $gate,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = app('tenant');
        $members = $this->staff->list($tenant);

        return StaffResource::collection($members)
            ->additional([
                'meta' => [
                    'used' => $members->count(),
                    'limit' => PlanCatalog::limitFor($this->gate->plan(), 'staff'),
                    'roles' => array_map(
                        fn (ShopRole $role) => ['value' => $role->value, 'label' => $role->label()],
                        ShopRole::cases(),
                    ),
                ],
            ])
            ->response();
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $user = $this->staff->create(app('tenant'), $request->validated());

        return (new StaffResource($user))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateStaffRequest $request, int $staff): JsonResponse
    {
        $user = $this->staff->update(app('tenant'), $staff, $request->validated(), $request->user());

        return (new StaffResource($user))->response();
    }

    public function destroy(Request $request, int $staff): Response
    {
        $this->staff->delete(app('tenant'), $staff, $request->user());

        return response()->noContent();
    }
}
