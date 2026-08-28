<?php

namespace App\Services\Payments\Contracts;

use App\Models\Order;
use App\Models\TenantPaymentMethod;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentInitiation;
use Illuminate\Http\Request;

/**
 * Everything the application needs from a payment provider, and nothing
 * more. Adding a provider means adding one class implementing this — no
 * migration, no changes to OrderService, no new branches in checkout.
 *
 * Two responsibilities only: start a payment, and interpret what the
 * provider tells us afterwards.
 */
interface PaymentGateway
{
    /**
     * Begin collecting payment for an order.
     *
     * Receives the tenant's own configuration for this method, since with
     * Stripe Connect the charge must name the shop's connected account —
     * the money goes to the shop, never to this platform.
     */
    public function initiate(Order $order, TenantPaymentMethod $method): PaymentInitiation;

    /**
     * Verify and translate an incoming webhook.
     *
     * Verification is folded into this one method rather than exposed
     * separately, so there is no way to parse a webhook without having
     * checked its signature first — an unsigned webhook is just an
     * anonymous HTTP request claiming an order was paid, and separating
     * the two steps makes "forgot to verify" a reachable bug.
     *
     * Returns null for events this gateway doesn't care about. Providers
     * send far more event types than any one integration uses, and
     * ignoring the rest is normal, not an error.
     *
     * @throws \App\Services\Payments\Exceptions\InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): ?PaymentEvent;
}
