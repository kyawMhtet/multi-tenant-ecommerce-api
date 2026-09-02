<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No price/SKU/stock columns here on purpose: those live on product_variants,
     * and every product gets at least one variant even without real options. This
     * keeps price/stock resolution on a single path everywhere (POS, cart, checkout,
     * inventory) instead of branching on "does this product have variants or not".
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // No image column: photos live in product_images (one-to-many,
            // sort_order decides the cover). See ImageUploadService.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
