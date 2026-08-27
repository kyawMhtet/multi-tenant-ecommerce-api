<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\Response;

class PublicOrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function store(StorePublicOrderRequest $request)
    {
        $order = $this->orderService->createOnlineOrder($request->validated());

        // Same OrderResource as the POS receipt: it already excludes
        // unit_cost, so it's already safe for an unauthenticated response.
        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
