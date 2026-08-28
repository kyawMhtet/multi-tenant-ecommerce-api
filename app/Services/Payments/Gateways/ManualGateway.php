<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Models\TenantPaymentMethod;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentInitiation;
use Illuminate\Http\Request;

/**
 * Methods with no processor behind them: cash on delivery, bank transfer
 * against a screenshot. For Myanmar, cash on delivery is likely to be the
 * highest-volume method, so this isn't an edge case — it's the common path.
 *
 * A real class implementing the same contract, rather than null-checks
 * scattered through the checkout flow. The order is created unpaid and
 * simply waits for a human to confirm the money arrived, which the existing
 * PATCH /orders/{order} endpoint already handles by updating
 * payment_status.
 */
class ManualGateway implements PaymentGateway
{
    public function initiate(Order $order, TenantPaymentMethod $method): PaymentInitiation
    {
        return PaymentInitiation::none();
    }

    /**
     * Nothing ever calls this — no provider exists to send a webhook, and
     * no route is registered for one. Present because the contract requires
     * it, returning null in keeping with "this event isn't interesting".
     */
    public function parseWebhook(Request $request): ?PaymentEvent
    {
        return null;
    }
}
