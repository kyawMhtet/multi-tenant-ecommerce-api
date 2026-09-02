<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Services\Billing\PlanCatalog;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Confirms a cancellation, and — more usefully — confirms what the shop keeps.
 *
 * Cancelling does not end access immediately; the shop keeps the period it
 * already paid for. Saying so is what prevents both halves of the usual
 * support pair: "I cancelled but I've lost access" and "I cancelled but I
 * think you'll charge me again".
 */
class SubscriptionCancelled extends BillingNotification
{
    public function __construct(private readonly Subscription $subscription)
    {
        parent::__construct();
    }

    public function databaseType(object $notifiable): string
    {
        return 'subscription_cancelled';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'plan' => $this->subscription->effectivePlan(),
            'plan_label' => PlanCatalog::labelFor($this->subscription->effectivePlan()),
            'access_ends_at' => $this->subscription->accessEndsAt()?->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->subscription->tenant;
        $plan = PlanCatalog::labelFor($this->subscription->effectivePlan());
        $endsOn = $this->subscription->accessEndsAt()?->toFormattedDateString();

        return $this->mailTemplate($tenant, 'Your subscription has been cancelled')
            ->line("We've cancelled your {$plan} subscription. You won't be charged again.")
            ->lineIf(
                (bool) $endsOn,
                "You keep {$plan} until {$endsOn} — the period you've already paid for. Nothing changes before then.",
            )
            // Nothing is deleted, ever. Worth saying, because "cancelled"
            // makes people assume their data is going.
            ->line('After that your shop stays exactly as it is and your storefront keeps running; you just won\'t be able to make changes until you subscribe again.')
            ->action('Subscribe again', $this->billingUrl($tenant));
    }
}
