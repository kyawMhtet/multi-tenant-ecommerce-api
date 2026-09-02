<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape for every billing email, so the queue decisions are stated once
 * rather than copied into four classes that would drift.
 *
 * MAIL IS QUEUED; THE IN-APP NOTIFICATION IS NOT. viaConnections() sends the
 * database channel on the 'sync' connection so the bell keeps working even
 * when no worker is running — which is the normal state of this project today,
 * and the exact failure NewOnlineOrderReceived avoids by not queueing at all.
 * Only the slow channel is deferred, so forgetting to start a worker delays
 * email rather than silently breaking notifications.
 *
 * $afterCommit matters more than it looks. These are sent from inside the
 * transaction that settles a payment, and without it a worker could pick the
 * job up before that transaction commits — emailing "your payment is
 * confirmed" for a ruling that then rolled back. With it, a rollback means the
 * job is never dispatched at all, which is the same guarantee the previous
 * send-inside-the-transaction approach gave.
 *
 * Anything queued here runs on a worker, where the 'tenant' container binding
 * does NOT reset between jobs — see AppServiceProvider::forgetTenantBetweenJobs().
 * Never assume a bound tenant in a notification.
 */
abstract class BillingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * $afterCommit is DECLARED BY Queueable, so it is set here rather than
     * redeclared as a property — PHP rejects a trait property redeclared with
     * a different default, and the resulting fatal fires during bootstrap
     * where it surfaces as a silent crash rather than an error.
     *
     * Subclasses must call parent::__construct().
     */
    public function __construct()
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return [
            'mail' => 'database',
            'database' => 'sync',
        ];
    }

    /**
     * Where every billing email points. One destination on purpose: whatever
     * the email is about, the answer is on the billing screen, and a shop
     * owner should never have to work out where to go.
     */
    protected function billingUrl(?Tenant $tenant): string
    {
        $base = str_replace('{slug}', (string) $tenant?->slug, (string) config('payments.admin_url'));

        return rtrim($base, '/').config('billing.return_path');
    }

    protected function money(float|string|null $amount, ?string $currency): string
    {
        return number_format((float) $amount, 2).' '.strtoupper((string) $currency);
    }

    protected function mailTemplate(?Tenant $tenant, string $subject): MailMessage
    {
        return (new MailMessage)
            ->subject($subject)
            ->greeting($tenant?->name ? "Hello {$tenant->name}," : 'Hello,');
    }
}
