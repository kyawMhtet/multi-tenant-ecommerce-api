<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the shop pays THE PLATFORM in — separated from what it sells in.
     *
     * These were one field, and that was wrong. `tenants.currency` answers
     * "what do this shop's prices and order totals mean", and it is immutable
     * because changing it would retroactively reinterpret every historical
     * total. Billing borrowed it, and borrowed that immutability as if it were
     * a billing rule ("a shop can't move to whichever currency is cheapest") —
     * but the constraint exists for an unrelated reason, and the two facts are
     * genuinely independent: a Yangon shop selling to tourists in USD still
     * banks in Kyat, and a Myanmar-owned shop in Thailand may sell Baht while
     * wanting to pay us from a Kyat account.
     *
     * NULL means "follow the shop's selling currency", which is correct for
     * almost every shop and keeps the common case decision-free. A value here
     * is therefore visibly an OVERRIDE someone made deliberately, not ambient
     * state — see BillingCurrency::for().
     *
     * Settable only by platform staff. Left to the shop it would be an
     * arbitrage lever: the ladders are not at parity (Pro is 699 THB against
     * 89,000 MMK, roughly 636 THB), so a shop could pick whichever is cheaper,
     * and the gap moves with FX.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_currency', 3)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_currency');
        });
    }
};
