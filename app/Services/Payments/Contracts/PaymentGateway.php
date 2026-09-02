<?php

namespace App\Services\Payments\Contracts;

use App\Models\Order;
use App\Models\TenantPaymentMethod;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentInitiation;
use Illuminate\Http\Request;

/**
 * Everything the app needs from a payment provider. Adding one means adding
 * one class — no migration, no changes to OrderService, no new branches.
 */
interface PaymentGateway
{
    /**
     * Receives the tenant's own config because with Connect the charge must
     * name the shop's connected account — money goes to the shop, never here.
     */
    public function initiate(Order $order, TenantPaymentMethod $method): PaymentInitiation;

    /**
     * Verification is folded in rather than exposed separately, so there is no
     * way to parse a webhook without checking its signature — an unsigned
     * webhook is an anonymous request claiming an order was paid, and two
     * steps would make "forgot to verify" reachable.
     *
     * Returns null for events this gateway ignores.
     *
     * @throws \App\Services\Payments\Exceptions\InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): ?PaymentEvent;
}
