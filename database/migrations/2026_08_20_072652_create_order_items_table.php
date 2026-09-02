<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * unit_price and unit_cost are snapshotted, not looked up live, so a later
     * price change never rewrites the financial history of a completed order.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            // Snapshotted like unit_price: product_variant_id is nullOnDelete,
            // and a surviving variant can still be renamed.
            $table->string('sku')->nullable();
            $table->json('attributes')->nullable();
            $table->decimal('quantity', 12, 2);
            // Per ITEM, not per order — mixed carts are ordinary. Derived at
            // sale time from the post-deduction balance, then snapshotted.
            $table->boolean('is_preorder')->default(false);
            $table->unsignedSmallInteger('preorder_lead_time_days')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
