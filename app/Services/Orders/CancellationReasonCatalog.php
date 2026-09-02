<?php

namespace App\Services\Orders;

/**
 * A fixed set rather than free text: a reason is only useful once it can be
 * COUNTED, and free text can never be grouped because everyone words it
 * differently. Specifics go in cancellation_note alongside.
 *
 * Split by fault, because that drives different follow-up — a shop-side
 * cancellation is a supply problem to fix, a customer-side one isn't.
 */
class CancellationReasonCatalog
{
    public const REASONS = [
        // Shop-side
        'out_of_stock' => 'Out of stock',
        'cannot_fulfil' => 'Cannot fulfil this order',
        // Distinct from out_of_stock: one means the shop ran out, the other
        // means it knowingly sold ahead and the supplier slipped. Counting
        // them together hides which supply problem is costing orders.
        'supplier_delay' => 'Supplier delayed or cancelled',
        'outside_delivery_area' => 'Outside delivery area',
        'shop_closed' => 'Shop closed',

        // Customer-side
        'customer_cancelled' => 'Customer changed their mind',
        'customer_unreachable' => 'Could not reach customer',

        // Payment
        'payment_not_received' => 'Payment never arrived',
        // System-set: the gateway said the payment window closed.
        'payment_expired' => 'Payment window expired',
        // System-set: the gateway couldn't be reached to start payment.
        'payment_initiation_failed' => 'Could not start payment',
        'duplicate_order' => 'Duplicate order',

        'other' => 'Other',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::REASONS);
    }

    public static function labelFor(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::REASONS[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }
}
