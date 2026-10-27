<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order's own expenses and income (PLAN §42.5а): what the shop paid for
 * THIS order beyond its goods — the carrier, assembly, sending it again — or
 * got on top of it. The shop's books only: these rows never touch what the
 * customer pays, are never printed, never reach a letter or 1C.
 *
 * `label` is free text; the form suggests the ones already used, which is
 * what lets the report add "Delivery to the carrier" up across orders.
 * `amount` is positive, in the books; `kind` says which way. `source` is
 * `manual` for a person, else the module that wrote it (a gateway's fee, a
 * carrier's real price — later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_inventory_order_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('kind', 8);
            $table->string('label', 190);
            $table->bigInteger('amount');
            $table->text('note')->nullable();
            $table->string('source', 64)->default('manual');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 190)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->index('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_inventory_order_costs');
    }
};
