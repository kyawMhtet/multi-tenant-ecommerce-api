<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Models\TenantPaymentMethod;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentInitiation;
use Illuminate\Http\Request;

/**
 * Methods with no processor: cash on delivery, bank transfer against a
 * screenshot. Likely the highest-volume path here, not an edge case.
 *
 * A real class implementing the same contract, rather than null-checks
 * scattered through checkout. The order waits unpaid for a human to confirm.
 */
class ManualGateway implements PaymentGateway
{
    public function initiate(Order $order, TenantPaymentMethod $method): PaymentInitiation
    {
        return PaymentInitiation::none();
    }

    /** Never called: no provider exists to send a webhook. */
    public function parseWebhook(Request $request): ?PaymentEvent
    {
        return null;
    }
}
