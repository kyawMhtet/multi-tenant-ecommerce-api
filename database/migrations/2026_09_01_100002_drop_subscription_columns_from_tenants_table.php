<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These four columns shipped in the very first migration and were never
     * read or written by anything — `plan` and `subscription_status` weren't
     * even fillable. Now that `subscriptions` exists and IS read on every
     * entitlement check, leaving them would create two places holding the
     * same fact, and the shop's abilities would depend on which one a given
     * piece of code happened to look at.
     *
     * Deleting the duplicate rather than syncing it is the same call made
     * against a preorder_quantity column next to the stock ledger, and
     * against storing "is dispatched" next to dispatched_at: derive, or hold
     * it once.
     *
     * The obvious objection is cost — `tenants` is already loaded by
     * ResolveTenant on every request, so reading the plan off it would be
     * free, while `subscriptions` is a second query. That is real, and it is
     * still the wrong trade: the extra query is lazy-loaded once per request
     * and only when a gate is actually checked, whereas a stale plan column
     * is a shop billed for Pro and served Starter, which is a support ticket
     * and a refund. Cache it later behind Subscription if it ever measures.
     *
     * down() restores the columns with their original defaults, but NOT their
     * values — the data lives in `subscriptions` now. Rolling back past this
     * migration means rolling back the feature.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'plan',
                'subscription_status',
                'trial_ends_at',
                'subscription_ends_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan')->default('trial');
            $table->enum('subscription_status', ['trial', 'active', 'past_due', 'cancelled'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
        });
    }
};
