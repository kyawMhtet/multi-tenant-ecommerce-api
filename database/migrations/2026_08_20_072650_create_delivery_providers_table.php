<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per TENANT, not a platform catalogue like PaymentMethodCatalog: those are
     * fixed because each entry teaches the app a behaviour. A courier teaches
     * it nothing, and the real set is open and regional, so a hardcoded list
     * would be wrong within a week and differently wrong per country.
     *
     * Still a table rather than free text on the order: "which courier is
     * losing our parcels?" needs grouping, and "Royal", "royal express" and
     * "RE" are three couriers to a GROUP BY and one to a human.
     */
    public function up(): void
    {
        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_providers');
    }
};
