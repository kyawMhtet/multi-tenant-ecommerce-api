<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the order is going, and how.
     *
     * Until now a storefront order captured only a name and phone — which
     * meant a cash-on-delivery order, the primary payment method for this
     * product's users, had no address to deliver to.
     *
     * fulfillment_type exists because restaurants routinely do both. Making
     * the address unconditionally required would force pickup orders to
     * invent one; leaving it always optional would let a delivery order be
     * placed with nowhere to send it. Defaulting to 'delivery' keeps every
     * existing row meaningful (they were all delivery-shaped) and matches
     * the common case.
     *
     * delivery_address is JSON rather than six columns. An address is a
     * write-once value object here — captured at checkout, read whole,
     * never queried by part — and its shape genuinely varies: a Yangon
     * address leans on landmarks and townships, a Bangkok one on soi and
     * district. The read-modify-write hazard that argued for real columns
     * elsewhere in this schema (logo_path, qr_path) doesn't apply, because
     * nothing ever partially updates an address after the order is placed.
     *
     * Snapshotted on the ORDER, deliberately, even though customers.address
     * also exists. Same principle as order_items.unit_price: if a customer
     * moves house, past orders must still record where they were actually
     * delivered. The customer record holds a default for prefilling next
     * time; the order holds the truth about this one.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('fulfillment_type', ['delivery', 'pickup'])
                ->default('delivery')
                ->after('source');

            $table->json('delivery_address')->nullable()->after('fulfillment_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_type', 'delivery_address']);
        });
    }
};
