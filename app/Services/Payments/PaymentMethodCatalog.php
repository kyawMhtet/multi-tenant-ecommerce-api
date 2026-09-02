<?php

namespace App\Services\Payments;

/**
 * A code constant rather than a table: each entry implies real behaviour the
 * app has to implement, which a row someone inserts can't supply.
 *
 * `gateway => null` means no processor — the money moves directly between
 * customer and shop. That's the common case here, not an edge case.
 */
class PaymentMethodCatalog
{
    public const METHODS = [
        'cod' => [
            'label' => 'Cash on delivery',
            'gateway' => null,
            'supports_qr' => false,
            'supports_proof' => false,
            'collects_upfront' => false,
        ],
        'qr_transfer' => [
            'label' => 'Bank / wallet transfer (QR)',
            'gateway' => null,
            'supports_qr' => true,
            'supports_proof' => true,
            'collects_upfront' => true,
        ],
        'card' => [
            'label' => 'Credit or debit card',
            'gateway' => 'stripe',
            'supports_qr' => false,
            'supports_proof' => false,
            'collects_upfront' => true,
        ],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::METHODS);
    }

    public static function gatewayFor(string $method): ?string
    {
        return self::METHODS[$method]['gateway'] ?? null;
    }

    public static function labelFor(string $method): string
    {
        return self::METHODS[$method]['label']
            ?? ucfirst(str_replace('_', ' ', $method));
    }

    public static function supportsQr(string $method): bool
    {
        return (bool) (self::METHODS[$method]['supports_qr'] ?? false);
    }

    public static function supportsProof(string $method): bool
    {
        return (bool) (self::METHODS[$method]['supports_proof'] ?? false);
    }

    /**
     * Whether the money reaches the shop before it parts with the goods.
     *
     * A different question from `gateway`, and the two don't line up:
     * qr_transfer has no gateway yet is paid up front, while a "pay at pickup"
     * would be deferred whatever processed it. TIMING, not who handles it.
     *
     * Unknown methods are treated as deferred and refused. This gates goods the
     * shop doesn't have yet, so failing closed is right: a forgotten flag shows
     * up immediately as a 422, the opposite mistake as an unpaid import.
     */
    public static function collectsUpfront(string $method): bool
    {
        return (bool) (self::METHODS[$method]['collects_upfront'] ?? false);
    }
}
