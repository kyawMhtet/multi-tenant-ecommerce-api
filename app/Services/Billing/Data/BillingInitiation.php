<?php

namespace App\Services\Billing\Data;

use App\Models\SubscriptionInvoice;

/**
 * What the admin app must do next. An object rather than a bare URL for the
 * same reason PaymentInitiation is one: it crosses into a separately deployed
 * frontend, and a string cannot say what kind of thing it is.
 *
 * Neither factory changes the shop's plan. That is the whole point — a
 * redirect can be faked or abandoned, and a bank transfer has not happened
 * yet. The plan moves when money is confirmed, never when it is requested.
 */
final class BillingInitiation
{
    public function __construct(
        public readonly BillingInitiationType $type,
        public readonly ?string $url = null,
        public readonly ?SubscriptionInvoice $invoice = null,
        public readonly array $instructions = [],
    ) {}

    public static function redirect(string $url): self
    {
        return new self(BillingInitiationType::Redirect, url: $url);
    }

    /**
     * Carries the invoice because the shop needs something to reference in
     * the transfer, and because uploading proof has to target a specific
     * period rather than "whatever is outstanding".
     */
    public static function transfer(SubscriptionInvoice $invoice, array $instructions): self
    {
        return new self(BillingInitiationType::Transfer, invoice: $invoice, instructions: $instructions);
    }
}
