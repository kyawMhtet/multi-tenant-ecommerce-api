<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\PlanCatalog;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A card payment succeeded.
 *
 * The card rail's counterpart to SubscriptionPaymentReviewed. Until now the
 * Stripe path sent nothing at all — not even in-app — so a shop paying by card
 * had no record of it anywhere in the product.
 *
 * Separate from the review notification rather than a flag on it, because
 * "reviewed" means a human ruled and nobody ruled on this one. A shop reading
 * its notification history should be able to tell which payments needed a
 * person.
 */
class SubscriptionPaymentReceived extends BillingNotification
{
    public function __construct(
        private readonly SubscriptionInvoice $invoice,
        private readonly Subscription $subscription,
    ) {
        parent::__construct();
    }

    public function databaseType(object $notifiable): string
    {
        return 'subscription_payment_received';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'reference' => 'SUB-'.$this->invoice->id,
            'plan' => $this->invoice->plan,
            'plan_label' => PlanCatalog::labelFor($this->invoice->plan),
            'amount' => (float) $this->invoice->amount,
            'currency' => $this->invoice->currency,
            'period_end' => $this->invoice->period_end?->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->subscription->tenant;
        $plan = PlanCatalog::labelFor($this->invoice->plan);
        $renewsOn = $this->subscription->current_period_ends_at?->toFormattedDateString();

        return $this->mailTemplate($tenant, "Payment received — {$plan}")
            ->line("We've received your card payment of {$this->money($this->invoice->amount, $this->invoice->currency)}.")
            ->line("You're on {$plan}.")
            // Card subscriptions renew by themselves, so this is a heads-up
            // rather than a request for action — worth saying plainly so the
            // next charge isn't a surprise.
            ->lineIf((bool) $renewsOn, "Your card will be charged again on {$renewsOn}.")
            ->action('View your plan', $this->billingUrl($tenant));
    }
}
