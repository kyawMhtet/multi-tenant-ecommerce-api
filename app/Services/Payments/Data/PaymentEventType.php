<?php

namespace App\Services\Payments\Data;

/**
 * The complete vocabulary this app understands about a payment. Each gateway
 * translates its own dialect into one of these three at the edge, so nothing
 * downstream learns which provider was involved.
 *
 * Keeping the set tiny is the point: a fourth case is a signal that a gateway
 * detail is leaking in — check it can't be expressed as one of these first.
 */
enum PaymentEventType: string
{
    /** Money captured. The order can be marked paid. */
    case Succeeded = 'succeeded';

    /** The customer never completed payment and the window closed. Release reserved stock. */
    case Expired = 'expired';

    /** The attempt was made and declined. The order stays unpaid; the customer may retry. */
    case Failed = 'failed';
}
