<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            // System-generated, globally unique (not per-tenant) short
            // random code powering the public storefront link. Never
            // accepted from request input — see
            // ProductService::generateVariantSlug().
            $table->string('slug')->nullable()->unique();
            $table->string('barcode')->nullable();
            $table->string('variant_name')->nullable();
            $table->json('attributes')->nullable();
            $table->string('unit')->default('pcs');
            $table->decimal('buying_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->boolean('track_stock')->default(true);
            // Permission to sell below zero, not a second counter: with
            // this on, current_stock goes negative and that IS the
            // backlog. See StockService::deductForSale().
            $table->boolean('allow_preorder')->default(false);
            $table->unsignedSmallInteger('preorder_lead_time_days')->nullable();
            // Refuses cash on delivery while this item is on preorder.
            $table->boolean('preorder_requires_prepayment')->default(false);
            $table->decimal('current_stock', 12, 2)->default(0);
            $table->decimal('low_stock_threshold', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
