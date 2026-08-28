<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which fulfillment options a shop actually offers.
     *
     * Without this every storefront offers both, so a delivery-only
     * restaurant shows a pickup option nobody can use, and a takeaway with
     * no driver shows delivery it can't honour. Either way the customer
     * finds out after ordering, which is the worst moment.
     *
     * Two boolean columns rather than another table like
     * tenant_payment_methods: that table exists because each method carries
     * real per-row configuration (a QR image, instructions, display order).
     * Fulfillment is two flags with nothing hanging off them. If delivery
     * ever needs a fee, a minimum order value or zones, that's the point to
     * revisit the shape — not before.
     *
     * Both default true so every existing shop keeps behaving exactly as it
     * does today. Nothing enforces "at least one" at the database level;
     * that's a validation rule (see UpdateTenantRequest), because a CHECK
     * constraint here would be a migration to change the day someone wants
     * a catalogue-only shop that takes no orders at all.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('allows_delivery')->default(true)->after('stripe_account_id');
            $table->boolean('allows_pickup')->default(true)->after('allows_delivery');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['allows_delivery', 'allows_pickup']);
        });
    }
};
