<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where an order's number comes from (Orders\OrderNumbers): a Postgres
 * sequence of its own, not the row's id. The id is the row's and counts
 * from 1 for ever; the number is what the customer and the shop call the
 * order, and a shop may want it to start from 15 000 — "next order number"
 * in the settings moves the sequence. Started above the orders already
 * there, so a shop that has been numbering by id goes on without a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        $start = (int) DB::table('m_checkout_orders')->max('id') + 1;

        DB::statement("CREATE SEQUENCE IF NOT EXISTS m_checkout_order_numbers START WITH {$start}");
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS m_checkout_order_numbers');
    }
};
