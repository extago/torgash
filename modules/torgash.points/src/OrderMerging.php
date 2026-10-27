<?php

namespace Torgash\Points;

use Illuminate\Support\Facades\DB;

/**
 * An order poured into another (PLAN §40.10/4). Points SPENT on B were a
 * discount on B's goods, and those goods are A's now: the spend moves to A's
 * row (and its ledger lines with it, so a refund of A gives them back). What A
 * EARNS is planned again from all of its goods when A is recalculated, which
 * the merge does next. An order whose points were already CREDITED is not
 * merged at all — that is a finished story, and folding it into another order
 * would credit its goods twice.
 */
class OrderMerging
{
    /** @return list<string> */
    public function refusals(array $refusals, array $context): array
    {
        foreach ([$context['into'], $context['from']] as $order) {
            if (DB::table('m_points_orders')->where('order_id', $order->id)->whereNotNull('credited_at')->exists()) {
                $refusals[] = __('Bonus points have already been credited for order :number.', ['number' => $order->order_number]);
            }
        }

        return $refusals;
    }

    public function merge(array $context): void
    {
        $into = $context['into'];
        $from = $context['from'];

        $mine = DB::table('m_points_orders')->where('order_id', $into->id)->first();
        $theirs = DB::table('m_points_orders')->where('order_id', $from->id)->first();

        $entries = DB::table('m_points_ledger')->where('order_id', $from->id)->pluck('id')->all();
        DB::table('m_points_ledger')->whereIn('id', $entries)->update(['order_id' => $into->id]);

        if ($theirs) {
            if ($mine) {
                DB::table('m_points_orders')->where('order_id', $into->id)->update([
                    'spent' => (int) $mine->spent + (int) $theirs->spent,
                    'spent_money' => (int) $mine->spent_money + (int) $theirs->spent_money,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('m_points_orders')->insert([...(array) $theirs, 'order_id' => $into->id]);
            }

            DB::table('m_points_orders')->where('order_id', $from->id)->delete();
        }

        $context['merge']->remember('torgash.points', [
            'entries' => $entries,
            'into' => $mine ? (array) $mine : null,
            'from' => $theirs ? (array) $theirs : null,
        ]);
    }

    public function undo(array $context): void
    {
        $moved = (array) $context['merge']->remembered('torgash.points');

        DB::table('m_points_ledger')->whereIn('id', (array) ($moved['entries'] ?? []))->update(['order_id' => $context['from']->id]);

        DB::table('m_points_orders')->where('order_id', $context['into']->id)->delete();
        foreach (['into', 'from'] as $side) {
            if (is_array($moved[$side] ?? null)) {
                DB::table('m_points_orders')->insert($moved[$side]);
            }
        }
    }
}
