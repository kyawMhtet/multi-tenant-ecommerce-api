<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces products.image_path with a proper one-to-many table: a
     * single nullable string column can't hold a gallery. `path` is
     * storage-relative (the 'public' disk), never a raw client-supplied
     * URL — see ImageUploadService, which is the only thing that ever
     * writes it. `sort_order` (lowest first) determines display order;
     * the lowest is treated as the product's cover image.
     */
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Optional: an image may belong to one specific variant (a
            // colour) rather than the product's general gallery.
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
