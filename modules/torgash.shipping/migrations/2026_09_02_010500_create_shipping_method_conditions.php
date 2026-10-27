<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a method is offered at all, beyond "the zone has a rate for it".
 *
 * Rows, not a blob on the method (PLAN §12.2): the same reasoning as the rate
 * bands — a condition is exported by exim, diffs in a code review, and is shown
 * one per line in the admin. A JSON blob would be none of those.
 *
 * ALL conditions must pass. "Any of these" lives inside a single row's value
 * (a list of categories, a list of groups), which is the shape merchants
 * actually write: "for furniture, for wholesale customers, over 500" is three
 * requirements, not three alternatives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_shipping_method_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')
                ->constrained('m_shipping_methods')
                ->cascadeOnDelete();

            // What the condition is about: category, attribute, customer_group,
            // coupon, cart_total, weight_known. See Conditions::KINDS.
            $table->string('kind', 24);
            // How it is tested — the operators a kind allows are its own.
            $table->string('operator', 16);
            // Whatever the operator needs: a list of ids, a code, an amount in
            // minor units, a {key, value} pair. jsonb because the shapes differ
            // and none of them is worth a column.
            $table->jsonb('value')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('shipping_method_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_shipping_method_conditions');
    }
};
