<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `method` and `gateway` are separate concepts, and the whole payment layer
     * rests on the distinction: a METHOD is what the customer picks (card, cod),
     * a GATEWAY is who processes it. One gateway serves many methods, so
     * collapsing them would mean re-integrating a provider per method.
     *
     * `gateway` is nullable because cash-on-delivery has no processor at all —
     * likely the highest-volume method here, so a first-class case.
     *
     * `config` holds non-secret per-method settings. Credentials never live
     * here; with Connect the only per-tenant value is a non-secret account id.
     */
    public function up(): void
    {
        Schema::create('tenant_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            // null means no processor: the money moves directly between
            // customer and shop and a human confirms it.
            $table->string('gateway')->nullable();
            // What the customer scans and reads in order to pay.
            $table->string('qr_path')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();

            // Configured once; toggling uses is_enabled rather than deleting,
            // so per-method config survives being switched off and on.
            $table->unique(['tenant_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_methods');
    }
};
