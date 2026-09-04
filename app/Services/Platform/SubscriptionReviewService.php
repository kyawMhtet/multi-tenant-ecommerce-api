<?php

namespace App\Services\Platform;

use App\Exceptions\BillingActionUnavailableException;
use App\Models\Concerns\TenantScope;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Services\Billing\BillingCurrency;
use App\Services\Billing\PlanCatalog;
use App\Models\SubscriptionInvoice;
use App\Notifications\SubscriptionPaymentReviewed;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Platform staff ruling on bank transfers.
 *
 * This is the manual rail's equivalent of a payment webhook: the only path by
 * which a transfer becomes a paid plan. It is written with the same care —
 * row locks, idempotency, and a refusal to touch anything a gateway owns.
 *
 * EVERY query here deliberately crosses tenants, which is why every one of
 * them strips TenantScope EXPLICITLY. With a PlatformAdmin authenticated
 * there is no tenant bound and no tenant_id to read, so the scope would
 * no-op on its own — but relying on that would make cross-tenant access an
 * accident of who happens to be logged in rather than a decision. Same
 * reasoning as StorefrontProductService::findPublicVariant().
 *
 * Always withoutGlobalScope(TenantScope::class), never withoutGlobalScopes():
 * the blanket form would also drop SoftDeletingScope elsewhere.
 */
class SubscriptionReviewService
{
    /**
     * Transfers waiting on a human decision — and ONLY those.
     *
     * Proof-carrying invoices only, oldest first. Invoices where the shop
     * asked for bank details and sent nothing have no decision to make and
     * used to sit in this same list, which meant the queue answered two
     * different questions at once: "what must I rule on" and "who hasn't
     * paid". A queue you have to visually filter is not a queue.
     *
     * Those live in awaitingTransfer() instead. They are not hidden — a
     * payment that arrives WITHOUT a screenshot has to stay findable, or
     * there would be no invoice to approve when the money turns up.
     */
    public function pending(int $perPage = 25): LengthAwarePaginator
    {
        return SubscriptionInvoice::withoutGlobalScope(TenantScope::class)
            ->awaitingApproval()
            ->with('tenant')
            ->paginate($perPage);
    }

    /**
     * Shops that asked how to pay and have sent nothing yet.
     *
     * A chase list, not a work queue — nothing here can be approved, because
     * there is nothing to look at. Kept visible because a shop that transfers
     * and forgets to upload is a real and common case on this rail, and the
     * money arriving with no screenshot needs an invoice to land against.
     */
    public function awaitingTransfer(int $perPage = 25): LengthAwarePaginator
    {
        return SubscriptionInvoice::withoutGlobalScope(TenantScope::class)
            ->awaitingTransfer()
            ->with('tenant')
            ->paginate($perPage);
    }

    /**
     * The full invoice ledger across every shop — the month-end
     * reconciliation view, and where "which payment do you mean" gets
     * answered.
     *
     * Deliberately separate from pending(): that one is the review QUEUE, with
     * its own filter and its own ordering (actionable first). This one is
     * history, newest first, and shows paid and void rows too. Same table and
     * the same cross-tenant rules, so it lives here rather than in a second
     * service that would have to repeat the bypass discipline.
     *
     * @param  array<string, mixed>  $filters
     */
    public function invoices(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return SubscriptionInvoice::withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->when(Arr::get($filters, 'status'), fn ($q, $v) => $q->where('status', $v))
            ->when(Arr::get($filters, 'rail'), fn ($q, $v) => $q->where('gateway', $v))
            ->when(Arr::get($filters, 'currency'), fn ($q, $v) => $q->where('currency', strtoupper($v)))
            ->when(Arr::get($filters, 'tenant_id'), fn ($q, $v) => $q->where('tenant_id', $v))
            // Inclusive of the whole `to` day: a reviewer entering a month end
            // means "up to and including", not "up to midnight that morning".
            ->when(Arr::get($filters, 'from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when(Arr::get($filters, 'to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Confirm the money arrived, and move the shop onto the plan it paid for.
     *
     * Idempotent by the same construction as WebhookProcessor: lock the row,
     * THEN re-check the status. Checking before the lock would let two
     * simultaneous approvals both read 'pending' and grant two months.
     * Approving twice must be a no-op, not a second period.
     */
    public function approve(int $invoiceId, PlatformAdmin $admin, ?string $note = null): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoiceId, $admin, $note) {
            $invoice = $this->lockInvoice($invoiceId);

            if ($invoice->status === 'paid') {
                return $invoice;
            }

            $this->refuseNonManual($invoice);

            $subscription = Subscription::withoutGlobalScope(TenantScope::class)
                ->whereKey($invoice->subscription_id)
                ->lockForUpdate()
                ->firstOrFail();

            [$periodStart, $periodEnd] = $this->grantedPeriod($subscription, $invoice);

            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'note' => $note,
                // Corrected to what was actually granted, so the ledger never
                // claims a period the shop did not receive.
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            $subscription->update([
                ...$this->planChange($subscription, $invoice),
                'status' => 'active',
                'gateway' => 'manual',
                'current_period_ends_at' => $periodEnd,
                // An approved payment un-cancels: a shop that cancelled and
                // then paid has plainly changed its mind.
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
            ]);

            $this->notifyShop($invoice, approved: true, subscription: $subscription->fresh());

            return $invoice;
        });
    }

    /**
     * Set (or clear) which currency a shop is billed in — the account it
     * transfers to, and which price list applies.
     *
     * Platform-only, deliberately. Left to the shop this would be an arbitrage
     * lever rather than a preference: the ladders are not at parity across
     * currencies (Pro is 699 THB against 89,000 MMK, roughly 636 THB), and the
     * gap moves with FX. Here it is a support action for the real case — a
     * shop whose bank account is in a different country from its customers.
     *
     * Passing null RESTORES the default of following the shop's selling
     * currency, rather than being a way to unset billing.
     *
     * Pending transfer invoices are voided, not converted: they carry an
     * amount and a set of bank details the shop was told to use, and silently
     * reinterpreting either would put a figure in front of a reviewer that
     * nobody ever asked the shop to pay. Paid invoices are never touched —
     * their currency is snapshotted, and it records money that actually moved.
     */
    public function setBillingCurrency(int $subscriptionId, ?string $currency): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $currency) {
            $currency = $currency === null ? null : strtoupper($currency);

            if ($currency !== null && ! in_array($currency, BillingCurrency::codes(), true)) {
                throw new BillingActionUnavailableException(
                    "This platform cannot receive payment in [{$currency}]."
                );
            }

            $subscription = Subscription::withoutGlobalScope(TenantScope::class)
                ->whereKey($subscriptionId)
                ->lockForUpdate()
                ->first();

            abort_if($subscription === null, 404, 'Subscription not found.');

            if (BillingCurrency::for($subscription) === BillingCurrency::for(
                (clone $subscription)->setAttribute('billing_currency', $currency)
            )) {
                return $subscription;
            }

            $subscription->invoices()
                ->where('status', 'pending')
                ->where('gateway', 'manual')
                ->update([
                    'status' => 'void',
                    'note' => 'Superseded — the billing currency for this shop changed.',
                ]);

            $subscription->update(['billing_currency' => $currency]);

            return $subscription;
        });
    }

    /**
     * The period the shop actually gets, which is NOT always the one printed
     * on the invoice.
     *
     * The dates are computed when the invoice is RAISED, but on the manual
     * rail the money is confirmed days or weeks later — the shop has to
     * transfer, it has to arrive, and then a human here has to look at it.
     * Honouring a stale period meant a shop could pay, be approved, and be
     * read-only the same second because the month it bought had already
     * elapsed. It had paid for nothing.
     *
     * So: if the quoted period is still live, honour it exactly — that is what
     * the shop was told, and it preserves paying-early-extends. If it has
     * already run out, grant the same LENGTH from now (or from the end of any
     * period still paid for, so nothing is lost by approving early).
     *
     * Length is carried over from the invoice rather than assuming a month, so
     * this keeps working if a plan is ever billed over a different interval.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function grantedPeriod(Subscription $subscription, SubscriptionInvoice $invoice): array
    {
        if ($invoice->period_end->isFuture()) {
            return [$invoice->period_start, $invoice->period_end];
        }

        $days = max(1, $invoice->period_start->diffInDays($invoice->period_end));

        $start = $subscription->current_period_ends_at?->isFuture()
            ? $subscription->current_period_ends_at->copy()
            : now();

        return [$start, $start->copy()->addDays($days)];
    }

    /**
     * How an approved invoice moves the plan — the one place upgrades and
     * downgrades diverge.
     *
     * A DOWNGRADE with paid time still on the clock is SCHEDULED, never
     * applied now: the shop paid for Pro through the end of this period and
     * must keep it. Taking a paid feature back mid-period is the one thing the
     * rest of this design refuses to do — nothing is deleted when a shop goes
     * over its limit, and a lapsed shop keeps every row it has.
     * Subscription::effectivePlan() flips on the date, so no scheduler is
     * involved.
     *
     * Everything else applies immediately and clears any scheduled change: an
     * upgrade is giving something, not taking it, and a shop that upgrades
     * after scheduling a downgrade has plainly changed its mind.
     *
     * Always the plan the invoice was RAISED for, never whatever the
     * subscription currently says — the shop paid for what it was quoted, and
     * a repricing in between must not silently redirect the money.
     *
     * @return array<string, mixed>
     */
    private function planChange(Subscription $subscription, SubscriptionInvoice $invoice): array
    {
        $downgrading = PlanCatalog::isDowngrade($subscription->effectivePlan(), $invoice->plan);
        $hasPaidTimeLeft = $subscription->current_period_ends_at?->isFuture() === true;

        if ($downgrading && $hasPaidTimeLeft) {
            return [
                'pending_plan' => $invoice->plan,
                // The boundary the switch happens on. It cannot be derived
                // afterwards, because current_period_ends_at is about to move
                // forward to the new period's end.
                'pending_plan_starts_at' => $invoice->period_start,
            ];
        }

        return [
            'plan' => $invoice->plan,
            'pending_plan' => null,
            'pending_plan_starts_at' => null,
        ];
    }

    /**
     * The screenshot doesn't match what arrived, or nothing arrived.
     *
     * Leaves the invoice UNPAID rather than voiding it, so the shop can
     * transfer again and re-upload against the same invoice — ManualBillingRail
     * reuses an unpaid one. A dead end here would mean the shop owes a period
     * it can no longer pay for.
     *
     * A reason is required, not optional: a shop told only "rejected" cannot
     * act, and will simply open a support ticket asking why.
     */
    public function reject(int $invoiceId, PlatformAdmin $admin, string $reason): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoiceId, $admin, $reason) {
            $invoice = $this->lockInvoice($invoiceId);

            if ($invoice->status === 'paid') {
                throw new BillingActionUnavailableException(
                    'That invoice is already settled. Reversing a payment is a refund, not a rejection.'
                );
            }

            $this->refuseNonManual($invoice);

            $invoice->update([
                'status' => 'failed',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'note' => $reason,
            ]);

            $this->notifyShop($invoice, approved: false);

            return $invoice;
        });
    }

    /**
     * Called inside the transaction, but nothing is actually sent until it
     * commits — BillingNotification sets $afterCommit. A rollback therefore
     * dispatches nothing, so the shop can never be emailed "payment
     * confirmed" for a ruling that was undone.
     *
     * $invoice->tenant->users is unscoped — User has no BelongsToTenant, and
     * this context has no tenant bound to scope by anyway. Same call
     * OrderService makes for a new online order.
     */
    private function notifyShop(
        SubscriptionInvoice $invoice,
        bool $approved,
        ?Subscription $subscription = null,
    ): void {
        Notification::send(
            $invoice->tenant->users,
            new SubscriptionPaymentReviewed($invoice->fresh(), $approved, $subscription),
        );
    }

    private function lockInvoice(int $invoiceId): SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)
            ->whereKey($invoiceId)
            ->lockForUpdate()
            ->first();

        abort_if($invoice === null, 404, 'Invoice not found.');

        return $invoice;
    }

    /**
     * Staff may only ever settle a bank transfer. A card invoice is Stripe's
     * to settle, via its webhook, and nothing else — exactly the restriction
     * that keeps settleManualPayments() away from card payments on the shop
     * side. Without it, a tired admin could mark a failed card charge paid
     * and the platform would carry a shop it was never paid for.
     */
    private function refuseNonManual(SubscriptionInvoice $invoice): void
    {
        if (! $invoice->isManual()) {
            throw new BillingActionUnavailableException(
                'Only bank transfers are settled by hand. A card invoice is settled by its gateway webhook.'
            );
        }
    }
}
