<?php

namespace App\Services\Billing\Contracts;

use App\Models\Subscription;
use App\Services\Billing\Data\BillingEvent;
use App\Services\Billing\Data\BillingInitiation;
use Illuminate\Http\Request;

/**
 * Everything the app needs from a way of collecting subscription money.
 *
 * Deliberately NOT App\Services\Payments\Contracts\PaymentGateway. That
 * contract is shaped around an Order and a TenantPaymentMethod and describes
 * money moving customer -> shop on a connected account. This one describes
 * money moving shop -> platform on the platform's own account. Sharing an
 * interface would mean one of the two always passing arguments that mean
 * nothing to it.
 *
 * "Rail" rather than "gateway" on purpose: the manual one has no gateway at
 * all, and naming it a gateway would make the primary path in this market
 * read as the exception.
 */
interface BillingRail
{
    /** 'stripe' or 'manual' — stored on subscriptions.gateway. */
    public function name(): string;

    /**
     * Whether this deployment can offer this rail for this plan IN THIS
     * CURRENCY. Currency is a parameter because availability genuinely varies
     * by it: Stripe has no MMK support at all, so card is structurally
     * unavailable to a Myanmar shop no matter what is configured, while the
     * transfer rail is available exactly where a receiving account exists.
     *
     * A rail offered but unconfigured is a dead end the shop only discovers
     * after choosing it.
     */
    public function isAvailable(string $plan, string $currency): bool;

    /**
     * Begin collection. The currency is derived from the subscription's own
     * tenant rather than passed, so there is no argument a caller could get
     * wrong and bill a shop in the wrong country's money.
     *
     * MUST NOT change the subscription's plan or status — asking for money is
     * not receiving it.
     */
    public function initiate(Subscription $subscription, string $plan): BillingInitiation;

    /**
     * Stop future charges, keeping access until the period already paid for
     * runs out.
     */
    public function cancel(Subscription $subscription): void;

    /**
     * Translate a provider callback into a BillingEvent, or null for events
     * this rail ignores.
     *
     * Verification is folded in rather than exposed as a separate step, for
     * the same reason PaymentGateway does it: an unverified webhook is an
     * anonymous request claiming a shop paid us, and two steps would make
     * "forgot to check the signature" reachable.
     *
     * @throws \App\Services\Payments\Exceptions\InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): ?BillingEvent;
}
