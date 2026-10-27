<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plain event log, populated entirely via hooks
 * (checkout.order_placed / checkout.order_status_changed) rather than being
 * baked into the order-placement or status-update code paths — order logging
 * is an optional listener on core order events, not core code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_checkout_order_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('m_checkout_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_checkout_order_logs');
    }
};
