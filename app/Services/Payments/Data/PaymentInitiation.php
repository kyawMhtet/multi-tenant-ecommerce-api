<?php

namespace App\Services\Payments\Data;

/**
 * What the storefront must do next to collect payment. Modelled on the
 * CLIENT'S required action, not gateway internals.
 *
 * An object rather than a bare URL because it crosses into a separately
 * deployed frontend repo: a string can't say what kind of thing it is, so
 * moving to a client token later would break that frontend silently.
 *
 * $reference is the provider's id for this attempt; the caller records it on a
 * pending payments row so the webhook can find its way back.
 */
final class PaymentInitiation
{
    public function __construct(
        public readonly PaymentInitiationType $type,
        public readonly ?string $reference = null,
        public readonly ?string $url = null,
        public readonly array $fields = [],
        public readonly ?string $token = null,
    ) {}

    /** Send the customer to the provider's hosted page. */
    public static function redirect(string $url, string $reference): self
    {
        return new self(PaymentInitiationType::Redirect, $reference, $url);
    }

    /**
     * Nothing to collect online. A first-class case rather than a null return,
     * so callers stay polymorphic instead of branching on "has a gateway?".
     */
    public static function none(): self
    {
        return new self(PaymentInitiationType::None);
    }
}
