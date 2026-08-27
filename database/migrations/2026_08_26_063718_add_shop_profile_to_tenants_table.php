<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shop profile fields, editable by the owner and (except where noted)
     * shown publicly on the storefront.
     *
     * logo_path/cover_path are real columns rather than keys inside the
     * existing settings JSON: replacing an image is a read-old/write-new
     * operation, and a column is a single atomic assignment while a JSON
     * sub-key is a read-modify-write that silently clobbers under two
     * concurrent saves (leaking a file with no row pointing at it). A
     * future "purge a deleted tenant's storage" job also wants
     * SELECT logo_path, not JSON_EXTRACT. business_hours/social_links DO
     * live in settings — they're genuinely variable-shape.
     *
     * Deliberately NOT backfilling business_phone/business_email from
     * owner_phone/owner_email: those were typed into a signup form for an
     * account, and PublicShopResource would immediately publish them on a
     * public web page. Publishing contact details is the owner's explicit
     * choice, made by filling in the shop profile form — not something a
     * migration should decide for them.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('slug');
            $table->string('cover_path')->nullable()->after('logo_path');
            // text, not string: Myanmar addresses are routinely multi-line
            // ("No. 123, 5th Floor, ... Township, Yangon").
            $table->text('address')->nullable()->after('cover_path');
            // 32 matches the phone length used everywhere else in this app
            // (see StorePublicOrderRequest's customer_phone).
            $table->string('business_phone', 32)->nullable()->after('address');
            $table->string('business_email')->nullable()->after('business_phone');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path', 'cover_path', 'address', 'business_phone', 'business_email',
            ]);
        });
    }
};
