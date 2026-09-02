<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\DispatchOrderRequest;
use App\Http\Requests\IndexOrderRequest;
use App\Http\Requests\RefundOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\Orders\CancellationReasonCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function index(IndexOrderRequest $request)
    {
        return OrderResource::collection(
            $this->orderService->listOrders($request->validated())
        );
    }

    public function store(StoreOrderRequest $request)
    {
        $order = $this->orderService->createPosOrder($request->validated());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load('items', 'customer', 'cashier', 'payments', 'cancelledBy', 'refundedBy', 'dispatchedBy'));
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        $order = $this->orderService->updateOrderStatus($order, $request->validated());

        return new OrderResource($order);
    }

    /**
     * Served rather than duplicated per client: this list grows, and a
     * frontend copy would quietly fall behind — offering fewer reasons than
     * the API accepts, or one it rejects.
     */
    public function cancellationReasons(): JsonResponse
    {
        return response()->json([
            'data' => collect(CancellationReasonCatalog::REASONS)
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
                ->values(),
        ]);
    }

    /**
     * Its own endpoint, not a status edit: it requires a reason, records who
     * and when, and returns stock — none of which a generic update enforces.
     */
    public function cancel(CancelOrderRequest $request, Order $order)
    {
        $order = $this->orderService->cancelOrder($order, $request->validated(), $request->user()?->id);

        return new OrderResource($order);
    }

    /**
     * Its own endpoint for the same reason cancel and refund are. Calling it
     * again re-dispatches, which is what a shop does when a parcel is lost.
     */
    public function dispatch(DispatchOrderRequest $request, Order $order)
    {
        $order = $this->orderService->dispatchOrder($order, $request->validated(), $request->user()?->id);

        return new OrderResource($order);
    }

    /**
     * Records that the shop returned the money — it moves none. An attestation
     * with an audit trail, not a financial operation.
     */
    public function refund(RefundOrderRequest $request, Order $order)
    {
        $order = $this->orderService->refundOrder($order, $request->validated(), $request->user()?->id);

        return new OrderResource($order);
    }
}
