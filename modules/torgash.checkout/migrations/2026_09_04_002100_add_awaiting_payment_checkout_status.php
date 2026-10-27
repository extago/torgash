<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The status an order sits in while it is waiting to be paid online — and, more
 * to the point, the status in which it holds no stock.
 *
 * Until now every order took its units out of the warehouse the moment it was
 * placed, because the shop's default status decreases inventory. With an online
 * gateway that is wrong in the ordinary case: a customer who opens the bank's
 * page and closes the tab leaves an order nobody will ever pay, holding the
 * last unit of something, until a human notices and cancels it by hand.
 *
 * Older engines answer this by creating EVERY order in a status that does not
 * hold stock ("Incomplete") and moving it out immediately: straight to Open
 * for a method with no payment script, and only on the gateway's word for one
 * with.
 * That is the right shape, and it is why no reservation table or timeout is
 * needed — an unpaid order blocks nothing because it never took anything.
 *
 * What is NOT copied is their implementation: `N` exists nowhere in their
 * statuses table, so every query that lists orders has to remember to exclude
 * it by hand (`status != 'N'` appears throughout their code). Here it is an
 * ordinary row: it lists, filters, translates and gets renamed like any other,
 * and a shop that does not want it can delete it — checkout falls back to
 * placing orders exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('m_checkout_order_statuses')->where('code', 'awaiting_payment')->exists()) {
            return;
        }

        // In front of the others: it is where an order's life starts.
        DB::table('m_checkout_order_statuses')->increment('position');

        DB::table('m_checkout_order_statuses')->insert([
            'code' => 'awaiting_payment',
            'name' => '{"en":"Awaiting payment","ru":"Ожидает оплаты"}',
            'color' => '#a78bfa',
            'position' => 0,
            'is_default' => false,
            // Not "none": the two behave identically in OrderStatusChanger (both
            // mean "does not hold"), and `increase` is the one that says out
            // loud what happens to an order moved here from a status that did.
            'inventory_action' => 'increase',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Orders may be sitting in it; deleting the row would leave them
        // pointing at a status that does not exist. They are moved to the
        // shop's default first, which is what an admin cancelling the feature
        // would have to do by hand anyway.
        $default = DB::table('m_checkout_order_statuses')->where('is_default', true)->value('code');

        if ($default) {
            DB::table('m_checkout_orders')->where('status', 'awaiting_payment')->update(['status' => $default]);
        }

        DB::table('m_checkout_order_statuses')->where('code', 'awaiting_payment')->delete();
        DB::table('m_checkout_order_statuses')->decrement('position');
    }
};
