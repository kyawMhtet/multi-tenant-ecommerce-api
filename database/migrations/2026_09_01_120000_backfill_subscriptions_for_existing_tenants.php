<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gives every tenant that predates the billing work a subscription row.
     *
     * Without this they have none, and PlanGate fails CLOSED on a missing
     * subscription — deliberately, since that state cannot come from
     * registration and treating it as unlimited would make "delete your
     * subscription row" the cheapest upgrade available. The consequence is
     * that deploying billing to an environment with existing tenants would
     * make every one of those shops read-only the moment it landed.
     *
     * A FRESH trial rather than an expired one. These shops did nothing
     * wrong, and dropping them straight into read-only would be exactly the
     * "taking something away" that the whole lapse design avoids: the rule
     * everywhere here is that enforcement bites on create, never by removing
     * what someone already had.
     *
     * A raw insert, not Subscription::create(): BelongsToTenant's creating
     * hook fills tenant_id from the bound tenant, and a migration has no
     * tenant bound, so the row would fail its NOT NULL constraint. Going
     * through the query builder also keeps this migration working unchanged
     * if SubscriptionService is later refactored — a migration that calls
     * application services breaks when they move.
     *
     * Mirrors SubscriptionService::startTrial(); keep the two in step if the
     * trial shape changes.
     */
    public function up(): void
    {
        $plan = (string) config('billing.trial_plan');
        $trialEndsAt = now()->addDays((int) config('billing.trial_days'));
        $now = now();

        $orphans = DB::table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->whereNull('subscriptions.id')
            ->pluck('tenants.id');

        if ($orphans->isEmpty()) {
            return;
        }

        DB::table('subscriptions')->insert(
            $orphans->map(fn ($tenantId) => [
                'tenant_id' => $tenantId,
                'plan' => $plan,
                'status' => 'trialing',
                // No rail chosen yet — a trial has no payment method, and
                // asserting one would claim a card that may never exist.
                'gateway' => null,
                'trial_ends_at' => $trialEndsAt,
                'current_period_ends_at' => null,
                'cancel_at_period_end' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    /**
     * Deliberately does nothing.
     *
     * Rolling this back would have to delete subscriptions, and by then some
     * of those rows may record a shop that has actually paid. Losing that is
     * unrecoverable from here, while leaving a few extra trial rows behind is
     * harmless — up() skips tenants that already have one, so re-running is
     * safe.
     */
    public function down(): void {}
};
