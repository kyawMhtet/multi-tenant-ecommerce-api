<?php

namespace App\Services\Payments;

/**
 * The payment methods this application knows how to render and process.
 *
 * Deliberately a code constant rather than a database table. Each entry
 * implies real behaviour — a customer-facing label, which gateway (if any)
 * processes it, whether a QR and proof-of-payment apply — and none of that
 * can be satisfied by a row someone inserts. Adding a method means teaching
 * the app something, so it belongs next to the code that does the teaching.
 *
 * `gateway => null` means no processor: the money moves directly between
 * customer and shop, and a human confirms it. That is the common case for
 * this product's users, not an edge case.
 */
class PaymentMethodCatalog
{
    public const METHODS = [
        'cod' => [
            'label' => 'Cash on delivery',
            'gateway' => null,
            'supports_qr' => false,
            'supports_proof' => false,
        ],
        'qr_transfer' => [
            'label' => 'Bank / wallet transfer (QR)',
            'gateway' => null,
            'supports_qr' => true,
            'supports_proof' => true,
        ],
        'card' => [
            'label' => 'Credit or debit card',
            'gateway' => 'stripe',
            'supports_qr' => false,
            'supports_proof' => false,
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
}
