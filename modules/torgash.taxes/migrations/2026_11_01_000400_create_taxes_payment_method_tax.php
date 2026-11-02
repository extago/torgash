<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which taxes a payment method's surcharge carries (PLAN §66), in this module's own table rather than a column of
 * checkout's: a shop that charges no tax uninstalls this and the surcharge
 * stays. No rows is no tax on the surcharge, which is what every method has
 * until somebody says otherwise. The rows say WHICH tax; the rate is still
 * the one that applies where the goods go (Destinations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_taxes_payment_method_tax', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('m_checkout_payment_methods')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('m_taxes_taxes')->cascadeOnDelete();

            $table->unique(['payment_method_id', 'tax_id']);
            $table->index('tax_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_taxes_payment_method_tax');
    }
};
