<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentInitiationResource;
use App\Services\Payments\CheckoutService;
use Illuminate\Http\Response;

class PublicOrderController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService) {}

    public function store(StorePublicOrderRequest $request)
    {
        $result = $this->checkoutService->checkout($request->validated());

        // Same OrderResource as the POS receipt: it already excludes
        // unit_cost, so it's already safe for an unauthenticated response.
        // The payment initiation rides alongside rather than inside it —
        // it describes what the client must do next, not a property of the
        // order, and it's deliberately absent from every other endpoint
        // that returns an order.
        return (new OrderResource($result->order))
            ->additional(['payment' => new PaymentInitiationResource($result->payment)])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
