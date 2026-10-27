<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order registered with CDEK — a waybill. One shop order can have several
 * over its life (one cancelled, a new one made), so this is a row per attempt,
 * not a column on the order: checkout names no carrier.
 *
 * CDEK registers an order asynchronously: the create call answers with a
 * `uuid` and "ACCEPTED", and only later does the order get its `cdek_number`
 * (the one on the label and in the tracking) — or turn out INVALID, with the
 * reasons in `errors`. `status_code` is CDEK's own order status (CREATED,
 * RECEIVED_AT_SHIPMENT_WAREHOUSE, …, DELIVERED); `statuses` its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_cdek_waybills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('m_checkout_orders')->cascadeOnDelete();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('number', 40);
            $table->string('cdek_number', 40)->nullable();
            $table->unsignedInteger('tariff_code');

            // registering | active | invalid | cancelled
            $table->string('state', 16)->default('registering');
            $table->string('status_code', 60)->nullable();
            $table->string('status_name', 190)->nullable();
            $table->timestamp('status_at')->nullable();
            $table->jsonb('statuses')->nullable();
            $table->jsonb('errors')->nullable();

            // What was sent, as sent: what the label says is this, whatever
            // the order has become since.
            $table->jsonb('payload');
            // Roubles to collect on delivery, kopecks; 0 = prepaid.
            $table->unsignedBigInteger('cod_kopecks')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'state']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_cdek_waybills');
    }
};
