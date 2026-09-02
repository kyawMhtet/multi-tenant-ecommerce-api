<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop's billing relationship with this platform. One row per tenant —
     * the unique index is the constraint that keeps "which plan is this shop
     * on" a question with exactly one answer.
     *
     * This table is the SINGLE SOURCE OF TRUTH for plan and subscription
     * state. The following migration drops the plan/subscription_status/
     * trial_ends_at/subscription_ends_at columns that were sitting unused on
     * `tenants`, rather than keeping both and syncing them: two places
     * holding the same fact is the same mistake a `preorder_quantity` column
     * would have been next to the stock ledger. One of them always drifts,
     * and the one the enforcement code happens to read decides what a shop
     * can do.
     *
     * `gateway` mirrors tenant_payment_methods exactly, including the
     * distinction that matters most here: 'manual' means there is no
     * processor and no webhook is ever coming — a human confirms a bank
     * transfer. That is not a degraded case, it is how most shops in this
     * market will pay.
     *
     * A plain string, not an enum, for both `plan` and `status`: the payments
     * table already learned this lesson the hard way, where `gateway` was
     * enum('cash','kbzpay',...) and every new provider cost an ALTER TABLE on
     * a live money table. Plans and statuses will both grow.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Validated against PlanCatalog::codes(), never free-form. Not a
            // DB constraint for the same reason NotReservedSlug isn't one:
            // the plan set is a product decision that will change, and a
            // migration is the wrong layer to pin it to.
            $table->string('plan');

            // trialing | active | past_due | cancelled
            // 'trialing' rather than 'trial' to match Stripe's own vocabulary,
            // so the webhook translation is an identity mapping and there is
            // one less place to get a rename wrong.
            $table->string('status')->default('trialing');

            // 'stripe' or 'manual'. Nullable while a shop is still on trial
            // and has not chosen how it will pay — a trial has no rail yet,
            // and defaulting it to 'stripe' would assert a card that may
            // never exist.
            $table->string('gateway')->nullable();

            // Provider-side identifiers, gateway-neutral names on purpose.
            // For Stripe these are cus_... and sub_...; a future 2C2P or
            // MyanMyanPay subscription would fill the same two columns
            // without a schema change.
            //
            // NOTE FOR ANYONE READING tenants.stripe_account_id NEXT TO THIS:
            // they are opposite directions. acct_... is Connect — money
            // flowing IN to the shop, shop is merchant of record. cus_... here
            // is billing — money flowing OUT of the shop to this platform,
            // platform is merchant of record. Confusing the two would charge
            // the wrong party on the wrong account.
            $table->string('external_customer_ref')->nullable();
            $table->string('external_subscription_ref')->nullable();

            $table->timestamp('trial_ends_at')->nullable();

            // When paid access runs out. Grace is DERIVED from this plus
            // config('billing.grace_days'), not stored — a stored grace date
            // would be a second fact that could disagree with the period end
            // it is supposed to follow. Same reason Order::isDispatched()
            // derives from dispatched_at.
            $table->timestamp('current_period_ends_at')->nullable();

            // "Cancelled, but paid up until the period end." Distinct from
            // status='cancelled', which means access is over. A shop that
            // cancels on day 2 of a paid month keeps what it bought.
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
