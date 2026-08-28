<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable, additive, and backward compatible: NULL means "a general
     * photo of the product" (today's behavior, unchanged for every
     * existing row), set means "specific to one variant" — a color/style
     * photo, most useful when variants differ visually (color, pattern)
     * and misleading when they don't (size-only variants have no reason
     * to duplicate the same photo per size). product_id stays required
     * even on a variant-specific row: an image is still "of" the product,
     * just also scoped to one of its variants.
     *
     * Chosen over a separate variant_images table specifically to keep
     * this easy to evolve later: one upload pipeline (ImageUploadService,
     * unchanged), one set of validation rules to mirror instead of
     * duplicate, and the two "buckets" (general vs. variant-specific) are
     * just a WHERE clause apart (see Product::images() vs.
     * ProductVariant::images()) rather than two schemas to keep in sync.
     * Splitting into a dedicated table later, if ever needed, would just
     * be `INSERT INTO variant_images SELECT ... WHERE product_variant_id
     * IS NOT NULL` — a mechanical extraction, not a redesign.
     *
     * cascadeOnDelete matters only for a hard delete: ProductVariant uses
     * SoftDeletes, and no variant-delete endpoint exists yet, so this is
     * dormant today — set now for correctness (matching product_id's and
     * tenant_id's FKs on this same table) rather than as a footgun to
     * remember to add later.
     */
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
