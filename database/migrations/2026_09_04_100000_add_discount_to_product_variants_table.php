<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-variant discounts.
     *
     * Type + value rather than a `sale_price` column: a stored sale price is a
     * second source of truth for one fact, so raising selling_price later
     * would silently deepen — or invert — a discount nobody touched. A
     * percentage survives a reprice, and `fixed` keeps "500 MMK off"
     * expressible, which makes the pair a strict superset of a sale price.
     *
     * The window is nullable at both ends and is never accompanied by an
     * is_on_sale flag: whether a discount is live is DERIVED from the dates
     * (ProductVariant::discountActive()), so the two can't disagree and the
     * shop doesn't have to remember to switch a promotion off. Same reasoning
     * as billing grace being derived from current_period_ends_at.
     *
     * order_items.discount_amount is the SNAPSHOT, the same rule as unit_price
     * and deposit_amount. unit_price deliberately keeps recording the LIST
     * price with the reduction beside it, rather than storing the discounted
     * figure and losing the fact that a discount happened at all — "how much
     * did we give away this month" is the whole reason a shop runs promotions.
     * It is also what orders.discount_amount and the hardcoded 0 in
     * OrderService::calculateTotal() were already shaped for.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Null = no discount. See App\Services\Pricing\DiscountType.
            $table->string('discount_type')->nullable()->after('selling_price');
            $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            // Null start = live immediately; null end = until withdrawn.
            $table->timestamp('discount_starts_at')->nullable()->after('discount_value');
            $table->timestamp('discount_ends_at')->nullable()->after('discount_starts_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // 0 on any line sold at list price. line_total is net of this;
            // orders.subtotal is the gross sum, orders.discount_amount the
            // sum of these, so subtotal - discount_amount = sum(line_total).
            $table->decimal('discount_amount', 12, 2)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_starts_at', 'discount_ends_at']);
        });
    }
};
