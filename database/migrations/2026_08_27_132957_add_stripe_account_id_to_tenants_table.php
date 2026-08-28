<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shop's Stripe Connect account id (acct_...).
     *
     * Deliberately a plain, unencrypted column: with Connect direct charges
     * this is an *identifier*, not a credential. Payments are created with
     * the platform's own secret key (in env) while naming this account as
     * the one being charged — so the shop's money goes straight to the
     * shop, and a database leak exposes no key capable of moving money.
     * That's the whole reason Connect was chosen over asking each shop to
     * paste in their own Stripe secret key.
     *
     * Nullable because it's populated only after the shop completes
     * Stripe's hosted onboarding — and "is this null?" is exactly the
     * signal the settings UI needs to decide between showing an onboarding
     * button and showing a connected state. A shop can exist, sell in
     * person via POS, and accept cash on delivery online without ever
     * touching Stripe.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('stripe_account_id');
        });
    }
};
