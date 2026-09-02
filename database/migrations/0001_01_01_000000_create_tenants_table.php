<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            // text, not string: Myanmar addresses are routinely multi-line
            // ("No. 123, 5th Floor, ... Township, Yangon").
            $table->text('address')->nullable();
            // 32 matches the phone length used everywhere else in this app
            // (see StorePublicOrderRequest's customer_phone).
            $table->string('business_phone', 32)->nullable();
            $table->string('business_email')->nullable();
            $table->string('owner_name');
            $table->string('owner_email');
            $table->string('owner_phone');
            $table->string('plan')->default('trial');
            $table->enum('subscription_status', ['trial', 'active', 'past_due', 'cancelled'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            // Chosen at signup and effectively permanent — money columns
            // carry no currency tag, so changing it once orders exist would
            // retroactively reinterpret every historical total.
            // UpdateTenantRequest refuses it.
            $table->string('currency', 3)->default('MMK');
            // Editable, unlike currency: business_hours are wall-clock times
            // with no zone attached, and Yangon (UTC+6:30) vs Bangkok (UTC+7)
            // is a real 30-minute difference.
            $table->string('timezone')->default('Asia/Yangon');
            // Only an acct_... id is stored per tenant; there are no
            // per-tenant Stripe secrets anywhere.
            $table->string('stripe_account_id')->nullable();
            $table->boolean('allows_delivery')->default(true);
            $table->boolean('allows_pickup')->default(true);
            // What the shop charges to deliver. Never taken from request
            // input — see DeliveryFeeCalculator.
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
