<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rates per destination — the step the first taxes migration left for later,
 * because an order then stored no country. It does now (`country`, `place_id`
 * and `postal_code`, the region derived from the place), so a tax can say
 * "20% everywhere, 0% in this country, not charged at all in that one".
 *
 * A row is WHERE plus a RATE, in the same vocabulary as a shipping zone rule
 * (country / `UA:12` region / postal pattern) and matched the same way — the
 * narrowest wins — but in this module's own table: a digital shop uninstalls
 * shipping and still owes VAT. The tax's own rate stays what it was, the
 * answer for everywhere no row names and for a customer who has not said
 * where they are yet; `only_listed` turns that answer off, which is a US
 * sales tax owed only in the states where the shop has nexus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_taxes_taxes', function (Blueprint $table) {
            $table->boolean('only_listed')->default(false);
        });

        Schema::create('m_taxes_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_id')->constrained('m_taxes_taxes')->cascadeOnDelete();
            // country: 'DE' · state: 'US:CA' · postal: '98*', '98?78', '98101'
            $table->string('kind', 16);
            $table->string('value', 64);
            // Derived from kind + value on save, never typed — see TaxRate.
            $table->smallInteger('level');
            // False = "not charged here": no line at all, which is a different
            // answer from a 0% line (an export invoice PRINTS its 0%).
            $table->boolean('charged')->default(true);
            $table->decimal('rate_percent', 7, 4)->nullable();
            $table->bigInteger('rate_amount')->nullable();
            $table->smallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tax_id', 'kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_taxes_rates');

        Schema::table('m_taxes_taxes', function (Blueprint $table) {
            $table->dropColumn('only_listed');
        });
    }
};
