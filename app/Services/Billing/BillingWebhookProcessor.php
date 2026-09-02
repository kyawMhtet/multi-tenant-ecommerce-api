<?php

namespace App\Services\Billing;

use App\Models\Concerns\TenantScope;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Notifications\SubscriptionCancelled;
use App\Notifications\SubscriptionPaymentFailed;
use App\Notifications\SubscriptionPaymentReceived;
use App\Services\Billing\Data\BillingEvent;
use App\Services\Billing\Data\BillingEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Applies a verified, already-translated billing event to a subscription.
 *
 * The card rail's counterpart to SubscriptionReviewService, which is what a
 * human does on the transfer rail. Same position in the design, same
 * standard: lock, then re-check, so a redelivery is a no-op rather than a
 * second month.
 *
 * Rail-agnostic by construction — it receives a BillingEvent, which carries
 * no provider-specific anything, so a second billing provider needs no
 * changes here at all.
 */
class BillingWebhookProcessor
{
    public function process(BillingEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $subscription = $this->resolveSubscription($event);

            if ($subscription === null) {
                // Logged and swallowed, never thrown. A 500 makes the provider
                // retry forever something that can never succeed — and an
                // event for a subscription we have no record of is exactly
                // that.
                Log::warning('Billing webhook referenced an unknown subscription.', [
                    'type' => $event->type->value,
                    'subscription_ref' => $event->subscriptionRef,
                    'customer_ref' => $event->customerRef,
                    'tenant_id' => $event->tenantId,
                ]);

                return;
            }

            match ($event->type) {
                BillingEventType::Paid => $this->markPaid($subscription, $event),
                BillingEventType::PaymentFailed => $this->markPastDue($subscription, $event),
                BillingEventType::Cancelled => $this->markCancelled($subscription, $event),
            };
        });
    }

    /**
     * A webhook carries neither a token nor an X-Tenant-Slug header, so no
     * tenant is bound — it is DERIVED from whichever reference resolves. A
     * sanctioned TenantScope bypass (see CLAUDE.md), and precise rather than
     * blanket: withoutGlobalScopes() would also drop SoftDeletingScope on
     * models that have it.
     *
     * Three lookups in decreasing order of certainty, because the FIRST
     * successful payment is what teaches us the provider's subscription id —
     * POST /billing/subscribe deliberately stores nothing, since asking for
     * money is not receiving it. So that first event can only be matched by
     * customer id or by the tenant_id the rail wrote into the provider-side
     * subscription's metadata.
     */
    private function resolveSubscription(BillingEvent $event): ?Subscription
    {
        $lookups = array_filter([
            $event->subscriptionRef ? ['external_subscription_ref', $event->subscriptionRef] : null,
            $event->customerRef ? ['external_customer_ref', $event->customerRef] : null,
            $event->tenantId ? ['tenant_id', $event->tenantId] : null,
        ]);

        foreach ($lookups as [$column, $value]) {
            $subscription = Subscription::withoutGlobalScope(TenantScope::class)
                ->where($column, $value)
                ->lockForUpdate()
                ->first();

            if ($subscription !== null) {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * Idempotency comes from the (gateway, external_ref) unique index plus the
     * row lock taken while resolving. Providers redeliver routinely, and a
     * repeat must leave the shop with one month, not two.
     */
    private function markPaid(Subscription $subscription, BillingEvent $event): void
    {
        if ($event->invoiceRef !== null && $this->alreadyRecorded($event->invoiceRef)) {
            return;
        }

        // A field could be missing on an unusual payload, and a shop that has
        // PAID must never be left locked out because of it. When we know money
        // arrived, erring toward granting access is the right direction.
        $periodStart = $event->periodStart ?? now();
        $periodEnd = $event->periodEnd ?? $periodStart->copy()->addMonth();

        $plan = $event->plan !== null && PlanCatalog::exists($event->plan)
            ? $event->plan
            : $subscription->plan;

        $invoice = $subscription->tenant->subscriptionInvoices()->create([
            'subscription_id' => $subscription->id,
            'plan' => $plan,
            'amount' => $event->amount ?? BillingCurrency::amountFor(
                $event->currency ?? BillingCurrency::for($subscription),
                $plan,
            ),
            'currency' => $event->currency ?? BillingCurrency::for($subscription),
            'gateway' => 'stripe',
            'external_ref' => $event->invoiceRef,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'paid',
            'paid_at' => now(),
            // reviewed_by stays null, and that null is the signal: nobody
            // ruled on this one, the gateway confirmed it.
            'meta' => $event->raw,
        ]);

        $subscription->update([
            'plan' => $plan,
            // A live card subscription supersedes any scheduled downgrade:
            // plan changes on Stripe go through cancel-then-resubscribe, so
            // arriving here means the shop deliberately started something new.
            'pending_plan' => null,
            'pending_plan_starts_at' => null,
            'status' => 'active',
            'gateway' => 'stripe',
            // Learned here rather than at checkout, so later events for this
            // subscription resolve by the cheapest lookup.
            'external_subscription_ref' => $event->subscriptionRef ?? $subscription->external_subscription_ref,
            'external_customer_ref' => $event->customerRef ?? $subscription->external_customer_ref,
            'current_period_ends_at' => $periodEnd,
            // A successful charge un-cancels, the same position
            // SubscriptionReviewService takes when a transfer is approved.
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
        ]);

        $this->notify($subscription, fn (Subscription $fresh) => new SubscriptionPaymentReceived(
            $invoice, $fresh,
        ));
    }

    /**
     * A declined card is NOT a cancellation and does not cut access. The
     * period simply stops being extended, and the existing grace window does
     * its job — a card that fails on the 1st is often paid by the 3rd, and
     * locking the shop out in between would cost it the trade it needs to pay.
     *
     * Mirrors the order webhook, where a failed payment deliberately does not
     * release stock.
     */
    private function markPastDue(Subscription $subscription, BillingEvent $event): void
    {
        // A shop that already cancelled must not be dragged back into
        // past_due by a trailing retry — that would restart grace on a
        // subscription nobody expects to continue.
        if ($subscription->status === 'cancelled') {
            return;
        }

        $subscription->update(['status' => 'past_due']);

        // The most time-sensitive message this app sends. Access is NOT cut
        // here, so without it the shop's first hint is the day grace runs out
        // and the catalogue locks.
        $this->notify($subscription, fn (Subscription $fresh) => new SubscriptionPaymentFailed($fresh));
    }

    /**
     * current_period_ends_at is left alone on purpose. Stripe deletes a
     * subscription at the end of the period when cancel_at_period_end was
     * set, so the stored date is already the right boundary — and if a
     * subscription is deleted early, the shop still keeps what it paid for.
     * Subscription::graceEndsAt() gives a deliberate cancellation no grace, so
     * access ends exactly there.
     */
    private function markCancelled(Subscription $subscription, BillingEvent $event): void
    {
        // Stripe deletes a subscription at period end when the shop cancelled
        // through this app, which already emailed them. Notifying again here
        // would tell them a second time, weeks later, about something they
        // did — so only a cancellation we didn't already know about speaks up.
        $alreadyKnown = $subscription->status === 'cancelled';

        $subscription->update([
            'status' => 'cancelled',
            'cancel_at_period_end' => true,
            'cancelled_at' => $subscription->cancelled_at ?? now(),
        ]);

        if (! $alreadyKnown) {
            $this->notify($subscription, fn (Subscription $fresh) => new SubscriptionCancelled($fresh));
        }
    }

    /**
     * Re-reads the subscription before building the notification, so the email
     * describes the state that was just written rather than the one held in
     * memory before the update.
     *
     * $subscription->tenant->users is unscoped — User has no BelongsToTenant —
     * which matters here because a webhook has no tenant bound at all.
     *
     * @param  callable(Subscription): \Illuminate\Notifications\Notification  $make
     */
    private function notify(Subscription $subscription, callable $make): void
    {
        $fresh = $subscription->fresh();

        Notification::send($fresh->tenant->users, $make($fresh));
    }

    private function alreadyRecorded(string $invoiceRef): bool
    {
        return SubscriptionInvoice::withoutGlobalScope(TenantScope::class)
            ->where('gateway', 'stripe')
            ->where('external_ref', $invoiceRef)
            ->where('status', 'paid')
            ->exists();
    }
}
