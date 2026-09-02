<?php

namespace App\Services\Billing\Data;

/**
 * WHY a rail can or cannot be used, not merely whether.
 *
 * A bare boolean made two very different situations look identical to the
 * shop: "we haven't set this up yet, ask us" and "this can never work in your
 * currency". A Myanmar shop was being told to get in touch about a card
 * option that will never exist, which wastes their time and ours.
 */
enum RailAvailability: string
{
    case Available = 'available';

    /**
     * The provider cannot handle this currency at all. PERMANENT — Stripe has
     * no MMK support, so no configuration will ever produce a price id for it.
     * Say so plainly rather than implying it's coming.
     */
    case CurrencyUnsupported = 'currency_unsupported';

    /**
     * The rail could work here, but this deployment hasn't finished setting it
     * up — a missing Stripe price id, or no receiving bank account for the
     * currency. Temporary, and on us to fix.
     */
    case NotConfigured = 'not_configured';

    /** Switched off deliberately (billing.manual_enabled). */
    case Disabled = 'disabled';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
