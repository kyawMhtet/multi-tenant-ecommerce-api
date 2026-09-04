<?php

namespace App\Services\Billing\Rails;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Contracts\BillingRail;
use App\Services\Billing\BillingCurrency;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\Data\BillingEvent;
use App\Services\Billing\Data\BillingInitiation;
use App\Services\Billing\Data\RailAvailability;
use Illuminate\Http\Request;

/**
 * Bank transfer. The PRIMARY rail in this market, not a fallback: a shop
 * owner in Yangon with a Kyat account has no way to pay Stripe at all, and a
 * card-only design would exclude a large share of the customers this product
 * exists for.
 *
 * There is no provider here, so there is no webhook and nothing is ever
 * confirmed automatically. What this rail produces is an unpaid invoice and
 * some bank details; a human on this side settles it.
 */
class ManualBillingRail implements BillingRail
{
    public function name(): string
    {
        return 'manual';
    }

    /**
     * Needs BOTH a receiving account in that currency and a price in it. An
     * account with no price is a transfer of an unknown amount a human then
     * has to guess at; a price with no account is a bill nobody can pay.
     */
    public function availability(string $plan, string $currency): RailAvailability
    {
        if (! config('billing.manual_enabled')) {
            return RailAvailability::Disabled;
        }

        // Never CurrencyUnsupported: a bank transfer works in any currency we
        // hold an account for, which is precisely why this rail is the one
        // Myanmar shops will ever have. A missing account is on us to fix.
        return BillingCurrency::canReceiveTransfer($currency, $plan)
            ? RailAvailability::Available
            : RailAvailability::NotConfigured;
    }

    public function initiate(Subscription $subscription, string $plan): BillingInitiation
    {
        $currency = BillingCurrency::for($subscription);
        $invoice = $this->pendingInvoice($subscription, $plan, $currency);
        $account = BillingCurrency::receivingAccount($currency);

        return BillingInitiation::transfer($invoice, [
            'bank_name' => $account['bank_name'] ?? null,
            'account_name' => $account['account_name'] ?? null,
            'account_number' => $account['account_number'] ?? null,
            'notes' => $account['instructions'] ?? null,
            'amount' => $invoice->amount,
            'currency' => $currency,
            // The shop puts this in the transfer note. Without something to
            // quote, matching an incoming payment to a shop is guesswork —
            // the same reason cancellation reasons aren't free text.
            'reference' => 'SUB-'.$invoice->id,
        ]);
    }

    /**
     * Reuses an existing unpaid invoice for the same plan rather than raising
     * a second one. A shop owner clicking "pay by transfer" twice, or coming
     * back tomorrow to re-read the bank details, must not end up owing two
     * months — and a human reviewing the queue should not have to work out
     * which of three identical invoices the screenshot belongs to.
     */
    private function pendingInvoice(Subscription $subscription, string $plan, string $currency): SubscriptionInvoice
    {
        // Abandoned intents are voided rather than reused. One raised months
        // ago still quotes a period that has since gone by, so handing it back
        // would bill the shop for a month already over — and it would keep a
        // dead row in the platform's chase list forever. Cleaned up lazily
        // here, when the shop returns, rather than by a scheduler: the dates
        // already say when an intent went stale.
        $subscription->invoices()
            ->staleIntent()
            ->where('plan', $plan)
            ->update([
                'status' => 'void',
                'note' => 'Expired — no transfer was received against this reference.',
            ]);

        $existing = $subscription->invoices()
            ->unpaid()
            ->where('gateway', 'manual')
            ->where('plan', $plan)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // An UPGRADE starts now, because that is when the shop actually gets
        // the higher plan — an invoice claiming a period that begins after
        // access did would make the ledger unreconcilable. The unused days on
        // the cheaper plan are forfeited, which is the trade for getting the
        // upgrade the moment it's asked for; proration would need credit
        // notes this app has no model for.
        //
        // A renewal or a downgrade counts from where paid access currently
        // ENDS, so paying early extends instead of discarding the remainder.
        // Same principle as preorderReadyBy() counting from created_at.
        $upgrading = PlanCatalog::isUpgrade($subscription->effectivePlan(), $plan);

        $start = ! $upgrading && $subscription->current_period_ends_at?->isFuture()
            ? $subscription->current_period_ends_at->copy()
            : now();

        return $subscription->invoices()->create([
            'plan' => $plan,
            'amount' => BillingCurrency::amountFor($currency, $plan),
            // Snapshotted, so a later repricing never rewrites what this shop
            // was asked for.
            'currency' => $currency,
            'gateway' => 'manual',
            // No provider, so no provider-side id. That null is precisely why
            // a human has to vouch for this one.
            'external_ref' => null,
            'period_start' => $start,
            'period_end' => $start->copy()->addMonth(),
            'status' => 'pending',
        ]);
    }

    /**
     * Nothing to call. A manual subscription stops by simply not being paid
     * again, so cancelling is entirely a local fact — recorded by
     * SubscriptionService, which owns every state transition.
     */
    public function cancel(Subscription $subscription): void {}

    /**
     * Always null. There is no provider, so nothing will ever call back about
     * a bank transfer — a human confirms it through SubscriptionReviewService
     * instead, which is this rail's equivalent of a webhook.
     *
     * Implementing the contract by doing nothing, rather than the contract
     * omitting the method, keeps every caller polymorphic. Same shape as
     * Payments\Gateways\ManualGateway.
     */
    public function parseWebhook(Request $request): ?BillingEvent
    {
        return null;
    }
}
