<?php

namespace App\Services\Payments\Data;

/**
 * A webhook, translated out of a provider's dialect and into this app's.
 *
 * $transactionRef is how the event finds its way back to an order: when a
 * payment is initiated we record the provider's own id on a pending
 * `payments` row, so the webhook only has to look up that row. That's also
 * why it's the idempotency key — `payments` has
 * unique(['gateway','transaction_ref']), so a provider redelivering the
 * same event can't produce a second payment.
 *
 * $amount is carried so the handler can verify the provider charged what
 * the order actually costs. Never assume it matches: the amount is decided
 * by whoever created the session, and confirming it here is what stops a
 * tampered or mismatched session quietly marking an expensive order paid.
 *
 * $raw is the untouched provider payload, stored on `payments.meta` for
 * support and debugging. Deliberately opaque — nothing in the app should
 * ever read a provider-specific key out of it to make a decision, or the
 * abstraction has leaked.
 */
final class PaymentEvent
{
    public function __construct(
        public readonly PaymentEventType $type,
        public readonly string $transactionRef,
        public readonly ?float $amount = null,
        public readonly array $raw = [],
    ) {}
}
