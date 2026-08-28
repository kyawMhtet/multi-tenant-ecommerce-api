<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which payment methods a shop accepts, one row per method.
     *
     * A real table rather than a key in tenants.settings: the storefront
     * queries "what does this shop accept?" on every checkout render, the
     * list needs a display order, and a shop must not be able to enable the
     * same method twice — none of which a JSON blob gives you.
     *
     * `method` and `gateway` are deliberately separate concepts, and this
     * is the distinction the whole payment layer rests on. A *method* is
     * what the customer picks (Visa, KBZPay, cash on delivery); a *gateway*
     * is who processes it. One gateway serves many methods — 2C2P alone
     * covers Visa, MPU and the local wallets — so collapsing them into one
     * column would mean re-integrating a provider per method later.
     *
     * `gateway` is nullable precisely because cash-on-delivery has no
     * processor at all: nothing to initiate, no webhook to wait for, just
     * an order that sits unpaid until a human confirms cash changed hands.
     * For Myanmar that will likely be the highest-volume method, so it's
     * modelled as a first-class case rather than an exception.
     *
     * `config` holds non-secret per-method settings (a COD surcharge, a
     * minimum order value). Credentials never live here — with Stripe
     * Connect the only per-tenant value is an account id, which lives on
     * `tenants` and isn't a secret. See the shop's stripe_account_id column.
     */
    public function up(): void
    {
        Schema::create('tenant_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('gateway')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();

            // A shop configures each method exactly once — toggling uses
            // is_enabled rather than deleting and re-adding rows, so a
            // shop's per-method config survives being switched off and on.
            $table->unique(['tenant_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_methods');
    }
};
