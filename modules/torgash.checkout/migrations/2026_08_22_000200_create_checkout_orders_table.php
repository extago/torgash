<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owned by torgash.checkout: order capture only. Status workflow, payment
// and shipping method modules build on top of this table in a later phase.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_checkout_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->nullable()->unique();
            $table->foreignId('storefront_id')->nullable()->constrained('storefronts')->nullOnDelete();
            $table->string('customer_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('address_line');
            $table->string('city');
            $table->string('postal_code')->nullable();
            $table->text('notes')->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('shipping_cost')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('status')->default('new');
            // Snapshot of cart lines at order time: product may change/disappear later.
            $table->jsonb('items');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_checkout_orders');
    }
};
