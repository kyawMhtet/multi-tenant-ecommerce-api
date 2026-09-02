<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plan change scheduled for the end of the period already paid for.
     *
     * Only downgrades use this. A shop dropping from Pro to Starter with two
     * weeks left keeps Pro for those two weeks — taking a paid feature back
     * mid-period is the one thing the rest of this design is careful never to
     * do (nothing is deleted when a shop goes over its limit, and a lapsed
     * shop keeps every row it has). Upgrades apply immediately, because
     * nothing is being taken away.
     *
     * Two columns rather than one because the DATE cannot be derived:
     * approving the downgrade invoice moves current_period_ends_at forward to
     * the new period's end, which destroys the boundary the switch happens on.
     *
     * No scheduler is needed and none should be added — Subscription::effectivePlan()
     * compares pending_plan_starts_at to now, the same derive-don't-store
     * approach as graceEndsAt() and Order::isDispatched(). A nightly job that
     * "applies" pending plans would be a second source of truth for a fact the
     * dates already answer.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('pending_plan')->nullable()->after('plan');
            $table->timestamp('pending_plan_starts_at')->nullable()->after('pending_plan');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['pending_plan', 'pending_plan_starts_at']);
        });
    }
};
