<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an amount in this currency is WRITTEN: where the sign stands and which
 * separators it uses — "$1,299.00", "1 299,00 ₴", "€ 1.299,00". Per currency,
 * as other engines keep it too, because it is a property of the money, not of
 * the page: a shop selling in dollars and hryvnia writes each the way its
 * readers expect.
 *
 * The defaults are exactly what every price printed before this: the sign
 * after with a space, a point, a space between thousands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            // before | before_space | after_space | after
            $table->string('symbol_position', 16)->default('after_space');
            $table->string('decimal_separator', 4)->default('.');
            $table->string('thousands_separator', 4)->default(' ');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn(['symbol_position', 'decimal_separator', 'thousands_separator']);
        });
    }
};
