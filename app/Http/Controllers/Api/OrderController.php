<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
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
        return new OrderResource($order->load('items', 'customer', 'cashier', 'payments'));
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        $order = $this->orderService->updateOrderStatus($order, $request->validated());

        return new OrderResource($order);
    }
}
