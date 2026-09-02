<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            $table->enum('source', ['pos', 'online']);
            $table->enum('fulfillment_type', ['delivery', 'pickup'])->default('delivery');
            // Snapshotted: if the customer moves, this order must still say
            // where it actually went. Null for pickup.
            $table->json('delivery_address')->nullable();

            // Deliberately NOT an order status: status tracks the commercial
            // lifecycle, dispatch tracks a parcel, and a COD order goes out
            // while still unpaid.
            $table->foreignId('delivery_provider_id')->nullable()
                ->constrained('delivery_providers')->nullOnDelete();
            // Snapshotted beside the FK: the FK is nullOnDelete so a shop can
            // drop a courier, and past orders must still name who carried them.
            $table->string('delivery_provider_name')->nullable();
            // The courier's reference, not ours.
            $table->string('tracking_number', 100)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'paid', 'processing', 'completed', 'cancelled', 'refunded']);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid', 'refunded']);
            $table->string('payment_method')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->text('cancellation_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_note')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->default('MMK');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
