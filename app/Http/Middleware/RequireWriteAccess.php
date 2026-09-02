<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses catalogue and configuration CHANGES from a shop whose subscription
 * has lapsed past its grace period.
 *
 * What it is deliberately NOT applied to matters more than what it is:
 *
 *   - the public storefront, which keeps serving. Customers hold links to
 *     product pages; going dark punishes them for the shop's unpaid invoice.
 *   - orders — POS and online, including cancel, refund and dispatch. A
 *     lapsed shop can still SELL and still FULFIL. Blocking fulfilment would
 *     strand a parcel a customer has already paid for, which is the platform
 *     hurting a third party to collect from someone else. Blocking sales
 *     would stop the shop earning, and a shop that cannot trade cannot pay.
 *   - billing itself, which must obviously stay reachable — locking the
 *     renew button behind an active subscription is the one bug in this
 *     feature that would be genuinely unrecoverable for the customer.
 *   - all reads, and the shop profile.
 *
 * So the pressure is real but bounded: the shop keeps running the business it
 * has, and cannot grow it — no new products, no restocking, no changing how
 * it takes payment.
 */
class RequireWriteAccess
{
    public function __construct(private readonly PlanGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->gate->ensureWritable();

        return $next($request);
    }
}
