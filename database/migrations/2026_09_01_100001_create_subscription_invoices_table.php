<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger of what a shop actually paid this platform, one row per
     * billing period. `subscriptions` holds current state; this holds
     * history, and the two answer different questions: "what can this shop do
     * today" versus "did they pay for March".
     *
     * A ledger rather than a paid_until counter, for the same reason
     * stock_movements is a ledger: when a shop disputes a charge or a
     * transfer goes missing, "which periods were paid, by what rail, and who
     * confirmed it" is the only answer that helps. A counter cannot be
     * audited.
     *
     * Deliberately parallel to the `payments` table, including the
     * unique(gateway, external_ref) idempotency backstop — providers
     * redeliver webhooks routinely, and a redelivery must be a no-op rather
     * than a second month of credit.
     */
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Snapshotted, not read through the subscription. Same rule as
            // order_items.unit_price and orders.delivery_provider_name: this
            // records what was billed at the time, and it must keep saying so
            // after the shop switches plans or the catalogue is repriced.
            $table->string('plan');
            $table->decimal('amount', 12, 2);
            // Snapshotted for the same reason, and separately from
            // tenants.currency — this is the PLATFORM's billing currency
            // (config('billing.currency')), not what the shop sells in.
            $table->string('currency', 3);

            $table->string('gateway');
            // Stripe's invoice id. Null on the manual rail: a bank transfer
            // has no provider-side identity at all, which is exactly why a
            // human has to vouch for it.
            $table->string('external_ref')->nullable();

            // What this money buys. Written when the invoice is raised, so an
            // approval that arrives late still credits the period it was for
            // rather than silently shifting the shop's renewal date forward.
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // pending | paid | failed | void
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();

            // The shop's transfer screenshot, manual rail only. IT IS A CLAIM,
            // NOT PROOF — trivially forged, and often just wrong (right
            // amount, wrong recipient). Nothing may read its presence as
            // payment; it exists so a human can glance and decide. Identical
            // rule to payments.proof_path on the shop side.
            $table->string('proof_path')->nullable();

            // Who confirmed the transfer, and when. NOT a foreign key to
            // `users` — deliberately. Every row in `users` belongs to a
            // tenant, so an FK there would model "a shop approved its own
            // payment", which is the one thing this column exists to prevent.
            // A plain identifier until a platform_admins table exists; it
            // becomes an FK to that table, never to users.
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // The idempotency backstop. Scoped to gateway because refs are
            // only unique within a provider, and nullable external_ref means
            // manual rows never collide with each other (MySQL treats NULLs
            // as distinct in a unique index) — correct here, since two
            // separate manual transfers are two separate facts.
            $table->unique(['gateway', 'external_ref']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
