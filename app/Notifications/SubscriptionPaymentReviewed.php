<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\PlanCatalog;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A human ruled on a bank transfer.
 *
 * The rejection case is the one that earns its keep: a shop told only
 * "rejected" cannot act, and will transfer again or open a support ticket. The
 * reviewer's reason travels with it.
 */
class SubscriptionPaymentReviewed extends BillingNotification
{
    public function __construct(
        private readonly SubscriptionInvoice $invoice,
        private readonly bool $approved,
        private readonly ?Subscription $subscription = null,
    ) {
        parent::__construct();
    }

    /** A stable discriminator in `type`; the default would be the FQCN. */
    public function databaseType(object $notifiable): string
    {
        return 'subscription_payment_reviewed';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            // The same string the shop put in the transfer note, so support
            // conversations have one shared reference.
            'reference' => 'SUB-'.$this->invoice->id,
            'approved' => $this->approved,
            'plan' => $this->invoice->plan,
            'plan_label' => PlanCatalog::labelFor($this->invoice->plan),
            'amount' => (float) $this->invoice->amount,
            'currency' => $this->invoice->currency,
            'period_end' => $this->invoice->period_end?->toIso8601String(),
            // The whole point on a rejection.
            'note' => $this->invoice->note,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->invoice->tenant;
        $amount = $this->money($this->invoice->amount, $this->invoice->currency);
        $plan = PlanCatalog::labelFor($this->invoice->plan);

        if (! $this->approved) {
            return $this->mailTemplate($tenant, "We couldn't confirm your {$plan} payment")
                ->line("We reviewed the transfer you uploaded for {$amount} and weren't able to match it.")
                // Quoted rather than paraphrased: it is the only part of this
                // email the shop can act on.
                ->line($this->invoice->note ?: 'No further detail was given.')
                ->line("Your invoice is still open, so you can transfer again and upload a new screenshot against the same reference (SUB-{$this->invoice->id}).")
                ->action('Open billing', $this->billingUrl($tenant));
        }

        return $this->mailTemplate($tenant, "Payment received — you're on {$plan}")
            ->line("Thanks — we've confirmed your transfer of {$amount}.")
            ->lines($this->planOutcomeLines($plan))
            ->action('View your plan', $this->billingUrl($tenant));
    }

    /**
     * Says what the shop actually got, which differs by direction and is the
     * one thing they can't work out from the amount alone: an upgrade applies
     * now, a downgrade is scheduled for the end of the period they already
     * paid for.
     *
     * @return list<string>
     */
    private function planOutcomeLines(string $plan): array
    {
        $subscription = $this->subscription;

        if ($subscription?->hasScheduledPlanChange()) {
            $from = PlanCatalog::labelFor($subscription->effectivePlan());
            $to = PlanCatalog::labelFor((string) $subscription->pending_plan);
            $on = $subscription->pending_plan_starts_at?->toFormattedDateString();

            return ["You'll stay on {$from} until {$on}, then move to {$to}. Nothing changes before then."];
        }

        $until = $subscription?->current_period_ends_at?->toFormattedDateString();

        return array_filter([
            "You're on {$plan}.",
            $until ? "Your next payment is due {$until}." : null,
        ]);
    }
}
