<?php

namespace App\Services\Payments\Data;

/**
 * What the storefront must do next to collect payment.
 *
 * Modelled around the CLIENT'S required action, not around gateway
 * internals — the storefront switches on $type and always knows how to
 * proceed, whichever provider a given shop uses.
 *
 * This is an object rather than a bare redirect URL on purpose, even
 * though every gateway on the roadmap is redirect-based today. It ends up
 * in the JSON response consumed by the separate Next.js storefront, which
 * makes it a contract across two repos with independent deploys. A bare
 * string can't say what kind of thing it is, so switching to a client
 * token later would either break the frontend silently or force a
 * versioned endpoint. Fifteen lines now removes that whole class of
 * problem — the general rule being to spend a little extra care at
 * boundaries you don't control both sides of.
 *
 * $reference is the provider's own id for this attempt (a Stripe Checkout
 * Session id, say). The caller records it on a pending `payments` row so
 * the eventual webhook can find its way back to this order.
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
     * Nothing to collect online — cash on delivery and bank transfer just
     * leave the order unpaid until a human confirms. A first-class case
     * rather than a null return, so callers stay polymorphic instead of
     * branching on "did this method have a gateway?".
     */
    public static function none(): self
    {
        return new self(PaymentInitiationType::None);
    }
}
