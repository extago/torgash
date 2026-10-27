<?php

namespace Torgash\Giftcards;

use Illuminate\Support\Facades\DB;

/**
 * An order poured into another (PLAN §40.10/4): what a gift card PAID for B
 * is now paid for A — the order's `prepaid_total` moves with it, so the
 * ledger's lines have to, or a refund of A would find nothing it may give
 * back to the card — and a card B SOLD belongs to A's line now, under that
 * line's new key, so paying A never issues it a second time. Everything moved
 * is remembered on the merge and moved back if it is undone.
 */
class OrderMerging
{
    public function merge(array $context): void
    {
        $into = $context['into'];
        $from = $context['from'];
        $keys = $context['keys'];

        $entries = DB::table('m_giftcards_ledger')->where('order_id', $from->id)->pluck('id')->all();
        DB::table('m_giftcards_ledger')->whereIn('id', $entries)->update(['order_id' => $into->id]);

        $cards = [];
        foreach (DB::table('m_giftcards_cards')->where('order_id', $from->id)->get(['id', 'order_line_key']) as $card) {
            $cards[$card->id] = $card->order_line_key;
            DB::table('m_giftcards_cards')->where('id', $card->id)->update([
                'order_id' => $into->id,
                'order_line_key' => $keys[(string) $card->order_line_key] ?? $card->order_line_key,
            ]);
        }

        $context['merge']->remember('torgash.giftcards', ['entries' => $entries, 'cards' => $cards]);
    }

    public function undo(array $context): void
    {
        $moved = (array) $context['merge']->remembered('torgash.giftcards');

        DB::table('m_giftcards_ledger')->whereIn('id', (array) ($moved['entries'] ?? []))->update(['order_id' => $context['from']->id]);

        foreach ((array) ($moved['cards'] ?? []) as $id => $key) {
            DB::table('m_giftcards_cards')->where('id', $id)->update(['order_id' => $context['from']->id, 'order_line_key' => $key]);
        }
    }
}
