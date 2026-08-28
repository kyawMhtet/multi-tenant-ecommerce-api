<?php

namespace App\Services\Payments\Data;

use App\Models\Order;

/**
 * A completed checkout: the order that now exists, plus what the customer
 * has to do next to pay for it.
 *
 * The two travel together because the storefront needs both in one
 * response — the order to show, and the initiation to act on.
 */
final class CheckoutResult
{
    public function __construct(
        public readonly Order $order,
        public readonly PaymentInitiation $payment,
    ) {}
}
