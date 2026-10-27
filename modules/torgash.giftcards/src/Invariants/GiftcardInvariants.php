<?php

namespace Torgash\Giftcards\Invariants;

use Illuminate\Support\Facades\DB;

/**
 * What must be true about gift cards, said by the module that owns them
 * (PLAN §40.4). All three are about money that moves quietly: a balance column
 * nobody reads against its history, and a copy of the same sum on the order.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the
 * guard being installed.
 */
class GiftcardInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'giftcards.balance_is_its_history' => [
                'label' => 'Every gift card\'s balance is the sum of its history',
                'explain' => 'The balance is written beside the history so a list can show it; it is never supposed to disagree with it. A difference means something wrote the column past the ledger — the history is the truth, and the card\'s page shows it.',
                'check' => fn (int $limit) => DB::table('m_giftcards_cards as c')
                    ->leftJoin('m_giftcards_ledger as l', 'l.card_id', '=', 'c.id')
                    ->groupBy('c.id', 'c.last4', 'c.balance')
                    ->havingRaw('c.balance <> coalesce(sum(l.amount), 0)')
                    ->limit($limit)
                    ->get(['c.id', 'c.last4', 'c.balance', DB::raw('coalesce(sum(l.amount), 0) as ledger')])
                    ->map(fn ($row) => [
                        'id' => 'card'.$row->id,
                        'title' => '····'.$row->last4,
                        'detail' => ['Balance :balance, history :ledger', ['balance' => admin_price((int) $row->balance), 'ledger' => admin_price((int) $row->ledger)]],
                        'url' => route('admin.giftcards.show', $row->id),
                    ])->all(),
            ],

            'giftcards.balance_not_negative' => [
                'label' => 'No gift card owes money',
                'explain' => 'A balance below zero means a card paid more than it held — two orders spending it at once past the lock, or a correction typed the wrong way. Correct it on the card\'s page and look at the orders in its history.',
                'check' => fn (int $limit) => DB::table('m_giftcards_cards')->where('balance', '<', 0)->limit($limit)->get(['id', 'last4', 'balance'])
                    ->map(fn ($row) => [
                        'id' => 'card'.$row->id,
                        'title' => '····'.$row->last4,
                        'detail' => ['Balance :balance', ['balance' => admin_price((int) $row->balance)]],
                        'url' => route('admin.giftcards.show', $row->id),
                    ])->all(),
            ],

            'giftcards.orders_agree_with_cards' => [
                'label' => 'What gift cards paid of each order is what the order says was paid in advance',
                'explain' => 'An order keeps its own copy of what was paid before any bank was asked; the cards\' histories are the record. When they differ, the order\'s "to pay" and its refunds are measured against the wrong number. Open the order: take the card off and put it on again, or refund the difference.',
                'check' => fn (int $limit) => DB::table('m_checkout_orders as o')
                    ->leftJoinSub(
                        DB::table('m_giftcards_ledger')->whereIn('type', ['redeem', 'refund'])->whereNotNull('order_id')
                            ->groupBy('order_id')->selectRaw('order_id, -sum(amount) as net'),
                        'g', 'g.order_id', '=', 'o.id',
                    )
                    ->whereNull('o.deleted_at')
                    ->whereRaw('o.prepaid_total <> coalesce(g.net, 0)')
                    ->orderByDesc('o.id')
                    ->limit($limit)
                    ->get(['o.id', 'o.order_number', 'o.prepaid_total', DB::raw('coalesce(g.net, 0) as net')])
                    ->map(fn ($row) => [
                        'id' => 'order'.$row->id,
                        'title' => (string) $row->order_number,
                        'detail' => ['Order says :order, cards say :cards', ['order' => admin_price((int) $row->prepaid_total), 'cards' => admin_price((int) $row->net)]],
                        'url' => url('/admin/orders/'.$row->order_number),
                    ])->all(),
            ],
        ];
    }
}
