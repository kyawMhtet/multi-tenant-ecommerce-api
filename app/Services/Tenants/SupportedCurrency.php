<?php

namespace App\Services\Tenants;

/**
 * A fixed set, not any ISO code: each entry needs its minor-unit handling
 * checked (StripeGateway::isZeroDecimal — a wrong entry is a silent 100x charge
 * error) and a gateway that can settle it. Arbitrary codes would let a shop
 * pick something no payment path supports and find out at checkout.
 *
 * Widening this is a deliberate act, not a config tweak.
 */
class SupportedCurrency
{
    public const CURRENCIES = [
        'MMK' => 'Myanmar Kyat',
        'THB' => 'Thai Baht',
        'USD' => 'US Dollar',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CURRENCIES);
    }

    public static function labelFor(string $code): string
    {
        return self::CURRENCIES[$code] ?? $code;
    }
}
