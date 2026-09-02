<?php

namespace App\Services\Billing\Data;

/**
 * The complete vocabulary this app understands about a SUBSCRIPTION's money.
 * Each rail translates its own dialect into one of these at the edge, so
 * nothing downstream learns which provider was involved.
 *
 * Kept tiny on purpose, the same discipline as PaymentEventType: a fourth case
 * is a signal that a provider detail is leaking in, so check it can't be
 * expressed as one of these first.
 *
 * Deliberately absent: a "plan changed" case. Stripe's
 * customer.subscription.updated matters once a Stripe billing portal exists
 * where a shop can change plan outside this app, and there isn't one. Adding
 * it speculatively would mean writing a state transition nothing can trigger.
 */
enum BillingEventType: string
{
    /** Money captured for a period. Extend access. */
    case Paid = 'paid';

    /**
     * The charge was attempted and declined. Access is NOT cut here — the
     * period simply stops being extended, and the existing grace window then
     * does its job. A card that fails on the 1st is usually paid by the 3rd.
     */
    case PaymentFailed = 'payment_failed';

    /** The provider-side subscription is over. No further charges are coming. */
    case Cancelled = 'cancelled';
}
