<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Services\Billing\PlanCatalog;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A card charge was declined.
 *
 * The most time-sensitive email here, and the reason it exists: a declined
 * card does NOT cut access — the shop keeps working through the grace window —
 * so without this the first they hear about it is the day the shop stops
 * letting them add products. Telling them while they can still fix it is the
 * whole point.
 */
class SubscriptionPaymentFailed extends BillingNotification
{
    public function __construct(private readonly Subscription $subscription)
    {
        parent::__construct();
    }

    public function databaseType(object $notifiable): string
    {
        return 'subscription_payment_failed';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'plan' => $this->subscription->plan,
            'plan_label' => PlanCatalog::labelFor($this->subscription->effectivePlan()),
            // Derived, never stored — see Subscription::graceEndsAt(). This is
            // the date that actually matters to the shop.
            'grace_ends_at' => $this->subscription->graceEndsAt()?->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->subscription->tenant;
        $graceEnds = $this->subscription->graceEndsAt()?->toFormattedDateString();

        return $this->mailTemplate($tenant, 'Your card payment did not go through')
            ->line("We couldn't take your subscription payment — the card was declined or has expired.")
            // Stated first and plainly. A billing email that reads like a
            // threat makes shops panic about a shop that is still working.
            ->line('Nothing has changed yet: your shop, storefront and orders are all still running.')
            ->lineIf(
                (bool) $graceEnds,
                "Please update your card before {$graceEnds}. After that you'll still be able to read everything, but you won't be able to add products or make changes until it's sorted.",
            )
            ->action('Update payment', $this->billingUrl($tenant));
    }
}
