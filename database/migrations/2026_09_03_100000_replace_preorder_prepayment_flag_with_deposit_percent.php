<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turns the all-or-nothing prepayment flag into a percentage.
     *
     * The flag could only express "pay everything up front" or "pay nothing",
     * and shops in this market routinely ask for HALF — an imported 668,000
     * MMK shoe is a real example: the customer won't wire the full price to a
     * Facebook page, and the shop won't front it to a Bangkok showroom on a
     * promise. A deposit splits the risk, which is why the arrangement exists.
     *
     * The percentage is a strict superset of the flag: 0 is what `false` meant,
     * 100 is what `true` meant, and everything between is newly expressible. So
     * the backfill below is exact and nothing changes behaviour for existing
     * variants.
     *
     * Per VARIANT, not per shop, for the same reason the flag was: the risk
     * scales with the item, and a shop sends a phone case COD while asking half
     * up front for an imported phone — both sitting in one catalogue.
     *
     * order_items.deposit_amount is the SNAPSHOT, same rule as unit_price and
     * preorder_lead_time_days: it records what this customer was actually asked
     * for, and must keep saying so after the shop changes the percentage or
     * reprices the item.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedTinyInteger('preorder_deposit_percent')
                ->default(0)
                ->after('preorder_lead_time_days');
        });

        DB::table('product_variants')
            ->where('preorder_requires_prepayment', true)
            ->update(['preorder_deposit_percent' => 100]);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('preorder_requires_prepayment');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // 0 on any line that isn't a deposit-bearing preorder — including
            // ordinary in-stock lines, which are governed by the payment method
            // alone and have nothing "due up front" of their own.
            $table->decimal('deposit_amount', 12, 2)->default(0)->after('preorder_lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('preorder_requires_prepayment')->default(false);
        });

        // Anything short of 100% collapses back to "no prepayment required" —
        // the old column cannot express a partial deposit, which is the whole
        // reason it was replaced.
        DB::table('product_variants')
            ->where('preorder_deposit_percent', '>=', 100)
            ->update(['preorder_requires_prepayment' => true]);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('preorder_deposit_percent');
        });
    }
};
