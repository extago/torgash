<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tables: which payment method is wired to which gateway, and everything
 * that gateway ever said.
 *
 * The binding is a table of OURS keyed by the payment method's id, not a column
 * added to `m_checkout_payment_methods`. A module may not patch another
 * module's schema — and the add-on this design was read from does exactly that
 * (it adds a column to the currencies table and never removes it on
 * uninstall), which is the mess the rule exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_payments_gateways', function (Blueprint $table) {
            $table->id();

            // No FK: checkout owns that table and this module must not constrain
            // it. A binding whose method is gone is skipped and shown as such.
            $table->unsignedBigInteger('payment_method_id')->unique();

            $table->string('gateway', 64);
            $table->jsonb('config')->nullable();

            // Explicit, never inferred from the shape of a key — the add-on that
            // decided "demo" by looking for "test_store" inside the merchant id
            // is one rename away from taking real money in a test.
            $table->boolean('test_mode')->default(false);
            $table->timestamps();
        });

        /**
         * Every callback and every admin action, verified or not.
         *
         * Raw bodies are kept because they are the only evidence in a dispute
         * with the bank — the reference add-on appended them to a text file with
         * no rotation and no way to search, which is the same idea done in the
         * way that stops working the first time it matters.
         */
        Schema::create('m_payments_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 64);
            $table->unsignedBigInteger('order_id')->nullable();

            // The gateway's own id for the payment (an invoice id), and its id
            // for THIS message. The second is what makes a redelivered callback
            // a duplicate rather than a second payment.
            $table->string('external_id')->nullable();
            $table->string('event_key')->nullable();

            $table->string('state', 32)->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->boolean('verified')->default(false);
            $table->boolean('applied')->default(false);
            $table->string('summary')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamp('created_at')->nullable();

            // Postgres treats NULLs as distinct, so admin actions and gateways
            // that send no message id simply never collide here.
            $table->unique(['gateway', 'event_key']);
            $table->index(['order_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_payments_transactions');
        Schema::dropIfExists('m_payments_gateways');
    }
};
