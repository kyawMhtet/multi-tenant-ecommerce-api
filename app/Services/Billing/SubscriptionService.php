<?php

namespace App\Services\Billing;

use App\Exceptions\BillingActionUnavailableException;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Notifications\SubscriptionCancelled;
use App\Services\Billing\Data\BillingInitiation;
use App\Services\Billing\Data\RailAvailability;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Owns every transition of a shop's subscription state. Nothing else writes
 * to `subscriptions` — the same rule that keeps every stock change inside
 * StockService, and for the same reason: the state is only coherent if one
 * place is responsible for moving it.
 */
class SubscriptionService
{
    public function __construct(
        private readonly BillingRailManager $rails,
        private readonly ImageUploadService $imageUploadService,
    ) {}

    /**
     * Every new shop starts here, inside the registration transaction.
     *
     * The trial runs on the TOP plan, not the cheapest. A shop that only ever
     * sees Starter has no way to discover the features it would be upgrading
     * for, and no reason to.
     *
     * gateway stays null: a trial has no payment rail yet, and defaulting it
     * to 'stripe' would assert a card that may never exist — in this market
     * most shops will end up on the manual rail instead.
     *
     * trial_ends_at is always set. Before this, register() wrote no date at
     * all and the tenants.subscription_status column defaulted to 'trial',
     * which meant every shop ever created was on an unlimited free trial that
     * nothing would end. Subscription::allowsWrites() now reads a null date
     * as expired rather than eternal, so the same omission would fail loudly
     * instead of silently giving the platform away.
     */
    public function startTrial(Tenant $tenant): Subscription
    {
        $plan = (string) config('billing.trial_plan');

        return $tenant->subscription()->create([
            'plan' => PlanCatalog::exists($plan) ? $plan : PlanCatalog::FALLBACK,
            'status' => 'trialing',
            'gateway' => null,
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days')),
        ]);
    }

    /**
     * Begin paying for a plan. Returns what the admin app must do next — a
     * Stripe redirect, or bank details and an invoice.
     *
     * Deliberately does NOT change the plan, the status, or subscriptions.gateway.
     * Asking for money is not receiving it: the Stripe redirect can be closed
     * and the bank transfer may never be sent. The plan moves when money is
     * CONFIRMED — by webhook on the card rail, by a human on the manual one.
     * Exactly the rule that already governs orders, where only a webhook may
     * mark one paid.
     */
    public function subscribe(Subscription $subscription, string $plan, string $rail): BillingInitiation
    {
        if (! PlanCatalog::exists($plan)) {
            throw new BillingActionUnavailableException("There is no plan called [{$plan}].");
        }

        $railInstance = $this->rails->rail($rail);
        $currency = BillingCurrency::for($subscription);

        $availability = $railInstance->availability($plan, $currency);

        if (! $availability->isAvailable()) {
            // The word "yet" is wrong when the answer is permanent, and a shop
            // owner reading it would wait for something that is not coming.
            throw new BillingActionUnavailableException(
                $availability === RailAvailability::CurrencyUnsupported
                    ? "Card payment is not supported in {$currency}. Bank transfer is the way to pay from here."
                    : "That payment option is not available for this plan in {$currency} yet."
            );
        }

        $this->guardAgainstDoubleCharging($subscription);
        $this->supersedePendingInvoicesForOtherPlans($subscription, $plan);

        return $railInstance->initiate($subscription, $plan);
    }

    /**
     * A shop with a live Stripe subscription must not be sent through
     * Checkout again — Stripe would happily create a SECOND subscription and
     * charge the card twice a month, which is the worst failure this feature
     * could have.
     *
     * Changing plan on an existing Stripe subscription is a different API
     * call with proration to reason about, and is deliberately left unbuilt
     * rather than approximated. Cancel-then-resubscribe is the honest
     * interim answer, and it costs the shop nothing: cancelling keeps access
     * to the end of the period already paid for.
     */
    private function guardAgainstDoubleCharging(Subscription $subscription): void
    {
        $hasLiveStripeSubscription = $subscription->gateway === 'stripe'
            && filled($subscription->external_subscription_ref)
            && ! $subscription->cancel_at_period_end
            && $subscription->status !== 'cancelled';

        // Blocks BOTH rails, not just a second Checkout. Stripe would create a
        // second subscription and charge twice a month; a bank transfer is
        // worse in a quieter way — approving it flips `gateway` to manual
        // while Stripe carries on charging the card every month, and nothing
        // in the app would show the shop being billed twice.
        if ($hasLiveStripeSubscription) {
            throw new BillingActionUnavailableException(
                'This shop already has an active card subscription. Cancel it first, then choose a new plan — you keep access until the period you have paid for ends.'
            );
        }
    }

    /**
     * A shop that asks for Starter by transfer and then changes its mind and
     * asks for Pro would otherwise be left owing TWO unpaid invoices, because
     * ManualBillingRail only reuses one matching the same plan. Staff would
     * see both in the queue with one screenshot between them, and approving
     * both would grant two periods.
     *
     * Voided rather than deleted: what a shop asked for and abandoned is real
     * history, and scopeUnpaid() excludes 'void', so a voided invoice can
     * never be reused or approved.
     *
     * Only PENDING ones, and only this subscription's — a paid invoice is a
     * record of money that actually moved and is never touched.
     */
    private function supersedePendingInvoicesForOtherPlans(Subscription $subscription, string $plan): void
    {
        $subscription->invoices()
            ->where('status', 'pending')
            ->where('gateway', 'manual')
            ->where('plan', '!=', $plan)
            ->update([
                'status' => 'void',
                'note' => 'Superseded — the shop chose a different plan before paying.',
            ]);
    }

    /**
     * Attaches the shop's transfer screenshot to an invoice.
     *
     * IT DOES NOT SETTLE ANYTHING. The status becomes (or stays) 'pending'
     * and the plan does not move. A screenshot is trivially forged and frequently just
     * wrong — right amount, wrong recipient — and here the party uploading it
     * is the party being billed, so treating it as payment would let any shop
     * grant itself a plan with an image file. It exists so a human can glance
     * and decide.
     */
    public function submitProof(SubscriptionInvoice $invoice, UploadedFile $proof): SubscriptionInvoice
    {
        if (! $invoice->isManual()) {
            throw new BillingActionUnavailableException(
                'Only bank transfer invoices take a payment screenshot.'
            );
        }

        if ($invoice->status === 'paid') {
            throw new BillingActionUnavailableException('That invoice is already paid.');
        }

        $oldProof = $invoice->proof_path;

        DB::transaction(function () use ($invoice, $proof, $oldProof) {
            $invoice->update([
                'proof_path' => $this->imageUploadService->store(
                    $proof,
                    'billing-proofs/'.$invoice->tenant_id,
                ),
                // A new screenshot is a NEW CLAIM, so a previously rejected
                // invoice goes back into the review queue.
                //
                // Without this it went nowhere: 'failed' is excluded from
                // scopeAwaitingApproval(), and scopeAwaitingTransfer() only
                // matches invoices with no proof at all — so a rejected
                // transfer the shop re-uploaded against was invisible to
                // reviewers permanently, which silently broke the recovery
                // path rejection is designed around ("transfer again and
                // upload against the same reference").
                //
                // reviewed_by / reviewed_at / note are deliberately KEPT. The
                // next reviewer wants to see that this was rejected before and
                // why — a second screenshot for the same wrong amount should
                // be recognisable as such.
                'status' => 'pending',
            ]);

            // Deferred, never synchronous: file I/O is not transactional, so
            // deleting now and rolling back later would leave the row
            // pointing at a file that no longer exists. Same pattern as
            // ProductService::deleteImage().
            if ($oldProof !== null) {
                DB::afterCommit(fn () => $this->imageUploadService->delete($oldProof));
            }
        });

        return $invoice->fresh();
    }

    /**
     * Stop future charges, keeping every day already paid for.
     *
     * The trial branch matters: Subscription::accessEndsAt() reads
     * current_period_ends_at once the status is 'cancelled', so a trialing
     * shop that cancelled would otherwise lose access the same second — the
     * exact "taking back something already given" this method exists to
     * avoid. Copying the trial end across preserves it.
     */
    public function cancel(Subscription $subscription): Subscription
    {
        if ($subscription->status === 'cancelled') {
            return $subscription;
        }

        $this->rails->rail($subscription->gateway ?? 'manual')->cancel($subscription);

        $subscription->update([
            'status' => 'cancelled',
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
            'current_period_ends_at' => $subscription->accessEndsAt(),
        ]);

        $fresh = $subscription->fresh();

        Notification::send($fresh->tenant->users, new SubscriptionCancelled($fresh));

        return $fresh;
    }
}
