<?php

namespace App\Services\Payments\Data;

/**
 * A webhook translated out of a provider's dialect into this app's.
 *
 * $transactionRef is how the event finds its order, and doubles as the
 * idempotency key via payments.unique(['gateway','transaction_ref']).
 *
 * $amount is carried so the handler can verify the provider charged what the
 * order costs. Never assume it matches — that check is what stops a mismatched
 * session quietly marking an expensive order paid.
 *
 * $raw is the untouched payload, stored on payments.meta for support.
 * Deliberately opaque: reading a provider-specific key out of it to make a
 * decision means the abstraction has leaked.
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
