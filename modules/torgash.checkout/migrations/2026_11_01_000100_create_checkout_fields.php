<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own questions at checkout ("Who takes the parcel", "How did you
 * find us") — Models\CheckoutField — and the order's answers to them, a list
 * of {code, label, value} written when it is placed (Orders\CheckoutFields).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_checkout_fields', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('type', 16)->default('text');
            $table->jsonb('label');
            $table->jsonb('help')->nullable();
            $table->jsonb('placeholder')->nullable();
            // One translatable list per language, matched by POSITION.
            $table->jsonb('options')->nullable();
            $table->boolean('required')->default(false);
            $table->string('place', 16)->default('recipient');
            $table->string('buyer', 16)->default('all');
            // Shipping method ids it is asked for; null = whatever the delivery.
            $table->jsonb('shipping_methods')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->jsonb('fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('fields');
        });
        Schema::dropIfExists('m_checkout_fields');
    }
};
