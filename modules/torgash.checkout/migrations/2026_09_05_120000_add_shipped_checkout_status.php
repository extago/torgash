<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Отправлено" — the status between processing and complete.
 *
 * The shop had nowhere to say that the goods have left the building but the
 * order is not finished: an order was either being dealt with or done, and a
 * customer asking "has it shipped?" got the same answer as one asking "has
 * anybody looked at it?". A parcel in transit is a week of the order's life.
 *
 * It matters more now that 1C can move statuses: a shipment date in 1C is
 * exactly this event, and mapping it onto "complete" would tell the customer
 * their order is finished while the courier still has it.
 *
 * Green, and deliberately not the same green as Processing (#97cf4d): two
 * neighbouring statuses in one palette entry are two statuses a manager cannot
 * tell apart in a list of forty orders. It decreases stock, like every status
 * from Open onwards — the goods are gone in the most literal sense.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('m_checkout_order_statuses')->where('code', 'shipped')->exists()) {
            return;
        }

        // Between Processing and everything after it. Positions are the shop's
        // to rearrange afterwards; what matters is that it does not arrive at
        // the end of the list, after the statuses that mean "over".
        $after = (int) DB::table('m_checkout_order_statuses')->where('code', 'processing')->value('position');

        DB::table('m_checkout_order_statuses')->where('position', '>', $after)->increment('position');

        DB::table('m_checkout_order_statuses')->insert([
            'code' => 'shipped',
            'name' => '{"en":"Shipped","ru":"Отправлено"}',
            'color' => '#5cb85c',
            'position' => $after + 1,
            'is_default' => false,
            'inventory_action' => 'decrease',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Same care as awaiting_payment: orders may be sitting in it, and a
        // deleted row would leave them pointing at a status that is not there.
        $default = DB::table('m_checkout_order_statuses')->where('is_default', true)->value('code');

        if ($default) {
            DB::table('m_checkout_orders')->where('status', 'shipped')->update(['status' => $default]);
        }

        $position = (int) DB::table('m_checkout_order_statuses')->where('code', 'shipped')->value('position');

        DB::table('m_checkout_order_statuses')->where('code', 'shipped')->delete();
        DB::table('m_checkout_order_statuses')->where('position', '>', $position)->decrement('position');
    }
};
