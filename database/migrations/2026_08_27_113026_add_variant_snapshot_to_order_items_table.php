<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshots the variant's identity alongside the price/cost snapshot
     * this table already keeps, for the same reason (see the
     * create_order_items migration): product_variant_id is nullOnDelete,
     * so a deleted variant would otherwise leave an order item with
     * nothing but a product name — and even a surviving variant can be
     * renamed or re-SKU'd, which would silently rewrite what a historical
     * order appears to have contained.
     *
     * sku is the identifier staff actually use to pull an item off the
     * shelf, and attributes (size/colour) is frequently the only thing
     * distinguishing two variants — variant_name is nullable and is in
     * fact null for most rows, since a simple product's single variant
     * has no name to show.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('variant_name');
            $table->json('attributes')->nullable()->after('sku');
        });

        // Best-effort backfill for rows whose variant still exists. This
        // is today's SKU, not provably the one at sale time — but for an
        // existing order that's far more useful than a permanently blank
        // column, and going forward every new row captures it correctly
        // at the moment of sale. Rows whose variant was deleted keep null,
        // which is the honest answer for them.
        //
        // Correlated subqueries rather than an UPDATE...JOIN: the join
        // form is MySQL-specific and SQLite (which the test suite runs on)
        // rejects it, so this keeps one migration working on both.
        //
        // Raw SQL rather than Eloquent, deliberately: models carry a tenant
        // global scope, and a migration runs with no tenant bound (nor
        // should it — it must touch every tenant's rows).
        DB::table('order_items')
            ->whereNotNull('product_variant_id')
            ->update([
                'sku' => DB::raw('(select sku from product_variants where product_variants.id = order_items.product_variant_id)'),
                'attributes' => DB::raw('(select attributes from product_variants where product_variants.id = order_items.product_variant_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['sku', 'attributes']);
        });
    }
};
