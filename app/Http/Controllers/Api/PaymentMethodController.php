<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\TenantPaymentMethod;
use App\Services\Payments\PaymentMethodCatalog;
use App\Services\Payments\PaymentMethodService;
use Illuminate\Http\JsonResponse;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentMethodService $paymentMethods) {}

    /**
     * The full catalogue merged with what the shop configured, not just the
     * configured rows: the settings screen needs to render "Cash on delivery
     * [off]" too, and a client receiving only configured rows would need its
     * own copy of what else exists.
     */
    public function index(): JsonResponse
    {
        $configured = TenantPaymentMethod::get()->keyBy('method');

        $methods = collect(PaymentMethodCatalog::codes())
            ->map(fn (string $code) => $configured->get($code) ?? new TenantPaymentMethod([
                'method' => $code,
                'gateway' => PaymentMethodCatalog::gatewayFor($code),
                'is_enabled' => false,
                'sort_order' => 0,
            ]))
            ->sortBy([['is_enabled', 'desc'], ['sort_order', 'asc']])
            ->values();

        return PaymentMethodResource::collection($methods)->response();
    }

    /** Multipart can't be sent via a real PUT/PATCH, so clients POST. */
    public function upsert(UpsertPaymentMethodRequest $request): JsonResponse
    {
        $method = $this->paymentMethods->upsert($request->validated());

        return (new PaymentMethodResource($method))->response();
    }
}
