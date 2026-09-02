<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // A plain string, not an enum: the set of processors grows,
            // and each addition shouldn't cost an ALTER TABLE.
            $table->string('gateway');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'success', 'failed', 'refunded']);
            $table->string('transaction_ref')->nullable();
            // The customer's transfer screenshot for manual methods.
            // NOTHING may treat its presence as payment — it's trivially
            // forged and exists so a human can glance and decide.
            $table->string('proof_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'transaction_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
