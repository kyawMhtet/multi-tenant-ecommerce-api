<?php

namespace App\Services\Delivery;

use App\Models\Tenant;

/**
 * What to charge for delivery on one order.
 *
 * Its own class because this is the seam where delivery pricing will grow —
 * zone rates, free-over-a-threshold and per-courier pricing are all ordinary
 * asks here, and each is a change to this method alone.
 *
 * The fee is NEVER taken from request input: a money amount a client can send
 * is one a client can set to zero.
 */
class DeliveryFeeCalculator
{
    /**
     * Pickup is free structurally, not by configuration, so a shop offering
     * both can't accidentally bill someone collecting in person.
     *
     * Growing the rules extends this signature (a subtotal for a threshold, an
     * address for zones) — not accepted yet, since an unused parameter reads
     * as a rule that exists and doesn't work.
     */
    public function for(Tenant $tenant, ?string $fulfillmentType): float
    {
        if ($fulfillmentType !== 'delivery') {
            return 0.0;
        }

        return round((float) $tenant->delivery_fee, 2);
    }
}
