<?php

namespace App\Services\Stripe;

/**
 * Converting between our decimal money columns and Stripe's minor units.
 *
 * Shared by both directions of money in this app — Connect charges (customer
 * -> shop, StripeGateway) and platform billing (shop -> us, the billing
 * webhook). Deliberately ONE copy: the zero-decimal list below is the kind of
 * thing that gets edited on intuition, and a wrong entry in either direction
 * is a silent 100x error rather than a crash. Two copies would eventually
 * disagree, and only one of them would be wrong.
 */
class StripeMoney
{
    /**
     * Verify against https://docs.stripe.com/currencies#zero-decimal before
     * adding anything. THB and MMK are both correctly absent.
     *
     * Never add a currency here on intuition.
     */
    private const ZERO_DECIMAL = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public static function isZeroDecimal(string $currency): bool
    {
        return in_array(strtolower($currency), self::ZERO_DECIMAL, true);
    }

    /**
     * Zero-decimal currencies take the plain integer; multiplying one by 100
     * charges a hundred times too much.
     *
     * round() before the cast matters: (int) on 19.99*100 can land on 1998
     * through binary representation, silently undercharging.
     */
    public static function toMinor(float $amount, string $currency): int
    {
        return self::isZeroDecimal($currency)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    public static function fromMinor(int $amount, string $currency): float
    {
        return self::isZeroDecimal($currency) ? (float) $amount : $amount / 100;
    }
}
