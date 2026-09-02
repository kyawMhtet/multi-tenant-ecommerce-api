<?php

namespace App\Services\Billing\Data;

use Illuminate\Support\Carbon;

/**
 * A verified, provider-agnostic statement about a subscription's money.
 *
 * Every field except `type` is nullable because the events do not carry the
 * same things: an invoice event knows an amount and a period, a cancellation
 * knows neither. The processor decides what it needs per case rather than the
 * rail inventing blanks to fill a rigid shape.
 *
 * `tenantId` and `plan` come from metadata the rail put on the provider-side
 * subscription when checkout started — see StripeBillingRail's
 * subscription_data.metadata, which exists precisely so these arrive here.
 */
final class BillingEvent
{
    public function __construct(
        public readonly BillingEventType $type,
        public readonly ?string $subscriptionRef = null,
        public readonly ?string $customerRef = null,
        /** The provider's invoice id — the idempotency key for a payment. */
        public readonly ?string $invoiceRef = null,
        public readonly ?int $tenantId = null,
        public readonly ?string $plan = null,
        /** Major units, already converted from the provider's minor units. */
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?Carbon $periodStart = null,
        public readonly ?Carbon $periodEnd = null,
        public readonly array $raw = [],
    ) {}
}
