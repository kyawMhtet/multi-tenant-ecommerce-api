<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Globally unique (not scoped by tenant_id like sku is) so a public
     * short link — /api/v1/public/products/{slug} — can resolve straight
     * to a variant, and from it the owning tenant, with no tenant header
     * or subdomain needed. Nullable because this is an additive column on
     * a table that may already have rows in the real dev database (unlike
     * a fresh test run); every variant created going forward always gets
     * one via ProductService, so in practice it's never null for new rows.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
