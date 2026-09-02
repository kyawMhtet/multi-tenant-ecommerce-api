<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform staff locking a shop out of its own admin — for a dispute, a
     * chargeback, an investigation.
     *
     * Deliberately NOT a reuse of `is_active`. That column makes ResolveTenant
     * 404 on BOTH of its branches, so it takes the public storefront down too:
     * customers who did nothing wrong lose the shop mid-order, and links they
     * already hold break. That is the right hammer for fraud and stays
     * available for it, but it is far too heavy for "we need to talk to this
     * shop about a payment", which is what this column is for.
     *
     * The asymmetry is the whole feature: suspension is checked only in
     * ResolveTenant's AUTHENTICATED branch, so the shop owner is locked out
     * while the storefront keeps serving. Same instinct as the billing lapse
     * design, where a shop that stops paying still keeps its public links
     * working.
     *
     * A reason is stored because one is required at the API — a shop told only
     * "suspended" cannot do anything except open a support ticket, which costs
     * more than typing the sentence would have. Same rule as a rejected
     * transfer.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_active');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
