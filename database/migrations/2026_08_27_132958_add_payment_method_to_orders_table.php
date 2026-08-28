<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which payment method the customer chose at checkout.
     *
     * This can't be inferred from the `payments` table, which is the
     * tempting shortcut: a cash-on-delivery order may have NO payments row
     * at all until a human confirms cash changed hands, and a card order
     * has none until Stripe's webhook arrives. So before payment resolves,
     * both look identical — an order with no payments row — even though one
     * is waiting on a delivery driver and the other on Stripe. The shop
     * owner needs to tell those apart at a glance, and the system needs it
     * to decide whether an unpaid order is stale or simply out for
     * delivery.
     *
     * Nullable for the same reason `cashier_id` is: POS orders don't go
     * through storefront checkout and have no method to record — they're
     * paid at the counter, which the existing Payment row with
     * gateway='cash' already captures.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
