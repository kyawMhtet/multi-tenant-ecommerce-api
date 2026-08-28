<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer's screenshot of their transfer.
     *
     * Lives on `payments` rather than `orders` because it's evidence for a
     * specific payment attempt, not a property of the order — a customer
     * who pays the wrong amount and transfers again produces two attempts
     * with two screenshots, and the shop needs to see both.
     *
     * Nullable: card payments never have one (the gateway is the evidence),
     * cash on delivery never has one, and even a QR order may be placed
     * before the customer has paid.
     *
     * A screenshot is NOT proof — it's a claim, and a trivially forgeable
     * one. Nothing in this app may treat its presence as payment. It exists
     * so a human can glance at it and decide, which is the same judgement
     * they already make today over Facebook and Line. The authority to mark
     * an order paid stays with the shop.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('transaction_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('proof_path');
        });
    }
};
