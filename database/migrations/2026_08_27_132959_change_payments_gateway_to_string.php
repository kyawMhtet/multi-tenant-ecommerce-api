<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `gateway` was enum('cash','kbzpay','wavepay','myanmyanpay','other').
     *
     * An enum made sense when the set looked closed, but the roadmap is
     * explicitly multi-gateway (Stripe now, 2C2P and MyanMyanPay later),
     * and every addition to a MySQL enum costs an ALTER TABLE on a table
     * that will only grow. Worse, it's a schema migration to express what
     * is really an application-level fact — which providers the code knows
     * how to talk to. A string moves that knowledge into the gateway
     * classes, where adding a provider is adding a class, not a migration.
     *
     * The same reasoning already applies elsewhere in this schema:
     * `tenants.plan` is a plain string, not an enum, for exactly this.
     *
     * Note there is no 'stripe' value to add here — that's the point. The
     * set of valid gateways is now defined by which PaymentGateway
     * implementations exist, and validated at the request layer rather than
     * by the database.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('gateway', ['cash', 'kbzpay', 'wavepay', 'myanmyanpay', 'other'])->change();
        });
    }
};
