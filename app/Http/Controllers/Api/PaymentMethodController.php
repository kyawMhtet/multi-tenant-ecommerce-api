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
     * Every method this app supports, merged with whatever the shop has
     * configured — not just the configured rows.
     *
     * The settings screen needs to render "Cash on delivery [off]"
     * alongside "QR transfer [on]", and a client that only received
     * configured rows would have to carry its own copy of the catalogue to
     * know what else could be switched on. Sending the full list keeps
     * that knowledge in one place.
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

    /**
     * Multipart (a QR upload) can't be sent via a real PUT/PATCH, so
     * clients POST — which suits an upsert anyway.
     */
    public function upsert(UpsertPaymentMethodRequest $request): JsonResponse
    {
        $method = $this->paymentMethods->upsert($request->validated());

        return (new PaymentMethodResource($method))->response();
    }
}
