<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An order taken by phone alone has no name, and says so by having none.
 *
 * It used to be stored as the words "Ordered by phone" in whatever language the
 * BUYER shopped in, so a Russian manager read English in the orders list and
 * on the dashboard. The words are now the reader's, drawn at display time
 * (`Order::customerLabel()`); rows that still carry them in either language
 * are emptied.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE m_checkout_orders ALTER COLUMN customer_name DROP NOT NULL');
        DB::table('m_checkout_orders')
            ->whereIn('customer_name', ['Ordered by phone', 'Заказ по телефону'])
            ->update(['customer_name' => null]);
    }

    public function down(): void
    {
        DB::table('m_checkout_orders')->whereNull('customer_name')->update(['customer_name' => 'Ordered by phone']);
        DB::statement('ALTER TABLE m_checkout_orders ALTER COLUMN customer_name SET NOT NULL');
    }
};
