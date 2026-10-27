<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The rates an order froze are as exact as the currency table's own (see the
 * core migration widening `currencies.rate`): an order charged in a currency
 * worth a hundred thousand of the books kept three significant digits of its
 * rate, and a reprint is supposed to match the receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE m_checkout_orders ALTER COLUMN charge_rate TYPE numeric, ALTER COLUMN payment_rate TYPE numeric');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE m_checkout_orders ALTER COLUMN charge_rate TYPE numeric(18,8), ALTER COLUMN payment_rate TYPE numeric(18,8)');
    }
};
